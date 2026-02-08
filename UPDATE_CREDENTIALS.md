# Обновление GigaChat креденшалов

⚠️ **ВАЖНО**: Старые креденшалы были в истории Git, поэтому были пересозданы.

## Новые креденшалы (для .env файла):

```bash
GIGACHAT_CLIENT_ID=6c07c6aa-375d-4ea4-af5e-44b86d966b91
GIGACHAT_CLIENT_SECRET=e362b60b-8821-4104-9ae0-5f264f8f64fb
```

## Как обновить на старом MacBook:

1. Открой файл `.env`:
   ```bash
   nano .env
   ```

2. Найди строку `GIGACHAT_CLIENT_SECRET` и замени на:
   ```
   GIGACHAT_CLIENT_SECRET=e362b60b-8821-4104-9ae0-5f264f8f64fb
   ```

3. Сохрани (Ctrl+O, Enter, Ctrl+X)

4. Перезапусти контейнеры:
   ```bash
   docker-compose restart
   ```

5. Проверь что всё работает:
   ```bash
   docker-compose exec app php bin/check-health.php
   ```

## Проверка

После обновления в выводе `check-health.php` должно быть:
- ✅ GigaChat API работает

