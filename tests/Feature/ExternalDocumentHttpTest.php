<?php

namespace Tests\Feature;

use App\Services\Documents\ExternalDocumentClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Promise\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExternalDocumentHttpTest extends TestCase
{
    use RefreshDatabase;

    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        parent::tearDown();
    }

    public function test_real_http_requests_overlap(): void
    {
        $sales = $this->startServer(500);
        $service = $this->startServer(500);
        $http = new Client;
        $responses = Utils::all([
            (new ExternalDocumentClient($http, 'sales', $sales, 3))->fetchAsync('1HGCM82633A004352'),
            (new ExternalDocumentClient($http, 'service', $service, 3))->fetchAsync('1HGCM82633A004352'),
        ])->wait();
        foreach ($responses as $response) {
            $this->assertSame('ok', $response['status']);
        }
        $a = $responses[0]['payload'];
        $b = $responses[1]['payload'];
        $this->assertLessThan(min($a['completed_at'], $b['completed_at']), max($a['started_at'], $b['started_at']), 'The upstream processing intervals must overlap.');

        config(['services.dealership_documents.sales_url' => $sales, 'services.dealership_documents.service_url' => $service]);
        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()->assertJsonCount(2, 'documents');
    }

    public function test_real_timeout_returns_the_other_sources_documents(): void
    {
        config([
            'services.dealership_documents.sales_url' => $this->startServer(1500),
            'services.dealership_documents.service_url' => $this->startServer(0),
            'services.dealership_documents.timeout' => 1,
        ]);
        $this->getJson('/api/v1/vehicles/1HGCM82633A004352/documents')
            ->assertOk()->assertJsonPath('sources.sales.status', 'timeout')
            ->assertJsonPath('sources.service.status', 'ok')->assertJsonCount(1, 'documents');
    }

    private function startServer(int $delayMs): string
    {
        // Allocate an ephemeral port; separate single-worker processes model independent upstreams.
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            $this->fail('Cannot bind local integration server: '.$error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, base_path('tests/Fixtures/upstream-router.php')], base_path(), [
            'TEST_UPSTREAM_DELAY_MS' => (string) $delayMs,
            'PHP_CLI_SERVER_WORKERS' => false,
        ]);
        $this->servers[] = $server;
        $server->start();
        $http = new Client(['timeout' => 0.2]);
        $deadline = microtime(true) + 5;
        do {
            try {
                if ((string) $http->get('http://'.$address.'/health')->getBody() === 'ready') {
                    return 'http://'.$address;
                }
            } catch (TransferException) {
                usleep(20000);
            }
        } while ($server->isRunning() && microtime(true) < $deadline);

        $this->fail('Integration server did not start: '.$server->getErrorOutput());
    }
}
