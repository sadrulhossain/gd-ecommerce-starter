<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Coupon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Create (POST, `coupons.create`) and update (PATCH, `coupons.update`) a coupon. */
class SaveCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'coupons.create' : 'coupons.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $type = $this->input('type', $this->route('coupon')?->type);

        return [
            'code' => [$required, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/', Rule::unique('coupons', 'code')->ignore($this->route('coupon'))],
            'type' => [$required, Rule::in([Coupon::PERCENT, Coupon::FIXED])],
            'value' => [$required, 'integer', 'min:1', $type === Coupon::PERCENT ? 'max:10000' : 'max:1000000000'],
            'min_subtotal' => ['sometimes', 'integer', 'min:0'],
            'max_discount' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
