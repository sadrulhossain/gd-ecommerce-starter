<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('barcode', 64)->nullable()->unique();
            $table->string('name', 200)->nullable();
            $table->jsonb('options')->default('{}');
            // Money: integer minor units (BIGINT) plus a currency code, never floats.
            $table->bigInteger('price');
            $table->bigInteger('compare_at_price')->nullable();
            $table->bigInteger('cost_price')->nullable();
            $table->char('currency', 3)->default('BDT');
            $table->unsignedInteger('weight_grams')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'is_active']);
        });

        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_prices_check CHECK (price >= 0 AND (cost_price IS NULL OR cost_price >= 0) AND (compare_at_price IS NULL OR compare_at_price > price))');
        // One variant per option combination (jsonb text is key-order independent).
        DB::statement('CREATE UNIQUE INDEX product_variants_options_unique ON product_variants (product_id, md5(options::text)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
