#!/bin/sh
# Container entrypoint (Render). No migrations here: the tables already exist in Supabase.
set -e
export PORT="${PORT:-80}"
cd /var/www/html

# Render injects env vars at runtime, so cache config/routes/views now (not at build time).
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

# The app needs a queue worker (webhooks, reminder notifications) and the scheduler (auto-reminders).
# Render's free tier has no worker/cron services, so run them here, as www-data (keeps the file cache writable),
# and restart them if they ever exit.
( while true; do runuser -u www-data -- php artisan queue:work --tries=3 --sleep=3 --max-time=3600; sleep 2; done ) &
( while true; do runuser -u www-data -- php artisan schedule:work; sleep 5; done ) &

# Production web server: Apache + mod_php on 0.0.0.0:$PORT (docker/apache.conf), in the foreground.
exec apache2-foreground
