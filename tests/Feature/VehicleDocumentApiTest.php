<?php

namespace Tests\Feature;

use App\Models\DocumentFetchAttempt;
use App\Models\VehicleDocument;
use App\Services\Documents\VehicleDocumentAggregator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VehicleDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_vin_is_rejected(): void
    {
        $this->getJson('/api/v1/vehicles/INVALID/documents')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['vin']);
    }

    public function test_documents_are_aggregated_normalized_and_persisted(): void
    {
        $this->fakeUpstreams([
            new Response(200, [], json_encode([
                'documents' => [
                    [
                        'id' => 'SALE-1',
                        'category' => 'sales_contract',
                        'name' => 'Sales Contract',
                        'createdAt' => '2026-09-10T10:15:00Z',
                        'url' => 'https://example.test/sales.pdf',
                    ],
                ],
            ])),
            new Response(200, [], json_encode([
                'records' => [
                    [
                        'documentId' => 'SVC-1',
                        'documentType' => 'service_invoice',
                        'description' => 'Service Invoice',
                        'date' => '2026-10-01T08:30:00Z',
                        'link' => 'https://example.test/service.pdf',
                    ],
                ],
            ])),
        ]);

        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()
            ->assertJsonPath('vin', '1HGCM82633A004352')
            ->assertJsonPath('sources.sales.status', 'ok')
            ->assertJsonPath('sources.service.status', 'ok')
            ->assertJsonCount(2, 'documents')
            ->assertJsonFragment(['source' => 'sales', 'title' => 'Sales Contract'])
            ->assertJsonFragment(['source' => 'service', 'title' => 'Service Invoice']);

        $this->assertSame(2, VehicleDocument::count());
        $this->assertSame(2, DocumentFetchAttempt::count());
    }

    public function test_partial_upstream_failure_returns_available_documents(): void
    {
        $this->fakeUpstreams([
            new Response(500, [], 'failed'),
            new Response(200, [], json_encode([
                'records' => [
                    [
                        'documentId' => 'SVC-1',
                        'documentType' => 'service_invoice',
                        'description' => 'Service Invoice',
                        'date' => '2026-10-01T08:30:00Z',
                        'link' => 'https://example.test/service.pdf',
                    ],
                ],
            ])),
        ]);

        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()
            ->assertJsonPath('sources.sales.status', 'error')
            ->assertJsonPath('sources.service.status', 'ok')
            ->assertJsonCount(1, 'documents')
            ->assertJsonCount(1, 'warnings');
    }

    public function test_both_upstream_failures_return_bad_gateway(): void
    {
        $this->fakeUpstreams([
            new Response(500, [], 'failed'),
            new Response(503, [], 'unavailable'),
        ]);

        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertStatus(502)
            ->assertJsonPath('sources.sales.status', 'error')
            ->assertJsonPath('sources.service.status', 'error')
            ->assertJsonCount(0, 'documents')
            ->assertJsonCount(2, 'warnings');

        $this->assertSame(2, DocumentFetchAttempt::count());
    }

    private function fakeUpstreams(array $responses): void
    {
        $mock = new MockHandler($responses);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $this->app->bind(Client::class, fn (): Client => $client);

        Config::set('services.dealership_documents.sales_url', 'https://sales.example.test');
        Config::set('services.dealership_documents.service_url', 'https://service.example.test');
    }

    public function test_timeout_preserves_available_documents_and_records_correlation(): void
    {
        Log::spy();
        $this->fakeUpstreams([
            new ConnectException('cURL error 28: Operation timed out', new Request('GET', 'https://sales.example.test')),
            new Response(200, [], json_encode(['records' => [['documentId' => 'SVC-1']]])),
        ]);

        $response = $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()
            ->assertJsonPath('sources.sales.status', 'timeout')
            ->assertJsonCount(1, 'documents');

        $id = $response->json('request_id');
        $response->assertHeader('X-Request-ID', $id);
        $this->assertSame(2, DocumentFetchAttempt::where('request_id', $id)->count());
        $this->assertDatabaseHas('document_fetch_attempts', ['request_id' => $id, 'source_system' => 'sales', 'status' => 'timeout']);
        Log::shouldHaveReceived('info')->with('Vehicle documents aggregated.', \Mockery::on(fn (array $context): bool => $context['request_id'] === $id))->once();
    }

    public function test_malformed_json_is_a_source_failure(): void
    {
        $this->fakeUpstreams([
            new Response(200, [], '{broken'),
            new Response(200, [], '{"records":[]}'),
        ]);
        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()
            ->assertJsonPath('sources.sales.status', 'invalid_response')
            ->assertJsonPath('sources.service.status', 'ok')
            ->assertJsonCount(1, 'warnings');
    }

    public function test_empty_success_is_not_a_gateway_failure(): void
    {
        $this->fakeUpstreams([new Response(200, [], '{"documents":[]}'), new Response(200, [], '{"records":[]}')]);
        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()->assertJsonCount(0, 'documents')->assertJsonCount(0, 'warnings');
    }

    public function test_repeated_search_updates_documents_without_duplicate_rows(): void
    {
        foreach (['Original', 'Updated'] as $title) {
            $this->fakeUpstreams([
                new Response(200, [], json_encode(['documents' => [
                    ['id' => 'SALE-1', 'name' => $title],
                    ['id' => 'SALE-1', 'name' => $title],
                ]])),
                new Response(200, [], '{"records":[]}'),
            ]);
            $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')->assertOk()->assertJsonCount(1, 'documents');
        }
        $this->assertSame(1, VehicleDocument::count());
        $this->assertDatabaseHas('vehicle_documents', ['external_id' => 'SALE-1', 'title' => 'Updated']);
        $this->assertSame(4, DocumentFetchAttempt::count());
    }

    public function test_both_requests_are_dispatched_before_waiting(): void
    {
        $started = [];
        $client = new Client(['handler' => function ($request) use (&$started) {
            $started[] = $request->getUri()->getHost();
            $promise = new Promise(function () use (&$promise, &$started): void {
                $this->assertCount(2, $started, 'Both HTTP requests must start before either promise is waited on.');
                $promise->resolve(new Response(200, [], '{"documents":[],"records":[]}'));
            });

            return $promise;
        }]);
        $this->app->bind(Client::class, fn (): Client => $client);
        Config::set('services.dealership_documents.sales_url', 'https://sales.example.test');
        Config::set('services.dealership_documents.service_url', 'https://service.example.test');
        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')->assertOk();
        $this->assertSame(['sales.example.test', 'service.example.test'], $started);
    }

    public function test_validation_and_gateway_errors_have_request_ids(): void
    {
        $invalid = $this->getJson('/api/v1/vehicles/INVALID/documents')->assertUnprocessable();
        $invalid->assertHeader('X-Request-ID', $invalid->json('request_id'));
        $this->assertSame(0, DocumentFetchAttempt::count());

        Log::spy();
        $this->fakeUpstreams([new Response(503), new Response(503)]);
        $failed = $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')->assertStatus(502);
        $id = $failed->json('request_id');
        $failed->assertHeader('X-Request-ID', $id);
        $this->assertSame(2, DocumentFetchAttempt::where('request_id', $id)->count());
        Log::shouldHaveReceived('warning')->with('All vehicle document sources failed.', \Mockery::on(fn (array $context): bool => $context['request_id'] === $id))->once();
    }

    public function test_internal_errors_return_a_correlated_safe_response(): void
    {
        Log::spy();
        $aggregator = \Mockery::mock(VehicleDocumentAggregator::class);
        $aggregator->shouldReceive('documentsForVin')->andThrow(new \RuntimeException('Sensitive database details'));
        $this->app->instance(VehicleDocumentAggregator::class, $aggregator);
        $response = $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')->assertStatus(500);
        $id = $response->json('request_id');
        $response->assertHeader('X-Request-ID', $id)->assertJsonPath('message', 'Unable to retrieve vehicle documents.');
        Log::shouldHaveReceived('error')->with('Vehicle document request failed.', \Mockery::on(fn (array $context): bool => $context['request_id'] === $id))->once();
    }
}
