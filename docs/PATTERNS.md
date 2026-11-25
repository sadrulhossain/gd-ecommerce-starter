# Reference patterns

Copy these shapes, do not invent new ones. Reference module: Sections (`Section*` under `api/app`);
singleton reference: Settings. Paths below are relative to `api/app` unless noted.

## New module checklist
1. **Migration + Model**: `#[Fillable]`, `casts()`. Bilingual text = columns `x` + `x_2nd`, `use HasSecondLanguage`.
2. **Policy** `Policies/<Model>Policy.php`: one method per action, `$user->checkPermissionTo('<module>.<action>')`.
   Permission names are `<module>.<action>`; the superadmin passes via `Gate::before`.
3. **FormRequest** `Http/Requests/Api/V1/<Action><Model>Request.php`: `authorize()` returns
   `$this->user()?->can('<ability>', $model)` (403 comes before validation). `rules()` use `sometimes`
   for PATCH and wrap bilingual fields in `Bilingual::rules([...])`.
4. **Resource** `Http/Resources/Api/V1/<Model>Resource.php`: explicit field list, both languages, no model dumps.
5. **Controller** `Http/Controllers/Api/V1/<Model>Controller.php`: thin. Reads call `Gate::authorize()`; writes
   type-hint the FormRequest, `$model->update($request->validated())`, return the Resource. The first docblock
   line is the Scramble title. Never build error JSON by hand: `Exceptions/JsonErrorRenderer` shapes it.
6. **Routes** in `routes/api.php`, inside the `auth:api` group: `apiResource(...)->only([...])`, `apiSingleton`
   for one-row tables; no create/delete for seeded rows. Non-model routes use `->middleware('can:<perm>')`.
7. **Seeder** `database/seeders/<Module>ModuleSeeder.php` extends `ModuleSeeder` (permissions, menu tree, role
   grants). Seed defaults with `firstOrCreate` so re-seeding never overwrites edits. Add it to `DatabaseSeeder`.
8. **Tests** `tests/Feature/<Module>/`: 401 anonymous, 403 missing permission, happy path, 422 validation
   (assert the field keys), seeder idempotence. Authenticate with `actingAsRole($role, [$perms])` (`tests/Pest.php`).

## Uploads
- `Services/MediaService`: `store($file, '<dir>')` returns the path kept in the model's `image` column;
  `describe($path)` goes in the Resource; `delete($old)` on replace or remove.
- Images upload through their own multipart endpoint: `POST|DELETE <resource>/{id}/image`
  (`SectionImageController`). Validate with `MediaService::imageRules()` (mime by content, size, dimensions).
- Files live at `<dir>/<random>/original.<ext>`; the queued `Jobs/GenerateWebpVariants` adds `<width>.webp`.

## Config and environment
- `env()` is read only in `config/*.php` (e.g. `config/locales.php`, `config/uploads.php`).
- Tests force their environment in `tests/bootstrap.php` (testing DB, array cache, sync queue); compose env
  beats phpunit.xml. Each compose service lists its own `environment:` (see `x-php` in `docker-compose.yml`).

## Public reads, contact, webhook (phase 6)
- Public reads live in the `LocalizePublicResponse` route group (`public/*`): the controller's `publicIndex`/`publicShow` skips
  `Gate::authorize` and returns visible rows only. `?lang=` collapses `x`/`x_2nd` pairs; no per-resource code is needed.
- Rich text: assign it to a model attribute whose setter calls `Services/HtmlSanitizer` (see `Post::body`); never sanitise in controllers.
- Public forms: honeypot field (`prohibited` rule), `Services/Captcha` checked in the FormRequest's `after()`, named limiter in
  `AppServiceProvider`, queued Notification to `Notification::route('mail', ...)`. Answer with `{"data":{"received":true}}`.
- Content-change webhook: add the model to the list in `AppServiceProvider::boot()` (it gets `ContentObserver`); tag = table name.
  Not for private data (contact messages). Tests use `Http::fake()` per test.
- OpenAPI: `php artisan scramble:export --path=storage/openapi.json` (php container) exports Scramble's spec to `packages/api-types/openapi.json`; commit it when routes change.

