# gd-ecommerce-starter

## Repo map
- `api/` — Laravel API (PHP 8.5, PostgreSQL 18.6; Redis 8.10.3 for cache, sessions, queue, carts, throttles; English only)
- `web/admin` — Next.js admin app
- `web/portal` — Next.js public portal
- `packages/` — shared packages
- `docker/` — Dockerfiles and compose config
- `docs/` — PHASES.md, VERSIONS.md, ENV.md, DECISIONS.md, PATTERNS.md

## Commands (docker compose directly, not make)
- `docker compose up -d --build --wait` / `docker compose down --remove-orphans` — start / stop the stack
- `sh scripts/check-ports.sh` — check host ports from .env are free or ours
- `docker compose exec -T php vendor/bin/pest --compact --filter=...` — targeted Pest tests
- Final check (once, at the end of a phase; needs the stack up): `docker compose config -q`, `APP_KEY=check docker compose -f docker-compose.prod.yml --env-file .env.prod.example config -q`, `sh -n` on scripts and docker/*/*.sh, `docker compose exec -T nginx nginx -t`, then in php: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `vendor/bin/pest --compact`, `composer audit`, `php artisan security:routes`; plus `sh scripts/check-secrets.sh`, `node scripts/check-floors.mjs`, `sh scripts/check-versions.sh`. No make.

## Rules
1) One phase at a time; read only CLAUDE.md, the phase row and files it names.
2) No repo-wide exploring, no unrelated refactors, no dependency not in VERSIONS.md.
3) Reuse the reference patterns in docs/PATTERNS.md; do not invent new ones.
4) While working run only targeted tests (pest --filter=...); run the final check once at the end.
5) Keep command output short (quiet flags, | tail -n 20). Never paste logs back.
6) No screenshots unless the phase says so.
7) Finish: tick the phase, git commit "phase N: title", reply in 8 lines or fewer
   (done, deviations, decisions I must make), then STOP and wait for approval.
8) Commits and PRs: never add a Claude signature, Co-Authored-By trailer, "Generated with" line
   or any other Claude attribution to commit messages, PR descriptions, code or docs. Anywhere.
   Commit messages are plain: "phase N: title" plus an optional short body.

## Phase workflow (every phase)
1. Start from an up-to-date main (`git checkout main && git pull origin main`); create `feature/phase-<no>-<kebab-task-name>`
   (the branch for each phase is listed in docs/PHASES.md).
2. Work only on that branch. Plain commit messages, no attribution.
3. When the final check passes, tick docs/PHASES.md, commit, `git push -u origin <branch>`.
4. Give the pull request link (https://github.com/sadrulhossain/gd-ecommerce-starter/compare/main...<branch>?expand=1, or `gh pr create --base main`
   with a plain title and body) and ask: "Please merge this pull request manually and tell me when the merge is done."
5. Stop. Never merge the pull request, never push to main, never start the next phase without the confirmation.
6. After the merge is confirmed: switch to main, pull, start the next phase from step 1.

## Design rules (invariants for every phase)
- Language: English only (`LOCALE_SUPPORTED=en`); do not enable a second language.
- Money: integer minor units (BIGINT) plus currency code (BDT); one formatting helper in the shared package; never floats.
- Stock: StockService is the only writer; stock_movements is append-only; stock_levels is a locked, transactional cache of the ledger.
- Documents: receipts, adjustments, returns and counts are draft, then posted; posted documents are immutable and corrected by a new document.
- Payments: every method implements PaymentProvider behind PaymentManager (E5); COD is the only v1 driver; a PSO/PSP is one driver class plus one
  registry entry, no checkout change; every payment event carries an idempotency key; credentials encrypted, never logged.
- Prices: computed server-side only by PricingService; orders store snapshots of name, price and tax.
- Auth: customers use a separate guard from admin users.
- Theme: the admin theme stays as inherited; portal styling changes only through the portal token layer and the E11 components (see docs/THEME.md).
