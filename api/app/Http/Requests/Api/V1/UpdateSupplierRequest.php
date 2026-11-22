<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('supplier')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:200'],
            'code' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('suppliers', 'code')->ignore($this->route('supplier'))],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
