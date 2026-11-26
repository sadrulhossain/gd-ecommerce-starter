<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\CodRemittance;
use App\Models\Coupon;
use App\Models\Delivery;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\PaymentEvent;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an audit_logs row, in the caller's transaction, for every change to a money field. Only model events are
 * seen: change these fields through Eloquent models, never with a bulk query update.
 */
class AuditObserver
{
    /** model => the attributes whose changes are recorded */
    public const AUDITED = [
        ProductVariant::class => ['price', 'compare_at_price', 'cost_price'],
        Order::class => ['status', 'payment_status', 'grand_total'],
        PaymentEvent::class => ['action', 'payment_method', 'status', 'amount'],
        PaymentMethod::class => ['code', 'is_enabled', 'credentials'],
        Delivery::class => ['courier_id', 'cod_amount', 'collected_amount'],
        CodRemittance::class => ['status', 'expected_amount', 'received_amount', 'difference'],
        Coupon::class => ['type', 'value', 'min_subtotal', 'max_discount', 'usage_limit', 'is_active'],
        TaxRate::class => ['rate_bp', 'is_default', 'is_active'],
        DeliveryZone::class => ['shipping_fee', 'is_active'],
    ];

    /** Recorded as changed, never with a value. */
    private const SECRET = ['credentials'];

    public function created(Model $model): void
    {
        $this->record($model, 'created', array_keys($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated', array_keys($model->getChanges()));
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', array_keys($model->getAttributes()));
    }

    /** @param  list<string>  $touched */
    private function record(Model $model, string $event, array $touched): void
    {
        $changes = [];
        foreach (array_intersect(self::AUDITED[$model::class] ?? [], $touched) as $field) {
            $changes[$field] = in_array($field, self::SECRET, true) ? '[changed]' : [
                'old' => $event === 'created' ? null : $model->getRawOriginal($field),
                'new' => $event === 'deleted' ? null : ($model->getAttributes()[$field] ?? null),
            ];
        }
        if ($changes === []) {
            return;
        }

        // An admin, a signed-in customer, or nobody (guest checkout, console, queue).
        [$type, $guard] = match (true) {
            Auth::guard('api')->hasUser() => ['user', 'api'],
            Auth::guard('customer')->hasUser() => ['customer', 'customer'],
            default => [null, null],
        };

        (new AuditLog)->forceFill([
            'auditable_type' => $model->getTable(),
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'changes' => $changes,
            'actor_type' => $type,
            'actor_id' => $guard === null ? null : Auth::guard($guard)->id(),
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ])->save();
    }
}
