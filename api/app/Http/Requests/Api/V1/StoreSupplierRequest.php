<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Supplier::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/', Rule::unique('suppliers', 'code')],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'tax_number' => ['nullable', 'string', 'max:64'],
            'payment_terms' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
