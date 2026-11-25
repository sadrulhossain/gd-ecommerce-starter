<?php

namespace App\Services;

use App\Models\CodRemittance;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Courier cash hand-overs: create a draft from collected deliveries, then post it with the cash actually received. */
class CodRemittanceService
{
    /**
     * @param  list<int>  $deliveryIds
     *
     * @throws ValidationException when a delivery is not this courier's, not collected, or already remitted
     */
    public function create(int $courierId, array $deliveryIds, ?string $reference, ?string $notes): CodRemittance
    {
        return DB::transaction(function () use ($courierId, $deliveryIds, $reference, $notes): CodRemittance {
            $deliveries = Delivery::query()->whereIn('id', $deliveryIds)->lockForUpdate()->get();

            $bad = $deliveries->count() !== count(array_unique($deliveryIds))
                || $deliveries->contains(fn (Delivery $d) => $d->courier_id !== $courierId || $d->collected_at === null || $d->remittance_id !== null);
            if ($bad) {
                throw ValidationException::withMessages(['delivery_ids' => 'Every delivery must belong to this courier, be collected, and not be in another remittance.']);
            }

            $remittance = new CodRemittance;
            $remittance->forceFill([
                'number' => CodRemittance::nextNumber(),
                'courier_id' => $courierId,
                'expected_amount' => (int) $deliveries->sum('collected_amount'),
                'reference' => $reference,
                'notes' => $notes,
            ])->save();

            Delivery::query()->whereIn('id', $deliveryIds)->update(['remittance_id' => $remittance->id]);

            return $remittance;
        });
    }

    public function post(CodRemittance $remittance, int $receivedAmount): CodRemittance
    {
        return DB::transaction(function () use ($remittance, $receivedAmount): CodRemittance {
            $remittance = CodRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($remittance);

            $remittance->forceFill([
                'status' => CodRemittance::POSTED,
                'received_amount' => $receivedAmount,
                'difference' => $receivedAmount - $remittance->expected_amount,
                'posted_at' => now(),
            ])->save();

            return $remittance;
        });
    }

    /** Delete a draft and release its deliveries. */
    public function delete(CodRemittance $remittance): void
    {
        DB::transaction(function () use ($remittance): void {
            $remittance = CodRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($remittance);

            Delivery::query()->where('remittance_id', $remittance->id)->update(['remittance_id' => null]);
            $remittance->delete();
        });
    }

    private function assertDraft(CodRemittance $remittance): void
    {
        if ($remittance->status !== CodRemittance::DRAFT) {
            throw ValidationException::withMessages(['status' => 'A posted remittance cannot be changed. Correct it with a new document.']);
        }
    }
}
