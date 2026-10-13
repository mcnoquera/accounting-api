#!/bin/sh
set -e

# Render's generated secrets are random strings; Laravel needs a base64 32-byte key.
if [ -z "$APP_KEY" ] && [ -n "$APP_KEY_SECRET" ]; then
  APP_KEY="base64:$(php -r 'echo base64_encode(hash("sha256", getenv("APP_KEY_SECRET"), true));')"
  export APP_KEY
fi

# Apache listens on $PORT (Render sets it; defaults to 8080).
PORT="${PORT:-8080}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache
php artisan migrate --force
php artisan app:ensure-admin

if [ "$SEED_DEMO_DATA" = "true" ]; then
  php artisan db:seed --force
fi

exec apache2-foreground
