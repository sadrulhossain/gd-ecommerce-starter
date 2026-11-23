<?php

use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\BrandImageController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContactMessageController;
use App\Http\Controllers\Api\V1\DashboardController;
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
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SectionController;
use App\Http\Controllers\Api\V1\SectionImageController;
use App\Http\Controllers\Api\V1\SectionPanelController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ServiceImageController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\SettingImageController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TeamMemberController;
use App\Http\Controllers\Api\V1\TeamMemberImageController;
use App\Http\Controllers\Api\V1\TestimonialController;
use App\Http\Controllers\Api\V1\TestimonialImageController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\LocalizePublicResponse;
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

        // Contact inbox (no create/delete)
        Route::apiResource('contact-messages', ContactMessageController::class)
            ->only(['index', 'show'])->parameters(['contact-messages' => 'contact_message']);
        Route::post('contact-messages/{contact_message}/read', [ContactMessageController::class, 'markRead']);
    });
});
