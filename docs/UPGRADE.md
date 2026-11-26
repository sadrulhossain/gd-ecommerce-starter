# Upgrade

Two kinds of upgrade: **your site to a newer release of this starter kit**, and **the pinned software** (PHP, Node, nginx, PostgreSQL, Redis, packages). Both end the same way: back up, deploy, check.

## Before every upgrade

1. Read [CHANGELOG.md](../CHANGELOG.md) from your current version to the new one. It lists new environment variables, seeders to run and anything that needs a manual step.
2. `sh scripts/backup.sh` and copy the backup off the server. Know your rollback target (the current tag).
3. Compare `.env.prod.example` with your `.env.prod` and add any new variable (`git diff vOLD vNEW -- .env.prod.example docs/ENV.md`).

## Upgrade the site to a new release

```sh
cd /opt/site
git fetch --tags
git checkout v1.1.0                      # the new tag
# set IMAGE_ID in .env.prod to the new version (it makes the portal rebuild once)
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build --wait
```

What happens: the images are rebuilt, the php container runs `migrate --force` before it reports healthy, the portal rebuilds its site on first start of the new image, nginx rebuilds the admin.
A few minutes of the portal answering with errors is normal while it rebuilds; nginx and the API stay up. Pages are served again once `portal` is `healthy`.

Then run what the changelog asks for, typically new seeders (they only add missing rows):

```sh
docker compose -f docker-compose.prod.yml --env-file .env.prod exec php php artisan db:seed --class=<NewModuleSeeder> --force
```

Finish with the checks from DEPLOY.md step 5, sign in to the admin, open the portal in both languages and send a test contact message.

## Upgrade pinned software

The single source of truth is [VERSIONS.md](VERSIONS.md): a **pin** (what we build against) and a **floor** (never go below). The routine, in a branch of your fork:

1. Look up the new version and its advisories (links are in the table). Never go below a floor.
2. Change the pin in `docs/VERSIONS.md` (with source and date), then in `.env.example`, `.env.prod.example` (and your `.env` / `.env.prod`) for images, or in `composer.json` / `package.json` / `pnpm-workspace.yaml` for packages. Pins in package files are exact, no ranges.
3. `docker compose exec php composer update <package>` or `pnpm up <package>@<version>`; commit the lock file.
4. The README checks (Pest, Pint, Larastan, composer audit, security gates) and the Playwright suites. Fix what breaks before merging.
5. Deploy as above. Image pins (PHP, Node, nginx, PostgreSQL, Redis) rebuild or pull new images on `up -d --build`.

Notes per component:

- **PostgreSQL**: minor updates (18.x) are safe: change the pin, back up, deploy. A major jump needs `pg_upgrade` or a dump and restore; take a backup and test the restore on a spare server first.
- **Redis**: minor updates are safe; the data is rebuildable (cache, sessions, carts, queue), but let the queue drain before upgrading.
- **PHP**: the pin is the patch release; rebuild the image. Read the release notes for removed ini settings (`docker/php/security.ini`).
- **Node / Next.js**: rebuilds the portal image; the portal rebuilds its site on start. Keep `pnpm audit` clean and re-run the axe and smoke specs.
- **nginx**: re-run `nginx -t` (the compose healthcheck fails if the config does not load).
- Run `docker run aquasec/trivy image <image>` on the new images when you want the scan of record (see SECURITY.md for how results are recorded).

## Rollback

If the new version misbehaves:

```sh
git checkout v1.0.0                      # the previous tag; restore IMAGE_ID in .env.prod
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build --wait
```

Code rolls back this way; the **database does not**. If the release ran migrations that changed data, restore the backup you took before upgrading:

```sh
sh scripts/restore.sh backups/<timestamp-before-upgrade>
```

Migrations in this boilerplate are written to be additive, but a restore is the only guaranteed way back. This is why the backup comes first.

## Merging upstream into a fork

Your shop is a fork of this kit, and the kit is a fork of the business-site template. Merge one level at a time: the kit from its upstream, your shop from the kit.

```sh
git remote add upstream <url of the repository you forked>
git remote set-url --push upstream DISABLED     # never push to it by mistake
git fetch upstream --tags
git checkout -b upgrade/v1.1.0 main
git merge v1.1.0                                # merge a release tag, not a moving branch
```

Then, in this order:

1. Resolve conflicts. Expect them in `docs/`, `packages/tokens/*.css`, `web/portal/messages/*.json`, `DatabaseSeeder.php`, `config/payments.php` and `routes/api.php`. For `api/composer.lock` and `pnpm-lock.yaml` take one side, then run the package manager again.
2. Never edit a migration that already ran on your site; add a new one. If upstream and you both added migrations, both stay.
3. Rebuild, run `php artisan migrate`, the CLAUDE.md final check, `php artisan stock:verify`, `php tests/load/checkout.php` and the Playwright suites.
4. Merge the branch, tag your own release, deploy as above.

Fixes from the business-site template can be merged into the kit the same way, but the kit replaced its database (MariaDB to PostgreSQL) and added Redis: review every database and Docker change by hand.
Keep your customisations in the files [CUSTOMIZE.md](CUSTOMIZE.md) names; that keeps merges small.