## Frontend (phase 7)
- Theme: import `tailwindcss` then `@gd/tokens/<app>.css` in the app's one CSS file. Use token utilities (`bg-primary-600`, `text-ink`,
  `border-line`, `font-display`), never raw hex. New tokens go in packages/tokens, not in an app.
- Data: type API data with `components['schemas'][...]` from `@gd/api-types`; call the API with `@gd/api-client` (`createClient`), never raw fetch.
- Regenerate after API changes: `scramble:export` (migrated DB), then `pnpm generate:types`; commit both outputs.
- Tailwind only sees literal class names: map values to full class strings, do not build them with template pieces.

## Admin module (phase 8)
- A CRUD screen is one entry in `web/admin/src/modules.ts`: `key` (permission prefix: `<key>.view|create|update|delete`), `path` (matches the
  `/me/menus` row), `endpoint`, `columns`, `fields` (`bilingual: true` renders "Title (EN)" / "Title (FR)" from `/v1/meta/locales`). The router,
  permission gate and buttons follow from it; add a page component only when the screen is not plain CRUD.
- Calls go through `src/api.ts` (token + refresh handled); never read tokens from the store in components. Use `can('x.y')` for UI gating,
  the API remains the authority.
- Shared pieces: `DataTable`, `FormField`, `BilingualField`, `ConfirmDialog`, `Toast`; do not add a second of any.

## Admin modules (phase 9)
- CrudPage extensions, each used by two modules: `type: 'checkgroup'` (string[]; grouped options render a matrix) with `optionsFrom`
  (options fetched from the API) for users/roles; `reorder: {endpoint, group?}` (drag rows, sends `PUT {ids}`) for sections/menus.
- Inline boolean save in a table cell: `ToggleCell` from a column's `render`. Not a CrudPage feature.
- Single-record screens are not CrudPage: `SettingsPage` + a `SingletonConfig` (tabs of dot-path fields, e.g. `hours.mon.open`).
- API: reorder endpoints are `PUT <resource>/reorder {ids}` registered before the resource route (`ReorderRequest` subclass per table).
  The superadmin role is never assignable, editable or deletable through the API; a non-superadmin can only grant permissions they hold.

## Content modules (phase 10)
- `image: true` on a CrudConfig adds the image panel to the edit form (`POST|DELETE <endpoint>/<id>/image`); show it in the list with a column `render`.
- Uploads use `lib/upload.ts` `uploadFile` (XHR, for progress; same token refresh as the client). Validate with `checkImage` first, the API stays the authority.
- Bulk upload is `GalleryUploader` (create item, then upload; a failed upload deletes the empty item), mounted via `pages` in router.tsx.
- Read-only screens that are not CrudPage (contact inbox) are a page plus an `InboxConfig` (`messagesConfig.ts`).

## Blog and profile (phase 11)
- Rich text: field `type: 'richtext'` (TipTap, `components/RichText.tsx`, HTML in and out; works with `bilingual`). Keep its extensions inside the
  HtmlSanitizer allowlist (api `HtmlSanitizer::ALLOWED`); add a tag there first if the editor needs one.
- `status` (draft|published) is the API's view of `is_published` (`ResolvesPostStatus`); forms use `status`, never the boolean.
- Own account: `PATCH /v1/me` (`ProfileController`), page `pages/Profile.tsx`; no permission, any signed-in user.

## Portal (phase 12)
- Locales: `lib/i18n.ts` reads LOCALE_DEFAULT / LOCALE_SUPPORTED. `proxy.ts` rewrites unprefixed paths to `/<default>/...` and redirects an explicit
  default prefix away; pages live under `app/[locale]/`. Build links with `localePath(locale, path)`, never by hand. Env is server-only: client
  components take `locales` / `unprefixed` as props (see `LanguageSwitcher`).
- Text: `messages/en.json` (default locale) and `messages/second.json` (second); add every key to both. Content text comes from the API with `?lang=`.
- Data: only through `lib/api.ts` (`apiFor(locale)`, server side, `API_INTERNAL_URL`). Reads are `revalidate: 3600` and tagged with the API table name;
  `POST /api/revalidate` (HMAC, same secret as the API webhook) expires a tag. New public resource: add it to `TAGS` there.
