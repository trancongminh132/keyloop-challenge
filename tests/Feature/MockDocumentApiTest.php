<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MockDocumentApiTest extends TestCase
{
    public static function sources(): array
    {
        return [['sales', 'documents'], ['service', 'records']];
    }

    #[DataProvider('sources')]
    public function test_mock_scenarios(string $source, string $key): void
    {
        $url = '/api/mock/'.$source.'/vehicles/1HGCM82633A004352/documents';
        config(['services.dealership_documents.mocks.'.$source.'.delay_ms' => 0]);
        config(['services.dealership_documents.mocks.'.$source.'.scenario' => 'success']);
        $this->getJson($url)->assertOk()->assertJsonCount(2, $key);
        config(['services.dealership_documents.mocks.'.$source.'.scenario' => 'empty']);
        $this->getJson($url)->assertOk()->assertJsonCount(0, $key);
        config(['services.dealership_documents.mocks.'.$source.'.scenario' => 'error']);
        $this->getJson($url)->assertStatus(503);
        config(['services.dealership_documents.mocks.'.$source.'.scenario' => 'malformed']);
        $this->get($url)->assertOk()->assertContent('{invalid-json');
    }

    public function test_delay_is_applied(): void
    {
        config(['services.dealership_documents.mocks.sales' => ['scenario' => 'success', 'delay_ms' => 50]]);
        $start = microtime(true);
        $this->getJson('/api/mock/sales/vehicles/1HGCM82633A004352/documents')->assertOk();
        $this->assertGreaterThanOrEqual(0.05, microtime(true) - $start);
    }

    public function test_mocks_are_unavailable_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->getJson('/api/mock/sales/vehicles/1HGCM82633A004352/documents')->assertNotFound();
        $this->getJson('/api/mock/service/vehicles/1HGCM82633A004352/documents')->assertNotFound();
    }
}
