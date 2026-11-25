<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreCodRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cod-remittances.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'courier_id' => ['required', 'integer', 'exists:couriers,id'],
            'delivery_ids' => ['required', 'array', 'min:1', 'max:500'],
            'delivery_ids.*' => ['integer', 'distinct', 'exists:deliveries,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
