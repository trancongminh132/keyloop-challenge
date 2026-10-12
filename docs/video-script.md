# Video Submission Script

Target length: 8-10 minutes, including short pauses for demonstrations. The assessment requires 5-10 minutes, with 1-2 minutes on AI collaboration.

Read the blockquotes as narration. Everything outside them is a recording cue. Timings are approximate; rehearse once and keep the final recording below 10 minutes.

## Before Recording

1. Replace `[your name]` and `[your current role or background]`. Use only personal experience and review work that you can explain accurately.
2. Rehearse the code walkthrough and demos. The AI section acknowledges substantial agent-generated implementation; complete your own review before describing decisions as yours.
3. Open the README, system design, aggregator, upstream client, normalizer, HTTP integration tests, and OpenAPI contract in the editor.
4. Run migrations and tests beforehand. Use the test count shown by your current run; the last verified result was 21 tests and 101 assertions.
5. Keep `.env` secrets out of the recording. Show `.env.example` when discussing configuration.
6. Use two terminal panes: one for the local server and one for commands. Use port 8000 only if it is free, or adjust both upstream URLs and demo URLs to another port.

From the project directory, prepare the database and clear cached configuration:

```bash
php artisan migrate
php artisan config:clear
```

Start the successful demo in the server pane:

```bash
MOCK_SALES_SCENARIO=success MOCK_SALES_DELAY_MS=0 MOCK_SERVICE_SCENARIO=success MOCK_SERVICE_DELAY_MS=0 PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

The demo assumes the upstream URLs in `.env.example`, a two-second `DOCUMENT_API_TIMEOUT`, and `APP_ENV=local`. Shell environment settings override the mock scenario values for this server process. Do not use a cached configuration during the demo.

If using the database commands below, check that the `sqlite3` CLI is installed and your local database is `database/database.sqlite`. Otherwise use your IDE's database viewer with the same SQL.

## 0:00-0:35 - Introduction

On screen: README title and project directory.

> Hello, my name is Minh. I'm a software engineer with more than 13 years of experience, mainly in backend engineering, distributed systems, and cloud platforms. Most recently, I worked on fraud detection and case management systems, where I was responsible for both technical leadership and team leadership. I previously managed a team of eight engineers and was involved in hiring, coaching, delivery planning, and stakeholder communication. My technical background includes PHP/Laravel, MongoDB, Redis, Kafka, AWS, GCP, Kubernetes, and event-driven architectures. I've also led several large initiatives, including cloud migration projects and the design of scalable APIs handling high transaction volumes. At this stage of my career, I'm looking for an opportunity where I can continue contributing technically while also helping teams and organizations grow.
>
> For this assessment, I selected Scenario D, the Unified Document Viewer. The problem is to search for a vehicle by VIN and bring together documents from Sales and Service systems, while clearly identifying each document's source.
>
> I chose the backend implementation option. The client layer is represented by cURL examples, automated tests, and an OpenAPI contract. I'll explain the design, demonstrate the API, and show how I directed and verified the AI-assisted implementation.

## 0:35-1:20 - Requirements and Decisions

On screen: acceptance criteria and assumptions in `docs/system-design.md`.

> I started by asking the AI to restate the acceptance criteria and identify ambiguous requirements before writing code.
>
> The main requirements were parallel calls to two mocked systems, a normalized document list with source identifiers, and persistent relational storage.
>
> I documented several decisions. A VIN is validated as 17 characters, excluding I, O, and Q. If one source fails, the API returns the other source's documents with an explicit warning. If both fail, it returns a bad gateway response. A successful empty list is treated as a valid result.
>
> I chose Laravel for its validation, database, logging, and testing support. SQLite keeps relational persistence easy to run locally. Guzzle provides asynchronous HTTP calls without adding another dependency. These choices keep the one-week assessment focused and reproducible.

## 1:20-2:20 - Architecture and Implementation

On screen: architecture diagram, then `VehicleDocumentAggregator.php`, `ExternalDocumentClient.php`, and `DocumentNormalizer.php`.

> The controller validates the VIN and creates a request ID. It then delegates to the aggregator, which starts both upstream requests before waiting for their results.
>
> The external client applies connection and response timeouts, then converts HTTP errors, transport failures, and invalid JSON into source-level statuses. That allows an upstream failure to become an explicit result the aggregator can handle.
>
> The normalizer maps the different Sales and Service payloads into the same document structure: ID, source, type, title, issue date, and URL.
>
> The aggregator removes duplicates using the source and external ID, sorts the documents newest first, and upserts them into the database. It also records each upstream attempt separately for debugging.
>
> The mock routes run in the same Laravel application to simplify local setup. This requires multiple server workers because the aggregator makes HTTP calls back into the application. The mock endpoints are restricted to local and testing environments.

Recording cue: the diagram shows the Laravel backend twice to illustrate outbound and return flow. Describe them as parts of the same application. The two source clients are instances of the same `ExternalDocumentClient` class.

## 2:20-3:10 - Successful Search

On screen: command terminal. Run:

```bash
curl -sS -i -H 'Accept: application/json' http://127.0.0.1:8000/api/v1/vehicles/1HGCM82633A004352/documents
```

Point to the HTTP status, document sources, source statuses, and request ID. The default mocks return four documents: two Sales and two Service.

> Here is a search using a valid VIN. The API returns HTTP 200 and one consolidated list containing documents from both systems.
>
> Each document retains its source, so the client can distinguish a sales contract from a service invoice without understanding the upstream payload formats.
>
> Both source statuses are successful, and there are no warnings. The request ID in the JSON matches the response header. That ID also connects this request to its operational records.
>
> This API returns freshly fetched documents. Persistence supports updates and inspection, but stored documents are not currently used as a fallback during an outage.

## 3:10-4:25 - Timeout and Failure Behavior

On screen: stop the demo server with Ctrl+C in its pane. Restart with Sales delayed for three seconds and Service unchanged:

```bash
MOCK_SALES_SCENARIO=success MOCK_SALES_DELAY_MS=3000 MOCK_SERVICE_SCENARIO=success MOCK_SERVICE_DELAY_MS=0 PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

