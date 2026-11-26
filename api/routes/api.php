<?php

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\BrandImageController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CodRemittanceController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContactMessageController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\CourierController;
use App\Http\Controllers\Api\V1\CustomerAuthController;
use App\Http\Controllers\Api\V1\CustomerOrderController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeliveryController;
use App\Http\Controllers\Api\V1\DeliveryZoneController;
use App\Http\Controllers\Api\V1\FaqController;
use App\Http\Controllers\Api\V1\GalleryItemController;
use App\Http\Controllers\Api\V1\GalleryItemImageController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LocalesController;
use App\Http\Controllers\Api\V1\LogoutController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MeMenusController;
use App\Http\Controllers\Api\V1\MenuController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\PostImageController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\Api\V1\ProductCategoryController;
use App\Http\Controllers\Api\V1\ProductCategoryImageController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseOrderLineController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\SectionImageController;
use App\Http\Controllers\Api\V1\SectionPanelController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServiceImageController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SettingImageController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\StockDocumentController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TaxRateController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\TeamMemberImageController;
use App\Http\Controllers\Api\V1\TestimonialController;
use App\Http\Controllers\Api\V1\TestimonialImageController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\WishlistController;
use App\Http\Middleware\LocalizePublicResponse;
use App\Models\StockDocument;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class);
    Route::get('meta/locales', LocalesController::class);

    // Public content (visible rows only). `?lang=` collapses bilingual pairs into one language.
    Route::middleware(LocalizePublicResponse::class)->group(function (): void {
        Route::get('public/settings', [SettingController::class, 'publicShow']);
        Route::get('public/sections', [SectionController::class, 'publicIndex']);
        Route::get('public/faqs', [FaqController::class, 'publicIndex']);
        Route::get('public/pricing', [PricingController::class, 'publicIndex']);
        Route::get('public/services', [ServiceController::class, 'publicIndex']);
        Route::get('public/services/{service}', [ServiceController::class, 'publicShow']);
        Route::get('public/testimonials', [TestimonialController::class, 'publicIndex']);
        Route::get('public/team', [TeamMemberController::class, 'publicIndex']);
        Route::get('public/gallery', [GalleryItemController::class, 'publicIndex']);
        Route::get('public/categories', [CategoryController::class, 'publicIndex']);
        Route::get('public/posts', [PostController::class, 'publicIndex']);
        Route::get('public/posts/{slug}', [PostController::class, 'publicShow']);
        Route::get('public/product-categories', [ProductCategoryController::class, 'publicIndex']);
        Route::get('public/brands', [BrandController::class, 'publicIndex']);
        Route::get('public/products', [ProductController::class, 'publicIndex']);
        Route::get('public/products/{slug}', [ProductController::class, 'publicShow']);
    });

    // Contact form: captcha first, then the throttled submit.
    Route::get('public/captcha', [ContactController::class, 'captcha'])->middleware('throttle:30,1');
    Route::post('contact', [ContactController::class, 'store'])->middleware('throttle:contact');

    // Storefront: carts (Redis, by token), checkout, guest order lookup, customer accounts (separate guard).
    Route::middleware('throttle:storefront')->group(function (): void {
        Route::post('public/carts', [CartController::class, 'store']);
        Route::get('public/carts/{token}', [CartController::class, 'show']);
        Route::post('public/carts/{token}/items', [CartController::class, 'addItem']);
        Route::patch('public/carts/{token}/items/{variantId}', [CartController::class, 'updateItem']);
        Route::delete('public/carts/{token}/items/{variantId}', [CartController::class, 'removeItem']);
        Route::post('public/carts/{token}/checkout', [CartController::class, 'checkout'])->middleware('throttle:checkout');
        Route::get('public/orders/{number}', [CustomerOrderController::class, 'lookup']);
        Route::get('public/delivery-zones', [DeliveryZoneController::class, 'publicIndex']);
        Route::get('public/payment-methods', [PaymentMethodController::class, 'publicIndex']);
        Route::get('public/products/{slug}/reviews', [ReviewController::class, 'publicIndex']);
    });
    Route::middleware('throttle:login')->group(function (): void {
        Route::post('customer/register', [CustomerAuthController::class, 'register']);
        Route::post('customer/login', [CustomerAuthController::class, 'login']);
    });
    Route::middleware(['auth:customer', 'throttle:storefront'])->prefix('customer')->group(function (): void {
        Route::get('me', [CustomerAuthController::class, 'me']);
        Route::post('logout', [CustomerAuthController::class, 'logout']);
        Route::get('orders', [CustomerOrderController::class, 'index']);
        Route::get('orders/{number}', [CustomerOrderController::class, 'show']);
        Route::post('orders/{number}/cancel', [CustomerOrderController::class, 'cancel']);
        Route::get('wishlist', [WishlistController::class, 'index']);
        Route::put('wishlist/{productId}', [WishlistController::class, 'store']);
        Route::delete('wishlist/{productId}', [WishlistController::class, 'destroy']);
        Route::post('products/{slug}/reviews', [ReviewController::class, 'store']);
    });
    // Authenticated routes. Module routes add ->middleware('can:<permission>').
    Route::middleware('auth:api')->group(function (): void {
        Route::get('me', MeController::class);
        Route::get('me/menus', MeMenusController::class);
        Route::patch('me', [ProfileController::class, 'update']);
        Route::post('logout', LogoutController::class);
        Route::get('dashboard', DashboardController::class)->middleware('can:dashboard.view');

        // Content (edit-only: seeded rows, no create/delete)
        // Single-section screens (hero, about): permissions <key>.view / <key>.update.
        foreach (['hero', 'about'] as $key) {
            Route::get($key, [SectionPanelController::class, 'show'])->defaults('key', $key)->middleware("can:{$key}.view");
            Route::patch($key, [SectionPanelController::class, 'update'])->defaults('key', $key)->middleware("can:{$key}.update");
            Route::post("{$key}/image", [SectionPanelController::class, 'storeImage'])->defaults('key', $key)->middleware("can:{$key}.update");
            Route::delete("{$key}/image", [SectionPanelController::class, 'destroyImage'])->defaults('key', $key)->middleware("can:{$key}.update");
        }
        Route::post('settings/logo', [SettingImageController::class, 'storeLogo']);
        Route::delete('settings/logo', [SettingImageController::class, 'destroyLogo']);
        Route::post('settings/favicon', [SettingImageController::class, 'storeFavicon']);
        Route::delete('settings/favicon', [SettingImageController::class, 'destroyFavicon']);
        Route::apiSingleton('settings', SettingController::class);
        Route::put('sections/reorder', [SectionController::class, 'reorder']);
        Route::apiResource('sections', SectionController::class)->only(['index', 'show', 'update']);
        Route::post('sections/{section}/image', [SectionImageController::class, 'store']);
        Route::delete('sections/{section}/image', [SectionImageController::class, 'destroy']);

        // Access: users, roles (+ the permission list for the matrix), menu tree
        Route::apiResource('users', UserController::class);
        Route::apiResource('roles', RoleController::class);
        Route::get('permissions', PermissionController::class)->middleware('can:roles.view');
        Route::put('menus/reorder', [MenuController::class, 'reorder']);
        Route::get('menus', [MenuController::class, 'index']);
        Route::patch('menus/{menu}', [MenuController::class, 'update']);

        // Content lists (full CRUD)
        Route::apiResource('faqs', FaqController::class);
        Route::apiResource('pricing', PricingController::class);

        Route::apiResource('services', ServiceController::class);
        Route::post('services/{service}/image', [ServiceImageController::class, 'store']);
        Route::delete('services/{service}/image', [ServiceImageController::class, 'destroy']);

        Route::apiResource('testimonials', TestimonialController::class);
        Route::post('testimonials/{testimonial}/image', [TestimonialImageController::class, 'store']);
        Route::delete('testimonials/{testimonial}/image', [TestimonialImageController::class, 'destroy']);

        Route::apiResource('team', TeamMemberController::class)->parameters(['team' => 'team_member']);
        Route::post('team/{team_member}/image', [TeamMemberImageController::class, 'store']);
        Route::delete('team/{team_member}/image', [TeamMemberImageController::class, 'destroy']);

        Route::apiResource('gallery', GalleryItemController::class)->parameters(['gallery' => 'gallery_item']);
        Route::post('gallery/{gallery_item}/image', [GalleryItemImageController::class, 'store']);
        Route::delete('gallery/{gallery_item}/image', [GalleryItemImageController::class, 'destroy']);

        // Blog
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('posts', PostController::class);
        Route::post('posts/{post}/image', [PostImageController::class, 'store']);
        Route::delete('posts/{post}/image', [PostImageController::class, 'destroy']);

        // Catalog: categories, brands, products, variants, images
        Route::put('product-categories/reorder', [ProductCategoryController::class, 'reorder']);
        Route::apiResource('product-categories', ProductCategoryController::class);
        Route::post('product-categories/{product_category}/image', [ProductCategoryImageController::class, 'store']);
        Route::delete('product-categories/{product_category}/image', [ProductCategoryImageController::class, 'destroy']);

        Route::apiResource('brands', BrandController::class);
        Route::post('brands/{brand}/image', [BrandImageController::class, 'store']);
        Route::delete('brands/{brand}/image', [BrandImageController::class, 'destroy']);

        Route::apiResource('products', ProductController::class);
        Route::post('products/{product}/publish', [ProductController::class, 'publish']);
        Route::post('products/{product}/unpublish', [ProductController::class, 'unpublish']);
        Route::post('products/{product}/archive', [ProductController::class, 'archive']);

        Route::get('products/{product}/variants', [ProductVariantController::class, 'index']);
        Route::post('products/{product}/variants', [ProductVariantController::class, 'store']);
        Route::get('variants/{variant}', [ProductVariantController::class, 'show']);
        Route::patch('variants/{variant}', [ProductVariantController::class, 'update']);
        Route::delete('variants/{variant}', [ProductVariantController::class, 'destroy']);

        Route::post('products/{product}/images', [ProductImageController::class, 'store']);
        Route::put('products/{product}/images/reorder', [ProductImageController::class, 'reorder']);
        Route::patch('product-images/{image}', [ProductImageController::class, 'update']);
        Route::delete('product-images/{image}', [ProductImageController::class, 'destroy']);

        // Purchasing: suppliers, purchase orders and their draft lines
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::post('purchase-orders/{purchase_order}/order', [PurchaseOrderController::class, 'order']);
        Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::post('purchase-orders/{purchase_order}/lines', [PurchaseOrderLineController::class, 'store']);
        Route::patch('purchase-order-lines/{line}', [PurchaseOrderLineController::class, 'update']);
        Route::delete('purchase-order-lines/{line}', [PurchaseOrderLineController::class, 'destroy']);

        // Inventory: check-in (goods receipts) and read-only stock views
        Route::apiResource('goods-receipts', GoodsReceiptController::class);
        Route::post('goods-receipts/{goods_receipt}/post', [GoodsReceiptController::class, 'post']);
        Route::get('stock/levels', [StockController::class, 'levels'])->middleware('can:stock.view');
        Route::get('stock/movements', [StockController::class, 'movements'])->middleware('can:stock.view');

        // Orders (admin): lifecycle moves go through OrderService
        Route::get('orders', [OrderController::class, 'index'])->middleware('can:orders.view');
        Route::get('orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view');
        Route::post('orders/{order}/advance', [OrderController::class, 'advance'])->middleware('can:orders.update');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{order}/delivery', [OrderController::class, 'assignDelivery']);

        // Delivery: zones, couriers, COD remittances (draft, then posted) and reports
        Route::get('delivery-zones', [DeliveryZoneController::class, 'index'])->middleware('can:delivery.view');
        Route::post('delivery-zones', [DeliveryZoneController::class, 'store']);
        Route::patch('delivery-zones/{zone}', [DeliveryZoneController::class, 'update']);
        Route::delete('delivery-zones/{zone}', [DeliveryZoneController::class, 'destroy'])->middleware('can:delivery.manage');
        Route::get('couriers', [CourierController::class, 'index'])->middleware('can:delivery.view');
        Route::post('couriers', [CourierController::class, 'store']);
        Route::patch('couriers/{courier}', [CourierController::class, 'update']);
        Route::delete('couriers/{courier}', [CourierController::class, 'destroy'])->middleware('can:delivery.manage');
        Route::get('cod-remittances', [CodRemittanceController::class, 'index'])->middleware('can:cod-remittances.view');
        Route::get('cod-remittances/{cod_remittance}', [CodRemittanceController::class, 'show'])->middleware('can:cod-remittances.view');
        Route::post('cod-remittances', [CodRemittanceController::class, 'store']);
        Route::post('cod-remittances/{cod_remittance}/post', [CodRemittanceController::class, 'post']);
        Route::delete('cod-remittances/{cod_remittance}', [CodRemittanceController::class, 'destroy'])->middleware('can:cod-remittances.delete');
        Route::get('reports/sales', [ReportController::class, 'sales'])->middleware('can:reports.view');
        Route::get('reports/cod-reconciliation', [ReportController::class, 'codReconciliation'])->middleware('can:reports.view');
        Route::get('reports/stock-valuation', [ReportController::class, 'stockValuation'])->middleware('can:reports.view');

        // Payment methods (registry-backed, never deleted) and the delivery list behind COD remittances
        Route::get('payment-methods', [PaymentMethodController::class, 'index'])->middleware('can:payment-methods.view');
        Route::post('payment-methods', [PaymentMethodController::class, 'store']);
        Route::patch('payment-methods/{payment_method}', [PaymentMethodController::class, 'update']);
        Route::get('deliveries', [DeliveryController::class, 'index'])->middleware('can:cod-remittances.view');

        // Promotions: tax rates, coupons, review moderation
        Route::get('tax-rates', [TaxRateController::class, 'index'])->middleware('can:tax-rates.view');
        Route::post('tax-rates', [TaxRateController::class, 'store']);
        Route::patch('tax-rates/{tax_rate}', [TaxRateController::class, 'update']);
        Route::delete('tax-rates/{tax_rate}', [TaxRateController::class, 'destroy'])->middleware('can:tax-rates.manage');
        Route::get('coupons', [CouponController::class, 'index'])->middleware('can:coupons.view');
        Route::get('coupons/{coupon}', [CouponController::class, 'show'])->middleware('can:coupons.view');
        Route::post('coupons', [CouponController::class, 'store']);
        Route::patch('coupons/{coupon}', [CouponController::class, 'update']);
        Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->middleware('can:coupons.delete');
        Route::get('reviews', [ReviewController::class, 'index'])->middleware('can:reviews.view');
        Route::post('reviews/{review}/approve', [ReviewController::class, 'approve'])->middleware('can:reviews.moderate');
        Route::post('reviews/{review}/reject', [ReviewController::class, 'reject'])->middleware('can:reviews.moderate');
        Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->middleware('can:reviews.delete');

        // Stock documents (draft, then posted): damages, supplier-returns, adjustments, stock-counts share one controller
        foreach (StockDocument::TYPES as $type => $config) {
            $path = $config['path'];
            Route::get($path, [StockDocumentController::class, 'index'])->defaults('type', $type);
            Route::post($path, [StockDocumentController::class, 'store'])->defaults('type', $type);
            Route::get("{$path}/{stock_document}", [StockDocumentController::class, 'show'])->defaults('type', $type);
            Route::patch("{$path}/{stock_document}", [StockDocumentController::class, 'update'])->defaults('type', $type);
            Route::delete("{$path}/{stock_document}", [StockDocumentController::class, 'destroy'])->defaults('type', $type);
            Route::post("{$path}/{stock_document}/post", [StockDocumentController::class, 'post'])->defaults('type', $type);
        }

        // Contact inbox (no create/delete)
        Route::apiResource('contact-messages', ContactMessageController::class)
            ->only(['index', 'show'])->parameters(['contact-messages' => 'contact_message']);
        Route::post('contact-messages/{contact_message}/read', [ContactMessageController::class, 'markRead']);
    });
});
