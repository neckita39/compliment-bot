<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$clientId = $_ENV['GIGACHAT_CLIENT_ID'];
$clientSecret = $_ENV['GIGACHAT_CLIENT_SECRET'];

echo "🤖 Отправляю приветственный запрос в GigaChat...\n\n";

$httpClient = HttpClient::create([
    'verify_peer' => false,
    'verify_host' => false,
]);

// Получаем токен
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
$accessToken = $tokenData['access_token'];

echo "✅ Токен получен\n\n";

// Отправляем приветственный запрос
echo "📤 Запрос: \"Привет! Как дела? Расскажи о себе в 2-3 предложениях.\"\n\n";

$chatResponse = $httpClient->request('POST', 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions', [
    'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $accessToken,
    ],
    'json' => [
        'model' => 'GigaChat',
        'messages' => [
            [
                'role' => 'user',
                'content' => 'Привет! Как дела? Расскажи о себе в 2-3 предложениях.',
            ],
        ],
        'temperature' => 0.8,
        'max_tokens' => 150,
    ],
]);

$chatData = $chatResponse->toArray();
$response = $chatData['choices'][0]['message']['content'];

echo "📥 Ответ от GigaChat:\n";
echo str_repeat("─", 60) . "\n";
echo $response . "\n";
echo str_repeat("─", 60) . "\n\n";

echo "✅ Всё работает отлично!\n";
