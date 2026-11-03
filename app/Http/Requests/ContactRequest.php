<?php

namespace App\Http\Requests;

use App\Enums\ContactType;
use App\Support\Tin;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(ContactType::class)],
            'tin' => ['nullable', 'string', function (string $attr, mixed $value, Closure $fail) {
                if (! Tin::isValid($value)) {
                    $fail('Enter a TIN with 9 digits plus a 3- or 5-digit branch code (e.g. 123-456-789-000).');
                }
            }],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['sometimes', 'integer', 'between:0,365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        $data = $this->validated();
        if (array_key_exists('tin', $data)) {
            $data['tin'] = $data['tin'] === null || $data['tin'] === '' ? null : Tin::normalize($data['tin']);
        }

        return $data;
    }
}
