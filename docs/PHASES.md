# Phases

Tick a phase only when it is finished and committed.

| Done | # | Phase | Scope / files |
|------|---|-------|---------------|
| [x] | 0 | Repo scaffold and docs | .gitignore, .editorconfig, CLAUDE.md, docs/* |
| [x] | 1 | Docker infrastructure | docker-compose.yml, docker/*, scripts/check-ports.sh, .env.example, hello apps in web/admin, web/portal, api/public |
| [x] | 2 | Laravel API | api/*, Dockerfile (composer, phpredis), /api/v1/health, /api/v1/meta/locales, JSON errors, Pest/Pint/Larastan/Scramble |
| [x] | 3 | Auth and RBAC | Passport 13, spatie/laravel-permission, menus table, /me, /me/menus, seeders, access tests |
| [x] | 4 | Settings, sections, media | settings (singleton), sections, MediaService + WebP queue, HasSecondLanguage, PATTERNS.md |
| [x] | 5 | FAQs, services, testimonials, team, gallery | migration + model + FormRequests + Resource + Policy + public/admin routes + seeder + feature test per module; image endpoints |
| [x] | 6 | Blog, contact, webhook, OpenAPI | posts + categories (HTMLPurifier sanitising), public POST /contact (Redis captcha, honeypot, throttle, queued mail), admin inbox, ContentObserver -> signed revalidate webhook, `?lang=` on public reads, packages/api-types/openapi.json |
| [x] | 7 | Frontend workspace | pnpm workspace (root package.json, pnpm-workspace.yaml, one pnpm-lock.yaml); packages/tokens (Tailwind 4 @theme, admin + portal themes), packages/api-types (openapi-typescript), packages/api-client (typed fetch); Tailwind 4 in web/admin (Vite) and web/portal (Next); one showcase route each (/showcase); docker/node/dev.sh on pnpm |
| [x] | 8 | Admin shell | API login page + PKCE login, memory-only tokens with single-flight refresh, route guards, sidebar from /me/menus, permission-gated routes, 403/404, dashboard counts; shared DataTable/FormField/BilingualField/ConfirmDialog/Toast; config-driven CrudPage; TanStack Query + Zustand; Vitest (store, guards); Playwright login + dashboard screenshots |

| [x] | 9 | Admin modules | users, roles (permission matrix), menus (tree + reorder), settings (tabbed bilingual singleton), sections (visibility toggle, drag reorder) as CrudPage configs; API: users, roles, permissions, menus, reorder endpoints; Vitest on configs |
| [x] | 10 | Content modules | services, FAQs, testimonials, team, gallery as CrudPage configs with image upload (progress); gallery multi-upload; contact messages read-only list/detail + mark read; Vitest on configs and upload helpers |
| [x] | 11 | Blog and profile | posts CrudPage config (TipTap rich text, bilingual, draft/published `status`, cover upload, SEO fields), categories, profile page (name, password: PATCH /me); posts SEO columns; Vitest on configs |
| [x] | 12 | Portal foundation | Next App Router, locale routing (default unprefixed, second at /<code>) with messages/en.json + second.json, header/footer from public settings, generateMetadata, language switcher, section renderer (is_visible), Home, signed revalidate route; API: public settings + sections; Home screenshots per language |
| [x] | 13 | Portal pages | About, Services, Service detail (/services/<id>), Gallery, FAQ, Privacy, Terms, localised 404, in both languages; pages reuse the section views; messages fall back to the default text; API public service detail |
| [x] | 14 | Blog, contact, SEO | Blog list (category filter, pagination) and detail, contact page (captcha, honeypot, states, hours, map link), metadata/Open Graph/canonical/hreflang, sitemap.xml, robots.txt, JSON-LD, ISR tags + revalidate, Playwright smoke |
| [x] | 15 | Security, accessibility, performance | Advisory and floor re-check, composer/pnpm audit, Trivy; review of PKCE, policies (route audit script), uploads, captcha/throttle, CORS, nginx headers, secrets; axe + Lighthouse on the portal; memory sizing; findings in docs/SECURITY.md |
| [x] | 16 | Production and release | docker-compose.prod.yml (portal Node server, admin static via nginx, TLS from mounted certs, no dev ports/Mailpit, private DB, restart + healthchecks), backup/restore scripts, README, DEPLOY, CUSTOMIZE, UPGRADE, CHANGELOG, tag v1.0.0 |
| [x] | 17 | Polish and theming | admin splash, collapsible nested sidebar menu, logo + favicon upload (settings, admin, portal), manual `Site*Seeder` sample data, portal redesign, mobile-friendly admin and portal |


## Ecommerce starter phases

Phases 0-17 above are inherited from the business site (all done). E0-E14 are this kit's phases; each is built on its own branch and merged by hand.

| Done | # | Phase | Size | Session | Branch |
|------|---|-------|------|---------|--------|
| [x] | E0 | Fork from main, PostgreSQL swap, Redis, green suite | M | ES1 | feature/phase-e0-fork-postgresql-swap-redis |
| [x] | E1 | Catalog API (categories, brands, products, variants) | M | ES1 | feature/phase-e1-catalog-api |
| [x] | E2 | Suppliers and purchase orders API | M | ES2 | feature/phase-e2-suppliers-purchase-orders-api |
| [x] | E3 | Check-in (goods receipt) and stock ledger | L | ES2 | feature/phase-e3-check-in-stock-ledger |
| [x] | E4 | Damage, returns, adjustments, stock counts API | M | ES3 | feature/phase-e4-damage-returns-adjustments-stock-counts |
| [x] | E5 | Payment framework: interfaces, registry, COD driver | M | ES3 | feature/phase-e5-payment-framework |
| [x] | E6 | Cart, checkout, order lifecycle API | L | ES4 | feature/phase-e6-cart-checkout-orders |
| [x] | E7 | Delivery, COD remittance, reconciliation, reports API | M | ES4 | feature/phase-e7-delivery-cod-remittance-reconciliation-reports |
| [ ] | E8 | Tax, coupons, wishlist, reviews API | M | ES5 | feature/phase-e8-tax-coupons-wishlist-reviews |
| [ ] | E9 | Admin: catalog, suppliers, purchasing, promotions | M | ES5 | feature/phase-e9-admin-catalog-suppliers-purchasing-promotions |
| [ ] | E10 | Admin: stock, check-in, orders, returns, reconciliation, payment methods | L | ES6 | feature/phase-e10-admin-stock-orders-returns-reconciliation-payment-methods |
| [ ] | E11 | Portal theme: ecommerce design system | M | ES6 | feature/phase-e11-portal-theme-ecommerce-design-system |
| [ ] | E12 | Portal: shop listing, product page, cart | M | ES7 | feature/phase-e12-portal-shop-product-cart |
| [ ] | E13 | Portal: checkout, account, tracking, SEO | M | ES7 | feature/phase-e13-portal-checkout-account-tracking-seo |
| [ ] | E14 | Hardening and release | L | ES7 | feature/phase-e14-hardening-release |

### E0 notes (done)

Branch: `feature/phase-e0-fork-postgresql-swap-redis` (from template `main` at `3eade4d`; origin `main` holds the untouched template).

Done:
- [x] Template cloned on `main`, base hash in DECISIONS.md 012, `upstream` push disabled, untouched `main` pushed to origin, phase branch created
- [x] Task 1: rename to gd-ecommerce-starter (project, containers, domain, DB name); host ports moved to the 30xxx block (sibling stacks use 28xxx/29xxx)
- [x] Task 2: PostgreSQL 18.6 service, `pdo_pgsql`, env vars `DB_*`, MariaDB removed (dev and prod compose, backup/restore/test-db scripts)
- [x] Task 4 (files): Redis 8.10.3 service with ACL (`docker/redis/start.sh`), phpredis 6.3.0 (builds on PHP 8.5, Predis not needed), cache/session/queue = redis, queue and scheduler containers wired to postgres and redis
- [x] Task 5: docs/THEME.md; Task 6: docs/TRIM.md (empty, nothing trimmed)
- [x] Task 7: docs/VERSIONS.md, floors script, `scripts/check-versions.sh` (replaces `make versions`)
- [x] Task 8: E0-E14 rows here
- [x] Task 9: phase workflow, design rules and commit rule in CLAUDE.md
- [x] Makefile removed; README, ENV, DEPLOY, UPGRADE, SIZING, SECURITY, OVERVIEW updated; English only (`LOCALE_SUPPORTED=en`)
- [x] Images built; postgres and redis start healthy; dev and prod compose files validate
- [x] Task 3: `migrate:fresh --seed` clean on PostgreSQL; Redis health, queue and scheduler running; whole Pest suite green (201 tests); final check passed
