<?php

namespace App\Http\Requests;

use App\Enums\TaxKind;
use App\Enums\VatCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxCodeRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('taxCode')?->id;
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('tax_codes', 'code')->ignore($id)],
            'name' => [$required, 'string', 'max:255'],
            'kind' => [$required, Rule::enum(TaxKind::class)],
            // Percentage with up to 2 decimals, e.g. "12" or "1.5"
            'rate' => [$required, 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'vat_category' => ['nullable', Rule::enum(VatCategory::class), Rule::requiredIf(fn () => $this->input('kind') === TaxKind::Vat->value)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'Use capital letters, digits and dashes (e.g. WC158).'];
    }

    /** @return array<string, mixed> attributes for the model, with the rate in basis points */
    public function modelAttributes(): array
    {
        $data = $this->validated();
        if (array_key_exists('rate', $data)) {
            [$whole, $frac] = array_pad(explode('.', (string) $data['rate']), 2, '0');
            $data['rate_bp'] = (int) $whole * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0');
            unset($data['rate']);
        }
        if (($data['kind'] ?? null) === TaxKind::Ewt->value) {
            $data['vat_category'] = null;
        }

        return $data;
    }
}
