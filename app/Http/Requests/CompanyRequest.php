<?php

namespace App\Http\Requests;

use App\Support\Tin;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CompanyRequest extends FormRequest
{
    /** Permission is checked before validation so non-admins get 403, not field errors. */
    public function authorize(): bool
    {
        return Gate::allows('manage-company');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tin' => ['required', 'string', function (string $attr, mixed $value, Closure $fail) {
                if (! Tin::isValid(is_string($value) ? $value : null)) {
                    $fail('Enter a valid TIN: 9 digits plus a 3- or 5-digit branch code, e.g. 123-456-789-000.');
                }
            }],
            'rdo_code' => ['nullable', 'regex:/^\d{3}$/'],
            'address' => ['required', 'string', 'max:500'],
            'zip_code' => ['nullable', 'regex:/^\d{4}$/'],
            'vat_registered' => ['required', 'boolean'],
            'currency' => ['sometimes', 'in:PHP'],
            'fiscal_year_start_month' => ['required', 'integer', 'between:1,12'],
        ];
    }

    public function messages(): array
    {
        return [
            'rdo_code.regex' => 'RDO code is 3 digits, e.g. 044.',
            'zip_code.regex' => 'ZIP code is 4 digits.',
            'currency.in' => 'Only Philippine peso (PHP) is supported.',
        ];
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        $data = $this->validated();
        $data['tin'] = Tin::normalize($data['tin']);
        $data['currency'] = 'PHP';

        return $data;
    }
}
