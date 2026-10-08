# Docker application setup

Docker packages the existing Laravel monolith without changing its product or schema. [ADR-0007](adr/ADR-0007-CONTAINER-RUNTIME.md) records the runtime decision. The default Compose stack is for local development; the `production` Dockerfile target is a separate deployable image. Production hosting remains subject to [deployment and recovery](DEPLOYMENT.md) and the open [Phase 7 release gates](PHASE_7_CLOSEOUT.md#remaining-release-gates).

## Local development

Install Docker Engine/Desktop with Compose v2 or later. On WSL, enable Docker Desktop integration and keep the checkout on the Linux filesystem. Host PHP/Composer/Node are optional for this workflow.

For a new checkout:

```bash
# Preserve an existing .env and APP_KEY.
test -f .env || cp .env.example .env
docker compose build app
# New installations only: generate the key once.
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d --wait
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=CategorySeeder
```

Open http://localhost:8000. Optionally create the existing ordinary local test login with `docker compose exec app php artisan db:seed --class=TestAccountSeeder` (`test@example.com` / `password`). Startup never runs migrations, generates keys or seeds users; those commands remain explicit.

Services:

| Service | Purpose | Local endpoint |
| --- | --- | --- |
| `app` | Apache/PHP 8.3, public document root, non-root application process | `127.0.0.1:8000` → container 8080 |
| `vite` | Node 22 development assets and hot reload | `127.0.0.1:5173` |
| `mysql` | Existing MySQL 8.4 with development/test databases | `127.0.0.1:3306` |

`APP_PORT`, `VITE_PORT` and `DB_FORWARD_PORT` change host ports. Update `APP_URL` when changing the app port. Containers always connect to `mysql:3306`; native PHP keeps using `.env`'s `127.0.0.1` and forwarded port. Do not use `localhost` as the database host inside containers.

Code/storage are bind-mounted; compiled views use a separate `compiled-views` volume because Livewire caches absolute paths that differ between host and container. The test lock remains shared. Set `LOCAL_UID`/`LOCAL_GID` to the output of `id -u`/`id -g` before building if your Linux account is not 1000:1000. The non-root development user writes with that identity. Do not solve permission errors with world-writable files. The named `composer-dependencies` and `node-dependencies` volumes isolate container dependencies from host installs; a new volume receives the image's installed dependencies. Rebuilding an image does not refresh an existing dependency volume, so synchronize after lockfile changes:

```bash
docker compose exec app composer install
docker compose exec app npm ci
docker compose restart vite
```

Useful commands:

```bash
docker compose logs --tail=50 app vite
docker compose exec app php artisan migrate:status
docker compose exec app composer ci:check
docker compose exec app npm run build
docker compose stop
```

PHP tests use the guarded `habit_log_test` database and shared bind-mounted test lock. Never overlap host/container test invocations or browser fixtures. Tests retain their existing local test credentials; if changing database credentials, update the test configuration consistently. The frontend build runs in the app's development image using Node 22. Vite owns `public/hot`; stop Vite before checking compiled assets and remove a stale `public/hot` only after confirming no Vite process is using it.

`docker compose stop` preserves all data. The existing `habit-log_mysql-data` volume is retained. **Do not run `docker compose down -v` on data you want to keep.** Changing MySQL initialization variables does not rewrite an existing volume's databases or credentials.

For the existing native workflow, start only `docker compose up -d --wait mysql`, then use host Composer/PHP/Node commands. Stop the container app/Vite before using the same host ports natively. The existing Playwright runner still uses its guarded host/WSL preview; installed-browser testing does not require adding browsers or system libraries to the application image.

## Production image

```bash
docker build --target production -t habit-log:production .
```

The multi-stage build installs Composer production dependencies from the lockfile, builds assets with `npm ci`, then copies only the runtime application into the final image. It excludes `.env` files, host caches/data, test code, development PHP dependencies and Node/Composer executables. Base tags track maintained PHP 8.3/Node 22/Composer 2 images; record the built image digest for a release and retain it for rollback. Locked packages do not freeze upstream base tags.

The image runs Apache as `www-data` on port 8080, serves only `public/`, writes PHP/application logs to stderr, and checks `/up`. Source is root-owned; `storage` and `bootstrap/cache` are writable. A health check alone does not prove database/mail/product readiness. Apache access logging omits query strings and token-bearing reset paths; review any upstream proxy logging separately.

Prepare a restricted runtime environment file outside the repository using the deployment runbook: production environment, debug off, stable application key, correct application origin/database, secure cookies for HTTPS, real mail configuration. Do not reuse the local `.env` or test account. A platform-neutral local invocation is:

```bash
docker run -d --name habit-log-app \
  --env-file /secure/path/habit-log.env \
  --publish 127.0.0.1:8080:8080 \
  --mount source=habit-log-app-storage,target=/var/www/html/storage \
  habit-log:production
```

Replace the environment-file placeholder and provide database network connectivity before use. This command does not provision production MySQL, TLS or a reverse proxy. The existing development Compose database is not a production database recipe.

Persist application storage, database and application key independently of containers. In a controlled deployment/maintenance window, run migrations and category seeding explicitly, then warm caches with the injected configuration:

```bash
docker exec habit-log-app php artisan migrate --force
docker exec habit-log-app php artisan db:seed --class=CategorySeeder --force
docker exec habit-log-app php artisan config:cache
docker exec habit-log-app php artisan route:cache
docker exec habit-log-app php artisan view:cache
```

Stop writers and take/verify backups before an upgrade as described in the runbook. Build-time config/route/view caches are deliberately absent: secrets and host-specific settings belong to runtime. Replacing a container discards those local caches; repeat cache warming for each replacement. Rolling back means running the prior compatible image with the same storage/database/key; do not roll back domain tables blindly. No image is pushed or live service deployed by this change.

## Verification record

Verified locally on 2026-10-09 using Docker 29.6.2 / Compose 5.3.1 (Linux amd64 through Docker Desktop/WSL). The development image reports PHP 8.3.35, Node 22.23.3 and npm 10.9.9. No application migration was added and no development database was reset. No commit, push or live deployment was performed. Existing development migrations remain applied through batch 6; the original MySQL data volume was reused.

| Check | Observed result |
| --- | --- |
| `docker compose config --quiet` | Valid configuration. |
| `docker compose build app` | Development image built with locked Composer/npm packages. |
| `docker build --target production -t habit-log:production .` | Production image built; platform requirements passed; assets compiled inside the build. |
| `docker compose up -d --wait app vite` | App, Vite and MySQL healthy on loopback. |
| `docker compose exec -T app composer ci:check` | Pint and **336 tests / 3,737 assertions** passed in 89.79 seconds. |
| `docker compose exec -T app npm run build` | Production frontend build passed. |
| `sh tests/Containers/smoke.sh` | Disposable production container passed non-root/runtime-only file checks, login/health/assets, public document-root restrictions, CSRF rejection and access-log privacy. The same check is added to CI after the image build. |
| Authenticated production browser rehearsal | Installed Chrome 154.0.8037.93 registered ordinary synthetic users, saved/reloaded activity and journal data, verified 100% analytics and denied another user's access. Checks passed again after removing/recreating the container with the same database, key and storage volume. A private storage canary also survived replacement. Runtime config/route/view caching passed. |
| Development browser | Login rendered with styles and no page errors; Vite's hot-reload WebSocket connected. |
| Native compatibility | After clearing legacy host views, the host session-boundary tests passed: **2 tests / 26 assertions**. |
| Cross-runtime test lock | A host-held lock rejected a container test process before any database access. |
| Locked package audits | Composer and npm audits passed with zero advisories after the tooling fixes below. |

The first container suite exposed host-compiled Livewire views containing `/home/james/...` paths that do not exist inside `/var/www/html`. Isolating compiled views in a named volume resolved this; the focused regression passed 6 tests / 79 assertions before the full passing rerun. Host views generated during that initial failed attempt were cleared, then native compatibility was verified. No authorization or application behavior was changed to make tests pass.

The initial image build reported existing npm advisories for [shell-quote](https://github.com/advisories/GHSA-pqg4-j6r4-53mv) and [source-map-js](https://github.com/advisories/GHSA-68fv-2mgg-jv7q). Compatible lockfile updates changed concurrently 10.0.5→10.0.6, shell-quote 1.9.0→1.12.0, and source-map-js 1.2.1→1.2.2. npm also refreshed bundled optional WebAssembly metadata. Package requirements did not change. These are development/build tools; Node and its packages are absent from the production runtime.

Evidence logs and synthetic browser artifacts are ignored under `storage/app/container-checks`. The last production image manifest is `sha256:d810023b3e96580a45a1d7654d71b8ce9c9b52796bce1a1f86a721f3c3bd07b5`; rebuilding mutable upstream base tags can produce another digest. The production rehearsal container, its temporary storage volume and synthetic runtime secret file were removed. Development services remain available at http://localhost:8000.

Limits: hosted CI has been configured but not run; ARM images, real production hosting/TLS/proxy/mail, physical devices and production capacity remain unverified. The browser rehearsal used isolated `habit_log_test`, not the development database. No base-image OS vulnerability scan or production-readiness certification is claimed. See the deployment runbook and Phase 7 closeout for remaining release gates.
