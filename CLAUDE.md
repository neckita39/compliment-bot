# Compliment Bot - Project Guide

## Project Overview
Telegram bot that sends romantic compliments to the user's wife at scheduled times. Built with Symfony 6.4, PostgreSQL, and DeepSeek AI.

## Architecture

### Core Components
- **Entity/Subscription.php** - User subscription model with Telegram chat info
- **Service/DeepSeekService.php** - AI-powered compliment generation via DeepSeek API
- **Service/TelegramService.php** - Telegram Bot API wrapper
- **Command/BotPollingCommand.php** - Long polling handler for bot updates
- **Scheduler/ComplimentSchedule.php** - Automated compliment delivery

### Tech Stack
- PHP 8.2+
- Symfony 6.4 (Console, Scheduler, Messenger, Doctrine)
- PostgreSQL 15
- Docker & docker-compose
- DeepSeek API (OpenAI-compatible)

## Bot Functionality

### User Commands
- `/start` - Welcome message with subscription keyboard
- `/admin` - Telegram admin panel (admin only)
- Callback buttons:
  - "💝 Подписаться" - Subscribe to daily compliments
  - "🚫 Отписаться" - Unsubscribe
  - "💌 Получить комплимент" - Get instant compliment

### Telegram Admin Panel
- Accessible via `/admin` command or "Панель админа" button in `/start`
- Admin identified by `ADMIN_USERNAME` env variable (case-insensitive)
- Single-message navigation via `editMessageText` (no chat clutter)
- Features:
  - Subscriber list with pagination (5 per page)
  - Subscriber detail card (status, role, schedule, last compliment)
  - Activate/deactivate subscriptions
  - Set weekday/weekend delivery time from presets
  - View compliment history with pagination
  - Send instant compliment to any subscriber

### Web Admin Panel
- URL: http://localhost:8848/admin
- Password-protected (uses ADMIN_PASSWORD from .env)
- Features:
  - View all subscriptions with statistics
  - Activate/deactivate subscriptions
  - Delete subscriptions
  - See last compliment timestamp

### Scheduled Delivery
- **Weekdays (Mon-Fri)**: 7:00 AM
- **Weekends (Sat-Sun)**: 9:00 AM
- Timezone: Europe/Moscow

## Environment Variables
```
TELEGRAM_BOT_TOKEN - Bot token from @BotFather
DEEPSEEK_API_KEY - DeepSeek API key
DATABASE_URL - PostgreSQL connection string
ADMIN_USERNAME - Telegram username (without @) for bot admin panel access
```

## Development Workflow

### Setup
```bash
# Install dependencies
composer install

# Run migrations
php bin/console doctrine:migrations:migrate

# Start long polling
php bin/console app:bot:polling

# Run scheduler (in separate terminal)
php bin/console messenger:consume scheduler_default
```

### Docker
```bash
docker-compose up -d
docker-compose exec php bin/console app:bot:polling
```

## Code Conventions
- Use strict types in all PHP files
- Follow Symfony best practices
- Handle all Telegram API errors gracefully
- Log all DeepSeek API errors with context
- Use fallback compliments when AI unavailable

## Database Schema
- **subscriptions** table:
  - telegram_chat_id (indexed) - Telegram user ID
  - telegram_username, telegram_first_name - User info
  - is_active (indexed) - Subscription status
  - created_at - Subscription date
  - last_compliment_at - Last compliment timestamp

## Notes
- DeepSeek API is OpenAI-compatible (uses same request format)
- Bot uses inline keyboard for better UX
- Fallback compliments ensure bot always works
- Long polling preferred over webhooks for simplicity
