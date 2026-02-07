#!/bin/bash
set -e

# Install dependencies FIRST (before anything else)
echo "📚 Installing Composer dependencies..."
composer install --no-interaction --optimize-autoloader

echo "🔧 Waiting for database to be ready..."
until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
    echo "⏳ Database is unavailable - sleeping"
    sleep 2
done

echo "✅ Database is ready!"

# Check if subscriptions table exists and mark migration as executed
if php bin/console dbal:run-sql "SELECT 1 FROM subscriptions LIMIT 1" > /dev/null 2>&1; then
    echo "📋 Subscriptions table already exists, marking migration as executed..."
    php bin/console doctrine:migrations:version --add DoctrineMigrations\\Version20240101000000 --no-interaction 2>/dev/null || true
fi

# Setup messenger transports first (creates tables if not exist)
echo "📬 Setting up Messenger transports..."
php bin/console messenger:setup-transports || true

# Check if messenger_messages table exists and mark migration as executed
if php bin/console dbal:run-sql "SELECT 1 FROM messenger_messages LIMIT 1" > /dev/null 2>&1; then
    echo "📨 Messenger table already exists, marking migration as executed..."
    php bin/console doctrine:migrations:version --add DoctrineMigrations\\Version20240101000001 --no-interaction 2>/dev/null || true
fi

# Run migrations
echo "🗄️  Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "🚀 Starting application..."
exec "$@"
