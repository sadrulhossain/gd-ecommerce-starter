<?php

namespace App\Providers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Faq;
use App\Models\GalleryItem;
use App\Models\OAuthClient;
use App\Models\Post;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Section;
use App\Models\Service;
use App\Models\Setting;
use App\Models\TeamMember;
use App\Models\Testimonial;
use App\Models\User;
use App\Observers\ContentObserver;
use App\Policies\RolePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Passport::useClientModel(OAuthClient::class);
        // Only the first-party admin client exists and it skips consent, so there is no consent screen to show.
        Passport::authorizationView(fn () => abort(403, 'Only first-party clients are supported.'));
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Public content changes -> queued signed webhook so the portal revalidates its cache.
        foreach ([
            Category::class, Faq::class, GalleryItem::class, Post::class, Pricing::class, Section::class,
            Service::class, Setting::class, TeamMember::class, Testimonial::class,
            Brand::class, Product::class, ProductCategory::class, ProductImage::class, ProductVariant::class,
        ] as $model) {
            $model::observe(ContentObserver::class);
        }

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip().'|'.strtolower((string) $request->input('email'))));
        // Named, so each has its own counter (unnamed `throttle:N,M` limiters on one request share a key).
        RateLimiter::for('storefront', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(config('contact.per_minute'))->by($request->ip()));

        Gate::policy(Role::class, RolePolicy::class);

        // Superadmin passes every gate check (permissions, policies, can: middleware).
        Gate::before(fn (User $user) => $user->isSuperadmin() ? true : null);
    }
}
