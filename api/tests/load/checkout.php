<?php

/*
 * Checkout load test against the running dev stack: many buyers, one SKU, all checking out at once. It passes only
 * when exactly as many orders are accepted as there is stock, every accepted order can be confirmed, and
 * `stock:verify` is clean. Real HTTP and real PostgreSQL row locks, which a single-process Pest test cannot do.
 *
 *   docker compose exec -T php php tests/load/checkout.php          # 50 buyers, stock 20
 *   BUYERS=100 STOCK=7 docker compose exec ... (pass with -e BUYERS=100 -e STOCK=7)
 *
 * It leaves an archived product, cancelled orders and ledger rows behind (the ledger is append-only), so it refuses
 * to run in production.
 */

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\OrderService;
use App\Services\StockService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('production')) {
    fwrite(STDERR, "load test: refused in production\n");
    exit(2);
}

$buyers = (int) (getenv('BUYERS') ?: 50);
$stock = (int) (getenv('STOCK') ?: 20);
$base = rtrim(getenv('BASE_URL') ?: 'http://nginx/api/v1', '/');
$host = parse_url((string) config('app.url'), PHP_URL_HOST);

if (! PaymentMethod::query()->where('code', 'cod')->where('is_enabled', true)->exists()) {
    fwrite(STDERR, "load test: enable the cod payment method first (db:seed --class=PaymentMethodsSeeder)\n");
    exit(2);
}

// One sellable variant with a known stock, received through StockService like any real stock.
$run = Str::lower(Str::random(8));
$category = ProductCategory::query()->create(['name' => "Load test {$run}", 'slug' => "load-test-{$run}"]);
$product = Product::query()->create(['category_id' => $category->id, 'name' => "Load test {$run}", 'slug' => "load-test-{$run}", 'has_variants' => false]);
$variant = $product->variants()->create(['sku' => "LOAD-{$run}", 'price' => 10000]);
$product->forceFill(['status' => Product::ACTIVE])->save();
app(StockService::class)->apply($variant->id, StockMovement::ADJUSTMENT, $stock, $product, null, null, 'load test');

/** Send every request at once; each buyer has its own address, so the per-IP throttles do not hide the race. */
$send = function (array $requests) use ($base, $host): array {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => [$path, $body]) {
        $curl = curl_init($base.$path);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ["Host: {$host}", 'Accept: application/json', 'Content-Type: application/json', 'X-Forwarded-For: 198.18.'.intdiv($i, 250).'.'.($i % 250 + 1)],
            CURLOPT_POSTFIELDS => json_encode((object) $body),
        ]);
        curl_multi_add_handle($multi, $curl);
        $handles[$i] = $curl;
    }
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.1);
    } while ($running > 0);

    return array_map(fn (CurlHandle $c) => [curl_getinfo($c, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($c), true)], $handles);
};

$carts = array_map(function (array $response): string {
    if ($response[0] !== 201) {
        fwrite(STDERR, "load test: creating a cart answered {$response[0]}\n");
        exit(2);
    }

    return $response[1]['data']['token'];
}, $send(array_fill(0, $buyers, ['/public/carts', []])));

$added = $send(array_map(fn (string $t) => ["/public/carts/{$t}/items", ['variant_id' => $variant->id, 'quantity' => 1]], $carts));
$ready = count(array_filter($added, fn (array $r) => $r[0] === 200));

$started = microtime(true);
$results = $send(array_map(fn (string $t) => ["/public/carts/{$t}/checkout", [
    'contact_name' => 'Load test', 'contact_phone' => '01700000000', 'address_line' => 'Load test', 'city' => 'Dhaka', 'payment_method' => 'cod',
]], $carts));
$seconds = round(microtime(true) - $started, 1);

$statuses = array_count_values(array_column($results, 0));
ksort($statuses);

$orders = Order::query()->whereHas('items', fn ($q) => $q->where('variant_id', $variant->id))->orderBy('id')->get();
$unconfirmable = 0;
foreach ($orders as $order) {
    try {
        app(OrderService::class)->advance($order);
    } catch (Throwable) {
        $unconfirmable++;
    }
}
$onHand = (int) StockLevel::query()->whereKey($variant->id)->value('on_hand');
$verified = Artisan::call('stock:verify') === 0;
$verifyOutput = trim(Artisan::output());

// Clean up what can be cleaned: cancel (returns the stock) and hide the product.
foreach ($orders as $order) {
    app(OrderService::class)->cancel($order->refresh(), 'load test');
}
$product->forceFill(['status' => Product::ARCHIVED])->save();
$category->update(['is_active' => false]);

echo "load test: {$buyers} buyers, stock {$stock}, {$ready} carts filled\n";
echo 'load test: checkout answers '.json_encode($statuses)." in {$seconds}s\n";
echo "load test: {$orders->count()} orders accepted, {$unconfirmable} could not be confirmed, {$onHand} left on hand\n";
echo $verifyOutput."\n";

$expected = min($buyers, $stock);
$passed = $ready === $buyers && $orders->count() === $expected && ($statuses[201] ?? 0) === $expected
    && ($statuses[422] ?? 0) === $buyers - $expected && $unconfirmable === 0 && $onHand === $stock - $expected && $verified;
echo $passed ? "load test: PASSED (no oversell)\n" : "load test: FAILED\n";
exit($passed ? 0 : 1);
