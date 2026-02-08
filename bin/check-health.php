#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

// Load environment
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

echo "🏥 Проверка здоровья системы Compliment Bot\n";
echo str_repeat("=", 60) . "\n\n";

$allOk = true;

// 1. Проверка переменных окружения
echo "1️⃣  Проверка переменных окружения...\n";
$requiredVars = [
    'DATABASE_URL' => 'База данных',
    'TELEGRAM_BOT_TOKEN' => 'Telegram Bot',
    'GIGACHAT_CLIENT_ID' => 'GigaChat Client ID',
    'GIGACHAT_CLIENT_SECRET' => 'GigaChat Client Secret',
    'ADMIN_PASSWORD' => 'Админ-панель',
];

foreach ($requiredVars as $var => $name) {
    $value = $_ENV[$var] ?? '';
    if (empty($value) || str_contains($value, 'your_') || str_contains($value, 'change_')) {
        echo "   ❌ {$name}: не настроен ({$var})\n";
        $allOk = false;
    } else {
        $maskedValue = $var === 'ADMIN_PASSWORD' ? '***' : substr($value, 0, 10) . '...';
        echo "   ✅ {$name}: {$maskedValue}\n";
    }
}
echo "\n";

// 2. Проверка подключения к PostgreSQL
echo "2️⃣  Проверка подключения к PostgreSQL...\n";
try {
    $dbUrl = $_ENV['DATABASE_URL'] ?? '';
    if (empty($dbUrl)) {
        throw new Exception('DATABASE_URL не установлен');
    }

    // Parse connection string
    if (!preg_match('/postgresql:\/\/([^:]+):([^@]+)@([^:]+):(\d+)\/(.+)/', $dbUrl, $matches)) {
        throw new Exception('Неверный формат DATABASE_URL');
    }

    [, $user, $pass, $host, $port, $dbname] = $matches;
    
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    
    $result = $pdo->query("SELECT COUNT(*) as count FROM subscriptions")->fetch();
    echo "   ✅ Подключение успешно (подписчиков: {$result['count']})\n";
} catch (Exception $e) {
    echo "   ❌ Ошибка: {$e->getMessage()}\n";
    $allOk = false;
}
echo "\n";

// 3. Проверка Telegram Bot API
echo "3️⃣  Проверка Telegram Bot API...\n";
try {
    $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    if (empty($token)) {
        throw new Exception('TELEGRAM_BOT_TOKEN не установлен');
    }

    $httpClient = HttpClient::create();
    $response = $httpClient->request('GET', "https://api.telegram.org/bot{$token}/getMe");
    $data = $response->toArray();

    if ($data['ok'] ?? false) {
        $botName = $data['result']['username'] ?? 'unknown';
        $firstName = $data['result']['first_name'] ?? 'unknown';
        echo "   ✅ Бот активен: @{$botName} ({$firstName})\n";
    } else {
        throw new Exception('Telegram API вернул ok=false');
    }
} catch (Exception $e) {
    echo "   ❌ Ошибка: {$e->getMessage()}\n";
    $allOk = false;
}
echo "\n";

// 4. Проверка GigaChat API
echo "4️⃣  Проверка GigaChat API...\n";
try {
    $clientId = $_ENV['GIGACHAT_CLIENT_ID'] ?? '';
    $clientSecret = $_ENV['GIGACHAT_CLIENT_SECRET'] ?? '';
    
    if (empty($clientId) || empty($clientSecret)) {
        throw new Exception('GIGACHAT_CLIENT_ID или GIGACHAT_CLIENT_SECRET не установлены');
    }

    $httpClient = HttpClient::create([
        'verify_peer' => false,
        'verify_host' => false,
        'timeout' => 10,
    ]);

    // Get OAuth token
    $authString = base64_encode($clientId . ':' . $clientSecret);
    $tokenResponse = $httpClient->request('POST', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'RqUID' => sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            ),
            'Authorization' => 'Basic ' . $authString,
        ],
        'body' => 'scope=GIGACHAT_API_PERS',
    ]);

    $tokenData = $tokenResponse->toArray();
    
    if (!isset($tokenData['access_token'])) {
        throw new Exception('Не удалось получить токен: ' . json_encode($tokenData));
    }

    // Test chat completion
    $chatResponse = $httpClient->request('POST', 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $tokenData['access_token'],
        ],
        'json' => [
            'model' => 'GigaChat',
            'messages' => [
                ['role' => 'user', 'content' => 'Скажи "тест пройден"'],
            ],
            'temperature' => 0.7,
            'max_tokens' => 50,
        ],
    ]);

    $chatData = $chatResponse->toArray();
    
    if (isset($chatData['choices'][0]['message']['content'])) {
        $response = trim($chatData['choices'][0]['message']['content']);
        echo "   ✅ API работает (ответ: \"{$response}\")\n";
    } else {
        throw new Exception('Неожиданный формат ответа');
    }
} catch (Exception $e) {
    echo "   ❌ Ошибка: {$e->getMessage()}\n";
    $allOk = false;
}
echo "\n";

// 5. Проверка файловой системы
echo "5️⃣  Проверка файловой системы...\n";
$paths = [
    'var/cache' => 'Кеш',
    'var/log' => 'Логи',
];

foreach ($paths as $path => $name) {
    $fullPath = __DIR__ . '/../' . $path;
    if (is_dir($fullPath) && is_writable($fullPath)) {
        echo "   ✅ {$name}: доступен для записи\n";
    } else {
        echo "   ❌ {$name}: недоступен ({$path})\n";
        $allOk = false;
    }
}
echo "\n";

// Итоги
echo str_repeat("=", 60) . "\n";
if ($allOk) {
    echo "✅ Все проверки пройдены успешно! Бот готов к работе.\n";
    exit(0);
} else {
    echo "❌ Обнаружены проблемы. Проверьте ошибки выше.\n";
    exit(1);
}
