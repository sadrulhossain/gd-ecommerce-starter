<?php

namespace App\Services;

use App\Models\ProductVariant;
use App\Models\StockDocument;
use App\Models\StockDocumentLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Draft lines and posting for damage, supplier return, adjustment and stock count documents. Stock moves only through StockService. */
class StockDocumentService
{
    public const MAX_QUANTITY = 1_000_000;

    /**
     * Replace the lines of a draft. Each entry needs `variant_id` and `quantity`; `unit_cost` defaults to the variant's cost price.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(StockDocument $document, array $lines): void
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', array_column($lines, 'variant_id'))->get()->keyBy('id');
        $rows = [];

        foreach ($lines as $line) {
            $variant = $variants->get((int) $line['variant_id']);

            $rows[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name !== null && $variant->name !== '' ? "{$variant->product->name} - {$variant->name}" : $variant->product->name,
                'quantity' => (int) $line['quantity'],
                'unit_cost' => isset($line['unit_cost']) ? (int) $line['unit_cost'] : $variant->cost_price,
            ];
        }

        $document->lines()->delete();
        $document->lines()->createMany($rows);
    }

    /**
     * Post a draft: write the ledger rows (damage and return remove, adjustment applies the signed change, count sets the
     * stock to the counted quantity) and make the document immutable. All or nothing.
     *
     * @throws ValidationException
     */
    public function post(StockDocument $document, int $userId, StockService $stock): StockDocument
    {
        return DB::transaction(function () use ($document, $userId, $stock): StockDocument {
            $document = StockDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->assertDraft();

            $lines = $document->lines()->get();

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Add at least one line before posting.']);
            }

            $movement = StockDocument::TYPES[$document->type]['movement'];

            // Lowest variant first: concurrent documents lock the same rows in the same order.
            foreach ($lines->sortBy('variant_id') as $line) {
                $this->postLine($document, $line, $movement, $userId, $stock);
            }

            $document->forceFill(['status' => StockDocument::POSTED, 'posted_at' => now(), 'posted_by' => $userId])->save();

            return $document;
        });
    }

    private function postLine(StockDocument $document, StockDocumentLine $line, string $movement, int $userId, StockService $stock): void
    {
        if ($document->type === StockDocument::COUNT) {
            $result = $stock->count($line->variant_id, $line->quantity, $document, $userId);
            $line->forceFill(['expected_quantity' => $result['expected']])->save();

            return;
        }

        $quantity = $document->type === StockDocument::ADJUSTMENT ? $line->quantity : -$line->quantity;

        try {
            $stock->apply($line->variant_id, $movement, $quantity, $document, $line->unit_cost, $userId, $document->reason);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['lines' => $e->errors()['quantity']]);
        }
    }
}
