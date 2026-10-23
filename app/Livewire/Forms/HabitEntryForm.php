<?php

namespace App\Livewire\Forms;

use App\Enums\HabitEntryStatus;
use App\Enums\HabitType;
use App\Models\HabitTrackingVersion;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Form;

class HabitEntryForm extends Form
{
    public $status = '';

    public $value = '';

    public $note = '';

    public $not_applicable_reason = '';

    public $completed = [];

    public static function validateInput(array $input, HabitTrackingVersion $version): array
    {
        foreach (['value', 'note', 'not_applicable_reason'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = trim($input[$key]);
                if ($input[$key] === '') {
                    $input[$key] = null;
                }
            }
        }
        $na = ($input['status'] ?? null) === HabitEntryStatus::NotApplicable->value;
        $numeric = in_array($version->type, [HabitType::Count, HabitType::Duration, HabitType::Avoidance], true);
        $statuses = array_column(HabitEntryStatus::cases(), 'value');
        if ($version->type === HabitType::Boolean) {
            $statuses = array_diff($statuses, ['partial']);
        }
        $rules = [
            'status' => ['required', Rule::in($statuses)],
            'value' => [$numeric && ! $na ? 'required' : 'nullable', function ($attribute, $value, $fail) {
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[0-9]+$/D', (string) $value) || (float) $value > 999999999) {
                    $fail('Enter a whole number from 0 to 999,999,999.');
                }
            }],
            'note' => ['nullable', 'string', 'max:2000'],
            'not_applicable_reason' => ['nullable', 'string', 'max:500'],
            'completed' => ['present', 'array', 'list', 'max:20'],
            'completed.*' => ['required', 'integer', 'distinct', Rule::in($version->checkpoints->modelKeys())],
        ];
        if (! $numeric) {
            $rules['value'] = ['prohibited'];
        }
        if ($version->type !== HabitType::Checkpoint) {
            $rules['completed'][] = 'size:0';
        }
        $messages = in_array($version->type, [HabitType::Count, HabitType::Duration, HabitType::Avoidance], true) ? [
            'form.status.required' => 'Choose an assessment.',
            'form.status.in' => 'Choose a valid assessment.',
            'form.value.required' => match ($version->type) {
                HabitType::Duration => 'Enter the actual minutes.',
                HabitType::Avoidance => 'Enter the number of occurrences.',
                default => 'Enter the actual count.',
            },
        ] : [];
        if ($version->type === HabitType::Checkpoint) {
            $messages = [
                'form.status.required' => 'Choose whether to track checkpoints or mark this date not applicable.',
                'form.status.in' => 'Choose whether to track checkpoints or mark this date not applicable.',
                'form.completed.*' => 'One or more checkpoint selections are invalid. Reload this entry and try again.',
                'form.completed.*.*' => 'One or more checkpoint selections are invalid. Reload this entry and try again.',
                'form.value.prohibited' => 'Use the checkpoint selections instead of a numeric measurement.',
                'form.note.string' => 'Enter your private note as plain text.',
                'form.note.max' => 'Keep your private note to 2,000 characters or fewer.',
                'form.not_applicable_reason.string' => 'Enter your N/A reason as plain text.',
                'form.not_applicable_reason.max' => 'Keep your N/A reason to 500 characters or fewer.',
            ];
        }
        $data = Validator::make(['form' => $input], collect($rules)->mapWithKeys(fn ($rule, $key) => ['form.'.$key => $rule])->all(), $messages)->validate()['form'];
        if ($version->type === HabitType::Checkpoint && ! $na) {
            $count = count($data['completed']);
            $data['status'] = $count === $version->checkpoints->count() ? 'completed' : ($count ? 'partial' : 'missed');
        }
        if (! $na) {
            $data['not_applicable_reason'] = null;
        }

        return $data;
    }
}
