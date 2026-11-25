<?php

use App\Models\Coupon;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\StockLevel;
use App\Models\TaxRate;
use Database\Seeders\CustomerClientSeeder;
use Database\Seeders\OrdersModuleSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Database\Seeders\PromotionsModuleSeeder;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;

const PRM_ALL = [
    'orders.view', 'orders.update', 'orders.cancel', 'tax-rates.view', 'tax-rates.manage',
    'coupons.view', 'coupons.create', 'coupons.update', 'coupons.delete', 'reviews.view', 'reviews.moderate', 'reviews.delete',
];

beforeEach(function () {
    $this->seed([PaymentMethodsSeeder::class, OrdersModuleSeeder::class, PromotionsModuleSeeder::class, CustomerClientSeeder::class]);
});

function prmProduct(int $price = 10000, array $attributes = []): Product
{
    $product = catalogProduct(['status' => Product::ACTIVE] + $attributes, [['sku' => 'P-'.uniqid(), 'price' => $price]]);
    $product->category->update(['is_active' => true]);
    StockLevel::query()->insertOrIgnore(['variant_id' => $product->variants()->firstOrFail()->id, 'on_hand' => 100]);

    return $product;
}

/** Quote a one-line cart (qty 1 unless given) through the public cart endpoint. */
function prmCart($test, Product $product, int $qty = 1, string $query = ''): array
{
    $token = $test->postJson('/api/v1/public/carts')->json('data.token');
    $test->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $product->variants()->firstOrFail()->id, 'quantity' => $qty]);

    return ['token' => $token, 'data' => $test->getJson("/api/v1/public/carts/{$token}{$query}")->assertOk()->json('data')];
}

function prmCheckout($test, string $token, array $extra = [])
{
    return $test->postJson("/api/v1/public/carts/{$token}/checkout", $extra + [
        'contact_name' => 'Rafi', 'contact_phone' => '017', 'address_line' => 'House 1', 'city' => 'Dhaka', 'payment_method' => 'cod',
    ]);
}

