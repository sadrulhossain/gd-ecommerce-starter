<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A standalone sequence survives `migrate:fresh` (it only drops tables), hence IF NOT EXISTS.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS purchase_order_number_seq START 1');

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->char('currency', 3)->default('BDT');
            $table->date('expected_at')->nullable();
            $table->text('notes')->nullable();
            // Money: integer minor units, computed server-side from the lines.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('grand_total')->default(0);
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'supplier_id']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name', 400);
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->bigInteger('unit_cost');
            $table->unsignedSmallInteger('tax_rate_bp')->default(0);
            $table->bigInteger('line_total');
            $table->bigInteger('tax_amount')->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'variant_id']);
        });

        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT purchase_order_lines_check CHECK (quantity_ordered > 0 AND unit_cost >= 0 AND tax_rate_bp <= 10000 AND quantity_received <= quantity_ordered)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        DB::statement('DROP SEQUENCE IF EXISTS purchase_order_number_seq');
    }
};
