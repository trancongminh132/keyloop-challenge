<?php

namespace App\Services\Documents;

use Illuminate\Support\Carbon;

class DocumentNormalizer
{
    public function normalize(string $source, string $vin, array $payload): array
    {
        return match ($source) {
            'sales' => $this->normalizeSales($vin, $payload),
            'service' => $this->normalizeService($vin, $payload),
            default => [],
        };
    }

    private function normalizeSales(string $vin, array $payload): array
    {
        return collect($payload['documents'] ?? [])
            ->map(fn (array $document): array => [
                'vin' => $vin,
                'source_system' => 'sales',
                'external_id' => (string) ($document['id'] ?? ''),
                'document_type' => $document['category'] ?? null,
                'title' => $document['name'] ?? 'Untitled sales document',
                'issued_at' => $this->date($document['createdAt'] ?? null),
                'document_url' => $document['url'] ?? null,
                'metadata' => [
                    'customer_reference' => $document['customerReference'] ?? null,
                ],
            ])
            ->filter(fn (array $document): bool => $document['external_id'] !== '')
            ->values()
            ->all();
    }

    private function normalizeService(string $vin, array $payload): array
    {
        return collect($payload['records'] ?? $payload['documents'] ?? [])
            ->map(fn (array $document): array => [
                'vin' => $vin,
                'source_system' => 'service',
                'external_id' => (string) ($document['documentId'] ?? $document['id'] ?? ''),
                'document_type' => $document['documentType'] ?? $document['type'] ?? null,
                'title' => $document['description'] ?? $document['title'] ?? 'Untitled service document',
                'issued_at' => $this->date($document['date'] ?? $document['issuedAt'] ?? null),
                'document_url' => $document['link'] ?? $document['url'] ?? null,
                'metadata' => [
                    'repair_order' => $document['repairOrder'] ?? null,
                    'mileage' => $document['mileage'] ?? null,
                ],
            ])
            ->filter(fn (array $document): bool => $document['external_id'] !== '')
            ->values()
            ->all();
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
