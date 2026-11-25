<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CodRemittance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A courier cash hand-over. Money is `{amount, currency}` in integer minor units.
 *
 * @mixin CodRemittance
 */
class CodRemittanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = (string) config('payments.currency');
        $money = fn (?int $amount): ?array => $amount === null ? null : ['amount' => $amount, 'currency' => $currency];

        return [
            'id' => $this->id,
            'number' => $this->number,
            'courier_id' => $this->courier_id,
            'status' => $this->status,
            'expected_amount' => $money($this->expected_amount),
            'received_amount' => $money($this->received_amount),
            'difference' => $money($this->difference),
            'reference' => $this->reference,
            'notes' => $this->notes,
            'posted_at' => $this->posted_at,
            'created_at' => $this->created_at,
            'deliveries' => $this->whenLoaded('deliveries', fn () => $this->deliveries->map(fn ($d) => [
                'id' => $d->id,
                'order_id' => $d->order_id,
                'tracking_number' => $d->tracking_number,
                'collected_amount' => $money($d->collected_amount),
            ])->all()),
        ];
    }
}
