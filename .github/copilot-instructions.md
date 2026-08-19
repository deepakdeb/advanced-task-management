# Advanced Task Management System — AI Agent Instructions

## Project Context

You are working on an existing **Laravel 12** application with:

* Laravel 12
* Laravel Sanctum API authentication already installed
* Redis configured
* `predis/predis` installed
* MySQL/PostgreSQL
* ULID-based identifiers
* PHPUnit or Pest according to the existing project
* GitHub Copilot Agent

Build a production-grade asynchronous task management system.

### Mandatory architecture

The system must support:

* asynchronous Redis queues
* task priorities
* ULIDs
* Sanctum authentication
* user-level authorization
* Strategy Pattern task processors
* concurrency protection
* idempotency
* retries and exponential/backoff delays
* graceful cancellation
* stale-task cleanup
* task audit logs
* comprehensive automated tests

Use:

```php
declare(strict_types=1);
```

in every new PHP source file.

Use Laravel 12 conventions and APIs. Do not downgrade Laravel or introduce unnecessary packages.

---

# STEP 1 — Database, Enums, Models & Foundation

## Objective

Build the complete database/domain foundation before implementing queue processing or APIs.

## First inspect the existing application

Before changing anything, inspect:

* `composer.json`
* `.env.example`
* `bootstrap/app.php`
* `config/database.php`
* `config/queue.php`
* `config/cache.php`
* existing migrations
* `app/Models/User.php`
* Sanctum configuration
* existing routes
* existing tests
* PHPUnit/Pest configuration
* existing formatting/static-analysis configuration

Do not overwrite existing functionality.

## Create task enums

Create:

```text
app/Enums/TaskType.php
app/Enums/TaskStatus.php
app/Enums/TaskPriority.php
```

### TaskType

```text
report
bulk_notification
data_processing
```

### TaskStatus

```text
pending
processing
completed
failed
cancelled
```

### TaskPriority

```text
critical
high
normal
low
```

Use PHP backed enums.

## Create `tasks` migration

Fields:

```text
id                 ULID primary key
user_id            foreign key
type               task type
title              string
payload            JSON/JSONB
status             task status
priority           task priority
attempts           unsigned integer
started_at         nullable datetime
completed_at       nullable datetime
failed_at          nullable datetime
error_message      nullable text
created_at
updated_at
```

Use sensible defaults:

```text
status = pending
priority = normal
attempts = 0
```

Create useful indexes:

```text
(user_id, status)
(user_id, created_at)
(status, priority)
(status, priority, created_at)
created_at
```

Do not create redundant indexes without justification.

## Create `task_logs` migration

Fields:

```text
id
task_id
event
message
metadata JSON/JSONB
created_at
```

Create indexes:

```text
task_id
(task_id, created_at)
```

Use a foreign key from `task_logs.task_id` to `tasks.id`.

## Models

Create/update:

```text
app/Models/Task.php
app/Models/TaskLog.php
app/Models/User.php
```

Requirements:

### Task

* ULID primary key
* `belongsTo(User::class)`
* `hasMany(TaskLog::class)`
* enum casts
* JSON payload cast
* datetime casts
* mass-assignment protection
* useful query scopes

### TaskLog

* `belongsTo(Task::class)`
* metadata JSON cast
* proper timestamps configuration because it only needs `created_at`

### User

Preserve existing Sanctum functionality and add:

```php
public function tasks(): HasMany
```

Do not duplicate existing migrations or break Sanctum.

## Verification

Run:

```bash
php artisan migrate
php artisan test
```

If safe for the development environment:

```bash
php artisan migrate:fresh
```

Do not proceed to Step 2 until the database and model layer works.

---

# STEP 2 — Task Architecture, Processors, Queue & Reliability

## Objective

Implement the asynchronous task engine.

## Processor contract

Create:

```text
app/Contracts/Tasks/TaskProcessorInterface.php
```

Example:

```php
interface TaskProcessorInterface
{
    public function process(Task $task): void;
}
```

## Implement processors

Create:

```text
app/Services/Tasks/Processors/ReportTaskProcessor.php
app/Services/Tasks/Processors/BulkNotificationTaskProcessor.php
app/Services/Tasks/Processors/DataProcessingTaskProcessor.php
```

Each implements `TaskProcessorInterface`.

Simulate realistic heavy processing.

The processors must:

