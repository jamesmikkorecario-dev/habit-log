#!/bin/sh
# Disposable production-image smoke check; no database writes or live deployment.
set -eu
image=${1:-habit-log:production}
container="habit-log-image-check-$$"
runtime_env=$(mktemp)
cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    rm -f "$runtime_env"
}
trap cleanup EXIT HUP INT TERM
chmod 600 "$runtime_env"
{
    printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)"
    printf 'APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=https://127.0.0.1\nSESSION_DRIVER=file\nCACHE_STORE=file\nMAIL_MAILER=array\n'
} > "$runtime_env"
docker run --detach --name "$container" --env-file "$runtime_env" \
    --publish 127.0.0.1::8080 "$image" >/dev/null
port=$(docker port "$container" 8080/tcp | sed 's/.*://')
origin="http://127.0.0.1:$port"
ready=0
for attempt in $(seq 1 60); do
    if curl --fail --silent "$origin/up" >/dev/null; then ready=1; break; fi
    sleep 1
done
if [ "$ready" != 1 ]; then echo 'Container did not become healthy.' >&2; exit 1; fi
test "$(docker exec "$container" id -u)" != 0
docker exec "$container" sh -ec '
    test ! -e .env
    test ! -e .env.testing
    test ! -e public/hot
    test ! -d tests
    test ! -d node_modules
    test ! -d vendor/phpunit
    if command -v node || command -v composer; then exit 1; fi
    test -w storage
    test -w bootstrap/cache
    test ! -w app
'
test "$(curl --silent -o /dev/null -w '%{http_code}' "$origin/login")" = 200
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: untrusted.example.test' "$origin/login")" = 400
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'X-Forwarded-Host: untrusted.example.test' -H 'X-Forwarded-Proto: http' "$origin/login")" = 200
docker exec "$container" php artisan config:cache >/dev/null
docker exec "$container" php artisan route:cache >/dev/null
docker exec "$container" php artisan view:cache >/dev/null
docker exec "$container" sh /usr/local/bin/habit-log-healthcheck
test "$(curl --silent -o /dev/null -w '%{http_code}' -H 'Host: untrusted.example.test' "$origin/login")" = 400
docker exec -i "$container" php < tests/Fixtures/production-mail-security.php
for path in .env composer.json vendor/autoload.php; do
    test "$(curl --silent -o /dev/null -w '%{http_code}' "$origin/$path")" = 404
done
test "$(curl --silent -o /dev/null -w '%{http_code}' -X POST "$origin/logout")" = 419
assets=$(docker exec "$container" php -r 'foreach(json_decode(file_get_contents("public/build/manifest.json"),true) as $asset) echo $asset["file"].PHP_EOL;')
for asset in $assets; do curl --fail --silent "$origin/build/$asset" >/dev/null; done
# Keep token-bearing paths and all query strings out of Apache access logs.
curl --silent "$origin/reset-password/container-token-canary?email=query-canary" >/dev/null
curl --silent "$origin/login?container-query-canary=1" >/dev/null
curl --silent "$origin/settings/security/email/verify?token=email-change-token-canary&signature=email-change-signature-canary" >/dev/null
if docker logs "$container" 2>&1 | grep -qE 'container-token-canary|query-canary|email-change-token-canary|email-change-signature-canary|production-mail-secret-canary'; then
    echo 'Sensitive URL reached container logs.' >&2; exit 1
fi
echo 'Production image passed: non-root, runtime-only files, health/login/assets, document root, CSRF, trusted hosts, cached config, credential URLs and mail/log privacy.'
