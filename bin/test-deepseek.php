<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;

// Load .env
$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');

$apiKey = $_ENV['DEEPSEEK_API_KEY'] ?? '';

echo "🔍 Testing DeepSeek API...\n\n";

if (empty($apiKey) || $apiKey === 'your_deepseek_api_key_here') {
    echo "❌ API key not configured!\n";
    echo "   DEEPSEEK_API_KEY = '{$apiKey}'\n";
    exit(1);
}

echo "✅ API key found: " . substr($apiKey, 0, 10) . "...\n\n";

$httpClient = HttpClient::create();

$prompt = <<<PROMPT
Напиши один красивый комплимент для жены на русском языке.

Требования:
- Не более 2-3 предложений
- Без кавычек и префиксов

Напиши только комплимент.
PROMPT;

echo "📤 Sending request to DeepSeek...\n";
echo "   URL: https://api.deepseek.com/v1/chat/completions\n";
echo "   Model: deepseek-chat\n\n";

try {
    $response = $httpClient->request('POST', 'https://api.deepseek.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
        ],
        'json' => [
            'model' => 'deepseek-chat',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens' => 200,
            'temperature' => 0.8,
        ],
    ]);

    $statusCode = $response->getStatusCode();
    echo "📥 Response status: {$statusCode}\n\n";

    $data = $response->toArray();
    
    echo "📋 Full response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

    if (isset($data['choices'][0]['message']['content'])) {
        $compliment = trim($data['choices'][0]['message']['content']);
        echo "✅ SUCCESS! Compliment received:\n";
        echo "   💝 {$compliment}\n";
    } else {
        echo "❌ No content in response!\n";
    }

} catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
    echo "❌ Client Error (4xx):\n";
    echo "   " . $e->getMessage() . "\n\n";
    
    try {
        $response = $e->getResponse();
        $data = $response->toArray(false);
        echo "   Response body:\n";
        echo "   " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } catch (\Exception $ex) {
        echo "   Could not parse response\n";
    }
    
} catch (\Symfony\Component\HttpClient\Exception\ServerException $e) {
    echo "❌ Server Error (5xx):\n";
    echo "   " . $e->getMessage() . "\n";
    
} catch (\Exception $e) {
    echo "❌ Exception:\n";
    echo "   Type: " . get_class($e) . "\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   Code: " . $e->getCode() . "\n";
}
