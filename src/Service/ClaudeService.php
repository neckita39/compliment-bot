<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClaudeService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-3-haiku-20240307';

    private const FALLBACK_COMPLIMENTS = [
        'Ты освещаешь мой мир своей улыбкой каждый день.',
        'Твоя доброта делает мир лучше.',
        'Рядом с тобой я становлюсь лучшей версией себя.',
        'Ты самое красивое, что случилось в моей жизни.',
        'Твои глаза — мои любимые звёзды.',
        'Каждый день с тобой — подарок.',
        'Ты умеешь найти свет даже в самые тёмные дни.',
        'Твоя улыбка — лучшее лекарство от всех проблем.',
        'Ты вдохновляешь меня быть лучше.',
        'С тобой даже обычные моменты становятся волшебными.',
        'Ты — моя любимая мелодия.',
        'Твоя нежность согревает моё сердце.',
        'Ты делаешь каждый день особенным.',
        'Рядом с тобой я чувствую себя дома.',
        'Ты — мой лучший друг и любовь всей жизни.',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $apiKey
    ) {
    }

    public function generateCompliment(?string $name = null): string
    {
        if (empty($this->apiKey) || $this->apiKey === 'your_claude_api_key_here') {
            return $this->getFallbackCompliment();
        }

        try {
            $prompt = $this->buildPrompt($name);

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'max_tokens' => 200,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['content'][0]['text'])) {
                return trim($data['content'][0]['text']);
            }

            $this->logger->warning('Unexpected Claude API response', ['response' => $data]);
            return $this->getFallbackCompliment();
        } catch (\Exception $e) {
            $this->logger->error('Claude API error', ['error' => $e->getMessage()]);
            return $this->getFallbackCompliment();
        }
    }

    private function buildPrompt(?string $name): string
    {
        $namePhrase = $name ? " для {$name}" : '';

        return <<<PROMPT
Напиши один красивый, искренний и романтичный комплимент{$namePhrase} на русском языке.

Требования:
- Комплимент должен быть тёплым и нежным
- Не более 2-3 предложений
- Без кавычек и префиксов типа "Комплимент:"
- Просто текст комплимента

Напиши только комплимент, без дополнительных пояснений.
PROMPT;
    }

    private function getFallbackCompliment(): string
    {
        return self::FALLBACK_COMPLIMENTS[array_rand(self::FALLBACK_COMPLIMENTS)];
    }
}
