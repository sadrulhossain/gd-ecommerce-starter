<?php

use App\Models\Courier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Database\Seeders\CustomerClientSeeder;
use Database\Seeders\OrdersModuleSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Laravel\Passport\Passport;

const ORD_ALL = ['orders.view', 'orders.update', 'orders.cancel'];

beforeEach(function () {
    $this->seed([PaymentMethodsSeeder::class, OrdersModuleSeeder::class, CustomerClientSeeder::class]);
});

/** An active, in-stock variant. */
function shopVariant(int $price = 10000, int $stock = 10): ProductVariant
{
    $product = catalogProduct(['status' => Product::ACTIVE], [['sku' => 'S-'.uniqid(), 'price' => $price]]);
    $product->category->update(['is_active' => true]);
    $variant = $product->variants()->firstOrFail();
    if ($stock > 0) {
        StockLevel::query()->insertOrIgnore(['variant_id' => $variant->id, 'on_hand' => $stock]);
    }

    return $variant;
}

function newCart($test): string
{
    return $test->postJson('/api/v1/public/carts')->assertCreated()->json('data.token');
}

function checkoutBody(array $over = []): array
{
    return $over + [
        'contact_name' => 'Rafi', 'contact_phone' => '01700000000', 'address_line' => 'House 1, Road 2',
        'city' => 'Dhaka', 'payment_method' => 'cod',
    ];
}

function placeOrder($test, ProductVariant $variant, int $qty = 2): array
{
    $token = newCart($test);
    $test->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => $qty])->assertOk();

    return $test->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody())->assertCreated()->json();
}

test('cart prices come from the server and include shipping', function () {
    $variant = shopVariant(25000);
    $token = newCart($this);

    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 2, 'price' => 1])
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price.amount', 25000)
        ->assertJsonPath('data.subtotal.amount', 50000)
        ->assertJsonPath('data.shipping_total.amount', 6000)
        ->assertJsonPath('data.grand_total.amount', 56000)
        ->assertJsonPath('data.grand_total.currency', 'BDT');

    $this->patchJson("/api/v1/public/carts/{$token}/items/{$variant->id}", ['quantity' => 3])->assertOk()->assertJsonPath('data.items.0.quantity', 3);
    $this->patchJson("/api/v1/public/carts/{$token}/items/{$variant->id}", ['quantity' => 0])->assertOk()->assertJsonPath('data.items', []);
});

test('unknown cart is 404 and bad lines are rejected', function () {
    $this->getJson('/api/v1/public/carts/nope')->assertNotFound();

    $token = newCart($this);
    $draft = catalogProduct([], [['sku' => 'D-1', 'price' => 100]])->variants()->firstOrFail();
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $draft->id, 'quantity' => 1])->assertUnprocessable()->assertInvalidFields(['quantity']);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => 999999, 'quantity' => 1])->assertUnprocessable()->assertInvalidFields(['variant_id']);

    $short = shopVariant(100, 1);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $short->id, 'quantity' => 2])->assertUnprocessable()->assertInvalidFields(['quantity']);
});

test('guest checkout makes a pending order with snapshots and an empty cart', function () {
    $variant = shopVariant(10000);
    $token = newCart($this);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 2]);

    $response = $this->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody())->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.payment_status', 'pending')
        ->assertJsonPath('data.grand_total.amount', 26000)
        ->assertJsonPath('data.items.0.line_total.amount', 20000);

    expect($response->json('data.number'))->toMatch('/^ORD-\d{6}$/')->and($response->json('lookup_token'))->toBeString();

    $variant->update(['price' => 99999]);
    $order = Order::firstOrFail();
    expect($order->items()->first()->unit_price)->toBe(10000)->and($order->customer_id)->toBeNull();

    $this->getJson("/api/v1/public/carts/{$token}")->assertOk()->assertJsonPath('data.items', []);
    $this->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody())->assertUnprocessable()->assertInvalidFields(['items']);
});

