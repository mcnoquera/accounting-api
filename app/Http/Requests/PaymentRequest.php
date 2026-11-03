<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $money = ['regex:/^\d{1,13}(\.\d{1,2})?$/'];

        return [
            'direction' => ['required', Rule::in(['receipt', 'payment'])],
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'cash_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')],
            'amount' => ['required', ...$money],
            'ewt_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('kind', 'ewt')],
            'withholding_base' => ['nullable', ...$money],
            'withholding_amount' => ['nullable', ...$money],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*.document_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', ...$money],
        ];
    }
}
