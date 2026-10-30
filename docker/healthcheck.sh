#!/bin/sh
set -eu
# Match trusted-host validation while probing the local listener. Never resolve
# or connect to APP_URL: ingress/TLS/database/mail need separate acceptance.
health_host=$(php -r 'echo parse_url(getenv("APP_URL") ?: "http://localhost", PHP_URL_HOST);')
curl --fail --silent --max-time 4 --header "Host: $health_host" http://127.0.0.1:8080/up >/dev/null
