<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GigaChatService implements ComplimentGeneratorInterface
{
    private const OAUTH_URL = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';
    private const API_URL = 'https://gigachat.devices.sberbank.ru/api/v1/chat/completions';
    private const MODEL = 'GigaChat';

    private ?string $accessToken = null;
    private ?int $tokenExpiresAt = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $clientId,
        private string $clientSecret
    ) {
    }

    public function generateCompliment(?string $name = null, string $role = 'wife', array $previousCompliments = []): string
    {
        if (empty($this->clientId) || $this->clientId === 'your_gigachat_client_id') {
            throw new \RuntimeException('❌ GigaChat credentials не настроены. Укажите GIGACHAT_CLIENT_ID и GIGACHAT_CLIENT_SECRET в .env файле.');
        }

        // Get or refresh access token
        $this->ensureValidToken();

        $prompt = $this->buildPrompt($name, $role, $previousCompliments);

        $response = $this->httpClient->request('POST', self::API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
            'json' => [
                'model' => self::MODEL,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.8,
                'max_tokens' => 200,
            ],
            'verify_peer' => false, // GigaChat uses self-signed cert
        ]);

        $data = $response->toArray();

        if (isset($data['choices'][0]['message']['content'])) {
            return trim($data['choices'][0]['message']['content']);
        }

        throw new \RuntimeException('❌ GigaChat API вернул неожиданный ответ (нет content в ответе)');
    }

    private function ensureValidToken(): void
    {
        // Check if token is still valid (with 5 min buffer)
        if ($this->accessToken && $this->tokenExpiresAt && time() < ($this->tokenExpiresAt - 300)) {
            return;
        }

        // Get new token
        $this->refreshToken();
    }

    private function refreshToken(): void
    {
        $authString = base64_encode("{$this->clientId}:{$this->clientSecret}");

        $response = $this->httpClient->request('POST', self::OAUTH_URL, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
                'RqUID' => $this->generateUUID(),
                'Authorization' => 'Basic ' . $authString,
            ],
            'body' => 'scope=GIGACHAT_API_PERS',
            'verify_peer' => false, // Sberbank uses self-signed cert
        ]);

        $data = $response->toArray();

        if (!isset($data['access_token'])) {
            throw new \RuntimeException('❌ Не удалось получить токен GigaChat: ' . json_encode($data));
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = time() + ($data['expires_at'] ?? 1800); // Default 30 min

        $this->logger->info('GigaChat token refreshed', [
            'expires_in' => $data['expires_at'] ?? 1800,
        ]);
    }

    private function buildPrompt(?string $name, string $role, array $previousCompliments = []): string
    {
        $namePhrase = $name ? " для {$name}" : '';
        
        // Формируем контекст с предыдущими сообщениями
        $historyContext = '';
        if (!empty($previousCompliments)) {
            $historyContext = "\n\nПредыдущие сообщения (НЕ повторяй их, придумай что-то новое):\n";
            foreach ($previousCompliments as $index => $compliment) {
                $historyContext .= ($index + 1) . ". {$compliment}\n";
            }
        }

        if ($role === 'sister') {
            return <<<PROMPT
Напиши одно тёплое подбадривающее сообщение{$namePhrase} — для 10-летней девочки-школьницы на русском языке.

Требования:
- Дружеское и позитивное настроение
- Мотивация, поддержка в учёбе или добрые слова
- Можно использовать 1-2 эмодзи (но не обязательно)
- Не более 2 предложений
- Без кавычек и префиксов
- Просто текст сообщения
- ВАЖНО: Придумай что-то уникальное, не похожее на предыдущие{$historyContext}

Напиши только сообщение, без дополнительных пояснений.
PROMPT;
        }

        // Default: wife role
        return <<<PROMPT
Напиши один красивый, искренний и романтичный комплимент{$namePhrase} на русском языке.

Требования:
- Комплимент должен быть тёплым и нежным
- Не более 2-3 предложений
- Без кавычек и префиксов типа "Комплимент:"
- Просто текст комплимента
- ВАЖНО: Придумай что-то уникальное, не похожее на предыдущие{$historyContext}

Напиши только комплимент, без дополнительных пояснений.
PROMPT;
    }

    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
