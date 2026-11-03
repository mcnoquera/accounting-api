<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseBillRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')],
            'supplier_invoice_number' => ['required', 'string', 'max:100'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'lines.*.vat_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('kind', 'vat')],
            'lines.*.ewt_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('kind', 'ewt')],
        ];
    }

    public function messages(): array
    {
        return ['lines.*.amount.regex' => 'Amount is a positive number with at most 2 decimals.'];
    }
}
