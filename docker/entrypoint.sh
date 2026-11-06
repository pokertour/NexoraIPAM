#!/bin/sh
set -eu

# Prepares the database before starting the services. Other commands, such as
# `docker run … php artisan key:generate --show`, run unchanged.
if [ "${1:-}" = "nexora-web" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY est requis. Générez-le avec : docker run --rm <image> php artisan key:generate --show" >&2
        exit 1
    fi

    php /app/artisan migrate --force --no-interaction
    php /app/artisan nexora:install --no-interaction

    exec supervisord -c /etc/supervisord.conf
fi

exec "$@"
