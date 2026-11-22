<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** Validation shared by every request that writes variants (inside a product, or one at a time). */
final class VariantRules
{
    private const MAX_MONEY = 99_999_999_999;

    /**
     * Field rules. `$prefix` is '' for a single variant or 'variants.*.' inside a product; `$partial` is true for PATCH.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix, bool $partial, ?ProductVariant $ignore = null): array
    {
        $required = $partial ? ['sometimes', 'required'] : ['required'];
        $unique = fn (string $column) => $ignore === null
            ? Rule::unique('product_variants', $column)
            : Rule::unique('product_variants', $column)->ignore($ignore);
        $distinct = str_contains($prefix, '*') ? ['distinct'] : [];

        return [
            "{$prefix}sku" => [...$required, 'string', 'max:64', ...$distinct, $unique('sku')],
            "{$prefix}barcode" => ['nullable', 'string', 'max:64', ...$distinct, $unique('barcode')],
            "{$prefix}name" => ['nullable', 'string', 'max:200'],
            "{$prefix}options" => ['sometimes', 'array', 'max:5'],
            "{$prefix}options.*" => ['string', 'max:100'],
            "{$prefix}price" => [...$required, 'integer', 'min:0', 'max:'.self::MAX_MONEY],
            "{$prefix}compare_at_price" => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_MONEY],
            "{$prefix}cost_price" => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_MONEY],
            "{$prefix}currency" => ['sometimes', 'string', Rule::in(['BDT'])],
            "{$prefix}weight_grams" => ['nullable', 'integer', 'min:0', 'max:10000000'],
            "{$prefix}is_active" => ['sometimes', 'boolean'],
            "{$prefix}sort_order" => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Cross-field checks for a whole list of variants sent with a new product.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    public static function checkNewSet(Validator $validator, array $variants, bool $hasVariants): void
    {
        if (! $hasVariants) {
            if (count($variants) !== 1 || ($variants[0]['options'] ?? []) !== []) {
                $validator->errors()->add('variants', 'A product without variants takes exactly one variant with no options.');
            }
        } else {
            $names = null;
            $seen = [];

            foreach ($variants as $i => $variant) {
                $options = (array) ($variant['options'] ?? []);

                if ($options === [] || array_is_list($options)) {
                    $validator->errors()->add("variants.{$i}.options", 'Name every option, for example {"Color": "Red"}.');

                    continue;
                }

                $keys = array_keys($options);
                sort($keys);
                $names ??= $keys;

                if ($keys !== $names) {
                    $validator->errors()->add("variants.{$i}.options", 'Every variant must use the same option names.');
                }

                ksort($options);
                $signature = json_encode($options);

                if (isset($seen[$signature])) {
                    $validator->errors()->add("variants.{$i}.options", 'Two variants have the same options.');
                }
                $seen[$signature] = true;
            }
        }

        foreach ($variants as $i => $variant) {
            self::checkCompareAt($validator, "variants.{$i}.compare_at_price", $variant['price'] ?? null, $variant['compare_at_price'] ?? null);
        }
    }

    public static function checkCompareAt(Validator $validator, string $key, mixed $price, mixed $compareAt): void
    {
        if ($compareAt !== null && $price !== null && (int) $compareAt <= (int) $price) {
            $validator->errors()->add($key, 'The compare-at price must be greater than the price.');
        }
    }

    /**
     * Cross-field checks for one variant added to, or changed on, an existing product.
     *
     * @param  array<string, mixed>  $input  the request input
     */
    public static function checkSingle(Validator $validator, Product $product, array $input, ?ProductVariant $existing = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $price = array_key_exists('price', $input) ? $input['price'] : $existing?->price;
        $compareAt = array_key_exists('compare_at_price', $input) ? $input['compare_at_price'] : $existing?->compare_at_price;
        self::checkCompareAt($validator, 'compare_at_price', $price, $compareAt);

        if ($existing === null || array_key_exists('options', $input)) {
            $options = (array) ($input['options'] ?? $existing->options ?? []);

            if (! $product->has_variants) {
                if ($options !== [] || ($existing === null && $product->variants()->exists())) {
                    $validator->errors()->add('options', 'This product has no variants; it keeps its single default variant.');
                }

                return;
            }

            if ($options === [] || array_is_list($options)) {
                $validator->errors()->add('options', 'Name every option, for example {"Color": "Red"}.');

                return;
            }

            $keys = array_keys($options);
            sort($keys);
            $sibling = $product->variants()->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->first();

            if ($sibling !== null) {
                $siblingKeys = array_keys($sibling->options);
                sort($siblingKeys);

                if ($keys !== $siblingKeys) {
                    $validator->errors()->add('options', 'Every variant must use the same option names.');
                }
            }

            $taken = $product->variants()
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->whereRaw('md5(options::text) = md5(?::jsonb::text)', [json_encode((object) $options)])
                ->exists();

            if ($taken) {
                $validator->errors()->add('options', 'Another variant already has these options.');
            }
        }
    }
}
