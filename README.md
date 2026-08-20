# Advanced Task Management System

Laravel 12 task management API with Sanctum authentication, Redis queues, priority workers, retry handling, cancellation, idempotency protection, audit logs, and stale-task recovery.

## Requirements

- PHP 8.2+
- Composer
- SQLite, MySQL, or PostgreSQL
- Redis
- Node.js and npm for the Laravel asset build
- Docker and Docker Compose

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

## Docker Setup

The project includes a Docker Compose configuration with PHP 8.2-FPM, Nginx, MySQL 8.0, Redis, and Laravel Horizon.

### Starting Services

```bash
docker compose up -d --build
```

This starts:
- **Nginx** on `http://localhost:8000`
- **MySQL** on `localhost:3306`
- **Redis** on `localhost:6379`
- **Laravel Horizon** for queue management

### Running Migrations

```bash
docker compose exec app php artisan migrate
```

### Running Workers

The project includes Horizon for queue management. Start Horizon workers:

```bash
docker compose exec horizon php artisan horizon
```

Or start individual priority workers manually:

```bash
docker compose exec app php artisan queue:work redis --queue=tasks-critical --tries=4 --timeout=120
docker compose exec app php artisan queue:work redis --queue=tasks-high --tries=4 --timeout=120
docker compose exec app php artisan queue:work redis --queue=tasks-normal --tries=4 --timeout=120
docker compose exec app php artisan queue:work redis --queue=tasks-low --tries=4 --timeout=120
```

### Running Tests

```bash
docker compose exec app php artisan test
```

### Stopping Services

```bash
docker compose down
```

To stop and remove volumes:

```bash
docker compose down -v
```

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

Base URL: `/api`

All endpoints are prefixed with `/api` and return JSON responses.

---

### Authentication

All task endpoints require a Bearer token obtained via the login endpoint.

#### Register

```http
POST /api/register
```

**Request Body:**

```json
{
	"name": "John Doe",
	"email": "john@example.com",
	"password": "password123"
}
```

**Response (201 Created):**

```json
{
	"message": "User registered successfully"
}
```

#### Login

```http
POST /api/login
```

**Request Body:**

```json
{
	"email": "john@example.com",
	"password": "password123"
}
```

**Response (200 OK):**

```json
{
	"token": "1|a1b2c3d4e5f6g7h8i9j0..."
}
```

#### Get Authenticated User

```http
GET /api/user
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
	"id": 1,
	"name": "John Doe",
	"email": "john@example.com",
	"created_at": "2025-01-01T00:00:00.000000Z",
	"updated_at": "2025-01-01T00:00:00.000000Z"
}
```

#### Logout

```http
POST /api/logout
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
	"message": "Logged out successfully"
}
```

---

### Tasks

All task endpoints require `Authorization: Bearer {token}` header.

#### Create Task

```http
POST /api/tasks
Authorization: Bearer {token}
```

Rate limited to 5 requests per minute.

**Request Body:**

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

**Response (201 Created):**

```json
{
	"id": "01HXYZ1234567890ABCDEF",
	"type": "report",
	"title": "Monthly Sales Report",
	"payload": {
		"report_id": "monthly-sales",
		"format": "csv",
		"sections": 12
	},
	"status": "pending",
	"priority": "high",
	"attempts": 0,
	"started_at": null,
	"completed_at": null,
	"failed_at": null,
	"created_at": "2025-01-01T00:00:00.000000Z",
	"updated_at": "2025-01-01T00:00:00.000000Z"
}
```

**Validation Errors (422):**

```json
{
	"message": "The payload.report_id field is required for this task type.",
	"errors": {
		"payload.report_id": ["The payload.report_id field is required for this task type."]
	}
}
```

#### List Tasks

```http
GET /api/tasks?status=pending&priority=high&search=sales&from=2025-01-01&to=2025-01-31&per_page=15&sort=created_at&direction=desc
Authorization: Bearer {token}
```

**Query Parameters:**

| Parameter | Type    | Description                                      |
|-----------|---------|--------------------------------------------------|
| status    | string  | Filter by status: pending, processing, completed, failed, cancelled |
| type      | string  | Filter by type: report, bulk_notification, data_processing |
| priority  | string  | Filter by priority: critical, high, normal, low  |
| search    | string  | Search in title or task ID                       |
| from      | date    | Filter tasks created after this date             |
| to        | date    | Filter tasks created before this date            |
| per_page  | integer | Records per page (max 100)                       |
| sort      | string  | Sort field: created_at, updated_at, priority, status |
| direction | string  | Sort direction: asc, desc                        |

**Response (200 OK):**

