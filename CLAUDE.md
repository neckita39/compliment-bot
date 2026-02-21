# Compliment Bot — Гайд по проекту

## Обзор проекта
Мультиплатформенная система автоматической отправки персонализированных AI-сообщений. Поддерживает Telegram и Битрикс24. Генерирует уникальные сообщения с учётом роли получателя и истории предыдущих сообщений (дедупликация).

## Архитектура

### Основные компоненты
- **Entity/Subscription.php** — подписка Telegram-пользователя
- **Entity/Bitrix24Subscription.php** — подписка пользователя Битрикс24
- **Entity/ComplimentHistory.php** — история сообщений (Telegram)
- **Entity/Bitrix24ComplimentHistory.php** — история сообщений (Битрикс24)
- **Service/GigaChatService.php** — генерация сообщений через GigaChat (основной AI)
- **Service/DeepSeekService.php** — генерация сообщений через DeepSeek (запасной AI)
- **Service/ComplimentGeneratorInterface.php** — интерфейс генератора сообщений
- **Service/TelegramService.php** — обёртка Telegram Bot API
- **Service/Bitrix24Service.php** — интеграция с Битрикс24 (webhook + bot API)
- **Command/BotPollingCommand.php** — long polling обработчик обновлений
- **Scheduler/ComplimentSchedule.php** — планировщик автоматической отправки
- **Controller/AdminController.php** — веб-панель администратора

### Стек технологий
- PHP 8.2+
- Symfony 6.4 (Console, Scheduler, Messenger, Doctrine)
- PostgreSQL 15
- Docker & docker-compose
- GigaChat API (основной AI-движок)
- DeepSeek API (запасной, OpenAI-совместимый)
- Supervisor (управление процессами)

## Роли сообщений
Четыре роли с разными промптами для AI:
- **Нейтральная** — тёплые, поддерживающие сообщения (универсальная)
- **Жена** — романтические комплименты
- **Сестра** — мотивационные сообщения для младшей сестры (детский контент)
- **Коллега** — профессиональные мотивационные сообщения от лица тимлида (для Битрикс24)

## Функциональность бота

### Команды пользователя
- `/start` — приветствие и главное меню с кнопками
- `/admin` — Telegram-админка (только для админа)
- Кнопки главного меню:
  - "Подписаться" — подписка на ежедневные сообщения
  - "Отписаться" — отмена подписки
  - "Получить комплимент" — моментальное сообщение
  - "Выбрать роль" — выбор роли (Нейтральная/Жена/Сестра)
  - "Выходные: ВКЛ/ВЫКЛ" — переключение отправки по выходным

### Telegram-админка
- Доступ через `/admin` или кнопку "Панель админа" в `/start`
- Админ определяется по `ADMIN_USERNAME` (без учёта регистра)
- Навигация через `editMessageText` (без засорения чата)
- Управление Telegram-подписчиками:
  - Список с пагинацией (5 на страницу)
  - Карточка подписчика (статус, роль, расписание, последнее сообщение)
  - Активация/деактивация, настройка времени отправки
  - Просмотр истории сообщений, отправка моментального сообщения
- Управление Битрикс24-подписчиками:
  - Список с пагинацией, добавление/удаление
  - Карточка с настройками времени и истории
  - Отправка моментального сообщения через Битрикс24

### Веб-админка
- URL: http://localhost:8848/admin
- Авторизация по паролю (ADMIN_PASSWORD из .env)
- Просмотр статистики и всех подписок
- Активация/деактивация/удаление подписок
- Настройка роли, времени отправки, размера контекста истории

### Расписание отправки
- Планировщик запускается каждую минуту
- Время настраивается индивидуально для каждого подписчика
- Поддержка раздельного времени для будней и выходных
- Возможность отключить отправку по выходным
- Часовой пояс: Europe/Moscow

### Дедупликация сообщений
- Настраиваемый размер контекста (сколько предыдущих сообщений учитывать)
- AI получает историю и генерирует уникальное сообщение
- Предотвращает повторение фраз, структуры и ключевых слов

## Переменные окружения
```
TELEGRAM_BOT_TOKEN        — Токен бота от @BotFather
GIGACHAT_CLIENT_ID        — Client ID GigaChat
GIGACHAT_CLIENT_SECRET    — Client Secret GigaChat
DEEPSEEK_API_KEY          — API-ключ DeepSeek (запасной AI)
DATABASE_URL              — Строка подключения PostgreSQL
ADMIN_PASSWORD            — Пароль веб-админки
ADMIN_USERNAME            — Telegram username (без @) для доступа к Telegram-админке
BITRIX24_PORTAL_URL       — URL портала Битрикс24
BITRIX24_WEBHOOK_USER_ID  — ID пользователя вебхука Битрикс24
BITRIX24_WEBHOOK_TOKEN    — Токен вебхука Битрикс24
BITRIX24_BOT_ID           — ID бота Битрикс24 (для imbot.message.add)
BITRIX24_BOT_CLIENT_ID    — Client ID бота Битрикс24 (для imbot.message.add)
```

## Разработка

### Локальный запуск
```bash
composer install
php bin/console doctrine:migrations:migrate
php bin/console app:bot:polling
# В отдельном терминале:
php bin/console messenger:consume scheduler_default
```

### Docker
```bash
docker-compose up -d
# Миграции выполняются автоматически через entrypoint.sh
```

### Проверка здоровья
```bash
docker-compose exec app php bin/check-health.php
```

## Соглашения по коду
- Строгая типизация (`declare(strict_types=1)`) во всех PHP-файлах
- Соблюдение best practices Symfony
- Грамотная обработка ошибок Telegram и AI API
- Логирование ошибок с контекстом
- Фоллбэк на заготовленные сообщения при недоступности AI

## Схема БД

### subscriptions (Telegram)
- `telegram_chat_id` (индекс) — ID пользователя
- `telegram_username`, `telegram_first_name` — инфо о пользователе
- `is_active` (индекс) — статус подписки
- `role` — роль для генерации сообщений
- `weekday_time`, `weekend_time` — время отправки
- `weekend_enabled` — включены ли выходные
- `history_context_size` — размер контекста для дедупликации
- `created_at`, `last_compliment_at` — временные метки

### bitrix24_subscriptions (Битрикс24)
- `bitrix24_user_id` (индекс) — ID пользователя Б24
- `bitrix24_user_name`, `portal_url` — инфо о пользователе
- `is_active` (индекс), `weekday_time`, `weekend_time`
- `weekend_enabled`, `history_context_size`
- `created_at`, `last_compliment_at`

### compliment_history / bitrix24_compliment_history
- История отправленных сообщений для дедупликации

## Деплой
- Бот работает на удалённом сервере
- Миграции выполняются автоматически при старте контейнера (docker/php/entrypoint.sh)
- Push кода + перезапуск контейнеров = деплой

## Supervisor-процессы
1. `telegram-bot` — long polling бота (app:bot:polling)
2. `messenger-consumer` — обработчик очереди сообщений
3. `scheduler-worker` — планировщик отправки
4. `cron` — системный cron

## Заметки
- Long polling вместо вебхуков (проще для разработки)
- Планировщик использует Symfony Messenger для асинхронного выполнения
- Битрикс24 поддерживает два режима: webhook и bot API (imbot.message.add)
- Фоллбэк-комплименты гарантируют работу бота даже без AI
