<?php

namespace App\Livewire\Forms;

use App\Enums\HabitType;
use App\Enums\HabitVisibility;
use App\Models\Habit;
use App\Support\NormalizedName;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Form;

class HabitForm extends Form
{
    public $name = '';

    public $description = '';

    public $category_id = '';

    public $type = 'boolean';

    public $visibility = 'private';

    public $goal_value = '';

    public $goal_unit = '';

    public $start_date = '';

    public $end_date = '';

    public $checkpoints = [];

    public function fillHabit(Habit $habit): void
    {
        $this->fill($habit->only(['name', 'description', 'category_id', 'goal_value', 'goal_unit']));
        $this->type = $habit->type->value;
        $this->visibility = $habit->visibility->value;
        $this->start_date = $habit->start_date->toDateString();
        $this->end_date = $habit->end_date?->toDateString() ?? '';
        $this->checkpoints = $habit->checkpoints->map(fn ($checkpoint) => [
            'id' => $checkpoint->id, 'label' => $checkpoint->label, 'key' => 'saved-'.$checkpoint->id,
        ])->all();
    }

    public function validatedData(?int $habitId): array
    {
        foreach (['name', 'description', 'goal_unit', 'goal_value', 'end_date'] as $field) {
            if (is_string($this->$field)) {
                $this->$field = trim($this->$field);
                if ($field !== 'name' && $this->$field === '') {
                    $this->$field = null;
                }
            }
        }

        $numeric = in_array($this->type, ['count', 'duration', 'avoidance'], true);
        if (! $numeric) {
            $this->goal_value = null;
            $this->goal_unit = null;
        } elseif ($this->type === 'duration') {
            $this->goal_unit = 'minutes';
        }

        if ($this->type !== 'checkpoint') {
            $this->checkpoints = [];
        } elseif (is_array($this->checkpoints)) {
            foreach ($this->checkpoints as &$checkpoint) {
                if (is_array($checkpoint) && is_string($checkpoint['label'] ?? null)) {
                    $checkpoint['label'] = trim($checkpoint['label']);
                }
            }
            unset($checkpoint);
        }

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where(fn ($query) => $query->where('is_system', true)->orWhere('user_id', auth()->id())))],
            'type' => ['required', Rule::enum(HabitType::class)],
            'visibility' => ['required', Rule::enum(HabitVisibility::class)],
            'goal_value' => ['nullable', 'integer', 'min:'.($this->type === 'avoidance' ? '0' : '1'), 'max:999999999'],
            'goal_unit' => ['nullable', 'string', 'max:30'],
            'start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:9999-12-31'],
            'checkpoints' => ['array', 'list', 'max:20', ...($this->type === 'checkpoint' ? ['required', 'min:1'] : [])],
            'checkpoints.*' => ['array:id,label,key'],
            'checkpoints.*.id' => ['nullable', 'integer', 'distinct', Rule::exists('habit_checkpoints', 'id')->where('habit_id', $habitId ?? 0)],
            'checkpoints.*.key' => ['sometimes', 'string', 'max:80'],
            'checkpoints.*.label' => ['required', 'string', 'max:80'],
        ], [
            'category_id.exists' => 'Choose an available category.',
            'checkpoints.*.id.exists' => 'This checkpoint is not available for this habit.',
        ]);

        $labels = [];
        $errors = [];
        foreach ($validated['checkpoints'] as $index => $checkpoint) {
            $label = NormalizedName::from($checkpoint['label']);
            if ($label === '' || in_array($label, $labels, true)) {
                $errors['form.checkpoints.'.$index.'.label'] = 'Use a distinct label containing letters or numbers.';
            }
            $labels[] = $label;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $validated;
    }
}
