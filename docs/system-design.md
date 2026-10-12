# Unified Document Viewer - System Design

## Scenario

Scenario D asks for a Unified Document Viewer that lets a user search by VIN and see documents from two mocked dealership systems in a single consolidated list. This implementation focuses on the backend service layer.

## Acceptance Criteria

- Provide a single VIN search endpoint.
- Call the mocked Sales System API and Service System API in parallel.
- Normalize both upstream responses into one document list.
- Clearly identify the source system for each document.
- Use persistent relational storage.
- Include automated tests, observability, and documentation.
- Keep the solution focused enough for a one-week assessment.

## Assumptions

- A valid VIN is 17 characters and excludes I, O, and Q.
- The backend fetches fresh upstream data for each search and upserts normalized documents.
- Partial upstream failure should return available documents with source status metadata.
- If both upstreams fail, the API returns `502 Bad Gateway`.
- Authentication and authorization are out of scope for this assessment.
- Mock APIs are Laravel routes in the same application for simplicity.
- Mock endpoints are restricted to local/testing environments. Production requires configured external upstream URLs.

## Architecture Diagram

```text
+-----------------------------+
| Client / cURL / Test        |
| Harness / Future UI         |
+--------------+--------------+
               |
               | GET /api/v1/vehicles/{vin}/documents
               v
+---------------------------------------------------------------+
| Laravel Backend                                               |
|                                                               |
|  +---------------------------+                                |
|  | VehicleDocumentController |                                |
|  +-------------+-------------+                                |
|                |                                              |
|                v                                              |
|  +---------------------------+                                |
|  | VehicleDocumentAggregator |                                |
|  +------+------+-------------+                                |
|         |      |                                              |
|         |      | parallel async HTTP                          |
|         |      |                                              |
|         v      v                                              |
|  +-------------+   +----------------+                         |
|  | Sales Client |   | Service Client |                         |
|  +------+------+   +-------+--------+                         |
|         |                  |                                  |
+---------|------------------|----------------------------------+
          |                  |
          v                  v
+-------------------+   +---------------------+
| Mock Sales API    |   | Mock Service API    |
| /api/mock/sales   |   | /api/mock/service   |
+---------+---------+   +----------+----------+
          |                        |
          +-----------+------------+
                      |
                      | source-specific payloads
                      v
+---------------------------------------------------------------+
| Laravel Backend                                               |
|                                                               |
|  +---------------------------+                                |
|  | VehicleDocumentAggregator |                                |
|  +-------------+-------------+                                |
|                |                                              |
|                v                                              |
|  +---------------------------+                                |
|  | DocumentNormalizer        |                                |
|  +-------------+-------------+                                |
|                |                                              |
|                v                                              |
|  +---------------------------+                                |
|  | Eloquent Models           |                                |
|  | VehicleDocument           |                                |
|  | DocumentFetchAttempt      |                                |
|  +-------------+-------------+                                |
+----------------|----------------------------------------------+
                 |
                 v
+--------------------------------+
| SQLite Relational Database     |
| vehicle_documents              |
| document_fetch_attempts        |
+--------------------------------+
```

## Data Flow

1. A client calls `GET /api/v1/vehicles/{vin}/documents`.
2. `VehicleDocumentController` validates the VIN and delegates to the aggregator.
3. `VehicleDocumentAggregator` starts Sales and Service upstream requests in parallel.
4. Each `ExternalDocumentClient` applies timeout handling and converts transport or HTTP failures into source-level statuses.
5. Successful upstream payloads are normalized into a common document shape by `DocumentNormalizer`.
6. Normalized documents are deduplicated and upserted into `vehicle_documents`.
7. Each upstream attempt is recorded in `document_fetch_attempts` with status, duration, document count, and error details.
8. The API returns one consolidated document list plus per-source status metadata and any warnings.

## Components

- `VehicleDocumentController`: validates VIN input and maps aggregation failures to HTTP responses.
- `VehicleDocumentAggregator`: coordinates parallel upstream calls, warning generation, persistence, and response shape.
- `ExternalDocumentClient`: makes async HTTP calls with timeouts and per-source failure mapping.
- `DocumentNormalizer`: translates Sales and Service payloads into one internal document structure.
- `vehicle_documents`: stores normalized documents by source and external id.
- `document_fetch_attempts`: stores source status, duration, document counts, and error details for observability.

## API Contract

```http
GET /api/v1/vehicles/{vin}/documents
Accept: application/json
```

