<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;

trait TicketTypeValidationRules
{
    /**
     * Get the validation rules used to validate ticket types.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function ticketTypeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'sales_start_at' => ['required', 'date'],
            'sales_end_at' => ['required', 'date', 'after:sales_start_at'],
        ];
    }

    /**
     * Get the Japanese names of the ticket type attributes for validation messages.
     *
     * @return array<string, string>
     */
    protected function ticketTypeAttributes(): array
    {
        return [
            'name' => '券種名',
            'price' => '価格',
            'capacity' => '定員',
            'sales_start_at' => '販売開始日時',
            'sales_end_at' => '販売終了日時',
        ];
    }
}
