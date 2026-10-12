<?php

namespace Tests\Unit;

use App\Services\Documents\DocumentNormalizer;
use PHPUnit\Framework\TestCase;

class DocumentNormalizerTest extends TestCase
{
    public function test_sales_documents_are_normalized(): void
    {
        $documents = (new DocumentNormalizer)->normalize('sales', '1HGCM82633A004352', [
            'documents' => [
                [
                    'id' => 'S-1',
                    'category' => 'sales_contract',
                    'name' => 'Sales Contract',
                    'createdAt' => '2026-09-10T10:15:00Z',
                    'url' => 'https://example.test/sales.pdf',
                    'customerReference' => 'C-1',
                ],
            ],
        ]);

        $this->assertSame('sales', $documents[0]['source_system']);
        $this->assertSame('S-1', $documents[0]['external_id']);
        $this->assertSame('sales_contract', $documents[0]['document_type']);
    }

    public function test_service_documents_are_normalized(): void
    {
        $documents = (new DocumentNormalizer)->normalize('service', '1HGCM82633A004352', [
            'records' => [
                [
                    'documentId' => 'RO-1',
                    'documentType' => 'service_invoice',
                    'description' => 'Service Invoice',
                    'date' => '2026-10-01T08:30:00Z',
                    'link' => 'https://example.test/service.pdf',
                ],
            ],
        ]);

        $this->assertSame('service', $documents[0]['source_system']);
        $this->assertSame('RO-1', $documents[0]['external_id']);
        $this->assertSame('service_invoice', $documents[0]['document_type']);
    }
}
