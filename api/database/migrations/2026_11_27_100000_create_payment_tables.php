<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->text('credentials')->nullable(); // encrypted by the model cast
            $table->timestamps();
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 100)->unique();
            $table->string('payment_method', 40)->index();
            $table->string('action', 20);
            $table->string('reference_type', 40);
            $table->unsignedBigInteger('reference_id');
            $table->bigInteger('amount');
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->string('provider_reference', 120)->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_methods');
    }
};
