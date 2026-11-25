<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS cod_remittance_number_seq START 1');

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->bigInteger('shipping_fee');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A courier's cash hand-over: draft, then posted (immutable). Expected = collected cash of its deliveries.
        Schema::create('cod_remittances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->foreignId('courier_id')->constrained()->restrictOnDelete();
            $table->string('status', 12)->default('draft');
            $table->bigInteger('expected_amount');
            $table->bigInteger('received_amount')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('courier_id')->constrained()->restrictOnDelete();
            $table->string('tracking_number', 100)->nullable();
            $table->bigInteger('cod_amount')->default(0);
            $table->bigInteger('collected_amount')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('remittance_id')->nullable()->constrained('cod_remittances')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_zone_id')->nullable()->after('customer_id')->constrained('delivery_zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_zone_id'));
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('cod_remittances');
        Schema::dropIfExists('couriers');
        Schema::dropIfExists('delivery_zones');
    }
};
