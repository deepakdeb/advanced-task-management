<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TaskPriority;
use App\Enums\TaskType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(TaskType::class)],
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'payload' => ['required', 'array', 'max:20'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = TaskType::tryFrom((string) $this->input('type'));
            $payload = $this->input('payload');

            if ($type === null || ! is_array($payload)) {
                return;
            }

            $required = match ($type) {
                TaskType::REPORT => ['report_id', 'format'],
                TaskType::BULK_NOTIFICATION => ['notification_template', 'recipient_ids'],
                TaskType::DATA_PROCESSING => ['source', 'operation'],
            };

            $allowed = match ($type) {
                TaskType::REPORT => ['report_id', 'format', 'sections'],
                TaskType::BULK_NOTIFICATION => ['notification_template', 'recipient_ids'],
                TaskType::DATA_PROCESSING => ['source', 'operation', 'records'],
            };

            foreach ($required as $key) {
                if (! array_key_exists($key, $payload)) {
                    $validator->errors()->add("payload.{$key}", "The {$key} field is required for this task type.");
                }
            }

            $unknown = array_diff(array_keys($payload), $allowed);
            if ($unknown !== []) {
                $validator->errors()->add('payload', 'The payload contains unsupported fields for this task type.');
            }

            $this->validatePayloadTypes($validator, $type, $payload);
        });
    }

    private function validatePayloadTypes(Validator $validator, TaskType $type, array $payload): void
    {
        $stringFields = match ($type) {
            TaskType::REPORT => ['report_id', 'format'],
            TaskType::BULK_NOTIFICATION => ['notification_template'],
            TaskType::DATA_PROCESSING => ['source', 'operation'],
        };

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $payload) && ! is_string($payload[$field])) {
                $validator->errors()->add("payload.{$field}", 'This field must be a string.');
            }
        }

        if ($type === TaskType::BULK_NOTIFICATION && isset($payload['recipient_ids']) && ! is_array($payload['recipient_ids'])) {
            $validator->errors()->add('payload.recipient_ids', 'This field must be an array.');
        }
    }
}
