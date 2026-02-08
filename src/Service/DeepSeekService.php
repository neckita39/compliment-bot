<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DeepSeekService
{
    private const API_URL = 'https://api.deepseek.com/v1/chat/completions';
    private const MODEL = 'deepseek-chat';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiKey
    ) {
    }

    public function generateCompliment(?string $name = null, string $role = 'wife', array $previousCompliments = []): string
    {
        if (empty($this->apiKey) || $this->apiKey === 'your_deepseek_api_key_here') {
            throw new \RuntimeException('❌ DeepSeek API key не настроен. Укажите DEEPSEEK_API_KEY в .env файле.');
        }

        $prompt = $this->buildPrompt($name, $role, $previousCompliments);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'json' => [
                'model' => self::MODEL,
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

        $data = $response->toArray();

        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }

        throw new \RuntimeException('❌ DeepSeek API вернул неожиданный ответ (нет content в ответе)');
    }

    private function buildPrompt(?string $name, string $role, array $previousCompliments = []): string
    {
        $namePhrase = $name ? " для {$name}" : '';

        if ($role === 'sister') {
            $prompt = <<<PROMPT
Напиши одно тёплое подбадривающее сообщение{$namePhrase} — для 10-летней девочки-школьницы на русском языке.

Требования:
- Дружеское и позитивное настроение
- Мотивация, поддержка в учёбе или добрые слова
- Можно использовать 1-2 эмодзи (но не обязательно)
- Не более 2 предложений
- Без кавычек и префиксов
- Просто текст сообщения

Напиши только сообщение, без дополнительных пояснений.
PROMPT;
        } else {
            // Default: wife role
            $prompt = <<<PROMPT
Напиши один красивый, искренний и романтичный комплимент{$namePhrase} на русском языке.

Требования:
- Комплимент должен быть тёплым и нежным
- Не более 2-3 предложений
- Без кавычек и префиксов типа "Комплимент:"
- Просто текст комплимента

Напиши только комплимент, без дополнительных пояснений.
PROMPT;
        }

        if (!empty($previousCompliments)) {
            $list = '';
            foreach ($previousCompliments as $i => $text) {
                $list .= ($i + 1) . '. ' . $text . "\n";
            }

            $prompt .= <<<DEDUP

ВАЖНО: Ниже приведены ранее отправленные сообщения. Твоё новое сообщение должно быть уникальным
и НЕ повторять ни одно из них по смыслу, структуре или ключевым фразам:
{$list}
DEDUP;
        }

        return $prompt;
    }
}