test('admin promotion routes need authentication and permissions', function () {
    $coupon = Coupon::create(['code' => 'X', 'type' => 'fixed', 'value' => 100]);
    $rate = TaxRate::create(['name' => 'VAT', 'rate_bp' => 1500]);
    $review = (new Review)->forceFill(['product_id' => prmProduct()->id, 'customer_id' => Customer::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass-1'])->id, 'rating' => 5, 'body' => 'ok']);
    $review->save();

    $this->getJson('/api/v1/coupons')->assertUnauthorized();
    $this->getJson('/api/v1/tax-rates')->assertUnauthorized();
    $this->getJson('/api/v1/reviews')->assertUnauthorized();

    actingAsRole('nobody');
    $this->getJson('/api/v1/coupons')->assertForbidden();
    $this->getJson("/api/v1/coupons/{$coupon->id}")->assertForbidden();
    $this->postJson('/api/v1/coupons', [])->assertForbidden();
    $this->patchJson("/api/v1/coupons/{$coupon->id}", [])->assertForbidden();
    $this->deleteJson("/api/v1/coupons/{$coupon->id}")->assertForbidden();
    $this->getJson('/api/v1/tax-rates')->assertForbidden();
    $this->postJson('/api/v1/tax-rates', [])->assertForbidden();
    $this->patchJson("/api/v1/tax-rates/{$rate->id}", [])->assertForbidden();
    $this->deleteJson("/api/v1/tax-rates/{$rate->id}")->assertForbidden();
    $this->getJson('/api/v1/reviews')->assertForbidden();
    $this->postJson("/api/v1/reviews/{$review->id}/approve")->assertForbidden();
    $this->postJson("/api/v1/reviews/{$review->id}/reject")->assertForbidden();
    $this->deleteJson("/api/v1/reviews/{$review->id}")->assertForbidden();
});

test('tax rates: crud, single default, product assignment, in-use delete refused', function () {
    actingAsRole('ops', [...PRM_ALL, 'products.update', 'products.view']);

    $vat = $this->postJson('/api/v1/tax-rates', ['name' => 'VAT', 'rate_bp' => 1500, 'is_default' => true])->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
    $zero = $this->postJson('/api/v1/tax-rates', ['name' => 'Exempt', 'rate_bp' => 0, 'is_default' => true])->assertCreated()->json('data.id');
    expect(TaxRate::find($vat)->is_default)->toBeFalse()->and(TaxRate::find($zero)->is_default)->toBeTrue();
    $this->postJson('/api/v1/tax-rates', ['rate_bp' => 20000])->assertUnprocessable()->assertInvalidFields(['name', 'rate_bp']);
    $this->patchJson("/api/v1/tax-rates/{$vat}", ['rate_bp' => 500])->assertOk()->assertJsonPath('data.rate_bp', 500);
    $this->getJson('/api/v1/tax-rates')->assertOk()->assertJsonCount(2, 'data');

    $product = prmProduct();
    $this->patchJson("/api/v1/products/{$product->id}", ['tax_rate_id' => $vat])->assertOk()->assertJsonPath('data.tax_rate_id', $vat);
    $this->patchJson("/api/v1/products/{$product->id}", ['tax_rate_id' => 9999])->assertUnprocessable()->assertInvalidFields(['tax_rate_id']);
    $this->deleteJson("/api/v1/tax-rates/{$vat}")->assertUnprocessable()->assertInvalidFields(['tax_rate']);
    $this->deleteJson("/api/v1/tax-rates/{$zero}")->assertNoContent();
});

test('tax is added per line on the discounted amount, from the product or default rate', function () {
    $vat = TaxRate::create(['name' => 'VAT', 'rate_bp' => 1500]);
    TaxRate::create(['name' => 'Default', 'rate_bp' => 500, 'is_default' => true]);

    $taxed = prmProduct(10000, ['tax_rate_id' => $vat->id]);
    $plain = prmProduct(10000);

    $cart = prmCart($this, $taxed, 2)['data'];
    expect($cart['subtotal']['amount'])->toBe(20000)->and($cart['tax_total']['amount'])->toBe(3000)
        ->and($cart['items'][0]['tax_amount']['amount'])->toBe(3000)
        ->and($cart['grand_total']['amount'])->toBe(20000 + 3000 + 6000);

    $cart = prmCart($this, $plain, 1)['data'];
    expect($cart['tax_total']['amount'])->toBe(500)->and($cart['grand_total']['amount'])->toBe(10000 + 500 + 6000);

    // Order snapshots keep the rate and tax.
    $token = prmCart($this, $taxed, 1)['token'];
    $order = prmCheckout($this, $token)->assertCreated()
        ->assertJsonPath('data.tax_total.amount', 1500)->assertJsonPath('data.items.0.tax_rate_bp', 1500)->json('data');
    $vat->update(['rate_bp' => 9999]);
    expect(Order::findOrFail($order['id'])->items()->first()->tax_rate_bp)->toBe(1500);
});

test('rounding: tax rounds half up per line', function () {
    TaxRate::create(['name' => 'Odd', 'rate_bp' => 1050, 'is_default' => true]);

    // 999 * 10.5% = 104.895 -> 105
    $cart = prmCart($this, prmProduct(999))['data'];
    expect($cart['tax_total']['amount'])->toBe(105);
});

test('coupon admin: crud, validation, uppercase code, redeemed coupon cannot be deleted', function () {
    actingAsRole('ops', PRM_ALL);

    $id = $this->postJson('/api/v1/coupons', ['code' => 'save10', 'type' => 'percent', 'value' => 1000, 'max_discount' => 5000])
        ->assertCreated()->assertJsonPath('data.code', 'SAVE10')->assertJsonPath('data.max_discount.amount', 5000)->assertJsonPath('data.used_count', 0)->json('data.id');
    $this->postJson('/api/v1/coupons', ['code' => 'SAVE10', 'type' => 'fixed', 'value' => 5])->assertUnprocessable()->assertInvalidFields(['code']);
    $this->postJson('/api/v1/coupons', ['code' => 'BIG', 'type' => 'percent', 'value' => 20000])->assertUnprocessable()->assertInvalidFields(['value']);
    $this->postJson('/api/v1/coupons', ['code' => 'bad code', 'type' => 'weird'])->assertUnprocessable()->assertInvalidFields(['code', 'type', 'value']);
    $this->patchJson("/api/v1/coupons/{$id}", ['used_count' => 99, 'usage_limit' => 5])->assertOk()->assertJsonPath('data.used_count', 0)->assertJsonPath('data.usage_limit', 5);
    $this->getJson('/api/v1/coupons?q=save')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/coupons/{$id}")->assertOk();

    $token = prmCart($this, prmProduct(), 1)['token'];
    prmCheckout($this, $token, ['coupon_code' => 'SAVE10'])->assertCreated();
    $this->deleteJson("/api/v1/coupons/{$id}")->assertUnprocessable()->assertInvalidFields(['coupon']);

    $other = Coupon::create(['code' => 'UNUSED', 'type' => 'fixed', 'value' => 100]);
    $this->deleteJson("/api/v1/coupons/{$other->id}")->assertNoContent();
});

test('coupon discount: percent with cap, fixed, minimum, and tax on the discounted amount', function () {
    TaxRate::create(['name' => 'VAT', 'rate_bp' => 1000, 'is_default' => true]);
    Coupon::create(['code' => 'PCT', 'type' => 'percent', 'value' => 2000, 'max_discount' => 1500]);
    Coupon::create(['code' => 'FIX', 'type' => 'fixed', 'value' => 3000, 'min_subtotal' => 5000]);

    $product = prmProduct(10000);

    // 20% of 10000 = 2000, capped at 1500; tax = 10% of 8500 = 850
    $cart = prmCart($this, $product, 1, '?coupon_code=pct')['data'];
    expect($cart['coupon_code'])->toBe('PCT')->and($cart['discount_total']['amount'])->toBe(1500)->and($cart['tax_total']['amount'])->toBe(850)
        ->and($cart['grand_total']['amount'])->toBe(10000 - 1500 + 850 + 6000)->and($cart['coupon_error'])->toBeNull();

    $cart = prmCart($this, $product, 1, '?coupon_code=FIX')['data'];
    expect($cart['discount_total']['amount'])->toBe(3000);

    // The discount never exceeds the subtotal.
    Coupon::create(['code' => 'HUGE', 'type' => 'fixed', 'value' => 999999]);
    expect(prmCart($this, $product, 1, '?coupon_code=HUGE')['data']['discount_total']['amount'])->toBe(10000);

    // Cart preview reports why a code does not apply instead of failing.
    $cart = prmCart($this, prmProduct(1000), 1, '?coupon_code=FIX')['data'];
    expect($cart['discount_total']['amount'])->toBe(0)->and($cart['coupon_error'])->toContain('minimum');
    expect(prmCart($this, $product, 1, '?coupon_code=NOPE')['data']['coupon_error'])->toContain('not valid');
});

test('coupon discount is spread across lines and the order stores it', function () {
    Coupon::create(['code' => 'TEN', 'type' => 'fixed', 'value' => 1000]);
    $a = prmProduct(3000);
    $b = prmProduct(7000);
    $token = $this->postJson('/api/v1/public/carts')->json('data.token');
    foreach ([$a, $b] as $p) {
        $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $p->variants()->first()->id, 'quantity' => 1]);
    }

    $order = prmCheckout($this, $token, ['coupon_code' => 'ten'])->assertCreated()
        ->assertJsonPath('data.discount_total.amount', 1000)->assertJsonPath('data.coupon_code', 'TEN')->json('data');
    $shares = array_map(fn ($i) => $i['discount_amount']['amount'], $order['items']);
    expect(array_sum($shares))->toBe(1000)->and($shares)->toBe([300, 700]);
});

