# Compliment Bot 💝

Telegram bot that sends romantic compliments to your loved ones at scheduled times. Powered by DeepSeek AI.

## Features

- 💝 **Subscribe/Unsubscribe** - Easy subscription management
- 💌 **Instant Compliments** - Get a compliment anytime with a button press
- ⏰ **Scheduled Delivery**:
  - Weekdays (Mon-Fri): 7:00 AM
  - Weekends (Sat-Sun): 9:00 AM
- 🤖 **AI-Generated** - Unique compliments powered by DeepSeek
- 🔄 **Fallback System** - Pre-written compliments if AI is unavailable
- 🖥️ **Web Admin Panel** - Manage subscriptions and view statistics

## Tech Stack

- PHP 8.2+
- Symfony 6.4
- PostgreSQL 15
- Docker & Docker Compose
- DeepSeek API
- Symfony Scheduler & Messenger

## Setup

### Prerequisites

- Docker and Docker Compose
- Telegram Bot Token from [@BotFather](https://t.me/BotFather)
- DeepSeek API Key from [platform.deepseek.com](https://platform.deepseek.com/api_keys)

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
DEEPSEEK_API_KEY=your_deepseek_api_key_here
```

3. **Start Docker containers**
```bash
docker-compose up -d
```

4. **Install dependencies**
```bash
docker-compose exec app composer install
```

5. **Run database migrations**
```bash
docker-compose exec app php bin/console doctrine:migrations:migrate --no-interaction
```

6. **Restart supervisor to start the bot**
```bash
docker-compose restart supervisor
```

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
- **💝 Подписаться** - Subscribe to daily compliments
- **🚫 Отписаться** - Unsubscribe
- **💌 Получить комплимент** - Get instant compliment

## Web Admin Panel

Access the web admin panel to manage subscriptions:

1. **Open in browser:** http://localhost:8848/admin
2. **Login:** Use the password from your `.env` file (`ADMIN_PASSWORD`)
3. **Features:**
   - View all subscriptions
   - See statistics (total, active, inactive)
   - Activate/deactivate subscriptions
   - Delete subscriptions
   - View last compliment time

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
│   ├── Entity/
│   │   └── Subscription.php           # User subscription model
│   ├── Message/
│   │   └── SendScheduledCompliment.php # Queue message
│   ├── MessageHandler/
│   │   └── SendScheduledComplimentHandler.php # Queue handler
│   ├── Repository/
│   │   └── SubscriptionRepository.php
│   ├── Scheduler/
│   │   └── ComplimentSchedule.php     # Cron schedule
│   └── Service/
│       ├── DeepSeekService.php        # AI compliment generation
│       └── TelegramService.php        # Telegram API wrapper
├── .env                         # Environment variables
├── composer.json               # PHP dependencies
├── docker-compose.yml          # Docker setup
└── README.md                   # This file
```

## Development

### Adding new compliments

Edit fallback compliments in `src/Service/DeepSeekService.php`:

```php
private const FALLBACK_COMPLIMENTS = [
    'Your new compliment here...',
    // ...
];
```

### Changing schedule

Edit `src/Scheduler/ComplimentSchedule.php`:

```php
// Weekdays at 7:00 AM
RecurringMessage::cron('0 7 * * 1-5', new SendScheduledCompliment('weekday'))

// Weekends at 9:00 AM
RecurringMessage::cron('0 9 * * 0,6', new SendScheduledCompliment('weekend'))
```

### Database access

```bash
docker-compose exec db psql -U app -d app
```

## Troubleshooting

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

### DeepSeek API errors

The bot will automatically use fallback compliments if DeepSeek API fails. Check logs:

```bash
docker-compose exec app tail -f var/log/dev.log
```

## License

Private project - All rights reserved

## Author

Built with ❤️ for my wife
