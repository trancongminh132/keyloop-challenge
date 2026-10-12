<?php

namespace App\Services\Documents;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Str;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ExternalDocumentClient
{
    public function __construct(
        private readonly Client $http,
        private readonly string $source,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function fetchAsync(string $vin): PromiseInterface
    {
        $startedAt = microtime(true);
        $url = rtrim($this->baseUrl, '/').'/vehicles/'.rawurlencode($vin).'/documents';

        return $this->http
            ->getAsync($url, [
                'connect_timeout' => min(1, $this->timeoutSeconds),
                'http_errors' => false,
                'timeout' => $this->timeoutSeconds,
            ])
            ->then(
                fn (ResponseInterface $response): array => $this->handleResponse($response, $startedAt),
                fn (Throwable $exception): array => $this->handleFailure($exception, $startedAt),
            );
    }

    private function handleResponse(ResponseInterface $response, float $startedAt): array
    {
        $durationMs = $this->durationMs($startedAt);
        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            return [
                'source' => $this->source,
                'status' => 'error',
                'http_status' => $statusCode,
                'duration_ms' => $durationMs,
                'document_count' => 0,
                'error_message' => 'Upstream returned HTTP '.$statusCode.'.',
            ];
        }

        try {
            $payload = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [
                'source' => $this->source,
                'status' => 'invalid_response',
                'http_status' => $statusCode,
                'duration_ms' => $durationMs,
                'document_count' => 0,
                'error_message' => 'Upstream returned invalid JSON.',
            ];
        }

        return [
            'source' => $this->source,
            'status' => 'ok',
            'http_status' => $statusCode,
            'duration_ms' => $durationMs,
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    private function handleFailure(Throwable $exception, float $startedAt): array
    {
        $message = $exception instanceof TransferException
            ? $exception->getMessage()
            : 'Unexpected upstream error.';

        return [
            'source' => $this->source,
            'status' => Str::contains(Str::lower($message), ['timeout', 'timed out'])
                ? 'timeout'
                : 'unavailable',
            'http_status' => null,
            'duration_ms' => $this->durationMs($startedAt),
            'document_count' => 0,
            'error_message' => Str::limit($message, 500),
        ];
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
