# Compliment Bot 💝

Telegram bot that sends personalized messages to your loved ones at scheduled times. Powered by GigaChat AI.

## Features

- 💝 **Role-Based Messages** - Different message types for different people (romantic compliments for wife, motivational messages for sister)
- 💌 **Instant Messages** - Get a message anytime with a button press
- ⏰ **Flexible Scheduling** - Configure individual send times for each subscriber
- 🤖 **AI-Generated** - Unique messages powered by GigaChat
- 🖥️ **Web Admin Panel** - Manage subscriptions, roles, and schedules
- 🏥 **Health Check** - Built-in diagnostic tools

## Tech Stack

- PHP 8.2+
- Symfony 6.4
- PostgreSQL 15
- Docker & Docker Compose
- GigaChat API (Sberbank)
- Symfony Scheduler & Messenger

## Setup

### Prerequisites

- Docker and Docker Compose
- Telegram Bot Token from [@BotFather](https://t.me/BotFather)
- GigaChat API Credentials from [developers.sber.ru](https://developers.sber.ru/portal/products/gigachat)

### Installation

1. **Clone the repository**
```bash
git clone <your-repo-url>
cd compliment-bot
```

2. **Configure environment**
```bash
cp .env.example .env
```

Edit `.env` and set:
```env
TELEGRAM_BOT_TOKEN=your_telegram_bot_token_here
GIGACHAT_CLIENT_ID=your_gigachat_client_id
GIGACHAT_CLIENT_SECRET=your_gigachat_client_secret
ADMIN_PASSWORD=your_secure_password
```

3. **Start Docker containers**
```bash
docker-compose up -d
```

The bot will start automatically via Supervisor. All dependencies will be installed and migrations will run automatically.

4. **Verify everything works**
```bash
docker-compose exec app php bin/check-health.php
```

This command checks:
- ✅ Environment variables
- ✅ PostgreSQL connection
- ✅ Telegram Bot API
- ✅ GigaChat API
- ✅ File system permissions

## Usage

### Start the bot

The bot runs automatically via Supervisor. To check logs:

```bash
# Bot logs
docker-compose exec supervisor tail -f /var/log/supervisor/telegram-bot.out.log

# Messenger consumer logs
docker-compose exec supervisor tail -f /var/log/supervisor/messenger.out.log

# Scheduler logs
docker-compose exec supervisor tail -f /var/log/supervisor/scheduler.out.log
```

### Manual commands (for testing)

```bash
# Run bot polling manually
docker-compose exec app php bin/console app:bot:polling

# Run messenger consumer
docker-compose exec app php bin/console messenger:consume scheduler_compliments
```

## Bot Commands

In Telegram, start a chat with your bot and use:

- `/start` - Initialize bot and show menu

Use the inline keyboard buttons:
- **💝 Подписаться** - Subscribe to daily messages
- **🚫 Отписаться** - Unsubscribe
- **💌 Получить комплимент** - Get instant message

## Web Admin Panel

Access the web admin panel to manage subscriptions:

1. **Open in browser:** http://localhost:8848/admin
2. **Login:** Use the password from your `.env` file (`ADMIN_PASSWORD`)
3. **Features:**
   - View all subscriptions
   - Configure role (Wife 💝 or Sister ✨)
   - Set individual send times (weekday/weekend)
   - Activate/deactivate subscriptions
   - Delete subscriptions
   - View last message timestamp

**Screenshots:**
- Dashboard shows subscriber list with Telegram username, chat ID, status
- One-click activation/deactivation
- Confirmation before deletion

## Project Structure

```
.
├── config/
│   ├── packages/
│   │   ├── messenger.yaml       # Message queue config
│   │   ├── scheduler.yaml       # Scheduler config
│   │   └── ...
│   └── services.yaml            # Service definitions
├── docker/
│   ├── nginx/                   # Nginx config
│   ├── php/                     # PHP Dockerfile
│   └── supervisor/              # Supervisor config
├── migrations/                  # Database migrations
├── src/
│   ├── Command/
│   │   └── BotPollingCommand.php      # Long polling handler
│   ├── Controller/
│   │   └── AdminController.php        # Web admin panel
│   ├── Entity/
│   │   ├── Subscription.php           # User subscription model
│   │   └── ComplimentHistory.php      # Message history
│   ├── Message/
│   │   └── SendScheduledCompliment.php # Queue message
│   ├── MessageHandler/
│   │   └── SendScheduledComplimentHandler.php # Queue handler
│   ├── Repository/
│   │   ├── SubscriptionRepository.php
│   │   └── ComplimentHistoryRepository.php
│   ├── Scheduler/
│   │   └── ComplimentSchedule.php     # Cron schedule
│   └── Service/
│       ├── ComplimentGeneratorInterface.php # AI service interface
│       ├── GigaChatService.php        # GigaChat AI integration
│       ├── DeepSeekService.php        # DeepSeek AI (alternative)
│       └── TelegramService.php        # Telegram API wrapper
├── bin/
│   ├── check-health.php            # System health check
│   ├── test-gigachat.php           # GigaChat API test
│   └── console                     # Symfony console
├── .env                         # Environment variables
├── composer.json               # PHP dependencies
├── docker-compose.yml          # Docker setup
└── README.md                   # This file
```

## Development

### Health Check

Run comprehensive system check:

```bash
# Inside Docker
docker-compose exec app php bin/check-health.php

# Or locally (if dependencies installed)
php bin/check-health.php
```

This verifies:
- Environment variables are configured
- PostgreSQL is accessible and has subscriptions table
- Telegram Bot API is working
- GigaChat API is working (gets token and generates test message)
- File system permissions are correct

### Testing GigaChat API

```bash
docker-compose exec app php bin/test-gigachat.php
```

### Changing role prompts

Edit `src/Service/GigaChatService.php` → `buildPrompt()` method to customize messages for each role.

### Changing schedule

Schedules are now configured per-subscriber in the admin panel. The scheduler runs every minute and checks if it's time to send messages.

### Database access

```bash
docker-compose exec db psql -U app -d app
```

## Troubleshooting

### Quick diagnostic

```bash
docker-compose exec app php bin/check-health.php
```

### Bot not responding

1. Check if supervisor is running:
```bash
docker-compose ps
```

2. Check bot logs:
```bash
docker-compose exec supervisor tail -f /var/log/supervisor/telegram-bot.err.log
```

3. Verify Telegram token:
```bash
curl https://api.telegram.org/bot<YOUR_TOKEN>/getMe
```

### Scheduled messages not sending

1. Check scheduler worker:
```bash
docker-compose exec supervisor tail -f /var/log/supervisor/scheduler.out.log
```

2. Verify messenger is running:
```bash
docker-compose exec app php bin/console messenger:stats
```

### GigaChat API errors

If messages fail to send, check:

1. API credentials are correct:
```bash
docker-compose exec app php bin/test-gigachat.php
```

2. Check application logs:
```bash
docker-compose exec app tail -f var/log/dev.log
```

API errors are now sent directly to users in Telegram so they know what went wrong.

## License

Private project - All rights reserved

## Author

Built with ❤️ for my wife
