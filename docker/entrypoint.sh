#!/bin/sh
# Runs on every container start, before nginx and PHP-FPM come up. Any
# failing step stops the container rather than serving a half-set-up app.
set -e

cd /var/www/html

# Cached here, not in the Dockerfile: config:cache freezes every env() value,
# and the real ones (APP_KEY, the DB host, the Shopify secret) only exist at
# run time.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# MySQL may still be starting, as it is on `docker compose up`.
php artisan app:wait-for-database --timeout=60

# Safe on every start: Laravel records each migration it runs in the
# migrations table and skips it next time. --force because Laravel refuses to
# migrate in production without it.
php artisan migrate --force

# The commands above ran as root, so anything they wrote belongs to root.
# PHP-FPM serves requests as www-data and has to be able to write here.
chown -R www-data:www-data storage bootstrap/cache

# exec, so supervisord replaces this shell as process 1 and receives the stop
# signal from `docker stop` directly.
exec "$@"
