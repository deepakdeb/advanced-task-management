# Advanced Task Management System

Laravel 12 task management API with Sanctum authentication, Redis queues, priority workers, retry handling, cancellation, idempotency protection, audit logs, and stale-task recovery.

## Requirements

- PHP 8.2+
- Composer
- SQLite, MySQL, or PostgreSQL
- Redis
- Node.js and npm for the Laravel asset build

Predis is installed and selected through `REDIS_CLIENT=predis`.

## Installation

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Configure the database and Redis values in `.env`. The default local setup uses SQLite and Redis on `127.0.0.1:6379`.

## Run The Application

Start the HTTP server:

```bash
php artisan serve
```

Start one dedicated worker per priority queue:

```bash
php artisan queue:work redis --queue=tasks-critical --tries=4 --timeout=120
php artisan queue:work redis --queue=tasks-high --tries=4 --timeout=120
php artisan queue:work redis --queue=tasks-normal --tries=4 --timeout=120
php artisan queue:work redis --queue=tasks-low --tries=4 --timeout=120
```

Run stale-task cleanup manually with `php artisan tasks:cleanup-stale`. Laravel schedules it every five minutes; production should run `php artisan schedule:work` or invoke the scheduler from cron.

## API

Authentication endpoints:

```text
POST /api/register
POST /api/login
POST /api/logout       authenticated
GET  /api/user         authenticated
```

Task endpoints, all authenticated with `auth:sanctum`:

```text
POST /api/tasks
GET  /api/tasks
GET  /api/tasks/{task}
POST /api/tasks/{task}/cancel
POST /api/tasks/{task}/retry
```

Create a report task:

```json
{
	"type": "report",
	"title": "Monthly Sales Report",
	"priority": "high",
	"payload": {
		"report_id": "monthly-sales",
		"format": "csv",
		"sections": 12
	}
}
```

Supported payloads are intentionally type-specific:

- `report`: `report_id`, `format`, optional `sections`
- `bulk_notification`: `notification_template`, `recipient_ids`
- `data_processing`: `source`, `operation`, optional `records`

Task listing supports `status`, `type`, `priority`, `search`, `from`, `to`, `per_page`, `sort`, and `direction`. Sorting is limited to `created_at`, `updated_at`, `priority`, and `status`; pagination is capped at 100 records.

## Architecture

The API creates a pending task and dispatches `ProcessTaskJob` after the database transaction commits. The job delegates processing through `TaskProcessorRegistry` and `TaskProcessorInterface`, so adding a processor does not require a processor conditional in the job.

Processors work in bounded batches and check cancellation before and between batches. Set `TASK_PROCESSING_DELAY_MS` to a positive value when demonstrating measurable worker-only processing; the default is zero for fast tests and local development.

Task state transitions use short transactions with `lockForUpdate()`. Redis atomic locks prevent duplicate execution of the same deterministic task execution key. Completion performs a final cancellation/state check, so a task cancelled during processing cannot later become completed.

## Reliability

- Maximum attempts and backoff are configured in `config/tasks.php`.
- Retry backoff defaults to 10, 30, 120, and 300 seconds.
- Permanent processor validation failures are stored as failed and do not retry indefinitely.
- Retry and cancellation transitions are authorized and concurrency-safe.
- `tasks:cleanup-stale` marks processing tasks older than the configured stale timeout as failed.
- Task logs record creation, queueing, processing, failures, cancellation, retry, and stale recovery events.
- Completion and failure logs include execution duration and retry metadata.

Relevant environment settings include `TASK_MAX_ATTEMPTS`, `TASK_STALE_TIMEOUT`, `TASK_LOCK_TIMEOUT`, `TASK_TIMEOUT`, `TASK_PROCESSING_DELAY_MS`, queue names, and processor batch sizes.

## Database

Tasks use ULIDs and enum casts for type, status, and priority. Task logs belong to tasks and are deleted with their parent task. Indexes cover user/status filtering, user/creation ordering, status/priority queue selection, and task-log lookup by task and creation time.

## Testing

Run the complete suite:

```bash
php artisan test
```

Run formatting checks:

```bash
./vendor/bin/pint --test
```

For an isolated database verification:

```bash
php artisan migrate:fresh --env=testing
php artisan test
```

The tests cover authentication, user isolation, payload validation, search and pagination filters, queue dispatch, priority routing, processor resolution, retries, cancellation races, stale cleanup, idempotent duplicate jobs, and task state transitions.

## Known Limitations

- Processors simulate work and do not integrate with external reporting, notification, or data systems.
- Redis must be available for production queue workers and distributed locks.
- PHPStan is not included; Pint and PHPUnit are the configured quality gates.