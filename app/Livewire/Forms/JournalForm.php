<?php

namespace App\Livewire\Forms;

use Illuminate\Support\Facades\Validator;
use Livewire\Form;

class JournalForm extends Form
{
    public $content = '';

    public $mood = '';

    public static function validateDate(mixed $date, string $field = 'date'): string
    {
        return Validator::make([$field => $date], [$field => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:9999-12-31']], [], [$field => 'journal date'])->validate()[$field];
    }

    public static function validateInput(array $input): array
    {
        foreach (['content', 'mood'] as $key) {
            if (is_string($input[$key] ?? null)) {
                $input[$key] = preg_replace('/^\s+|\s+$/u', '', $input[$key]);
            }
        }
        if (($input['mood'] ?? '') === '') {
            $input['mood'] = null;
        }

        return Validator::make(['form' => $input], [
            'form.content' => ['required', 'string', 'max:10000'],
            'form.mood' => ['nullable', function ($attribute, $value, $fail) {
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-5]$/D', (string) $value)) {
                    $fail('Choose a mood from 1 to 5, or leave it unset.');
                }
            }],
        ], [], ['form.content' => 'journal text', 'form.mood' => 'mood'])->validate()['form'];
    }
}
