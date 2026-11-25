<?php

use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Payments\Drivers\CodProvider;
use App\Payments\PaymentManager;
use App\Payments\PaymentProvider;
use App\Payments\PaymentRequest;
use App\Payments\PaymentResult;
use Database\Seeders\PaymentMethodsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(PaymentMethodsSeeder::class));

test('seeder is idempotent and keeps edits', function () {
    PaymentMethod::where('code', 'cod')->update(['name' => 'Pay at door']);
    $this->seed(PaymentMethodsSeeder::class);

    expect(PaymentMethod::count())->toBe(1)
        ->and(PaymentMethod::first()->name)->toBe('Pay at door');
});

test('every registered driver implements PaymentProvider', function () {
    $manager = app(PaymentManager::class);

    foreach ($manager->codes() as $code) {
        expect($manager->driver($code))->toBeInstanceOf(PaymentProvider::class)
            ->and($manager->driver($code)->code())->toBe($code);
    }
    expect($manager->driver('cod'))->toBeInstanceOf(CodProvider::class);
});

test('cod initiate is pending and idempotent', function () {
    $manager = app(PaymentManager::class);

    $a = $manager->initiate('cod', 'order-1-init', 'order', 1, 125000);
    $b = $manager->initiate('cod', 'order-1-init', 'order', 1, 125000);

    expect($a->status)->toBe('pending')->and($a->amount)->toBe(125000)->and($a->currency)->toBe('BDT')
        ->and($b->id)->toBe($a->id)
        ->and(PaymentEvent::count())->toBe(1);
});

test('refund is recorded under its own key', function () {
    $manager = app(PaymentManager::class);
    $manager->initiate('cod', 'k1', 'order', 1, 500);
    $refund = $manager->refund('cod', 'k1-refund', 'order', 1, 500);

    expect($refund->status)->toBe('refunded')->and($refund->action)->toBe('refund')
        ->and(PaymentEvent::count())->toBe(2);
});

test('unknown and disabled methods are rejected', function () {
    $manager = app(PaymentManager::class);

    expect(fn () => $manager->initiate('nope', 'k2', 'order', 1, 100))->toThrow(ValidationException::class);

    PaymentMethod::where('code', 'cod')->update(['is_enabled' => false]);
    expect(fn () => $manager->initiate('cod', 'k3', 'order', 1, 100))->toThrow(ValidationException::class);
    expect(PaymentEvent::count())->toBe(0);
});

test('a new driver needs only a class and a registry entry', function () {
    $driver = new class implements PaymentProvider
    {
        public function code(): string
        {
            return 'fake';
        }

        public function initiate(PaymentRequest $request): PaymentResult
        {
            return new PaymentResult(PaymentResult::PAID, 'ref-'.$request->credentials['token']);
        }

        public function refund(PaymentRequest $request): PaymentResult
        {
            return new PaymentResult(PaymentResult::REFUNDED);
        }
    };
    app()->instance('fake-driver', $driver);
    config(['payments.drivers.fake' => 'fake-driver']);
    PaymentMethod::create(['code' => 'fake', 'name' => 'Fake', 'credentials' => ['token' => 'secret-token']]);

    $event = app(PaymentManager::class)->initiate('fake', 'k4', 'order', 2, 900);

    expect($event->status)->toBe('paid')->and($event->provider_reference)->toBe('ref-secret-token');
});

test('credentials are encrypted at rest and hidden', function () {
    $method = PaymentMethod::create(['code' => 'fake', 'name' => 'Fake', 'credentials' => ['token' => 'secret-token']]);

    $raw = DB::table('payment_methods')->where('id', $method->id)->value('credentials');

    expect($raw)->not->toContain('secret-token')
        ->and($method->fresh()->credentials)->toBe(['token' => 'secret-token'])
        ->and($method->fresh()->toArray())->not->toHaveKey('credentials');
});