Successful response:

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
    "sales": { "status": "ok", "duration_ms": 125 },
    "service": { "status": "ok", "duration_ms": 140 }
  },
  "warnings": []
}
```

Partial failure response still returns `200`:

```json
{
  "request_id": "4cc9dfe0-b69d-4d12-8c88-c6c9fd1f0a3d",
  "vin": "1HGCM82633A004352",
  "documents": [],
  "sources": {
    "sales": { "status": "timeout", "duration_ms": 2000 },
    "service": { "status": "ok", "duration_ms": 140 }
  },
  "warnings": [
    "Sales System API timed out; returned available documents only."
  ]
}
```

If both sources fail, the API returns `502`.

All document search responses carry a server-generated UUID in `request_id` and the `X-Request-ID` header, including validation, upstream, and handled internal errors. The machine-readable contract and examples are in [openapi.yaml](openapi.yaml).

## Failure Handling

- One source fails or times out: return documents from the other source and include a warning.
- Both sources fail: return `502` with source statuses and warnings.
- Invalid VIN: return `422` before calling upstream systems.
- Malformed JSON: mark that source as `invalid_response`.
- Duplicate document from a source: deduplicate by `source_system` and `external_id`.
- Database persistence failure: return a safe JSON `500` with the request ID and log the exception with the same ID.

## Observability

- Laravel logs one aggregation summary per request with VIN, request id, source statuses, and document count.
- `document_fetch_attempts` records source-level status, duration, HTTP status, document count, and error message.
- Source metadata is returned in the API response to make partial failures visible to clients.
- Response request IDs match stored attempt records and aggregation logs. Complete upstream failure emits a warning log; internal failure emits an error log. Invalid VINs do not call upstreams or create attempt records.

## Failure Demonstration and Verification

Each mock source accepts server-side `success`, `empty`, `error`, or `malformed` configuration and an independent delay of up to 10 seconds. Set a source delay above `DOCUMENT_API_TIMEOUT` to demonstrate a real timeout. Mock controls are unavailable through public query parameters, and mock endpoints return 404 outside local/testing environments. The README contains a repeatable demo and reset procedure.

Feature tests verify dispatch-before-wait, source failures, empty success, duplicate removal, idempotent repeated searches, request correlation, and safe internal errors. Real HTTP integration tests start two independent temporary local PHP servers, assert that upstream processing intervals overlap, and verify partial results after a real timeout. These tests use an in-memory database and clean up child processes.

## Technologies

- Laravel 12: fast REST API development, validation, routing, testing, and database support.
- SQLite: persistent relational storage with no extra infrastructure.
- Guzzle async promises: already included through Laravel and suitable for parallel upstream calls.
- PHPUnit: included with Laravel and sufficient for feature/unit coverage.

## Evaluation Framework Alignment

### Problem Solving & System Design

The design focuses on the selected backend service layer and documents the main assumptions behind ambiguous requirements: VIN validation, partial upstream failure behavior, timeout handling, deduplication, and mock API scope. The architecture separates HTTP entrypoints, upstream clients, aggregation logic, normalization, persistence, and observability records so each part has a clear responsibility.

### Technical Execution

The implementation exposes a REST API with an OpenAPI contract, calls Sales and Service systems in parallel using async HTTP promises, normalizes both payload shapes, persists documents in SQLite, and records each upstream fetch attempt. Tests cover input handling, normalization, persistence, duplicate removal, repeated searches, empty results, malformed JSON, partial and complete failures, and request correlation. Local HTTP integration tests verify overlapping upstream execution and real timeout behavior.

### AI Engineering & Verification

AI was used as a collaborative engineering tool, not as an unchecked code generator. The workflow was:

- Direct AI with explicit constraints: Laravel backend only, persistent relational storage, two mocked upstream APIs, parallel requests, normalized response shape, automated tests, observability, and minimal infrastructure.
- Ask AI to restate acceptance criteria and identify ambiguous requirements before implementation.
- Review the proposed architecture and narrow it to a small backend slice with clear service boundaries.
- Use AI to draft implementation scaffolding, edge cases, and test coverage.
- Debug and refine the output by running the test suite, inspecting failures, applying Laravel Pint formatting, running migrations, and manually checking the live API with cURL.
- Own the final solution by documenting assumptions, tradeoffs, failure behavior, and future improvements.

### Communication & Presentation

The README explains setup, running, testing, mock APIs, and the public API contract. This design document describes architecture, data flow, assumptions, failure handling, observability, technology choices, and AI collaboration.

## Future Improvements

- Add authentication and authorization for dealership users.
- Replace in-app mock routes with independently deployable mock services if integration testing needs to be closer to production.
- Export fetch-attempt metrics to a monitoring backend such as Prometheus, Datadog, or CloudWatch.
- Add a lightweight frontend search page if the selected implementation scope expands beyond the backend layer.

## GenAI Collaboration

GenAI was used to clarify acceptance criteria, identify ambiguity, propose assumptions, design the API contract, and implement a minimal service architecture. The output was verified through focused automated tests covering validation, normalization, persistence, partial failure, and complete upstream failure.
