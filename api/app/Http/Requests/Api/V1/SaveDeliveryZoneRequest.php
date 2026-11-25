<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/** Create (POST) and update (PATCH) a delivery zone. `shipping_fee` is integer minor units. */
class SaveDeliveryZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delivery.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'shipping_fee' => [$required, 'integer', 'min:0', 'max:100000000'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