test('coupon rules: inactive, window, usage limit, checkout rejects bad code and counts use', function () {
    Coupon::create(['code' => 'OFF', 'type' => 'fixed', 'value' => 100, 'is_active' => false]);
    Coupon::create(['code' => 'FUTURE', 'type' => 'fixed', 'value' => 100, 'starts_at' => now()->addDay()]);
    Coupon::create(['code' => 'PAST', 'type' => 'fixed', 'value' => 100, 'ends_at' => now()->subDay()]);
    $once = Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 100, 'usage_limit' => 1]);
    $product = prmProduct();

    foreach (['OFF', 'FUTURE', 'PAST', 'MISSING'] as $code) {
        $token = prmCart($this, $product)['token'];
        prmCheckout($this, $token, ['coupon_code' => $code])->assertUnprocessable()->assertInvalidFields(['coupon_code']);
    }
    expect(Order::count())->toBe(0);

    $token = prmCart($this, $product)['token'];
    prmCheckout($this, $token, ['coupon_code' => 'ONCE'])->assertCreated();
    expect($once->fresh()->used_count)->toBe(1);

    $token = prmCart($this, $product)['token'];
    prmCheckout($this, $token, ['coupon_code' => 'ONCE'])->assertUnprocessable()->assertInvalidFields(['coupon_code']);
    expect(Order::count())->toBe(1);
});

test('cancelling an order gives the coupon use back', function () {
    $coupon = Coupon::create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 100, 'usage_limit' => 1]);
    $id = prmCheckout($this, prmCart($this, prmProduct())['token'], ['coupon_code' => 'ONCE'])->assertCreated()->json('data.id');
    expect($coupon->fresh()->used_count)->toBe(1);

    actingAsRole('ops', PRM_ALL);
    $this->postJson("/api/v1/orders/{$id}/cancel")->assertOk();
    expect($coupon->fresh()->used_count)->toBe(0);

    prmCheckout($this, prmCart($this, prmProduct())['token'], ['coupon_code' => 'ONCE'])->assertCreated();
    expect($coupon->fresh()->used_count)->toBe(1);
});

