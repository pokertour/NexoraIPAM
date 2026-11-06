#!/bin/sh
set -eu

php-fpm --nodaemonize &
php_pid=$!
nginx -c /etc/nginx/nexora.conf -g 'daemon off;' &
nginx_pid=$!

stop() {
    kill "$nginx_pid" "$php_pid" 2>/dev/null || true
    wait "$nginx_pid" "$php_pid" 2>/dev/null || true
}
trap 'stop; exit 0' TERM INT

status=0
wait -n "$nginx_pid" "$php_pid" || status=$?
stop
if [ "$status" -eq 0 ]; then
    status=1
fi
exit "$status"
