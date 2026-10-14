<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

trait EventValidationRules
{
    /**
     * Get the validation rules used to validate events.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function eventRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'venue' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * Get the Japanese names of the event attributes for validation messages.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'title' => 'タイトル',
            'description' => '説明',
            'venue' => '会場',
            'starts_at' => '開始日時',
            'ends_at' => '終了日時',
        ];
    }
}