- Sections: add a view to `components/sections/index.tsx` keyed by the section `key`; the renderer skips `is_visible: false` and falls back to a generic view.
- Styling: logical utilities only (`ms-`, `me-`, `ps-`, `pe-`, `text-start`, `border-s`), so RTL locales work. `images.unoptimized` is on: use `components/Media.tsx`.
- Dev note: after changing a server component that a client component consumes, a stale Turbopack cache can serve old props; `rm -rf web/portal/.next` and restart node.
- Screenshots: `docker compose --profile e2e run --rm --no-deps -T -w /app/web/portal e2e npx playwright test`.

## Portal pages (phase 13)
- A page for a section is `lib/section-page.tsx` `sectionPage('<key>', [extra keys])` in a two-line `page.tsx`: it renders the same `SectionBlock` view as Home
  (title becomes the h1) and 404s when the section is hidden. Add the key to `SECTION_PAGES` so the header links to the page instead of a Home anchor.
- Static legal text (`privacy`, `terms`) lives in `messages/*.json` under `legal`, with `{site}` / `{email}` filled from settings (`lib/legal-page.tsx`).
  It is a generic template: the business must review it.
- `getMessages` falls back key by key to `en.json`, so a missing or empty entry in `second.json` shows the default text. API content does the same via `?lang=`.
- 404: `app/[locale]/[...rest]/page.tsx` calls `notFound()` for unknown paths; `not-found.tsx` must not read request headers (that makes every locale route dynamic, no ISR): `NotFoundContent` picks the locale from the URL.
- Proxy matcher: keep the double backslash in `.*\..*`; a single one silently matches nothing useful. After editing `proxy.ts` restart node with a clean `.next`.

## Blog, contact and SEO (phase 14)
- Every page builds its metadata with `lib/seo.ts` `pageMetadata({locale, path, siteName, ...})`: title, description, canonical, hreflang (all locales + x-default), Open Graph, Twitter.
  `SITE_URL` is the public origin used for all absolute URLs; `metadataBase` is set in the locale layout.
- JSON-LD goes through `components/JsonLd.tsx` (escapes `<`): LocalBusiness on Home, Article on a post. Build the objects in `lib/seo.ts`.
- `app/sitemap.ts` and `app/robots.ts` use the same cached, tagged fetches as the pages, so the revalidate webhook refreshes them. New public page: add it to the sitemap.
- Blog: `/blog` (`?category=<slug>`, `?page=n`, canonical is the plain list), `/blog/<slug>`. Post `body` is sanitised by the API and rendered as HTML in `.post-body`.
- Contact: `components/ContactForm.tsx` talks only to `app/api/contact/*` (server proxies to the API; the captcha is one-time, refetched after every submit). The honeypot field is `website`.
- Smoke tests: `web/portal/e2e/smoke.spec.ts` (`docker compose --profile e2e run --rm --no-deps -T -w /app/web/portal e2e npx playwright test smoke`). The contact test leaves one message with subject `e2e-smoke`; the blog test skips without published posts.

## Security and quality gates (phase 15)
- Security gates: `php artisan security:routes` (every `auth:api` route needs `can:` or a policy/Gate call; own-account routes are listed in `AuditRoutes::ALLOWED`), `scripts/check-secrets.sh`, `scripts/check-floors.mjs`. A new route gets its check; do not add allowlist entries.
- nginx: `include /etc/nginx/snippets/security-headers.conf;` in every server and in every location that sets its own `add_header` (those replace inherited ones). Set the CSP per vhost.
- The OAuth authorize step is S256-only (`RequirePkceS256`). Cross-origin API access is closed; list origins in `CORS_ALLOWED_ORIGINS` only when a browser app needs it.
- Portal: never read `headers()` or `cookies()` in the locale layout, `not-found.tsx` or shared components (every page turns dynamic and loses ISR). `web/portal/e2e/a11y.spec.ts` (axe) must stay at zero violations; white text needs `bg-brand-700` or darker.
- Dev edits do not reach the node container's file watcher on Windows: after changing code, stop node, delete `web/portal/.next` and start it again.

