#!/bin/sh
set -e
cd /app

if [ ! -s database/demo.sqlite ]; then
    touch database/demo.sqlite
    php artisan demo:reset --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
