<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Carts live in Redis (the default cache store), keyed by an unguessable token the client keeps.
 * A cart stores only variant ids and quantities; prices are quoted by PricingService on every read.
 */
class CartService
{
    public function create(): string
    {
        $token = Str::random(40);
        $this->save($token, []);

        return $token;
    }

    public function exists(string $token): bool
    {
        return Cache::has($this->key($token));
    }

    /** @return array<int, int> variant id => quantity */
    public function items(string $token): array
    {
        /** @var array<int, int> $items */
        $items = Cache::get($this->key($token), []);

        return $items;
    }

    /** Set a line's quantity (0 removes it). */
    public function set(string $token, int $variantId, int $quantity): void
    {
        $items = $this->items($token);
        if ($quantity <= 0) {
            unset($items[$variantId]);
        } else {
            $items[$variantId] = $quantity;
        }
        $this->save($token, $items);
    }

    public function clear(string $token): void
    {
        $this->save($token, []);
    }

    /** @param  array<int, int>  $items */
    private function save(string $token, array $items): void
    {
        Cache::put($this->key($token), $items, now()->addDays((int) config('shop.cart_ttl_days')));
    }

    private function key(string $token): string
    {
        return 'cart:'.hash('sha256', $token);
    }
}
