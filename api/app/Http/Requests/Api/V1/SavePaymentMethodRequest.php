<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST, `payment-methods.create`) and update (PATCH, `payment-methods.update`) a payment method.
 * `credentials` is write-only: omit it to keep the stored value, send `{}` to clear it, or send an object to replace it.
 */
class SavePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'payment-methods.create' : 'payment-methods.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            // A code must name a driver registered in config/payments.php.
            'code' => $create ? ['required', 'string', 'max:40', Rule::in(array_keys((array) config('payments.drivers'))), Rule::unique('payment_methods', 'code')] : ['prohibited'],
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'credentials' => ['sometimes', 'nullable', 'array', 'max:20'],
            'credentials.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_keys((array) $this->input('credentials', [])) as $key) {
                if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_.-]{1,60}$/', $key)) {
                    $validator->errors()->add('credentials', 'Credential names may use letters, numbers, dots, dashes and underscores only.');

                    return;
                }
            }
        }];
    }
}
