#!/bin/sh
set -eu
if [ "${1:-}" != "sushi-server" ]; then
    exec "$@"
fi

: "${APP_KEY:?Set a stable APP_KEY in the hosting environment}"
PORT="${PORT:-80}"
case "$PORT" in
    ''|*[!0-9]*) echo "PORT must be numeric" >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535" >&2
    exit 1
fi
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
sed -i "s/^Listen [0-9][0-9]*/Listen $PORT/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9][0-9]*>/<VirtualHost *:$PORT>/" /etc/apache2/sites-available/000-default.conf
# mod_php requires exactly one MPM. Normalize this at runtime because some
# container hosts restore the base image's event/worker module links.
a2dismod -f mpm_event mpm_worker >/dev/null
a2enmod mpm_prefork >/dev/null
apache2ctl -t
php artisan config:cache
php artisan route:cache
php artisan view:cache
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/sushi.conf