Repeat the search command after the server reports it is ready. Point to `sources.sales.status`, Service documents, and the warning.

> Now I have configured Sales to respond after three seconds, while the upstream timeout is two seconds.
>
> Sales times out, but Service succeeds. The response remains HTTP 200 and contains the available Service documents. It also marks Sales as timed out and includes a warning, so the client can show that the result is incomplete.
>
> I chose this behavior to preserve useful results without hiding the missing source.

Stop the server and start both mocks in the error scenario:

```bash
MOCK_SALES_SCENARIO=error MOCK_SALES_DELAY_MS=0 MOCK_SERVICE_SCENARIO=error MOCK_SERVICE_DELAY_MS=0 PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

Repeat the search. Point to `502`, both source statuses, and warnings.

> When both systems fail, the API returns HTTP 502 with the failure details. It does not represent an outage as a successful search with no documents.

Run the invalid VIN example:

```bash
curl -sS -i -H 'Accept: application/json' http://127.0.0.1:8000/api/v1/vehicles/INVALID/documents
```

> Invalid input returns HTTP 422 before either upstream is called.

Recording cue: server restarts can be edited out. Keep the configuration change and resulting responses visible. Reset the server to the successful configuration after this segment.

## 4:25-5:05 - Persistence and Observability

On screen: database viewer or these read-only queries:

```bash
sqlite3 -header -column database/database.sqlite "SELECT source_system, external_id, title FROM vehicle_documents WHERE vin = '1HGCM82633A004352';"
```

```bash
sqlite3 -header -column database/database.sqlite "SELECT request_id, source_system, status, http_status, duration_ms, document_count FROM document_fetch_attempts WHERE request_id = (SELECT request_id FROM document_fetch_attempts ORDER BY id DESC LIMIT 1);"
```

Then show the corresponding summary in `storage/logs/laravel.log`, using the actual request ID from the valid search. The latest valid search above is the both-source failure; the invalid VIN does not create fetch attempts.

> The database stores normalized documents separately from fetch attempts. Repeated searches update existing documents instead of creating another row for the same source and external ID.
>
> Fetch attempts record the source, status, duration, HTTP status, and document count under a shared request ID. The logs use that same ID, so I can trace an API response to both upstream attempts.
>
> These records describe application behavior; they are not a distributed tracing system. For this scope, structured logs and persistent attempt records provide a practical debugging path.

## 5:05-6:00 - Verification

On screen: run the suite, then open the dispatch-before-wait test and `ExternalDocumentHttpTest.php`.

```bash
php artisan test
```

> The automated suite covers normalization, VIN validation, persistence, duplicate removal, repeated searches, empty results, malformed JSON, and upstream failures. It also checks request correlation and safe internal error responses.
>
> Parallel requests needed stronger evidence than a successful response. One test checks that the aggregator dispatches both requests before waiting on either result.
>
> A separate integration test starts two independent local HTTP servers and checks that their processing intervals overlap. Another delays Sales beyond a real one-second client timeout and verifies that Service documents still return through the API.
>
> These tests use an in-memory database and stop their temporary servers afterward. Together, they check both the coordination logic and actual HTTP behavior.

Recording cue: point to the result actually shown. Do not say the mocked feature tests alone prove real concurrency. The overlap assertion tests the real client calls; the dispatch test checks aggregator coordination.

## 6:00-7:35 - AI Collaboration

On screen: AI Collaboration Narrative in the README, then selected tests or relevant conversation excerpts if available.

> AI was an essential collaborator in this assessment and generated a substantial part of the implementation, including code, tests, and documentation.
>
> My initial direction was to clarify the requirements, document assumptions, and propose an architecture and API contract before implementing. I specified Laravel, two mocked upstream systems, parallel requests, relational persistence, tests, observability, and minimal infrastructure.
>
> The review questions focused on observable behavior: what happens when one source times out, how duplicates are identified, how malformed JSON is handled, and what evidence shows that the requests run concurrently.
>
> I also asked the AI to strengthen the submission with configurable failures, real HTTP verification, request correlation, and an OpenAPI contract. The agent helped generate those changes and execute the checks. The verification evidence includes test results, formatting checks, API responses, and database records.
>
> One challenge during verification was that the execution sandbox blocked local socket binding. That prevented the real HTTP tests from starting their temporary servers. The tests were rerun with local socket permission and passed; they were not weakened to avoid exercising HTTP behavior.
>
> A passing suite is evidence, but it does not cover every production concern. I need to understand the implementation, explain its assumptions, and make changes when the behavior is wrong. That is how I approach ownership of an AI-assisted solution: clear direction, reviewable changes, and verification tied to the requirements.

Personalization cue: replace the sandbox example with another real challenge if you can explain it more confidently. Do not claim that you personally wrote or manually debugged code that the agent produced. Before recording, be able to explain each test and the decisions described here in your own words.

## 7:35-8:30 - Lessons, Limits, and Closing

On screen: future improvements in the design document, then `docs/openapi.yaml` and README.

> The main lesson is that aggregation requires a clear definition of completeness. An empty successful response, a partial result, and a complete upstream outage should have different meanings to the client.
>
> I also learned that concurrency should be verified directly. Starting asynchronous calls is the implementation approach; overlapping HTTP execution is the evidence that the approach works in the tested environment.
>
> This is a focused assessment implementation. Authentication and dealership-level authorization are future work. For production, I would also review document identity across dealerships, database write consistency, stronger upstream payload validation, and retention of fetch-attempt records.
>
> Cached fallback is another possible improvement, but it would need explicit freshness information so users can distinguish stored documents from current upstream results.
>
> The submission includes working backend code, automated tests, a system design document, setup instructions, and an OpenAPI contract. Thank you for reviewing it.

## After Recording

1. Confirm the video is between 5 and 10 minutes and the AI section is between 1 and 2 minutes.
2. Check that the terminal output is readable and the recording contains no secrets.
3. Reset both mock scenarios to `success` and both delays to `0`; stop the demo server when finished.
4. Ensure the submitted repository contains the working code and documents referenced in the video.
