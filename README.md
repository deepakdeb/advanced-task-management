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

## Running Migrations

```bash
php artisan migrate
```

The database schema includes:

- `tasks` table with ULID primary key, JSON payload, and enum-cast status/priority fields
- `task_logs` table for audit trail of all task lifecycle events
- Standard Laravel `users` and `password_reset_tokens` tables

Run migrations in a fresh database:

```bash
php artisan migrate:fresh --seed
```

## Starting Workers

Start one dedicated worker per priority queue. Each worker processes only its assigned queue:

```bash
php artisan queue:work redis --queue=tasks-critical --tries=4 --timeout=120
php artisan queue:work redis --queue=tasks-high --tries=4 --timeout=120
php artisan queue:work redis --queue=tasks-normal --tries=4 --timeout=120
php artisan queue:work redis --queue=tasks-low --tries=4 --timeout=120
```

Worker configuration options:

- `--tries=4`: Maximum retry attempts before marking task as failed
- `--timeout=120`: Job timeout in seconds (matches `TASK_TIMEOUT` config)
- `--queue`: Processes tasks from the specified queue only

For production, consider using supervisord or systemd to manage worker processes and auto-restart on failure.

## Running Tests

```bash
php artisan test
```

Run formatting checks:

```bash
./vendor/bin/pint --test
```

For isolated database verification:

```bash
php artisan migrate:fresh --env=testing
php artisan test
```

The tests cover authentication, user isolation, payload validation, search and pagination filters, queue dispatch, priority routing, processor resolution, retries, cancellation races, stale cleanup, idempotent duplicate jobs, and task state transitions.

## Architecture

### Overview

The system follows a CQRS-inspired architecture:

1. API controllers handle HTTP requests and validate input
2. Tasks are created in pending state with ULID identifiers
3. After database transaction commits, `ProcessTaskJob` is dispatched to Redis
4. The job delegates to `TaskProcessorRegistry` which resolves the appropriate `TaskProcessorInterface`
5. Task state transitions use short transactions with `lockForUpdate()` for concurrency control

### Components

- **Task Model**: Represents a task with type, title, payload, status, priority, and attempts count
- **TaskProcessorRegistry**: Resolves task type to the appropriate processor implementation
- **TaskLockService**: Provides Redis-based atomic locks for idempotency
- **TaskExecutionService**: Orchestrates the actual task processing with state management
- **TaskLogService**: Records lifecycle events for audit trail

## Queue Strategy

Tasks are distributed across four priority queues:

| Priority  | Queue Name     | Environment Variable          | Use Case                          |
|-----------|----------------|---------------------------------|-----------------------------------|
| Critical  | tasks-critical | `TASK_QUEUE_CRITICAL`          | System-critical operations        |
| High      | tasks-high     | `TASK_QUEUE_HIGH`              | Important user-facing operations  |
| Normal    | tasks-normal   | `TASK_QUEUE_NORMAL`            | Standard task processing          |
| Low       | tasks-low      | `TASK_QUEUE_LOW`               | Background/batch operations     |

Workers are configured with dedicated queues to ensure high-priority tasks are processed before lower-priority ones. Redis priorities ensure fair scheduling within each queue.

## Retry Strategy

### Configuration

Retries are configured in `config/tasks.php`:

```php
'retry_backoff' => [10, 30, 120, 300],  // seconds between retries
'max_attempts' => 4,  // total attempts (initial + 3 retries)
'retryable_exceptions' => [RuntimeException::class],
```

### Behavior

1. On failure, the system checks if the exception is in `retryable_exceptions`
2. If retryable and attempts < max_attempts, task is requeued with delay based on backoff schedule
3. Non-retryable failures result in permanent task failure
4. Retry is delayed, not immediate (queued at specific future time)

### Backoff Schedule

| Attempt | Delay    |
|---------|----------|
| 2       | 10s      |
| 3       | 30s      |
| 4       | 120s     |
| 5       | 300s     |

## Idempotency Strategy

Idempotency is enforced through **execution key locking**:

```php
public function executionKey(): string
{
    return hash('sha256', json_encode([
        'user_id' => $this->user_id,
        'type' => $this->type->value,
        'payload' => $this->payload,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}
```

### How It Works

1. When a task is dispatched, `executed_attempt_key` is stored as a hash in Redis
2. `TaskLockService::run()` acquires an atomic lock using `task-execution:{key}`
3. If another worker holds the lock, the job returns null (duplicate detected)
4. Lock timeout defaults to 1 hour (`TASK_LOCK_TIMEOUT`)

