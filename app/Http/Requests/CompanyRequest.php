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
            // Percent with up to 2 decimals, e.g. "3" or "1.5"
            'percentage_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
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
        if (array_key_exists('percentage_tax_rate', $data)) {
            if ($data['percentage_tax_rate'] !== null) {
                [$whole, $frac] = array_pad(explode('.', (string) $data['percentage_tax_rate']), 2, '0');
                $data['percentage_tax_rate_bp'] = (int) $whole * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
            }
            unset($data['percentage_tax_rate']);
        }

        return $data;
    }
}
