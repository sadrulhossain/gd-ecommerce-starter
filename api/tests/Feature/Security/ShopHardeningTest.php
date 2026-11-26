<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use Database\Seeders\CustomerClientSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

beforeEach(function () {
    $this->seed([PaymentMethodsSeeder::class, CustomerClientSeeder::class]);
});

/** An active variant whose stock came in through the ledger, as real stock does. */
function stockedVariant(int $stock = 5)
{
    $product = catalogProduct(['status' => Product::ACTIVE], [['sku' => 'H-'.uniqid(), 'price' => 10000]]);
    $variant = $product->variants()->firstOrFail();
    app(StockService::class)->apply($variant->id, StockMovement::ADJUSTMENT, $stock, $product);

    return $variant;
}

function filledCart($test, int $variantId, int $quantity): string
{
    $token = $test->postJson('/api/v1/public/carts')->json('data.token');
    $test->postJson("/api/v1/public/carts/{$token}/items", ['variant_id' => $variantId, 'quantity' => $quantity])->assertOk();

    return $token;
}

function buyer(): array
{
    return ['contact_name' => 'Rafi', 'contact_phone' => '01700000000', 'address_line' => 'Road 2', 'city' => 'Dhaka', 'payment_method' => 'cod'];
}

test('pending orders reserve stock so a second buyer cannot take the same units', function () {
    $variant = stockedVariant(3);
    $first = filledCart($this, $variant->id, 2);
    $second = filledCart($this, $variant->id, 2);

    $this->postJson("/api/v1/public/carts/{$first}/checkout", buyer())->assertCreated();
    $this->postJson("/api/v1/public/carts/{$second}/checkout", buyer())->assertUnprocessable()->assertInvalidFields(['items.0']);
    $this->getJson("/api/v1/public/carts/{$second}")->assertJsonPath('data.items.0.issue', 'Only 1 in stock.')->assertJsonPath('data.can_checkout', false);

    expect(Order::query()->count())->toBe(1)->and(StockLevel::query()->whereKey($variant->id)->value('on_hand'))->toBe(3);

    // Cancelling the pending order frees the units.
    actingAsRole('clerk', ['orders.cancel']);
    $this->postJson('/api/v1/orders/'.Order::query()->value('id').'/cancel', ['reason' => 'test'])->assertOk();
    $this->postJson("/api/v1/public/carts/{$second}/checkout", buyer())->assertCreated();
});

test('a cart cannot be checked out twice at the same time', function () {
    $token = filledCart($this, stockedVariant()->id, 1);
    Cache::lock('checkout:'.hash('sha256', $token), 60)->get();

    $this->postJson("/api/v1/public/carts/{$token}/checkout", buyer())->assertUnprocessable()->assertInvalidFields(['items']);
    expect(Order::query()->count())->toBe(0);
});

test('checkout ignores client prices and totals', function () {
    $variant = stockedVariant();
    $token = filledCart($this, $variant->id, 2);

    $this->postJson("/api/v1/public/carts/{$token}/checkout", buyer() + [
        'grand_total' => 1, 'subtotal' => 1, 'shipping_total' => 0, 'discount_total' => 99999, 'payment_status' => 'paid', 'status' => 'delivered',
        'items' => [['variant_id' => $variant->id, 'unit_price' => 1]],
    ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.payment_status', 'pending')
        ->assertJsonPath('data.subtotal.amount', 20000)->assertJsonPath('data.grand_total.amount', 26000);
});

test('money changes are audited with the actor, and the log is append-only', function () {
    $variant = stockedVariant();
    $token = filledCart($this, $variant->id, 1);
    $this->postJson("/api/v1/public/carts/{$token}/checkout", buyer())->assertCreated();
    $order = Order::query()->firstOrFail();

    $user = actingAsRole('manager', ['orders.update', 'products.update']);
    $this->postJson("/api/v1/orders/{$order->id}/advance")->assertOk();
    $this->patchJson("/api/v1/variants/{$variant->id}", ['price' => 12000])->assertOk();

    $price = AuditLog::query()->where('auditable_type', 'product_variants')->where('event', 'updated')->firstOrFail();
    expect($price->changes['price'])->toEqual(['old' => 10000, 'new' => 12000])
        ->and($price->actor_type)->toBe('user')->and($price->actor_id)->toBe($user->id);
    $statusChange = AuditLog::query()->where('auditable_type', 'orders')->where('event', 'updated')->get()->pluck('changes.status')->filter()->first();
    expect($statusChange)
        ->toEqual(['old' => 'pending', 'new' => 'confirmed']);
    // Stock changes are audited in the ledger itself, with the same actor.
    expect(StockMovement::query()->where('type', StockMovement::SALE)->value('created_by'))->toBe($user->id);

    expect(fn () => DB::transaction(fn () => DB::table('audit_logs')->delete()))->toThrow(QueryException::class);
});

test('gateway credentials are encrypted at rest and never reach the audit log', function () {
    $method = PaymentMethod::query()->where('code', 'cod')->firstOrFail();
    $method->update(['credentials' => ['api_key' => 'sk-super-secret']]);

    expect(DB::table('payment_methods')->where('id', $method->id)->value('credentials'))->not->toContain('sk-super-secret');
    $log = AuditLog::query()->where('auditable_type', 'payment_methods')->where('event', 'updated')->firstOrFail();
    expect($log->changes['credentials'])->toBe('[changed]')->and(json_encode(AuditLog::query()->get()))->not->toContain('sk-super-secret');
});

test('a customer cannot read or cancel another customer\'s order', function () {
    $owner = Customer::query()->create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'password123']);
    $other = Customer::query()->create(['name' => 'B', 'email' => 'b@example.com', 'password' => 'password123']);
    $token = filledCart($this, stockedVariant()->id, 1);
    Passport::actingAs($owner, [], 'customer');
    $number = $this->postJson("/api/v1/public/carts/{$token}/checkout", buyer())->assertCreated()->json('data.number');

    Passport::actingAs($other, [], 'customer');
    $this->getJson("/api/v1/customer/orders/{$number}")->assertNotFound();
    $this->postJson("/api/v1/customer/orders/{$number}/cancel")->assertNotFound();
    $this->getJson("/api/v1/public/orders/{$number}?token=")->assertNotFound();
    expect(Order::query()->value('status'))->toBe('pending');
});

