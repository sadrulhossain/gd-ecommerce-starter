<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only reports. Routes carry `can:reports.view`. Money is `{amount, currency}` in integer minor units. */
class ReportController extends Controller
{
    /**
     * Sales report
     *
     * Orders per day between `from` and `to` (dates, default the last 30 days), cancelled orders excluded
     * from the totals, plus order counts by status.
     */
    public function sales(Request $request): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = Carbon::parse($request->query('from', now()->subDays(29)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->toDateString()))->endOfDay();

        $base = DB::table('orders')->whereBetween('created_at', [$from, $to]);

        $days = (clone $base)->where('status', '!=', Order::CANCELLED)
            ->selectRaw('created_at::date AS day, count(*) AS orders, coalesce(sum(grand_total), 0) AS revenue')
            ->groupByRaw('created_at::date')->orderBy('day')->get();

        $byStatus = (clone $base)->selectRaw('status, count(*) AS orders')->groupBy('status')->pluck('orders', 'status');

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => (int) $days->sum('orders'),
            'revenue' => $this->money((int) $days->sum('revenue')),
            'by_status' => $byStatus,
            'days' => $days->map(fn ($d) => ['date' => $d->day, 'orders' => (int) $d->orders, 'revenue' => $this->money((int) $d->revenue)])->all(),
        ]]);
    }

    /**
     * COD reconciliation
     *
     * Per courier: cash collected on delivered orders, cash in posted remittances (expected and received),
     * the net difference of those remittances, and what is still outstanding (collected, not in a posted remittance).
     */
    public function codReconciliation(): JsonResponse
    {
        $rows = DB::table('couriers')
            ->leftJoin('deliveries', 'deliveries.courier_id', '=', 'couriers.id')
            ->leftJoin('cod_remittances', function ($join): void {
                $join->on('cod_remittances.id', '=', 'deliveries.remittance_id')->where('cod_remittances.status', 'posted');
            })
            ->groupBy('couriers.id', 'couriers.name')->orderBy('couriers.name')
            ->selectRaw('couriers.id, couriers.name,
                coalesce(sum(deliveries.collected_amount), 0) AS collected,
                coalesce(sum(case when cod_remittances.id is not null then deliveries.collected_amount end), 0) AS remitted')
            ->get();

        $received = DB::table('cod_remittances')->where('status', 'posted')
            ->selectRaw('courier_id, sum(received_amount) AS received, sum(difference) AS difference')->groupBy('courier_id')->get()->keyBy('courier_id');

        $couriers = $rows->map(function ($r) use ($received) {
            $got = $received->get($r->id);

            return [
                'courier_id' => $r->id,
                'name' => $r->name,
                'collected' => $this->money((int) $r->collected),
                'remitted_expected' => $this->money((int) $r->remitted),
                'remitted_received' => $this->money((int) ($got->received ?? 0)),
                'difference' => $this->money((int) ($got->difference ?? 0)),
                'outstanding' => $this->money((int) $r->collected - (int) $r->remitted),
            ];
        });

        return response()->json(['data' => [
            'couriers' => $couriers->all(),
            'outstanding' => $this->money((int) $rows->sum('collected') - (int) $rows->sum('remitted')),
        ]]);
    }

    /**
     * Stock valuation
     *
     * On-hand units times cost price, over all variants that have stock.
     */
    public function stockValuation(): JsonResponse
    {
        $row = DB::table('stock_levels')
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.variant_id')
            ->where('stock_levels.on_hand', '>', 0)
            ->selectRaw('count(*) AS variants, coalesce(sum(stock_levels.on_hand), 0) AS units,
                coalesce(sum(stock_levels.on_hand * coalesce(product_variants.cost_price, 0)), 0) AS cost_value,
                coalesce(sum(stock_levels.on_hand * product_variants.price), 0) AS retail_value')
            ->first();

        return response()->json(['data' => [
            'variants' => (int) $row->variants,
            'units' => (int) $row->units,
            'cost_value' => $this->money((int) $row->cost_value),
            'retail_value' => $this->money((int) $row->retail_value),
        ]]);
    }

    /** @return array{amount: int, currency: string} */
    private function money(int $amount): array
    {
        return ['amount' => $amount, 'currency' => (string) config('payments.currency')];
    }
}