* process work in bounded batches
* avoid excessive memory usage
* support cancellation checkpoints
* throw meaningful exceptions
* not directly control HTTP responses
* not contain authentication logic
* not dispatch themselves

## Strategy Pattern

Create:

```text
TaskProcessorRegistry
```

or an equivalent resolver.

It must resolve:

```text
TaskType::REPORT
    -> ReportTaskProcessor

TaskType::BULK_NOTIFICATION
    -> BulkNotificationTaskProcessor

TaskType::DATA_PROCESSING
    -> DataProcessingTaskProcessor
```

Do **not** use a giant `switch` or `if/elseif` chain in `ProcessTaskJob`.

Adding a processor must not require modifying a giant conditional job.

## Task services

Create only useful services, such as:

```text
TaskExecutionService
TaskStateService
TaskLockService
TaskLogService
TaskCancellationService
TaskRetryService
TaskDispatcher
```

Use dependency injection.

## Queue job

Create:

```text
app/Jobs/Tasks/ProcessTaskJob.php
```

The job orchestrates:

```text
pending
   ↓
processing
   ↓
processor
   ↓
completed
```

and handles:

```text
processing
   ↓
failed
```

The job must not contain processor-specific business logic.

## Redis queues

Use separate queues:

```text
tasks-critical
tasks-high
tasks-normal
tasks-low
```

Map:

```text
critical -> tasks-critical
high     -> tasks-high
normal   -> tasks-normal
low      -> tasks-low
```

Use Laravel's Redis queue configuration.

Do not replace Predis.

## Concurrency protection

Use:

* `lockForUpdate()` for atomic database state transitions
* Redis atomic locks for task execution where appropriate

A task must never be processed simultaneously by two workers.

Avoid holding database transactions while heavy processing occurs.

Correct pattern:

```text
BEGIN
  lock task
  pending -> processing
  attempts++
COMMIT

execute processor

BEGIN
  lock task
  processing -> completed
COMMIT
```

## Idempotency

Implement deterministic task execution protection.

Use a stable hash based on relevant task data, such as:

```text
user_id
task type
canonical payload
```

Use SHA-256 or equivalent.

Use database uniqueness and/or Redis locking appropriately.

Duplicate jobs must not repeat state-changing work.

## Retry

Configure:

* maximum attempts
* backoff
* retryable exceptions

Example backoff:

```text
10s
30s
120s
300s
```

Make values configurable.

Permanent failures must not retry forever.

## Configuration

Create:

```text
config/tasks.php
```

Keep configurable:

```text
max attempts
stale timeout
lock timeout
queue names
batch sizes
backoff
```

## Verification

Test:

```text
pending -> processing -> completed
pending -> processing -> failed
duplicate execution protection
processor resolution
queue priority
retry configuration
```

Run:

```bash
php artisan test
```

Do not proceed until the queue architecture is stable.

---

# STEP 3 — API, Authentication, Validation & Authorization

## Objective

Build the secure API layer.

## Authentication

Use existing Laravel Sanctum.

Implement:

```text
POST /api/register
POST /api/login
POST /api/logout
```

Requirements:

* password hashing
* Sanctum tokens
* validation
* logout revokes current token
* protected endpoints use `auth:sanctum`

Do not manually implement cryptographic authentication.

## Requests

Create:

```text
StoreTaskRequest
TaskFilterRequest
```

### StoreTaskRequest

Validate:

```text
type
title
priority
payload
```

Payload validation must depend on task type.

Example:

```text
report
    report_id
    format

bulk_notification
    notification_template
    recipient_ids

data_processing
    source
    operation
```

Reject unsupported payload structures.

### TaskFilterRequest

Validate:

```text
status
type
priority
from
to
per_page
sort
direction
```

Whitelist sorting:

```text
created_at
updated_at
priority
status
```

Never concatenate user-controlled SQL into `orderBy()`.

Limit pagination, for example:

```text
1–100
```

## Task Policy

Create:

```text
app/Policies/TaskPolicy.php
```

Methods:

```text
view
cancel
retry
```

Users may only operate on their own tasks.

Never trust a client-provided `user_id`.

## Task Controller

Create:

```text
TaskController
```

Implement:

```text
POST /api/tasks
GET /api/tasks
GET /api/tasks/{task}
POST /api/tasks/{task}/cancel
POST /api/tasks/{task}/retry
```