This ensures the same deterministic task (same user, type, payload) cannot be processed concurrently, even across multiple workers or job retries.

## Concurrency Considerations

### Database-Level

- Task state transitions use `lockForUpdate()` in short transactions
- Unique ULIDs prevent primary key collisions
- Indexes on `(status, priority)` support efficient queue selection

### Redis-Level

- Atomic locks prevent duplicate execution via `TaskLockService`
- Lock timeout prevents deadlocks (default 1 hour)

### Race Conditions Handled

1. **Cancellation during processing**: Final check after processor completes
2. **Concurrent retry requests**: Only one retry succeeds
3. **Multiple stale cleanup runs**: Idempotent failure marking
4. **Multiple cancel requests**: Only first cancellation succeeds

### Queue Fairness

Workers process from highest-priority queue to lowest. Tasks within a queue are processed FIFO. Multiple workers on the same queue provide parallelism for that priority level.

## Known Limitations

1. **No external system integration**: Processors simulate work; they do not integrate with external reporting systems, notification services, or data pipelines.

2. **Redis dependency**: Production queue workers and distributed locks require Redis availability.

3. **Failure visibility**: Failed tasks require manual inspection; no alerting mechanism is built in.

4. **Dead letter queue**: Failed tasks are logged but not routed to a dead letter queue for later inspection.

## API Endpoints

### Authentication

| Method | Endpoint   | Description                    |
|--------|------------|--------------------------------|
| POST   | /api/register | Register new user           |
| POST   | /api/login    | Authenticate, returns token  |
| POST   | /api/logout   | Revoke current token         |
| GET    | /api/user     | Get authenticated user info  |

### Tasks

All task endpoints require `auth:sanctum` middleware.

| Method | Endpoint              | Description                    |
|--------|-----------------------|--------------------------------|
| POST   | /api/tasks            | Create new task                |
| GET    | /api/tasks            | List tasks (filtered)          |
| GET    | /api/tasks/{task}     | Get specific task              |
| POST   | /api/tasks/{task}/cancel | Cancel running/pending task |
| POST   | /api/tasks/{task}/retry  | Retry failed task           |

### Supported Task Types

- `report`: Requires `report_id`, `format`, optional `sections`
- `bulk_notification`: Requires `notification_template`, `recipient_ids`
- `data_processing`: Requires `source`, `operation`, optional `records`

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

### Query Parameters

Task listing supports filtering and pagination:

- `status`: pending, processing, completed, failed, cancelled
- `type`: report, bulk_notification, data_processing
- `priority`: critical, high, normal, low
- `search`: search in title
- `from`, `to`: date range filter
- `per_page`: max 100 records
- `sort`: created_at, updated_at, priority, status
- `direction`: asc, desc

## Environment Variables

| Variable                    | Default     | Description                           |
|-----------------------------|-------------|---------------------------------------|
| `TASK_MAX_ATTEMPTS`         | 4           | Maximum retry attempts                |
| `TASK_STALE_TIMEOUT`        | 1800        | Seconds before stale task recovery    |
| `TASK_LOCK_TIMEOUT`         | 3600        | Redis lock timeout in seconds         |
| `TASK_TIMEOUT`              | 120         | Job processing timeout in seconds   |
| `TASK_PROCESSING_DELAY_MS`  | 0           | Simulated processing delay in ms     |
| `TASK_LOCK_STORE`           | redis       | Cache store for distributed locks     |
| `TASK_QUEUE_CRITICAL`       | tasks-critical | Critical priority queue name    |
| `TASK_QUEUE_HIGH`           | tasks-high     | High priority queue name        |
| `TASK_QUEUE_NORMAL`         | tasks-normal   | Normal priority queue name      |
| `TASK_QUEUE_LOW`            | tasks-low      | Low priority queue name         |
| `TASK_REPORT_BATCH_SIZE`    | 100         | Batch size for report processor       |
| `TASK_NOTIFICATION_BATCH_SIZE` | 100      | Batch size for notification processor |
| `TASK_DATA_BATCH_SIZE`      | 500         | Batch size for data processor         |

## CLI Commands

```bash
php artisan tasks:cleanup-stale  # Mark stale tasks as failed
php artisan schedule:work       # Run scheduled tasks (including cleanup)
php artisan queue:restart       # Restart all workers (for config changes)
```