<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS stock_document_number_seq START 1');

        // One table for the four draft-then-posted stock documents: damage, supplier return, adjustment, stock count.
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('type', 16);
            $table->string('status', 16)->default('draft');
            $table->string('reason', 200)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('sku', 64);
            $table->string('name', 400);
            // Damage and return: units removed (positive). Adjustment: signed change. Count: the counted quantity.
            $table->integer('quantity');
            // Count only: on-hand at the moment of posting.
            $table->integer('expected_quantity')->nullable();
            $table->bigInteger('unit_cost')->nullable();
            $table->timestamps();

            $table->unique(['stock_document_id', 'variant_id']);
        });
        DB::statement('ALTER TABLE stock_document_lines ADD CONSTRAINT stock_document_lines_check CHECK (unit_cost IS NULL OR unit_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_document_lines');
        Schema::dropIfExists('stock_documents');
        DB::statement('DROP SEQUENCE IF EXISTS stock_document_number_seq');
    }
};
