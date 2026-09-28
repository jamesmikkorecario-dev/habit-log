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
        $data = Validator::make(['form' => $input], collect($rules)->mapWithKeys(fn ($rule, $key) => ['form.'.$key => $rule])->all())->validate()['form'];
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
