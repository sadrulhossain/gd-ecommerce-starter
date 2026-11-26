<?php

namespace App\Console\Commands;

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read-only integrity check of the stock ledger, its cache, and purchasing quantities. Exits 1 on any disagreement. */
class VerifyStock extends Command
{
    protected $signature = 'stock:verify';

    protected $description = 'Prove that the stock ledger, stock levels, purchase orders and goods receipts agree';

    public function handle(): int
    {
        $checks = [
            'stock level differs from the ledger sum' => $this->levelsAgainstLedger(),
            'ledger running balance is broken' => $this->runningBalances(),
            'purchase order line differs from its posted receipts' => $this->orderLinesAgainstReceipts(),
            'purchase order status differs from its received quantities' => $this->orderStatuses(),
            'posted goods receipt differs from its ledger rows' => $this->receiptsAgainstLedger(),
        ];

        $failures = 0;
        foreach ($checks as $title => $rows) {
            foreach ($rows as $row) {
                $this->error("{$title}: {$row}");
                $failures++;
            }
        }

        if ($failures > 0) {
            $this->error("stock:verify: {$failures} problem(s)");

            return self::FAILURE;
        }

        $this->info('stock:verify: ok ('.count($checks).' checks)');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function levelsAgainstLedger(): array
    {
        return $this->describe(<<<'SQL'
            SELECT COALESCE(l.variant_id, m.variant_id) AS variant_id, COALESCE(l.on_hand, 0) AS on_hand, COALESCE(m.total, 0) AS ledger
            FROM stock_levels l
            FULL OUTER JOIN (SELECT variant_id, SUM(quantity) AS total FROM stock_movements GROUP BY variant_id) m ON m.variant_id = l.variant_id
            WHERE COALESCE(l.on_hand, 0) <> COALESCE(m.total, 0)
            ORDER BY 1
            SQL);
    }

    /** @return list<string> */
    private function runningBalances(): array
    {
        return $this->describe(<<<'SQL'
            SELECT id AS movement_id, variant_id, balance_after, running
            FROM (SELECT id, variant_id, balance_after, SUM(quantity) OVER (PARTITION BY variant_id ORDER BY id) AS running FROM stock_movements) m
            WHERE balance_after <> running
            ORDER BY id
            SQL);
    }

    /** @return list<string> */
    private function orderLinesAgainstReceipts(): array
    {
        return $this->describe(<<<'SQL'
            SELECT p.id AS purchase_order_line_id, p.quantity_ordered, p.quantity_received, COALESCE(r.total, 0) AS posted
            FROM purchase_order_lines p
            LEFT JOIN (
                SELECT g.purchase_order_line_id, SUM(g.quantity) AS total
                FROM goods_receipt_lines g JOIN goods_receipts h ON h.id = g.goods_receipt_id
                WHERE h.status = ?
                GROUP BY g.purchase_order_line_id
            ) r ON r.purchase_order_line_id = p.id
            WHERE p.quantity_received <> COALESCE(r.total, 0) OR p.quantity_received > p.quantity_ordered
            ORDER BY p.id
            SQL, [GoodsReceipt::POSTED]);
    }

    /** @return list<string> */
    private function orderStatuses(): array
    {
        return $this->describe(<<<'SQL'
            SELECT o.number, o.status, SUM(p.quantity_ordered) AS ordered, SUM(p.quantity_received) AS received
            FROM purchase_orders o JOIN purchase_order_lines p ON p.purchase_order_id = o.id
            GROUP BY o.id, o.number, o.status
            HAVING o.status <> CASE
                WHEN SUM(p.quantity_received) = 0 THEN CASE WHEN o.status IN (?, ?, ?) THEN o.status ELSE ? END
                WHEN BOOL_AND(p.quantity_received >= p.quantity_ordered) THEN ?
                ELSE ?
            END
            ORDER BY o.id
            SQL, [
            PurchaseOrder::DRAFT, PurchaseOrder::ORDERED, PurchaseOrder::CANCELLED, PurchaseOrder::ORDERED,
            PurchaseOrder::RECEIVED, PurchaseOrder::PARTIALLY_RECEIVED,
        ]);
    }

    /** @return list<string> */
    private function receiptsAgainstLedger(): array
    {
        return $this->describe(<<<'SQL'
            SELECT COALESCE(r.receipt_id, m.receipt_id) AS goods_receipt_id, COALESCE(r.variant_id, m.variant_id) AS variant_id,
                   COALESCE(r.total, 0) AS lines, COALESCE(m.total, 0) AS ledger
            FROM (
                SELECT h.id AS receipt_id, g.variant_id, SUM(g.quantity) AS total
                FROM goods_receipt_lines g JOIN goods_receipts h ON h.id = g.goods_receipt_id
                WHERE h.status = ?
                GROUP BY h.id, g.variant_id
            ) r
            FULL OUTER JOIN (
                SELECT reference_id AS receipt_id, variant_id, SUM(quantity) AS total
                FROM stock_movements WHERE type = ? AND reference_type = 'goods_receipts'
                GROUP BY reference_id, variant_id
            ) m ON m.receipt_id = r.receipt_id AND m.variant_id = r.variant_id
            WHERE COALESCE(r.total, 0) <> COALESCE(m.total, 0)
            ORDER BY 1, 2
            SQL, [GoodsReceipt::POSTED, StockMovement::RECEIPT]);
    }

    /**
     * @param  list<string>  $bindings
     * @return list<string> one `column=value` line per offending row
     */
    private function describe(string $sql, array $bindings = []): array
    {
        return array_values(array_map(
            fn (object $row): string => urldecode(http_build_query((array) $row, '', ' ')),
            DB::select($sql, $bindings),
        ));
    }
}
