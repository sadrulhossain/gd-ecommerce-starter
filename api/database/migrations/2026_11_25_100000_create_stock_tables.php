<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS goods_receipt_number_seq START 1');

        // stock_levels is a locked, transactional cache of the ledger; only StockService writes it.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->foreignId('variant_id')->primary()->constrained('product_variants')->restrictOnDelete();
            $table->integer('on_hand')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_check CHECK (on_hand >= 0)');

        // The ledger: append-only (a trigger rejects UPDATE and DELETE). `quantity` is signed.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('type', 24);
            $table->integer('quantity');
            $table->integer('balance_after');
            $table->bigInteger('unit_cost')->nullable();
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('type');
        });
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_check CHECK (quantity <> 0 AND balance_after >= 0 AND (unit_cost IS NULL OR unit_cost >= 0))');
        DB::unprepared("CREATE OR REPLACE FUNCTION stock_movements_append_only() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'stock_movements is append-only'; END; \$\$");
        DB::statement('CREATE TRIGGER stock_movements_append_only BEFORE UPDATE OR DELETE ON stock_movements FOR EACH ROW EXECUTE FUNCTION stock_movements_append_only()');

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->char('currency', 3)->default('BDT');
            $table->string('delivery_note', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_order_id', 'status']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name', 400);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_cost');
            $table->timestamps();

            $table->unique(['goods_receipt_id', 'purchase_order_line_id']);
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_check CHECK (quantity > 0 AND unit_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        DB::statement('DROP FUNCTION IF EXISTS stock_movements_append_only()');
        DB::statement('DROP SEQUENCE IF EXISTS goods_receipt_number_seq');
    }
};
