<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'lines.*.unit_price' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'lines.*.vat_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('kind', 'vat')],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.*.quantity.regex' => 'Quantity is a positive number with at most 3 decimals.',
            'lines.*.unit_price.regex' => 'Unit price is a positive amount with at most 2 decimals.',
        ];
    }
}