test('wishlist needs the customer guard, is idempotent and lists only sold products', function () {
    $this->getJson('/api/v1/customer/wishlist')->assertUnauthorized();

    $customer = Customer::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass-1']);
    Passport::actingAs($customer, [], 'customer');
    $product = prmProduct();
    $draft = catalogProduct([], [['sku' => 'DRAFT-1', 'price' => 100]]);

    $this->putJson("/api/v1/customer/wishlist/{$product->id}")->assertNoContent();
    $this->putJson("/api/v1/customer/wishlist/{$product->id}")->assertNoContent();
    $this->putJson("/api/v1/customer/wishlist/{$draft->id}")->assertNotFound();
    $this->getJson('/api/v1/customer/wishlist')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product->id);

    // Another customer's list is separate.
    Passport::actingAs(Customer::create(['name' => 'B', 'email' => 'b@x.test', 'password' => 'secret-pass-1']), [], 'customer');
    $this->getJson('/api/v1/customer/wishlist')->assertOk()->assertJsonCount(0, 'data');

    Passport::actingAs($customer, [], 'customer');
    $this->deleteJson("/api/v1/customer/wishlist/{$product->id}")->assertNoContent();
    $this->getJson('/api/v1/customer/wishlist')->assertOk()->assertJsonCount(0, 'data');
});

test('reviews: only after delivery, one per product, moderated before public', function () {
    $customer = Customer::create(['name' => 'Mim', 'email' => 'mim@x.test', 'password' => 'secret-pass-1']);
    $product = prmProduct();
    $slug = $product->slug;

    $this->postJson("/api/v1/customer/products/{$slug}/reviews", ['rating' => 5, 'body' => 'Great'])->assertUnauthorized();
    Passport::actingAs($customer, [], 'customer');
    $this->postJson("/api/v1/customer/products/{$slug}/reviews", ['rating' => 5, 'body' => 'Great'])->assertUnprocessable()->assertInvalidFields(['product']);

    // Buy it and get it delivered.
    $token = prmCart($this, $product)['token'];
    $orderId = prmCheckout($this, $token)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/customer/products/{$slug}/reviews", ['rating' => 5, 'body' => 'Early'])->assertUnprocessable()->assertInvalidFields(['product']);

    $ops = actingAsRole('ops', PRM_ALL);
    $courier = Courier::create(['name' => 'Pathao']);
    $this->postJson("/api/v1/orders/{$orderId}/advance")->assertOk();
    $this->postJson("/api/v1/orders/{$orderId}/advance")->assertOk();
    Order::findOrFail($orderId)->delivery()->forceCreate(['order_id' => $orderId, 'courier_id' => $courier->id, 'cod_amount' => 0]);
    $this->postJson("/api/v1/orders/{$orderId}/advance")->assertOk();
    $this->postJson("/api/v1/orders/{$orderId}/advance")->assertOk()->assertJsonPath('data.status', 'delivered');

    Passport::actingAs($customer, [], 'customer');
    $this->postJson("/api/v1/customer/products/{$slug}/reviews", ['rating' => 9])->assertUnprocessable()->assertInvalidFields(['rating', 'body']);
    $reviewId = $this->postJson("/api/v1/customer/products/{$slug}/reviews", ['rating' => 4, 'title' => 'Good', 'body' => 'Works well'])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
    $this->postJson("/api/v1/customer/products/{$slug}/reviews", ['rating' => 1, 'body' => 'Again'])->assertUnprocessable()->assertInvalidFields(['product']);

    // Pending is not public.
    $this->getJson("/api/v1/public/products/{$slug}/reviews")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.count', 0);

    Passport::actingAs($ops, [], 'api');
    $this->getJson('/api/v1/reviews?status=pending')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/reviews/{$reviewId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $this->getJson("/api/v1/public/products/{$slug}/reviews")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.author', 'Mim')->assertJsonPath('summary.count', 1)->assertJsonPath('summary.average', 4)
        ->assertJsonMissingPath('data.0.customer_id');

    $this->postJson("/api/v1/reviews/{$reviewId}/reject")->assertOk();
    $this->getJson("/api/v1/public/products/{$slug}/reviews")->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/reviews/{$reviewId}")->assertNoContent();
});

test('promotions seeder is idempotent', function () {
    $this->seed(PromotionsModuleSeeder::class);

    expect(Permission::where('name', 'coupons.view')->count())->toBe(1);
});