test('customer tokens expire after a week', function () {
    $this->postJson('/api/v1/customer/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'password123'])->assertCreated();

    $expires = now()->parse(DB::table('oauth_access_tokens')->value('expires_at'));
    expect($expires->between(now()->addDays(6), now()->addDays(8)))->toBeTrue();
});

test('cart, checkout, tracking and account routes are rate limited', function () {
    $this->getJson('/api/v1/public/orders/ORD-000001?token=x')->assertHeader('X-RateLimit-Limit', 60);
    $this->postJson('/api/v1/public/carts')->assertHeader('X-RateLimit-Limit', 60);
    $this->postJson('/api/v1/public/carts/none/checkout', buyer())->assertHeader('X-RateLimit-Limit', 10);
    $this->postJson('/api/v1/customer/login', ['email' => 'a@example.com', 'password' => 'wrong'])->assertHeader('X-RateLimit-Limit', 10);

    Passport::actingAs(Customer::query()->create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'password123']), [], 'customer');
    $this->getJson('/api/v1/customer/orders')->assertHeader('X-RateLimit-Limit', 60);
});

test('stock:verify passes after real documents and reports any tampering', function () {
    $variant = stockedVariant(5);
    $token = filledCart($this, $variant->id, 2);
    $this->postJson("/api/v1/public/carts/{$token}/checkout", buyer())->assertCreated();

    actingAsRole('manager', ['orders.update', 'orders.cancel', 'purchase-orders.create', 'purchase-orders.order', 'goods-receipts.create', 'goods-receipts.post']);
    $order = Order::query()->firstOrFail();
    $this->postJson("/api/v1/orders/{$order->id}/advance")->assertOk();
    $this->postJson("/api/v1/orders/{$order->id}/cancel", ['reason' => 'test'])->assertOk();

    $supplier = Supplier::query()->create(['code' => 'ACME', 'name' => 'Acme']);
    $po = $this->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier->id, 'lines' => [['variant_id' => $variant->id, 'quantity_ordered' => 10, 'unit_cost' => 500]]])
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/purchase-orders/{$po['id']}/order")->assertOk();
    $line = PurchaseOrder::query()->findOrFail($po['id'])->lines()->firstOrFail();
    $receipt = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po['id'], 'lines' => [['purchase_order_line_id' => $line->id, 'quantity' => 4]]])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/goods-receipts/{$receipt}/post")->assertOk();

    $this->artisan('stock:verify')->expectsOutputToContain('stock:verify: ok')->assertSuccessful();

    DB::table('stock_levels')->where('variant_id', $variant->id)->update(['on_hand' => 99]);
    $this->artisan('stock:verify')->expectsOutputToContain('stock level differs from the ledger sum')->assertFailed();
    DB::table('stock_levels')->where('variant_id', $variant->id)->update(['on_hand' => 9]);

    DB::table('purchase_order_lines')->where('id', $line->id)->update(['quantity_received' => 10]);
    $this->artisan('stock:verify')->expectsOutputToContain('purchase order line differs')->expectsOutputToContain('purchase order status differs')->assertFailed();
});
