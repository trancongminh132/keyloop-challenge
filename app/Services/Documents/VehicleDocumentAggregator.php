<?php

namespace App\Services\Documents;

use App\Models\DocumentFetchAttempt;
use App\Models\VehicleDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VehicleDocumentAggregator
{
    public function __construct(
        private readonly DocumentNormalizer $normalizer,
        private readonly Client $client,
    ) {}

    public function documentsForVin(string $vin, ?string $requestId = null): array
    {
        $requestId ??= (string) Str::uuid();
        $responses = Utils::all($this->promises($vin))->wait();
        $documents = [];
        $sources = [];
        $warnings = [];

        foreach ($responses as $source => $response) {
            $normalized = [];

            if ($response['status'] === 'ok') {
                $normalized = $this->normalizer->normalize($source, $vin, $response['payload'] ?? []);
                $documents = [...$documents, ...$normalized];
            } else {
                $warnings[] = $this->warningFor($source, $response['status']);
            }

            $sources[$source] = [
                'status' => $response['status'],
                'duration_ms' => $response['duration_ms'],
            ];

            $this->recordAttempt($requestId, $vin, $source, $response, count($normalized));
        }

        if ($documents === [] && collect($sources)->every(fn (array $source): bool => $source['status'] !== 'ok')) {
            Log::warning('All vehicle document sources failed.', [
                'request_id' => $requestId,
                'vin' => $vin,
                'sources' => $sources,
            ]);
            throw new DocumentAggregationException($sources, $warnings);
        }

        $documents = $this->deduplicate($documents);
        $this->persistDocuments($documents);

        Log::info('Vehicle documents aggregated.', [
            'request_id' => $requestId,
            'vin' => $vin,
            'document_count' => count($documents),
            'sources' => $sources,
        ]);

        return [
            'request_id' => $requestId,
            'vin' => $vin,
            'documents' => array_map(fn (array $document): array => $this->apiDocument($document), $documents),
            'sources' => $sources,
            'warnings' => $warnings,
        ];
    }

    private function promises(string $vin): array
    {
        $timeout = (int) config('services.dealership_documents.timeout', 2);

        return [
            'sales' => (new ExternalDocumentClient(
                $this->client,
                'sales',
                (string) config('services.dealership_documents.sales_url'),
                $timeout,
            ))->fetchAsync($vin),
            'service' => (new ExternalDocumentClient(
                $this->client,
                'service',
                (string) config('services.dealership_documents.service_url'),
                $timeout,
            ))->fetchAsync($vin),
        ];
    }

    private function recordAttempt(
        string $requestId,
        string $vin,
        string $source,
        array $response,
        int $documentCount,
    ): void {
        DocumentFetchAttempt::create([
            'request_id' => $requestId,
            'vin' => $vin,
            'source_system' => $source,
            'status' => $response['status'],
            'http_status' => $response['http_status'],
            'duration_ms' => $response['duration_ms'],
            'document_count' => $documentCount,
            'error_message' => $response['error_message'] ?? null,
        ]);
    }

    private function persistDocuments(array $documents): void
    {
        foreach ($documents as $document) {
            VehicleDocument::updateOrCreate(
                [
                    'source_system' => $document['source_system'],
                    'external_id' => $document['external_id'],
                ],
                $document,
            );
        }
    }

    private function deduplicate(array $documents): array
    {
        return collect($documents)
            ->unique(fn (array $document): string => $document['source_system'].':'.$document['external_id'])
            ->sortByDesc('issued_at')
            ->values()
            ->all();
    }

    private function apiDocument(array $document): array
    {
        return [
            'id' => $document['external_id'],
            'source' => $document['source_system'],
            'type' => $document['document_type'],
            'title' => $document['title'],
            'issued_at' => $document['issued_at']?->toJSON(),
            'document_url' => $document['document_url'],
        ];
    }

    private function warningFor(string $source, string $status): string
    {
        $name = $source === 'sales' ? 'Sales System API' : 'Service System API';

        return match ($status) {
            'timeout' => $name.' timed out; returned available documents only.',
            'invalid_response' => $name.' returned an invalid response; returned available documents only.',
            default => $name.' failed; returned available documents only.',
        };
    }
}
