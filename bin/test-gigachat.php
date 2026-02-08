#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

// Load environment
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$clientId = $_ENV['GIGACHAT_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['GIGACHAT_CLIENT_SECRET'] ?? '';

if (empty($clientId) || empty($clientSecret)) {
    echo "❌ Error: GIGACHAT_CLIENT_ID or GIGACHAT_CLIENT_SECRET not found in .env\n";
    exit(1);
}

echo "🔐 Testing GigaChat API connection...\n\n";

$httpClient = HttpClient::create([
    'verify_peer' => false,
    'verify_host' => false,
]);

// Step 1: Get OAuth token
echo "Step 1: Requesting OAuth token...\n";
try {
    $authString = base64_encode($clientId . ':' . $clientSecret);
    
    $tokenResponse = $httpClient->request('POST', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth', [
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
            'RqUID' => sprintf('%s-%s-%s-%s-%s',
                bin2hex(random_bytes(4)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(2)),
                bin2hex(random_bytes(6))
            ),
            'Authorization' => 'Basic ' . $authString,
        ],
        'body' => 'scope=GIGACHAT_API_PERS',
    ]);

    $tokenData = $tokenResponse->toArray();
    
    if (!isset($tokenData['access_token'])) {
        echo "❌ Error: No access_token in response\n";
        echo "Response: " . json_encode($tokenData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

    echo "✅ Token received successfully!\n";
    echo "Token expires in: " . ($tokenData['expires_at'] ?? 'unknown') . "\n\n";

    // Step 2: Test chat completion
    echo "Step 2: Testing chat completion...\n";
    
    $accessToken = $tokenData['access_token'];
    
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
                    'role' => 'system',
                    'content' => 'Ты — помощник, который пишет короткие комплименты.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Напиши короткий комплимент.',
                ],
            ],
            'temperature' => 0.7,
            'max_tokens' => 100,
        ],
    ]);

    $chatData = $chatResponse->toArray();
    
    if (isset($chatData['choices'][0]['message']['content'])) {
        $compliment = $chatData['choices'][0]['message']['content'];
        echo "✅ Chat completion successful!\n";
        echo "Generated compliment: " . $compliment . "\n";
    } else {
        echo "❌ Error: Unexpected response format\n";
        echo "Response: " . json_encode($chatData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }

} catch (\Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface $e) {
    echo "❌ Client Error: " . $e->getMessage() . "\n";
    try {
        $response = $e->getResponse();
        echo "Status Code: " . $response->getStatusCode() . "\n";
        echo "Response Body: " . $response->getContent(false) . "\n";
    } catch (\Exception $e2) {
        echo "Could not get response details\n";
    }
    exit(1);
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n✅ All tests passed!\n";