test('checkout validates the fields and the payment method', function () {
    $variant = shopVariant();
    $token = newCart($this);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 1]);

    $this->postJson("/api/v1/public/carts/{$token}/checkout", [])->assertUnprocessable()
        ->assertInvalidFields(['contact_name', 'contact_phone', 'address_line', 'city', 'payment_method']);
    $this->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody(['payment_method' => 'bkash']))->assertUnprocessable()->assertInvalidFields(['payment_method']);
    expect(Order::count())->toBe(0);
});

test('stock changed since the cart was filled blocks checkout', function () {
    $variant = shopVariant(100, 5);
    $token = newCart($this);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 4]);
    StockLevel::where('variant_id', $variant->id)->update(['on_hand' => 1]);

    $this->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody())->assertUnprocessable();
    expect(Order::count())->toBe(0);
});

test('guest lookup needs the token', function () {
    $placed = placeOrder($this, shopVariant());
    $number = $placed['data']['number'];

    $this->getJson("/api/v1/public/orders/{$number}?token={$placed['lookup_token']}")->assertOk()->assertJsonPath('data.number', $number);
    $this->getJson("/api/v1/public/orders/{$number}?token=wrong")->assertNotFound();
    $this->getJson("/api/v1/public/orders/{$number}")->assertNotFound();
});

test('customer register, login, me and own orders on the customer guard', function () {
    $this->postJson('/api/v1/customer/register', ['name' => 'Mim', 'email' => 'mim@example.com', 'password' => 'secret-pass-1'])
        ->assertCreated()->assertJsonStructure(['data' => ['id', 'email'], 'token'])->assertJsonMissingPath('data.password');
    $this->postJson('/api/v1/customer/register', ['name' => 'Mim', 'email' => 'mim@example.com', 'password' => 'secret-pass-1'])
        ->assertUnprocessable()->assertInvalidFields(['email']);
    $this->postJson('/api/v1/customer/login', ['email' => 'mim@example.com', 'password' => 'bad'])->assertUnprocessable();

    $token = $this->postJson('/api/v1/customer/login', ['email' => 'mim@example.com', 'password' => 'secret-pass-1'])->assertOk()->json('token');
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/customer/me')->assertOk()->assertJsonPath('data.email', 'mim@example.com');
    // A customer token is not an admin token.
    $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/me')->assertUnauthorized();
    $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/customer/logout')->assertNoContent();
});

