# ADR-0007: Docker application runtime

- **Status:** Accepted
- **Date:** 2026-10-09
- **Scope:** Supersedes the native-only runtime choice in ADR-0001; the Laravel modular monolith and product contracts remain unchanged.

## Decision

The user requested application containerization after the MySQL-only setup. Use official PHP 8.3 Apache, Node 22 and Composer 2 build images. Apache serves only Laravel's `public/` directory on an unprivileged port; the application runs as `www-data`. This avoids an extra web-server container and keeps one application process alongside MySQL. No queue, Redis, new framework or application package is needed.

One Dockerfile provides a development target with Composer/npm/test tools and a production target containing locked PHP runtime dependencies and compiled frontend assets. Production contains no environment files, Node, Composer executable, development dependencies or tests. Configuration and the existing application key are injected at runtime; starting a container never generates keys, migrates, seeds or installs dependencies. Apache access logs omit query strings and token-bearing password-reset paths.

Development Compose runs the application, Vite and the existing MySQL service. Code is bind-mounted; dependency directories and compiled views use named volumes to avoid mixing host/container installations or absolute cached paths. Development UID/GID are configurable for Linux/WSL ownership. The MySQL volume identity is preserved. Published development ports bind to loopback. Native development remains available by starting only the MySQL service.

Tests continue to force the isolated `habit_log_test` database and validate the actual connection before resets. Only host/port defaults become overridable so the same suite works on the Compose network. Host and container tests share the bind-mounted lock and must run serially.

## Consequences and verification

Application code remains unchanged. There are no migrations or automatic data conversions. Existing databases are upgraded through explicit Artisan commands; container replacement is an application-code rollback and must preserve storage, database and key. Dependency volumes must be synchronized after lockfile changes. A production image is a packaging artifact, not a deployed service; TLS, proxy trust, mail, backups and capacity remain deployment gates.

Verify both image builds, runtime user/document root, exclusion of secrets and developer tools, health/assets/real authenticated persistence, container restart persistence, all PHP tests/Pint and the frontend build. Record actual commands and limitations in [the Docker guide](../DOCKER.md).

References: [official PHP image](https://hub.docker.com/_/php), [Docker multi-stage builds](https://docs.docker.com/build/building/multi-stage/), [Laravel deployment](https://laravel.com/framework/docs/13.x/deployment).