## Production (phase 16)
- Production lives in `docker-compose.prod.yml` + `docker/prod/` + `.env.prod`, never in the dev compose file. Images contain the code; nothing is bind-mounted except TLS certs. Do not publish ports other than 80/443.
- A new env variable: add it to the `x-php` (or service) `environment:` in the prod file, to `.env.prod.example` and to the production table in ENV.md. Required secrets use `${VAR:?message}`.
- A new service needs `restart: always`, a `healthcheck`, the `logging` anchor and a `depends_on` with `condition: service_healthy`.
- The portal builds on first container start (it needs the API); bump `IMAGE_ID` per release. The php entrypoint migrates and caches config; seeders are run by hand (UPGRADE.md).
- Backups: `scripts/backup.sh` / `restore.sh`. Anything new that holds state (a volume) must be added to both.

## Catalog (E1)
- Names: the blog already owns `Category` / `categories`, so shop categories are `ProductCategory` / `product_categories`, route `product-categories`, permissions `product-categories.*`. Brands: `brands.*`; products, variants and images: `products.*` (`products.publish` for publish/unpublish/archive).
- English only: no `_2nd` columns, no `HasSecondLanguage`, no `Bilingual::rules`.
- Everything sold is a variant. A product without options (`has_variants` false) owns one default variant, so cart, stock and orders always reference `variant_id`. Product create takes `variants` (at least one); later changes go through `products/{id}/variants` and `variants/{id}`. Options are `{"Color":"Red"}` in a jsonb column (model accessor keeps `{}`, never `[]`); all variants of a product use the same option names; one variant per combination.
- Money: `price`, `compare_at_price`, `cost_price` are BIGINT minor units in, `{amount, currency}` out (`ProductVariantResource`). `cost_price` appears only in admin resources, never in `PublicProductResource`. `VariantRules` holds the shared rules for both create paths.
- `status` (draft|active|archived) is not fillable and not in the update request; only `ProductController::publish|unpublish|archive` change it, and publish runs `ProductPublishGuard`.
- Slugs: optional on create, generated by `Services/SlugService` (soft-deleted rows keep theirs reserved).
- Storefront visibility lives in one place: `Product::scopeStorefront()` (active product, active category, one active variant). Public reads add `?category=<slug>` (includes subcategories), `brand`, `q`, `min_price`, `max_price`, `sort`, `per_page` (`ListPublicProductsRequest`).
- Category depth (3) and cycles are checked by `Rules/ValidCategoryParent`.
- Images: `product_images` rows via `POST products/{id}/images` (first one is primary), `PATCH|DELETE product-images/{id}`, `PUT products/{id}/images/reorder`; categories and brands use the single-image endpoint pattern.
- Sample data: `Site/SiteCatalogSeeder` (manual, like the other `Site*Seeder`s).
- Tests: `tests/Feature/Catalog`; `catalogProduct()` and `assertInvalidFields()` are in `tests/Pest.php` (validation errors live under `error.details`).

## Purchasing (E2)
- Permissions: `suppliers.*`; `purchase-orders.view|create|update|delete|order|cancel`. Lines are part of the order and use `purchase-orders.update`. Admin only, no public routes, no content webhook (private data).
- Draft, then posted: a draft purchase order is editable; `POST purchase-orders/{id}/order` posts it and it is immutable afterwards (every write returns 422 on `status` via `PurchaseOrder::assertDraft()`). Fix a posted order by cancelling it and creating a new one. Only a draft can be deleted; only an `ordered` order with nothing received can be cancelled.
- `partially_received` / `received` and `lines.quantity_received` are written only by E3's check-in (through `StockService` flows), never by the purchasing endpoints.
- Numbers come from the Postgres sequence `purchase_order_number_seq` (`PO-000001`); the migration uses `IF NOT EXISTS` because `migrate:fresh` does not drop sequences.
- Money: integer minor units in, `{amount, currency}` out; totals (`subtotal`, `tax_total`, `grand_total`) and line totals are computed only by `Services/PurchaseOrderService` and never accepted from a client. Tax is per line in basis points (100 = 1%), rounded half-up per line (the tax-rate module arrived in E8, but purchase orders still take `tax_rate_bp` per line). `unit_cost` defaults to the variant's `cost_price`. Lines snapshot `sku` and `name`.
- Line endpoints return the whole order because totals change: `POST purchase-orders/{id}/lines`, `PATCH|DELETE purchase-order-lines/{id}`.
- A supplier with purchase orders can be deactivated, not deleted (422). Tests: `tests/Feature/Purchasing`.

