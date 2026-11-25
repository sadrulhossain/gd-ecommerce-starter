<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/** A storefront customer. Authenticates on the `customer` guard, never on the admin `api` guard. */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password'])]
class Customer extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
