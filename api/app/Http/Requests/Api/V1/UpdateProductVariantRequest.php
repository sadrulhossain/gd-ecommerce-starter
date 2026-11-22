<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('variant')->product) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return VariantRules::rules('', true, $this->route('variant'));
    }

    /** @return list<callable> */
    public function after(): array
    {
        $variant = $this->route('variant');

        return [fn (Validator $validator) => VariantRules::checkSingle($validator, $variant->product, $this->all(), $variant)];
    }
}