### Create task

Flow:

```text
authenticate
    ↓
validate
    ↓
transaction
    ↓
create pending task
    ↓
create audit log
    ↓
commit
    ↓
dispatch ProcessTaskJob
    ↓
return 201
```

Do not process the task synchronously.

Use after-commit queue dispatching where appropriate.

### List tasks

Always filter by:

```text
authenticated user
```

Never allow:

```text
GET /api/tasks?user_id=another-user
```

to bypass isolation.

Support:

* pagination
* filters
* sorting
* date ranges

Avoid N+1 queries.

### Show task

Authorize through `TaskPolicy`.

### Cancel

Allowed:

```text
pending -> cancelled
processing -> cancelled
```

Not allowed:

```text
completed
failed
cancelled
```

Use concurrency-safe state transitions.

### Retry

Allowed:

```text
failed -> pending
```

Then dispatch a new job after the transaction commits.

## API Resource

Create:

```text
TaskResource
```

Expose only appropriate fields.

Never expose:

* passwords
* Sanctum tokens
* internal secrets
* sensitive exception data

## API status codes

Use:

```text
201 Created
200 OK
204 No Content
401 Unauthorized
403 Forbidden
404 Not Found
409 Conflict
422 Unprocessable Entity
```

as appropriate.

## Verification

Test:

* registration
* login
* logout
* unauthorized access
* task creation
* task listing
* task details
* authorization
* cancellation
* retry
* invalid payloads
* invalid filters
* pagination
* sorting

---

# STEP 4 — Cancellation, Cleanup, Scheduling & Production Hardening

## Objective

Complete reliability and lifecycle management.

## Graceful cancellation

Implement cooperative cancellation.

Workers must check cancellation:

```text
before processing
between batches
after expensive operations
before state-changing commits
```

Provide a service method similar to:

```php
throwIfCancelled(Task $task): void
```

A cancelled task must never be marked completed afterward.

Use row-level locking or Redis checkpoints to prevent race conditions.

## Cancellation race handling

Correctly handle scenarios such as:

```text
Worker:
processing

User:
cancel

Worker:
about to complete
```

The worker must re-check state before final completion.

If the task is cancelled:

```text
do not mark completed
```

## Retry workflow

Implement:

```text
failed
   ↓
retry API
   ↓
pending
   ↓
new queue job
```

Reset only the appropriate execution fields.

Do not reset historical audit logs.

Create:

```text
retry_requested
retry_dispatched
```

events as appropriate.

## Task audit logging

Create a centralized `TaskLogService`.

Support events such as:

```text
created
queued
processing_started
processing_completed
processing_failed
cancel_requested
cancelled
retry_requested
retry_dispatched
stale
```

Never log:

* passwords
* Sanctum tokens
* API keys
* authorization headers
* secrets
* unnecessarily sensitive payloads

## Stale task command

Create:

```text
app/Console/Commands/Tasks/CleanupStaleTasks.php
```

Command:

```bash
php artisan tasks:cleanup-stale
```

Find tasks where:

```text
status = processing
AND
started_at < now() - 30 minutes
```

For every candidate:

1. acquire lock
2. reload task
3. verify it is still processing
4. mark failed
5. set `failed_at`
6. set safe error message
7. create stale audit log
8. release lock

Do not blindly update stale records.

## Scheduling

Schedule cleanup every five minutes using Laravel 12's current scheduling mechanism.

Do not assume Laravel 10/11's `Console\Kernel` structure.

Inspect `bootstrap/app.php` and existing scheduling conventions first.

## Production hardening

Verify:

* queue workers have sensible timeouts
* Redis locks have TTLs
* stale tasks can recover
* retries cannot create duplicate processing
* transactions remain short
* task payloads are bounded
* bulk operations are chunked
* API responses do not expose internal exceptions
* logs contain enough context for debugging

## Verification

Test:

```text
pending -> cancelled
processing -> cancelled
completed cannot cancel
failed -> retry
retry dispatch
stale processing -> failed
recent processing remains unchanged
cancelled task cannot become completed
```

Run:

```bash
php artisan test
```

and:

```bash
php artisan route:list
```

---

# STEP 5 — Complete Automated Testing, Security Review & Final Verification

## Objective

Perform the final production-readiness pass.

Do not add new functionality unless required to fix a discovered issue.

## Feature tests

