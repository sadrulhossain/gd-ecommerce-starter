# gd-business-site-boilerplate

## Repo map
- `api/` — Laravel API (PHP 8.5, MariaDB; cache, sessions and queue use the database)
- `web/admin` — Next.js admin app
- `web/portal` — Next.js public portal
- `packages/` — shared packages
- `docker/` — Dockerfiles and compose config
- `docs/` — PHASES.md, VERSIONS.md, ENV.md, DECISIONS.md, PATTERNS.md

## Commands (docker compose directly, not make)
- `docker compose up -d --build --wait` / `docker compose down --remove-orphans` — start / stop the stack
- `sh scripts/check-ports.sh` — check host ports from .env are free or ours
- `docker compose exec -T php vendor/bin/pest --compact --filter=...` — targeted Pest tests
- Final check (once, at the end of a phase; needs the stack up): `docker compose config -q`, `sh -n` on scripts, `docker compose exec -T nginx nginx -t`, then in php: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G --no-progress`, `vendor/bin/pest --compact`, `composer audit`, `php artisan security:routes`; plus `sh scripts/check-secrets.sh`, `node scripts/check-floors.mjs`

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
