<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentAggregationException;
use App\Services\Documents\VehicleDocumentAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class VehicleDocumentController extends Controller
{
    public function __invoke(string $vin, VehicleDocumentAggregator $aggregator): JsonResponse
    {
        $vin = strtoupper($vin);
        $requestId = (string) Str::uuid();

        $validator = Validator::make(
            ['vin' => $vin],
            [
                'vin' => [
                    'required',
                    'string',
                    'regex:/^[A-HJ-NPR-Z0-9]{17}$/',
                ],
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The VIN must contain 17 letters and digits, excluding I, O and Q.',
                'request_id' => $requestId,
                'errors' => $validator->errors(),
            ], 422)->header('X-Request-ID', $requestId);
        }

        try {
            $result = $aggregator->documentsForVin($vin, $requestId);
        } catch (DocumentAggregationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'request_id' => $requestId,
                'vin' => $vin,
                'documents' => [],
                'sources' => $exception->sources(),
                'warnings' => $exception->warnings(),
            ], 502)->header('X-Request-ID', $requestId);
        } catch (Throwable $exception) {
            Log::error('Vehicle document request failed.', [
                'request_id' => $requestId,
                'vin' => $vin,
                'exception' => $exception,
            ]);

            return response()->json([
                'message' => 'Unable to retrieve vehicle documents.',
                'request_id' => $requestId,
            ], 500)->header('X-Request-ID', $requestId);
        }

        return response()->json($result)->header('X-Request-ID', $requestId);
    }
}
