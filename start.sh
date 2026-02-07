#!/bin/bash

echo "🚀 Starting Compliment Bot..."

# Check if .env exists
if [ ! -f .env ]; then
    echo "❌ .env file not found!"
    echo "Please copy .env.example to .env and configure it:"
    echo "  cp .env.example .env"
    echo "  nano .env"
    exit 1
fi

# Start Docker containers
echo "📦 Starting Docker containers..."
docker-compose up -d

# Wait for database
echo "⏳ Waiting for database to be ready..."
sleep 5

# Install dependencies if vendor doesn't exist
if [ ! -d "vendor" ]; then
    echo "📚 Installing Composer dependencies..."
    docker-compose exec app composer install --no-interaction
fi

# Run migrations
echo "🗄️  Running database migrations..."
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction

# Restart supervisor to ensure all services are running
echo "🔄 Restarting Supervisor..."
docker-compose restart supervisor

echo ""
echo "✅ Compliment Bot is running!"
echo ""
echo "📋 Useful commands:"
echo "  - View bot logs:       docker-compose exec supervisor tail -f /var/log/supervisor/telegram-bot.out.log"
echo "  - View scheduler logs: docker-compose exec supervisor tail -f /var/log/supervisor/scheduler.out.log"
echo "  - Stop bot:            docker-compose down"
echo "  - View all logs:       docker-compose logs -f"
echo ""