Cover:

### Authentication

```text
register
login
logout
invalid credentials
protected endpoints
```

### Task creation

```text
valid task
invalid type
invalid priority
invalid payload
unauthenticated creation
job dispatched
initial pending status
```

### User isolation

User A must not:

```text
view User B's task
cancel User B's task
retry User B's task
```

Test using real API requests.

### Task listing

Test:

```text
status filter
type filter
priority filter
date range
pagination
sort whitelist
sort direction
```

Ensure users only receive their own tasks.

### Task processing

Test:

```text
report processor
bulk notification processor
data processing processor
```

Verify:

```text
pending -> processing -> completed
```

### Failure

Verify:

```text
processing -> failed
error logged
attempt count updated
failure timestamp stored
```

### Retry

Verify:

```text
failed -> pending -> processing -> completed
```

### Cancellation

Verify:

```text
pending -> cancelled
processing -> cancelled
```

and ensure cancelled tasks cannot later become completed.

### Concurrency

Create a test proving that two workers cannot process the same task concurrently.

The test must verify actual locking/idempotency behavior, not merely the final database status.

### Stale cleanup

Test:

```text
processing > 30 minutes -> failed
processing < 30 minutes -> unchanged
completed -> unchanged
cancelled -> unchanged
```

## Unit tests

Unit-test:

```text
TaskProcessorRegistry
TaskStateService
TaskCancellationService
TaskRetryService
TaskLogService
processors
```

Mock external dependencies where appropriate.

Do not over-mock Eloquent behavior in feature tests.

## Security review

Verify:

* all task endpoints use Sanctum
* policies enforce ownership
* no `user_id` can be injected
* lifecycle fields cannot be mass assigned
* sort columns are whitelisted
* payload validation is strict
* SQL injection is impossible through filters
* sensitive data is not logged
* exceptions do not leak stack traces
* another user's ULID cannot access their task
* retry/cancel operations are authorization protected

## Database review

Verify:

```text
ULID primary keys
foreign keys
indexes
JSON/JSONB columns
enum casts
timestamps
task log relationships
```

Run:

```bash
php artisan migrate:fresh
```

only if safe for the development/test database.

Then:

```bash
php artisan test
```

## Queue verification

Verify Redis workers using:

```bash
php artisan queue:work redis --queue=tasks-critical
php artisan queue:work redis --queue=tasks-high
php artisan queue:work redis --queue=tasks-normal
php artisan queue:work redis --queue=tasks-low
```

Do not run all workers as one process if production priority isolation requires dedicated workers.

Document the recommended worker configuration.

## Code quality

If already installed, run:

```bash
./vendor/bin/pint
```

and:

```bash
./vendor/bin/phpstan analyse
```

or the project's existing static-analysis tool.

Then run:

```bash
php artisan test
```

Finally inspect:

```bash
git diff
git status
```

Ensure no unrelated files were changed.

## Final Definition of Done

All of these must pass:

* [ ] Laravel 12 compatibility
* [ ] Sanctum authentication
* [ ] ULID task IDs
* [ ] Task enums
* [ ] User ownership
* [ ] Task migrations
* [ ] Task logs
* [ ] Redis queues
* [ ] Priority queues
* [ ] Strategy Pattern
* [ ] Processor registry
* [ ] Concurrency locks
* [ ] Idempotency
* [ ] Retries
* [ ] Backoff
* [ ] Graceful cancellation
* [ ] Stale cleanup
* [ ] Scheduled cleanup
* [ ] API validation
* [ ] API authorization
* [ ] API resources
* [ ] User isolation
* [ ] Feature tests
* [ ] Unit tests
* [ ] Concurrency tests
* [ ] Cancellation tests
* [ ] Retry tests
* [ ] Cleanup tests
* [ ] Formatting passes
* [ ] Existing tests pass
* [ ] No secrets committed
* [ ] No unrelated code changed

## Agent Execution Rule

Execute **only one step at a time**.

At the end of each step, report:

```text
Step:
Status:

Implemented:
- ...

Files changed:
- ...

Tests:
- ...

Result:
- PASS / FAIL

Issues:
- ...
```

If tests fail:

1. diagnose the root cause
2. fix it
3. rerun the failed tests
4. rerun the relevant suite
5. continue only after the step passes

Never skip failing tests and never claim a step is complete when verification has failed.
