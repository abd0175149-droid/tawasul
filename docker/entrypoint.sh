#!/bin/bash
set -e

echo "🚀 TAWASUL — Starting Production Setup..."

cd /var/www/html

# Ensure .env file exists (needed for key:generate and config:cache)
if [ ! -f .env ]; then
    if [ -f .env.production ]; then
        cp .env.production .env
    else
        touch .env
    fi
fi

# Generate app key if not set or empty
if [ -z "$APP_KEY" ] || ! grep -q '^APP_KEY=base64:' .env; then
    echo "🔑 Generating application key..."
    php artisan key:generate --force --no-interaction
fi

# Export APP_KEY from .env file so it overrides the empty env var from docker-compose
export APP_KEY=$(grep '^APP_KEY=' .env | cut -d'=' -f2-)
echo "🔑 APP_KEY loaded: ${APP_KEY:0:20}..."

# Ensure SQLite database exists
if [ ! -f database/database.sqlite ]; then
    echo "📦 Creating SQLite database..."
    touch database/database.sqlite
    chown www-data:www-data database/database.sqlite
fi

# Restore migrations/seeders/factories shadowed by the database volume
if [ -d /opt/app-database ]; then
    echo "🔄 Syncing migrations/seeders from image into the database volume..."
    for d in migrations seeders factories; do
        [ -d "/opt/app-database/$d" ] || continue
        mkdir -p "database/$d"
        cp -rf "/opt/app-database/$d/." "database/$d/"
    done
fi

# Fix permissions
chown -R www-data:www-data storage bootstrap/cache database
chmod -R 775 storage bootstrap/cache database

# Cache configuration for production
echo "⚡ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Run migrations
echo "🗄️ Running migrations..."
php artisan migrate --force --no-interaction

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true

# Seed chart of accounts if fresh database
php artisan db:seed --class=ChartOfAccountsSeeder --force --no-interaction 2>/dev/null || true

echo "✅ TAWASUL is ready! Listening on port 4030"

# Execute the main process (supervisord)
exec "$@"
