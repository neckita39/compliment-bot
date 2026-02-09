<?php

namespace App\Service;

use App\Enum\Role;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DeepSeekService implements ComplimentGeneratorInterface
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

        $prompt = Role::from($role)->buildPrompt($name, $previousCompliments);

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
}
