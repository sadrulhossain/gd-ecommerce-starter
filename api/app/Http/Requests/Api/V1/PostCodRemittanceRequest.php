<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PostCodRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cod-remittances.post') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['received_amount' => ['required', 'integer', 'min:0', 'max:1000000000000']];
    }
}
