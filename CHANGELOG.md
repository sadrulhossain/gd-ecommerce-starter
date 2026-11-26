# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versions follow [SemVer](https://semver.org).
Each entry lists new environment variables and seeders to run, because upgrades need them (see docs/UPGRADE.md).

## [1.0.0] - 2026-11-26

First release of gd-ecommerce-starter, built on the business-site template (below).

### Added
- **Database and runtime:** PostgreSQL 18.7 replaces MariaDB; Redis 8.10.3 for cache, sessions, queue, carts and throttles; queue and scheduler containers. English only.
- **Catalog:** categories, brands, products, variants, images; money as integer minor units (BDT).
- **Purchasing and stock:** suppliers, purchase orders, goods receipts, an append-only stock ledger with one writer, damages, supplier returns, adjustments, stock counts.
- **Selling:** payment framework with a cash-on-delivery driver, cart, guest and account checkout, order lifecycle, delivery zones, couriers, COD remittance and reconciliation, reports, tax rates, coupons, wishlist and review APIs.
- **Admin:** screens for catalog, purchasing, promotions, stock, orders, delivery, reconciliation, reports and payment methods.
- **Portal:** ecommerce design system, shop, product page, cart, checkout, account, order tracking, shop SEO.
- **Hardening:** stock reservation at checkout, double-submit protection, `stock:verify`, a checkout load test, an append-only audit log of money changes, rate limits on customer routes, 7-day customer tokens, restore test.

### Changed
- PHP 8.5.13, nginx 1.30.8, Laravel 13.44.1, Symfony 8.1.12 (security releases).

### Upgrade notes
- New environment variables: none in E14 (see docs/ENV.md and `.env.prod.example`). Image pins changed: update `PHP_VERSION`, `NGINX_VERSION` and `POSTGRES_VERSION`.
- Migrations run on start. Seeders: `php artisan db:seed --force` once on first deploy.

### Known limits
- Offline payment methods only: no gateway redirect, webhook or unpaid-order expiry (docs/PAYMENTS.md). Pending orders hold stock until confirmed or cancelled.
- One warehouse; no courier API, customer returns, customer address book or order emails.
- The audit log has no admin screen. The portal CSP still allows inline scripts.

## Business-site template 1.0.0 - 2026-11-13

First release.

### Added
- **API** (Laravel 13, PHP 8.5, MariaDB): sections, services, team, testimonials, gallery, FAQs, blog (categories, posts, sanitised rich text), contact inbox with captcha, honeypot and throttle,
  settings, users, roles and permissions, per-user menus, dashboard, image uploads with WebP variants, content-change webhook, OAuth 2 with PKCE (Passport), OpenAPI export.
- **Admin** (Vite, React 19): sign-in through the authorization-code flow with PKCE, CRUD screens driven by `modules.ts`, TipTap blog editor, profile, role matrix, bilingual fields.
- **Portal** (Next.js 16): Home and section pages, services, gallery, FAQ, blog (categories, pagination), contact page, legal pages, localised 404, two languages, ISR with tag revalidation,
  canonical/hreflang/Open Graph, `sitemap.xml`, `robots.txt`, JSON-LD.
- **Security**: S256-only PKCE, closed CORS, security headers and CSP per vhost, per-visitor throttling, upload validation by content, security gates (route/policy audit, secrets scan, version floors).
  Findings and scans: docs/SECURITY.md.
- **Quality**: Pest, Larastan, Pint; Vitest; Playwright smoke and axe suites (WCAG 2 A/AA, both languages, zero violations); Lighthouse SEO 100.
- **Production** (`docker-compose.prod.yml`, `.env.prod.example`): portal as a Node server, admin built static and served by nginx, TLS from mounted certificates, only ports 80/443 published,
  private MariaDB, restart policies, healthchecks, rotated logs, uploads path from env. `scripts/backup.sh` and `scripts/restore.sh` (database, uploads, OAuth keys).
- **Docs**: README, DEPLOY, CUSTOMIZE, UPGRADE, ENV, PATTERNS, DECISIONS, SECURITY, SIZING, VERSIONS, PHASES.

### Notes
- Environment variables: see docs/ENV.md and `.env.prod.example`. Seeders: run `php artisan db:seed --force` once on first deploy.
- The database is MariaDB 11.8 and there is no Redis (docs/DECISIONS.md, 010); backups use `mariadb-dump`.
- Known limits: the portal CSP still allows inline scripts (nonces are the next step); Lighthouse performance 90+ was not reached under the default mobile throttle on the slow development host (docs/SECURITY.md).


[1.0.0]: https://github.com/sadrulhossain/gd-ecommerce-starter/releases/tag/v1.0.0
