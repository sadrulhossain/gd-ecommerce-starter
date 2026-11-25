<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS order_number_seq START 1');

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('phone', 30)->nullable();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('lookup_token', 64)->unique(); // guest access to one order
            $table->string('status', 20)->default('pending')->index();
            $table->string('payment_method', 40);
            $table->string('payment_status', 20)->default('pending');
            $table->string('currency', 3)->default('BDT');
            $table->bigInteger('subtotal');
            $table->bigInteger('shipping_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('grand_total');
            // Contact and address snapshot: never joined back to a customer record.
            $table->string('contact_name', 120);
            $table->string('contact_phone', 30);
            $table->string('contact_email', 190)->nullable();
            $table->string('address_line', 255);
            $table->string('city', 100);
            $table->string('district', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->text('note')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        // Snapshots of name, price and tax at the moment of the order.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('sku', 100);
            $table->string('name', 255);
            $table->jsonb('options')->nullable();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price');
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('customers');
    }
};
