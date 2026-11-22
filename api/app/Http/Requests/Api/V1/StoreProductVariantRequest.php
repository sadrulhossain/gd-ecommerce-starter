<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('product')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return VariantRules::rules('', false);
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => VariantRules::checkSingle($validator, $this->route('product'), $this->all())];
    }
}
