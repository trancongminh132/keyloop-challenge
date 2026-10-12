# Keyloop Unified Document Viewer

Laravel backend implementation for Keyloop Technical Assessment Scenario D.

## What It Does

The API accepts a VIN, calls mocked Sales and Service document systems in parallel, normalizes their different payloads, persists the normalized documents, and returns one consolidated list with source identifiers.

## Requirements

- PHP 8.2+ with cURL and PDO SQLite extensions
- Composer
- SQLite

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

The default mocked upstream URLs are:

```env
SALES_SYSTEM_URL=http://127.0.0.1:8000/api/mock/sales
SERVICE_SYSTEM_URL=http://127.0.0.1:8000/api/mock/service
DOCUMENT_API_TIMEOUT=2
```

When using the same Laravel app as both API and mock upstream, run the local server with multiple workers so self-calls can complete:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve
```

## API

```http
GET /api/v1/vehicles/{vin}/documents
```

Example:

```bash
curl -i -H 'Accept: application/json' http://127.0.0.1:8000/api/v1/vehicles/1HGCM82633A004352/documents
```

Response shape:

```json
{
  "request_id": "4cc9dfe0-b69d-4d12-8c88-c6c9fd1f0a3d",
  "vin": "1HGCM82633A004352",
  "documents": [
    {
      "id": "SVC-1",
      "source": "service",
      "type": "service_invoice",
      "title": "Service Invoice",
      "issued_at": "2026-10-01T08:30:00.000000Z",
      "document_url": "https://example.test/service.pdf"
    }
  ],
  "sources": {
    "sales": { "status": "ok", "duration_ms": 120 },
    "service": { "status": "ok", "duration_ms": 140 }
  },
  "warnings": []
}
```

## Mock APIs

```http
GET /api/mock/sales/vehicles/{vin}/documents
GET /api/mock/service/vehicles/{vin}/documents
```

Mock endpoints are available only in `local` and `testing` environments. Each source supports `success` (default), `empty`, `error` (HTTP 503), and `malformed` (invalid JSON). An independent delay setting applies before the response, capped at 10 seconds. Configuration is server-side; public request parameters cannot change scenarios.

For a timeout demo, set these values in `.env`:

```env
MOCK_SALES_SCENARIO=success
MOCK_SALES_DELAY_MS=3000
MOCK_SERVICE_SCENARIO=success
MOCK_SERVICE_DELAY_MS=0
DOCUMENT_API_TIMEOUT=2
```

Run `php artisan config:clear`, restart the multi-worker server, and repeat the API request. Expect `200`, Service documents, `sources.sales.status=timeout`, and a warning. Set Sales to `error` or `malformed` to demonstrate those cases; set both sources to `error` for `502`. Reset both scenarios to `success` and both delays to `0` afterward. Keep enough server workers available for the aggregator and both self-calls; delayed mock requests occupy workers even after the client times out.

Every document search response includes a server-generated `request_id` and matching `X-Request-ID` header, including `422`, `502`, and handled `500` responses. For valid searches, use this ID to correlate `storage/logs/laravel.log` with `document_fetch_attempts.request_id`. Validation failures do not create upstream attempts. Internal failures may leave only the log record if database writes failed.

The public contract, response schemas, and examples are in [docs/openapi.yaml](docs/openapi.yaml). It can be opened in any OpenAPI 3.0-compatible viewer; no additional runtime dependency is required.

## Tests

```bash
php artisan test
```

The test suite covers VIN validation, normalization, persistence, duplicate removal, repeated-search updates, empty results, HTTP failures, malformed JSON, timeouts, mock scenarios, and request correlation. It also checks that the aggregator dispatches both requests before waiting.

`ExternalDocumentHttpTest` starts two temporary independent PHP servers on ephemeral loopback ports, verifies overlapping upstream processing intervals using real Guzzle HTTP requests, and exercises a real one-second timeout through the aggregation endpoint. These tests require local socket/process permissions and PHP's cURL extension; servers are stopped in test cleanup. They do not contact external services or use your local database.

```bash
php artisan test --filter=ExternalDocumentHttpTest
```

## Design

See `docs/system-design.md` for architecture, assumptions, failure handling, observability, and the API contract.

The design document also includes a short mapping to the assessment evaluation framework: problem solving and system design, technical execution, AI engineering and verification, and communication.

## AI Collaboration Narrative

I used GenAI as a design and implementation partner. First, I asked it to restate the assessment requirements, separate ambiguity from acceptance criteria, and propose explicit assumptions. I then reviewed the proposed architecture and chose a minimal Laravel backend: one REST endpoint, mocked source endpoints, async upstream calls, normalization, relational persistence, and focused automated tests.

During implementation, I used AI to draft service boundaries, edge cases, and test coverage. I then validated the output by reading the generated code, checking that no unnecessary infrastructure or dependencies were introduced, running Pint, running the PHPUnit suite, applying database migrations, and testing the live endpoint with cURL. The final design keeps ownership with the developer: the AI accelerated boilerplate and edge-case enumeration, while the implementation is verified by tests, documented assumptions, and manual API checks.