```json
{
	"data": [
		{
			"id": "01HXYZ1234567890ABCDEF",
			"type": "report",
			"title": "Monthly Sales Report",
			"payload": {
				"report_id": "monthly-sales",
				"format": "csv",
				"sections": 12
			},
			"status": "completed",
			"priority": "high",
			"attempts": 1,
			"started_at": "2025-01-01T00:00:00.000000Z",
			"completed_at": "2025-01-01T00:00:01.000000Z",
			"failed_at": null,
			"created_at": "2025-01-01T00:00:00.000000Z",
			"updated_at": "2025-01-01T00:00:01.000000Z"
		}
	],
	"links": {
		"first": "http://localhost/api/tasks?page=1",
		"last": "http://localhost/api/tasks?page=1",
		"prev": null,
		"next": null
	},
	"meta": {
		"current_page": 1,
		"from": 1,
		"last_page": 1,
		"path": "http://localhost/api/tasks",
		"per_page": 15,
		"to": 1,
		"total": 1
	}
}
```

#### Get Single Task

```http
GET /api/tasks/{task}
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
	"id": "01HXYZ1234567890ABCDEF",
	"type": "report",
	"title": "Monthly Sales Report",
	"payload": {
		"report_id": "monthly-sales",
		"format": "csv",
		"sections": 12
	},
	"status": "processing",
	"priority": "high",
	"attempts": 0,
	"started_at": "2025-01-01T00:00:00.000000Z",
	"completed_at": null,
	"failed_at": null,
	"created_at": "2025-01-01T00:00:00.000000Z",
	"updated_at": "2025-01-01T00:00:00.000000Z"
}
```

**Not Found (404):**

```json
{
	"message": "No query results for model [App\\Models\\Task] 999"
}
```

#### Cancel Task

```http
POST /api/tasks/{task}/cancel
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
	"message": "Task cancelled."
}
```

**Conflict (409) - Task cannot be cancelled:**

```json
{
	"message": "The task cannot be cancelled in its current state."
}
```

#### Retry Failed Task

```http
POST /api/tasks/{task}/retry
Authorization: Bearer {token}
```

**Response (200 OK):**

```json
{
	"id": "01HXYZ1234567890ABCDEF",
	"type": "report",
	"title": "Monthly Sales Report",
	"payload": {
		"report_id": "monthly-sales",
		"format": "csv",
		"sections": 12
	},
	"status": "pending",
	"priority": "high",
	"attempts": 0,
	"started_at": null,
	"completed_at": null,
	"failed_at": null,
	"created_at": "2025-01-01T00:00:00.000000Z",
	"updated_at": "2025-01-01T00:00:00.000000Z"
}
```

**Conflict (409) - Only failed tasks can be retried:**

```json
{
	"message": "Only failed tasks can be retried."
}
```

---

### Supported Task Types

| Type                | Required Payload Fields           | Optional Payload Fields       |
|---------------------|-----------------------------------|-------------------------------|
| `report`            | `report_id`, `format`             | `sections`                    |
| `bulk_notification` | `notification_template`, `recipient_ids` | -                    |
| `data_processing`   | `source`, `operation`             | `records`                     |

**Example - Bulk Notification:**

```json
{
	"type": "bulk_notification",
	"title": "Send Welcome Emails",
	"priority": "normal",
	"payload": {
		"notification_template": "welcome_email",
		"recipient_ids": [1, 2, 3, 4, 5]
	}
}
```

**Example - Data Processing:**

```json
{
	"type": "data_processing",
	"title": "Process Daily Transactions",
	"priority": "normal",
	"payload": {
		"source": "daily_transactions",
		"operation": "aggregate",
		"records": 10000
	}
}
```

---

### Using cURL

**Register:**

```bash
curl -X POST http://localhost/api/register \
  -H "Content-Type: application/json" \
  -d '{"name":"John Doe","email":"john@example.com","password":"password123"}'
```

**Login:**

```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"john@example.com","password":"password123"}'
```

**Create Task:**

```bash
curl -X POST http://localhost/api/tasks \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer {token}" \
  -d '{"type":"report","title":"Monthly Sales Report","priority":"high","payload":{"report_id":"monthly-sales","format":"csv","sections":12}}'
```

**List Tasks:**

```bash
curl -X GET "http://localhost/api/tasks?status=pending&per_page=15" \
  -H "Authorization: Bearer {token}"
```

**Get Task:**

```bash
curl -X GET http://localhost/api/tasks/{task_id} \
  -H "Authorization: Bearer {token}"
```

**Cancel Task:**

```bash
curl -X POST http://localhost/api/tasks/{task_id}/cancel \
  -H "Authorization: Bearer {token}"
```

**Retry Task:**

```bash
curl -X POST http://localhost/api/tasks/{task_id}/retry \
  -H "Authorization: Bearer {token}"
```

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