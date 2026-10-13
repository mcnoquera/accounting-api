<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JournalEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $amount = ['nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'];

        return [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => $amount,
            'lines.*.credit' => $amount,
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.debit.regex' => 'Debit must be a positive amount with at most 2 decimals.',
            'lines.*.credit.regex' => 'Credit must be a positive amount with at most 2 decimals.',
        ];
    }
}
