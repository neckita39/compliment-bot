<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Bitrix24Service
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $portalUrl,
        private string $webhookUserId,
        private string $webhookToken
    ) {
    }

    public function isConfigured(): bool
    {
        return !empty($this->portalUrl) && !empty($this->webhookUserId) && !empty($this->webhookToken);
    }

    public function getPortalUrl(): string
    {
        return $this->portalUrl;
    }

    public function sendMessage(int $userId, string $message): bool
    {
        $message .= "\n[size=10][i]Сообщение сгенерировано роботом, но с любовью[/i][/size]";

        try {
            $response = $this->httpClient->request('POST', $this->buildUrl('im.message.add'), [
                'json' => [
                    'DIALOG_ID' => (string) $userId,
                    'MESSAGE' => $message,
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['error'])) {
                $this->logger->error('Bitrix24 sendMessage error', [
                    'error' => $data['error'],
                    'error_description' => $data['error_description'] ?? '',
                    'user_id' => $userId,
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Bitrix24 sendMessage exception', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            return false;
        }
    }

    public function getUserInfo(int $userId): ?array
    {
        try {
            $response = $this->httpClient->request('POST', $this->buildUrl('im.user.get'), [
                'json' => [
                    'ID' => $userId,
                ],
            ]);

            $data = $response->toArray();

            if (isset($data['error'])) {
                $this->logger->error('Bitrix24 getUserInfo error', [
                    'error' => $data['error'],
                    'error_description' => $data['error_description'] ?? '',
                    'user_id' => $userId,
                ]);
                return null;
            }

            $result = $data['result'] ?? null;
            if (!$result) {
                return null;
            }

            return [
                'name' => trim(($result['name'] ?? '') ?: (($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''))),
                'first_name' => $result['first_name'] ?? '',
                'last_name' => $result['last_name'] ?? '',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Bitrix24 getUserInfo exception', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            return null;
        }
    }

    private function buildUrl(string $method): string
    {
        $portal = rtrim($this->portalUrl, '/');
        if (!str_starts_with($portal, 'https://') && !str_starts_with($portal, 'http://')) {
            $portal = 'https://' . $portal;
        }

        return "{$portal}/rest/{$this->webhookUserId}/{$this->webhookToken}/{$method}.json";
    }
}
