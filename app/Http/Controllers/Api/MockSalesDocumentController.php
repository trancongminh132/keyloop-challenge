<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documents\MockDocumentResponse;
use Symfony\Component\HttpFoundation\Response;

class MockSalesDocumentController extends Controller
{
    public function __invoke(string $vin, MockDocumentResponse $mock): Response
    {
        $vin = strtoupper($vin);

        return $mock->respond('sales', [
            'vin' => $vin,
            'documents' => [
                [
                    'id' => 'SALE-'.$vin.'-contract',
                    'category' => 'sales_contract',
                    'name' => 'Sales Contract',
                    'createdAt' => '2026-09-10T10:15:00Z',
                    'url' => 'https://sales.example.test/documents/'.$vin.'/contract.pdf',
                    'customerReference' => 'CUST-1001',
                ],
                [
                    'id' => 'SALE-'.$vin.'-finance',
                    'category' => 'finance_agreement',
                    'name' => 'Finance Agreement',
                    'createdAt' => '2026-09-10T10:20:00Z',
                    'url' => 'https://sales.example.test/documents/'.$vin.'/finance.pdf',
                    'customerReference' => 'CUST-1001',
                ],
            ],
        ]);
    }
}
