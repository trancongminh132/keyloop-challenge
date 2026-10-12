<?php

namespace App\Services\Documents;

use Symfony\Component\HttpFoundation\Response;

class MockDocumentResponse
{
    public function respond(string $source, array $payload): Response
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $settings = config('services.dealership_documents.mocks.'.$source, []);
        $delayMs = max(0, min(10000, (int) ($settings['delay_ms'] ?? 0)));

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        return match ($settings['scenario'] ?? 'success') {
            'success' => response()->json($payload),
            'empty' => response()->json(['vin' => $payload['vin'], $source === 'sales' ? 'documents' : 'records' => []]),
            'error' => response()->json(['message' => 'Simulated upstream failure.'], 503),
            'malformed' => response('{invalid-json', 200, ['Content-Type' => 'application/json']),
            default => response()->json(['message' => 'Unknown mock scenario.'], 500),
        };
    }
}
