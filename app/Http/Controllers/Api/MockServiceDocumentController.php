<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\MockDocumentResponse;
use Symfony\Component\HttpFoundation\Response;

class MockServiceDocumentController extends Controller
{
    public function __invoke(string $vin, MockDocumentResponse $mock): Response
    {
        $vin = strtoupper($vin);

        return $mock->respond('service', [
            'vin' => $vin,
            'records' => [
                [
                    'documentId' => 'SVC-'.$vin.'-invoice',
                    'documentType' => 'service_invoice',
                    'description' => 'Service Invoice',
                    'date' => '2026-10-01T08:30:00Z',
                    'link' => 'https://service.example.test/documents/'.$vin.'/invoice.pdf',
                    'repairOrder' => 'RO-2088',
                    'mileage' => 45210,
                ],
                [
                    'documentId' => 'SVC-'.$vin.'-inspection',
                    'documentType' => 'inspection_report',
                    'description' => 'Vehicle Inspection Report',
                    'date' => '2026-10-01T08:45:00Z',
                    'link' => 'https://service.example.test/documents/'.$vin.'/inspection.pdf',
                    'repairOrder' => 'RO-2088',
                    'mileage' => 45210,
                ],
            ],
        ]);
    }
}
