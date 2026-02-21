# Compliment Bot

Мультиплатформенная система автоматической отправки персонализированных сообщений, сгенерированных AI. Поддерживает Telegram и Битрикс24.

## Возможности

- **Мультиплатформенность** — отправка через Telegram и Битрикс24
- **Ролевая система** — разные стили сообщений для разных получателей (жена, сестра, коллега, нейтральный)
- **AI-генерация** — уникальные сообщения от GigaChat (основной) и DeepSeek (запасной)
- **Гибкое расписание** — индивидуальное время отправки для каждого подписчика
- **Дедупликация** — AI учитывает предыдущие сообщения, чтобы не повторяться
- **Две админки** — Telegram-бот и веб-панель
- **Моментальные сообщения** — отправка по нажатию кнопки
- **Health Check** — встроенная диагностика системы

## Стек технологий

- PHP 8.2+
- Symfony 6.4
- PostgreSQL 15
- Docker & Docker Compose
- GigaChat API (Сбербанк)
- DeepSeek API (запасной)
- Symfony Scheduler & Messenger
- Supervisor

## Установка

### Требования

- Docker и Docker Compose
- Токен Telegram-бота от [@BotFather](https://t.me/BotFather)
- Учётные данные GigaChat от [developers.sber.ru](https://developers.sber.ru/portal/products/gigachat)

### Запуск

1. **Клонировать репозиторий**
```bash
git clone <your-repo-url>
cd compliment-bot
```

2. **Настроить окружение**
```bash
cp .env.example .env
```

Заполнить в `.env`:
```env
TELEGRAM_BOT_TOKEN=ваш_токен_бота
GIGACHAT_CLIENT_ID=ваш_client_id
GIGACHAT_CLIENT_SECRET=ваш_client_secret
ADMIN_PASSWORD=пароль_для_вебадминки
ADMIN_USERNAME=ваш_telegram_username
```

3. **Запустить контейнеры**
```bash
docker-compose up -d
```

Бот запустится автоматически через Supervisor. Зависимости установятся и миграции выполнятся при старте контейнера.

4. **Проверить работоспособность**
```bash
docker-compose exec app php bin/check-health.php
```

## Использование

### Telegram-бот

Начните чат с ботом и используйте:

- `/start` — главное меню
- `/admin` — панель администратора (только для админа)

Кнопки главного меню:
- **Подписаться** — подписка на ежедневные сообщения
- **Отписаться** — отмена подписки
- **Получить комплимент** — моментальное сообщение
- **Выбрать роль** — стиль сообщений (Нейтральная / Жена / Сестра)
- **Выходные: ВКЛ/ВЫКЛ** — отправка по выходным

### Telegram-админка

Доступна через команду `/admin`:
- Управление Telegram- и Битрикс24-подписчиками
- Настройка времени отправки для каждого подписчика
- Просмотр истории сообщений
- Отправка моментальных сообщений
- Добавление Битрикс24-подписчиков

### Веб-админка

1. Открыть: http://localhost:8848/admin
2. Ввести пароль из `.env` (`ADMIN_PASSWORD`)
3. Возможности:
   - Статистика подписок
   - Настройка ролей, времени отправки, контекста дедупликации
   - Активация/деактивация/удаление подписок
   - Просмотр истории сообщений

## Структура проекта

```
.
├── config/
│   ├── packages/
│   │   ├── messenger.yaml           # Конфигурация очередей
│   │   ├── scheduler.yaml           # Конфигурация планировщика
│   │   └── ...
│   └── services.yaml                # Определения сервисов
├── docker/
│   ├── nginx/                       # Конфиг Nginx
│   ├── php/                         # Dockerfile PHP
│   └── supervisor/                  # Конфиг Supervisor
├── migrations/                      # Миграции БД
├── src/
│   ├── Command/
│   │   └── BotPollingCommand.php          # Long polling обработчик
│   ├── Controller/
│   │   └── AdminController.php            # Веб-админка
│   ├── Entity/
│   │   ├── Subscription.php               # Telegram-подписка
│   │   ├── Bitrix24Subscription.php       # Битрикс24-подписка
│   │   ├── ComplimentHistory.php          # История (Telegram)
│   │   └── Bitrix24ComplimentHistory.php  # История (Битрикс24)
│   ├── Message/
│   │   └── SendScheduledCompliment.php    # Сообщение очереди
│   ├── MessageHandler/
│   │   └── SendScheduledComplimentHandler.php  # Обработчик очереди
│   ├── Repository/
│   │   ├── SubscriptionRepository.php
│   │   ├── Bitrix24SubscriptionRepository.php
│   │   ├── ComplimentHistoryRepository.php
│   │   └── Bitrix24ComplimentHistoryRepository.php
│   ├── Scheduler/
│   │   └── ComplimentSchedule.php         # Расписание (cron)
│   └── Service/
│       ├── ComplimentGeneratorInterface.php  # Интерфейс AI
│       ├── GigaChatService.php              # GigaChat AI
│       ├── DeepSeekService.php              # DeepSeek AI
│       ├── TelegramService.php              # Telegram API
│       └── Bitrix24Service.php              # Битрикс24 API
├── bin/
│   ├── check-health.php               # Диагностика системы
│   ├── test-gigachat.php              # Тест GigaChat API
│   └── console                        # Symfony console
├── .env                            # Переменные окружения
├── composer.json                   # PHP-зависимости
├── docker-compose.yml              # Docker-конфигурация
└── README.md
```

## Разработка

### Диагностика
```bash
docker-compose exec app php bin/check-health.php
```

### Тестирование GigaChat API
```bash
docker-compose exec app php bin/test-gigachat.php
```

### Кастомизация промптов
Промпты для каждой роли в методе `buildPrompt()` файла `src/Service/GigaChatService.php`.

### Расписание
Настраивается индивидуально для каждого подписчика через админку. Планировщик запускается каждую минуту и проверяет, кому пора отправить сообщение.

### Доступ к БД
```bash
docker-compose exec db psql -U app -d app
```

## Устранение неполадок

### Быстрая диагностика
```bash
docker-compose exec app php bin/check-health.php
```

### Бот не отвечает
```bash
# Проверить контейнеры
docker-compose ps

# Логи бота
docker-compose exec supervisor tail -f /var/log/supervisor/telegram-bot.err.log

# Проверить токен
curl https://api.telegram.org/bot<ТОКЕН>/getMe
```

### Сообщения не отправляются по расписанию
```bash
# Логи планировщика
docker-compose exec supervisor tail -f /var/log/supervisor/scheduler.out.log

# Статус очереди
docker-compose exec app php bin/console messenger:stats
```

### Ошибки GigaChat API
```bash
# Тест API
docker-compose exec app php bin/test-gigachat.php

# Логи приложения
docker-compose exec app tail -f var/log/dev.log
```

## Лицензия

Частный проект — Все права защищены
