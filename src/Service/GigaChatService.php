<?php

namespace App\Service;

use App\Enum\Role;
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
        $this->logger->info('GigaChat: generating compliment', [
            'name' => $name,
            'role' => $role,
            'previousCount' => count($previousCompliments),
        ]);

        $this->ensureValidToken();

        $prompt = Role::from($role)->buildPrompt($name, $previousCompliments);

        try {
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

            $statusCode = $response->getStatusCode();
            $this->logger->info('GigaChat: response received', ['statusCode' => $statusCode]);

            $data = $response->toArray();

            if (isset($data['choices'][0]['message']['content'])) {
                $compliment = trim($data['choices'][0]['message']['content']);
                $this->logger->info('GigaChat: compliment generated successfully');
                return $compliment;
            }

            $this->logger->error('GigaChat: unexpected response format', ['response' => $data]);
            throw new \RuntimeException('GigaChat API вернул неожиданный ответ: ' . json_encode($data));
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            $responseBody = $e->getResponse()->getContent(false);
            $this->logger->error('GigaChat: client error', [
                'statusCode' => $e->getResponse()->getStatusCode(),
                'body' => $responseBody,
            ]);
            throw new \RuntimeException('GigaChat API ошибка (' . $e->getResponse()->getStatusCode() . '): ' . $responseBody);
        } catch (\Symfony\Component\HttpClient\Exception\ServerException $e) {
            $responseBody = $e->getResponse()->getContent(false);
            $this->logger->error('GigaChat: server error', [
                'statusCode' => $e->getResponse()->getStatusCode(),
                'body' => $responseBody,
            ]);
            throw new \RuntimeException('GigaChat API серверная ошибка (' . $e->getResponse()->getStatusCode() . '): ' . $responseBody);
        } catch (\Symfony\Component\HttpClient\Exception\TransportException $e) {
            $this->logger->error('GigaChat: transport error', ['error' => $e->getMessage()]);
            throw new \RuntimeException('GigaChat API ошибка соединения: ' . $e->getMessage());
        }
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
        $this->logger->info('GigaChat: requesting OAuth token');

        $authString = base64_encode("{$this->clientId}:{$this->clientSecret}");

        try {
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
        } catch (\Exception $e) {
            $this->logger->error('GigaChat: OAuth token request failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Не удалось получить токен GigaChat: ' . $e->getMessage());
        }

        if (!isset($data['access_token'])) {
            $this->logger->error('GigaChat: no access_token in OAuth response', ['response' => $data]);
            throw new \RuntimeException('Не удалось получить токен GigaChat: ' . json_encode($data));
        }

        $this->accessToken = $data['access_token'];
        // expires_at from GigaChat is a Unix timestamp in milliseconds
        $this->tokenExpiresAt = isset($data['expires_at']) ? (int)($data['expires_at'] / 1000) : time() + 1800;

        $this->logger->info('GigaChat: token refreshed successfully', [
            'expires_at' => date('Y-m-d H:i:s', $this->tokenExpiresAt),
        ]);
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