## Check-in and stock ledger (E3)
- Permissions: `goods-receipts.view|create|update|delete|post`; `stock.view` (read-only `GET stock/levels`, `GET stock/movements`, guarded by `can:stock.view`). Admin only, no public routes, no content webhook.
- `Services/StockService::apply()` is the only writer of stock. It runs in a transaction, locks the variant's `stock_levels` row, appends one `stock_movements` row (signed `quantity`, `balance_after`, `type`, `reference_type` = source table, `reference_id`) and refuses a negative result (422 on `quantity`). Later phases add movement types and call the same method; never write `stock_levels` or `stock_movements` anywhere else.
- `stock_movements` is append-only: a Postgres trigger rejects UPDATE and DELETE. Correct a mistake with a new document (a reverse movement), never an edit.
- Goods receipt: draft, then posted (same shape as purchase orders). A receipt belongs to one `ordered` or `partially_received` purchase order. Lines are `purchase_order_line_id` + `quantity` (+ optional `unit_cost`, default the order line's cost); the quantity cannot exceed what is outstanding. `PATCH` with `lines` replaces all lines of a draft. A draft does not touch stock.
- `POST goods-receipts/{id}/post` (`GoodsReceiptService::post`) locks the receipt, order and order lines, re-checks outstanding quantities (two drafts can race), writes the movements, adds to `purchase_order_lines.quantity_received`, and sets the order to `partially_received` or `received`. Posted receipts cannot be changed or deleted (422 on `status`).
- Numbers come from the sequence `goods_receipt_number_seq` (`GR-000001`); like `purchase_order_number_seq` they are not rolled back by tests, so assert the format, not the value.
- Tests: `tests/Feature/Inventory`.

## Damage, returns, adjustments, stock counts (E4)
- One table pair (`stock_documents`, `stock_document_lines`), one `StockDocumentController`, one `StockDocumentPolicy`; the route carries the type (`->defaults('type', ...)` in a loop over `StockDocument::TYPES`). A document reached through the wrong URL is a 404; permissions follow the document's real type.
- Types, URLs, permissions (`<prefix>.view|create|update|delete|post`) and numbers: damage `/damages` `DM-`, supplier return `/supplier-returns` `SR-`, adjustment `/adjustments` `ADJ-`, stock count `/stock-counts` `SC-`. One sequence `stock_document_number_seq` (not rolled back by tests: assert the format).
- Draft, then posted, as in E2/E3: `PATCH` with `lines` replaces all lines, `POST <path>/{id}/post` posts. Posted documents cannot be changed or deleted (422 on `status`); correct them with a new document. Drafts never touch stock.
- Posting (`StockDocumentService::post`) goes only through `StockService`: damage and supplier return remove `quantity` (positive on the document), an adjustment applies its signed non-zero `quantity`, a count calls `StockService::count()` (locks the level, writes one `count` movement for counted minus on-hand, none when equal) and stores `expected_quantity` per line (`variance` in the Resource). One negative result rolls the whole post back (422 on `lines`). Movement types: `damage`, `return_to_supplier`, `adjustment`, `count`; the document's `reason` is the movement `note`.
- "Returns" here are returns to a supplier. Customer returns need orders and come with E6/E10.
- Tests: `tests/Feature/Inventory/StockDocumentsTest.php`.

## Payment framework (E5)
- Code lives in `app/Payments`: `PaymentProvider` (interface: `code()`, `initiate()`, `refund()`), `PaymentRequest`/`PaymentResult` (value objects), `PaymentManager` (the only entry point) and `Drivers/<Name>Provider`. Nothing else calls a driver.
- Registry: `config/payments.php` `drivers` maps method code to driver class. A new PSO/PSP is one driver class plus one registry line (plus a `payment_methods` row); checkout code does not change.
- `payment_methods` (code, name, `is_enabled`, sort, `credentials`): credentials use the `encrypted:array` cast, are `#[Hidden]` and must never be logged or put in a Resource. A disabled or unknown method is a 422 on `payment_method`.
- `payment_events` is the log: one row per action (`initiate`, `refund`), unique `idempotency_key`; repeating a key returns the stored event and never calls the driver again. Amount is BIGINT minor units plus `currency` (`config('payments.currency')`, BDT). `reference_type`/`reference_id` point at the order (E6).
- COD (`CodProvider`) is the only v1 driver: `initiate` returns `pending` (cash is reconciled later, E10), `refund` returns `refunded`. Seeded by `PaymentMethodsSeeder` with `firstOrCreate`.
- No HTTP routes yet: admin payment-method management comes with E10, checkout with E6. Tests: `tests/Feature/Payments`.

## Cart, checkout, orders (E6)
- Customers: `Customer` model on the separate Passport guard `customer` (provider `customers`), never on `api`. `POST customer/register|login` return a Bearer token (personal access, issued through the client `CustomerClientSeeder` creates); `GET customer/me`, `POST customer/logout`, `GET customer/orders[/{number}]`, `POST customer/orders/{number}/cancel` (own, pending only) sit behind `auth:customer`. A customer token gets 401 on admin routes and the reverse. Tests authenticate with `Passport::actingAs($customer, [], 'customer')`.
- Cart: `Services/CartService`, Redis through the default cache store, key `cart:<sha256(token)>`, `shop.cart_ttl_days`. It stores only variant id => quantity. `POST public/carts` returns the token; `GET public/carts/{token}`, `POST .../items`, `PATCH|DELETE .../items/{variantId}`. Unknown token is 404.
- Prices: only `Services/PricingService::quote()` computes them, from the database, on every cart read and at checkout (`strict` throws 422 for an unsellable line or short stock). Flat shipping `shop.shipping_flat` (E7 replaced it with zones); tax and coupon discounts came with E8. Clients never send a price.
- Checkout `POST public/carts/{token}/checkout` works for guests and signed-in customers (optional Bearer): `OrderService::place()` creates a `pending` order with contact/address snapshot and `order_items` snapshots (sku, name, options, unit price, tax), starts the payment through `PaymentManager::initiate()` (key `order-<id>-initiate`), then empties the cart. The response carries `lookup_token`; a guest reads the order with `GET public/orders/{number}?token=`. The token is hidden everywhere else.
- Lifecycle (`OrderService`, row locked): pending, confirmed, packed, shipped, delivered; cancel from pending, confirmed or packed. Admin `POST orders/{id}/advance` moves one step. Confirm writes `sale` movements (negative) through `StockService` and fails 422 if any line is short (nothing changes); cancelling a confirmed or packed order writes `sale_cancel` movements. Pending cancels touch no stock. Other transitions are 422 on `status`.
- Admin: `orders.view` (list with `status`, `payment_status`, `q`; show), `orders.update` (advance), `orders.cancel`; menu Sales > Orders; seeders `OrdersModuleSeeder`, `CustomerClientSeeder`. Order numbers come from `order_number_seq` (`ORD-000001`, assert the format in tests).
- Not yet: delivery zones, COD remittance and reconciliation (E7). Tests: `tests/Feature/Orders`.

## Delivery, COD remittance, reconciliation, reports (E7)
- Permissions: `delivery.view|manage` (zones, couriers, assigning a courier), `cod-remittances.view|create|post|delete`, `reports.view`; seeder `DeliveryModuleSeeder` (menus Delivery and Reports). List/show routes use `can:` middleware, writes check the permission in their FormRequest.
- Zones: `delivery_zones` (name, `shipping_fee` minor units, `is_active`, sort). `GET public/delivery-zones` feeds checkout; checkout and `GET public/carts/{token}?delivery_zone_id=` take the zone and `PricingService::quote(..., zoneId:)` uses its fee, else `shop.shipping_flat`. The order stores `delivery_zone_id` and its own `shipping_total`; changing a fee never touches placed orders.
- Couriers: `couriers`; one with deliveries is deactivated, not deleted (422).
- Delivery: `POST orders/{id}/delivery` (`DeliveryService::assign`) for a packed or shipped order creates or updates the order's `deliveries` row; `cod_amount` is the order grand total when `payment_method` is `cod`, else 0, set server-side. An order cannot move to shipped without a delivery (422 on `delivery`). Delivering writes `collected_amount`/`collected_at` and sets COD orders to `payment_status` paid (the courier now holds the cash).
- COD remittance: `cod_remittances` (`RM-000001` from `cod_remittance_number_seq`), draft then posted like E3/E4. `POST cod-remittances` takes `courier_id` + `delivery_ids` (collected, same courier, not in another remittance) and computes `expected_amount`; `POST cod-remittances/{id}/post` takes `received_amount` and stores `difference` (received minus expected). Posted ones are immutable (422 on `status`); deleting a draft releases its deliveries. There is no edit: delete the draft and create a new one.
- Reports (`reports.view`, read-only): `reports/sales?from&to` (per day, cancelled orders excluded, counts by status), `reports/cod-reconciliation` (per courier: collected, remitted expected and received, difference, outstanding), `reports/stock-valuation` (cost and retail value of on-hand stock).
- Throttles for the storefront are named limiters (`storefront` 60/min, `checkout` 10/min) in `AppServiceProvider`: unnamed `throttle:N,M` limiters on one request share a single counter, so never nest them.
- Tests: `tests/Feature/Delivery`.

## Tax, coupons, wishlist, reviews (E8)
- Permissions: `tax-rates.view|manage`, `coupons.view|create|update|delete`, `reviews.view|moderate|delete`; seeder `PromotionsModuleSeeder` (menu Promotions). Reads use `can:` middleware, writes check their permission in the FormRequest, as in E7.
- Tax: prices are tax-exclusive. `tax_rates` (`rate_bp`, 100 = 1%, one default enforced by a partial unique index); `products.tax_rate_id` (nullable, set through the product endpoints) falls back to the default rate, then to 0. `PricingService` adds tax per line on the discounted line amount, rounded half-up (`intdiv(x * bp + 5000, 10000)`); orders snapshot `tax_rate_bp` and `tax_amount` per item, so rate edits never touch placed orders. A rate used by a product cannot be deleted (422).
- Coupons: `coupons.code` is stored upper-case; `type` `percent` (`value` in basis points, optional `max_discount`) or `fixed` (`value` in minor units); `min_subtotal`, `starts_at`/`ends_at`, `usage_limit`, `used_count` (written only by `CouponService`). The code travels as `?coupon_code=` on the cart (a preview: a bad code gives `coupon_error`, not a 422) and as `coupon_code` at checkout (a bad code is 422 on `coupon_code`). The discount comes off the subtotal, is spread over the lines in proportion (remainder on the last line, `order_items.discount_amount`), and never exceeds the subtotal; shipping is not discounted. `grand_total = subtotal - discount + tax + shipping`.
- Redemption: `OrderService::place()` calls `CouponService::redeem()` inside the order transaction (locks the coupon, re-checks the limit, one `coupon_redemptions` row per order); cancelling the order calls `release()` and gives the use back. A redeemed coupon cannot be deleted (deactivate it). There is no per-customer limit: guests cannot be tracked.
- Wishlist (customer guard): `GET customer/wishlist`, `PUT|DELETE customer/wishlist/{productId}`; adding is idempotent and only storefront-visible products can be added; the list returns `PublicProductResource`.
- Reviews: `POST customer/products/{slug}/reviews` needs a delivered order containing the product (422 on `product`), one review per customer and product, starts `pending`. `GET public/products/{slug}/reviews` lists approved reviews (author name only) with `summary.count` and `summary.average`. Admin: `GET reviews`, `POST reviews/{id}/approve|reject`, `DELETE reviews/{id}`.
- Tests: `tests/Feature/Promotions`.
