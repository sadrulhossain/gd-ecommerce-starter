<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->unsignedInteger('rate_bp'); // 100 = 1%
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        // At most one default rate.
        DB::statement('CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates (is_default) WHERE is_default');

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique(); // stored upper-case
            $table->string('type', 10); // percent | fixed
            $table->bigInteger('value'); // percent: basis points; fixed: minor units
            $table->bigInteger('min_subtotal')->default(0);
            $table->bigInteger('max_discount')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('coupon_code', 40)->nullable()->after('discount_total');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->bigInteger('discount_amount')->default(0)->after('unit_price');
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'product_id']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body');
            $table->string('status', 12)->default('pending')->index(); // pending | approved | rejected
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'customer_id']);
        });
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('wishlist_items');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('discount_amount'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('coupon_code'));
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::table('products', fn (Blueprint $table) => $table->dropConstrainedForeignId('tax_rate_id'));
        Schema::dropIfExists('tax_rates');
    }
};
