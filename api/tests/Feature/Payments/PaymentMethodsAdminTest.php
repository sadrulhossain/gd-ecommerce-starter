<?php

use App\Models\Courier;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Payments\PaymentManager;
use Database\Seeders\PaymentMethodsModuleSeeder;
use Database\Seeders\PaymentMethodsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

const PM_ALL = ['payment-methods.view', 'payment-methods.create', 'payment-methods.update'];

beforeEach(function () {
    $this->seed([PaymentMethodsSeeder::class, PaymentMethodsModuleSeeder::class]);
    // A second registered driver, so adding a method has something to pick.
    config(['payments.drivers.fake' => 'fake-driver']);
});

it('requires authentication and the module permissions', function () {
    $cod = PaymentMethod::where('code', 'cod')->firstOrFail();

    $this->getJson('/api/v1/payment-methods')->assertUnauthorized();
    $this->postJson('/api/v1/payment-methods', [])->assertUnauthorized();

    actingAsRole('nobody');
    $this->getJson('/api/v1/payment-methods')->assertForbidden();
    $this->postJson('/api/v1/payment-methods', ['code' => 'fake', 'name' => 'Fake'])->assertForbidden();
    $this->patchJson("/api/v1/payment-methods/{$cod->id}", ['name' => 'X'])->assertForbidden();
});

it('lists methods with the registered drivers and never shows credentials', function () {
    PaymentMethod::where('code', 'cod')->firstOrFail()->update(['credentials' => ['token' => 'secret-token']]);
    actingAsRole('ops', PM_ALL);

    $response = $this->getJson('/api/v1/payment-methods')->assertOk()
        ->assertJsonPath('data.0.code', 'cod')->assertJsonPath('data.0.credential_keys', ['token'])->assertJsonPath('data.0.driver_registered', true)
        ->assertJsonPath('meta.drivers', ['cod', 'fake']);

    expect($response->getContent())->not->toContain('secret-token');
});

it('adds a method only for a registered, unused driver', function () {
    actingAsRole('ops', PM_ALL);

    $this->postJson('/api/v1/payment-methods', ['code' => 'nope', 'name' => 'Nope'])->assertUnprocessable()->assertInvalidFields(['code']);
    $this->postJson('/api/v1/payment-methods', ['code' => 'cod', 'name' => 'Again'])->assertUnprocessable()->assertInvalidFields(['code']);
    $this->postJson('/api/v1/payment-methods', ['code' => 'fake'])->assertUnprocessable()->assertInvalidFields(['name']);

    $this->postJson('/api/v1/payment-methods', ['code' => 'fake', 'name' => 'Fake pay', 'credentials' => ['api_key' => 'k-123']])
        ->assertCreated()->assertJsonPath('data.credential_keys', ['api_key'])->assertJsonPath('data.is_enabled', true);

    $raw = DB::table('payment_methods')->where('code', 'fake')->value('credentials');
    expect($raw)->not->toContain('k-123');
});

it('updates a method; credentials are kept, replaced or cleared; the code cannot change', function () {
    $method = PaymentMethod::create(['code' => 'fake', 'name' => 'Fake', 'credentials' => ['token' => 'one']]);
    actingAsRole('ops', PM_ALL);

    $this->patchJson("/api/v1/payment-methods/{$method->id}", ['name' => 'Renamed', 'is_enabled' => false, 'sort' => 5])->assertOk()
        ->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.is_enabled', false)->assertJsonPath('data.credential_keys', ['token']);
    expect($method->fresh()->credentials)->toBe(['token' => 'one']);

    $this->patchJson("/api/v1/payment-methods/{$method->id}", ['credentials' => ['secret' => 'two']])->assertOk()->assertJsonPath('data.credential_keys', ['secret']);
    expect($method->fresh()->credentials)->toBe(['secret' => 'two']);

    $this->patchJson("/api/v1/payment-methods/{$method->id}", ['credentials' => []])->assertOk()->assertJsonPath('data.credential_keys', []);
    $this->patchJson("/api/v1/payment-methods/{$method->id}", ['code' => 'cod'])->assertUnprocessable()->assertInvalidFields(['code']);
    $this->patchJson("/api/v1/payment-methods/{$method->id}", ['credentials' => ['bad key!' => 'x']])->assertUnprocessable()->assertInvalidFields(['credentials']);
});

it('a disabled method cannot be used at checkout', function () {
    PaymentMethod::where('code', 'cod')->firstOrFail()->update(['is_enabled' => false]);

    expect(app(PaymentManager::class)->codes())->toContain('cod');
    expect(fn () => app(PaymentManager::class)->initiate('cod', 'k-disabled', 'orders', 1, 100))
        ->toThrow(ValidationException::class);
});

it('lists deliveries, with a collectable filter, behind the remittance permission', function () {
    $courier = Courier::create(['name' => 'Pathao']);
    $make = function (bool $collected, ?int $remittance = null) use ($courier): Delivery {
        $order = new Order;
        $order->forceFill([
            'number' => Order::nextNumber(), 'lookup_token' => Str::random(40), 'payment_method' => 'cod',
            'subtotal' => 100, 'grand_total' => 100, 'contact_name' => 'A', 'contact_phone' => '1', 'address_line' => 'x', 'city' => 'y',
        ])->save();
        $delivery = new Delivery;
        $delivery->forceFill([
            'order_id' => $order->id, 'courier_id' => $courier->id, 'cod_amount' => 100,
            'collected_amount' => $collected ? 100 : null, 'collected_at' => $collected ? now() : null, 'remittance_id' => $remittance,
        ])->save();

        return $delivery;
    };
    $ready = $make(true);
    $make(false);

    $this->getJson('/api/v1/deliveries')->assertUnauthorized();
    actingAsRole('nobody');
    $this->getJson('/api/v1/deliveries')->assertForbidden();

    actingAsRole('ops', ['cod-remittances.view']);
    $this->getJson('/api/v1/deliveries')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/deliveries?collectable=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ready->id)->assertJsonPath('data.0.collected_amount.amount', 100);
    $this->getJson("/api/v1/deliveries?courier_id={$courier->id}&collectable=1")->assertOk()->assertJsonCount(1, 'data');
});

it('seeds payment-method permissions once', function () {
    $this->seed(PaymentMethodsModuleSeeder::class);

    expect(Permission::where('name', 'payment-methods.view')->count())->toBe(1);
});