test('customer routes need the customer guard and show only own orders', function () {
    $this->getJson('/api/v1/customer/orders')->assertUnauthorized();

    $mine = Customer::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-pass-1']);
    $other = Customer::create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'secret-pass-1']);
    $variant = shopVariant();

    Passport::actingAs($mine, [], 'customer');
    $token = newCart($this);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 1]);
    $number = $this->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody())->assertCreated()->json('data.number');
    expect(Order::firstOrFail()->customer_id)->toBe($mine->id);

    $this->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/customer/orders/{$number}")->assertOk();

    Passport::actingAs($other, [], 'customer');
    $this->getJson('/api/v1/customer/orders')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/customer/orders/{$number}")->assertNotFound();

    Passport::actingAs($mine, [], 'customer');
    $this->postJson("/api/v1/customer/orders/{$number}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

test('admin orders need permissions', function () {
    $placed = placeOrder($this, shopVariant());
    $id = $placed['data']['id'];

    $this->getJson('/api/v1/orders')->assertUnauthorized();
    actingAsRole('nobody');
    $this->getJson('/api/v1/orders')->assertForbidden();
    $this->getJson("/api/v1/orders/{$id}")->assertForbidden();
    $this->postJson("/api/v1/orders/{$id}/advance")->assertForbidden();
    $this->postJson("/api/v1/orders/{$id}/cancel")->assertForbidden();

    actingAsRole('viewer', ['orders.view']);
    $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/orders?status=shipped')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('data.items.0.quantity', 2);
    $this->postJson("/api/v1/orders/{$id}/advance")->assertForbidden();
});

test('lifecycle: confirm takes stock, delivered is final', function () {
    $variant = shopVariant(10000, 10);
    $id = placeOrder($this, $variant, 3)['data']['id'];
    actingAsRole('ops', ORD_ALL);

    expect(StockLevel::find($variant->id)->on_hand)->toBe(10);

    $this->postJson("/api/v1/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', 'confirmed');
    expect(StockLevel::find($variant->id)->on_hand)->toBe(7);
    $movement = StockMovement::where('type', 'sale')->firstOrFail();
    expect($movement->quantity)->toBe(-3)->and($movement->reference_type)->toBe('orders')->and($movement->reference_id)->toBe($id);

    $this->postJson("/api/v1/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', 'packed');
    $this->postJson("/api/v1/orders/{$id}/advance")->assertUnprocessable()->assertInvalidFields(['delivery']);
    Order::whereKey($id)->firstOrFail()->delivery()->forceCreate([
        'order_id' => $id, 'courier_id' => Courier::create(['name' => 'Pathao'])->id, 'cod_amount' => 26000,
    ]);
    foreach (['shipped', 'delivered'] as $status) {
        $this->postJson("/api/v1/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', $status);
    }
    $this->postJson("/api/v1/orders/{$id}/advance")->assertUnprocessable()->assertInvalidFields(['status']);
    $this->postJson("/api/v1/orders/{$id}/cancel")->assertUnprocessable()->assertInvalidFields(['status']);
    expect(StockLevel::find($variant->id)->on_hand)->toBe(7);
});

test('cancel returns stock only after confirm', function () {
    $variant = shopVariant(10000, 10);
    actingAsRole('ops', ORD_ALL);

    $pending = placeOrder($this, $variant, 2)['data']['id'];
    $this->postJson("/api/v1/orders/{$pending}/cancel", ['reason' => 'Changed mind'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Changed mind');
    expect(StockLevel::find($variant->id)->on_hand)->toBe(10)->and(StockMovement::count())->toBe(0);

    $confirmed = placeOrder($this, $variant, 4)['data']['id'];
    $this->postJson("/api/v1/orders/{$confirmed}/advance")->assertOk();
    expect(StockLevel::find($variant->id)->on_hand)->toBe(6);
    $this->postJson("/api/v1/orders/{$confirmed}/cancel")->assertOk();
    expect(StockLevel::find($variant->id)->on_hand)->toBe(10)
        ->and(StockMovement::where('type', 'sale_cancel')->firstOrFail()->quantity)->toBe(4);
});

test('confirm fails when stock is short and changes nothing', function () {
    $variant = shopVariant(10000, 5);
    $id = placeOrder($this, $variant, 5)['data']['id'];
    StockLevel::where('variant_id', $variant->id)->update(['on_hand' => 2]);
    actingAsRole('ops', ORD_ALL);

    $this->postJson("/api/v1/orders/{$id}/advance")->assertUnprocessable();
    expect(Order::find($id)->status)->toBe('pending')->and(StockMovement::count())->toBe(0);
});

test('orders seeder is idempotent', function () {
    $this->seed(OrdersModuleSeeder::class);
    $this->seed(CustomerClientSeeder::class);

    expect(Passport::client()->newQuery()->where('provider', 'customers')->count())->toBe(1);
});

test('browsing the cart does not use up the checkout throttle', function () {
    $variant = shopVariant();
    $token = newCart($this);
    $this->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variant->id, 'quantity' => 1]);

    foreach (range(1, 15) as $i) {
        $this->getJson("/api/v1/public/carts/{$token}")->assertOk();
    }
    $this->postJson("/api/v1/public/carts/{$token}/checkout", checkoutBody())->assertCreated();
});
