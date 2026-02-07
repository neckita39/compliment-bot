<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramService
{
    private const API_URL = 'https://api.telegram.org/bot';

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private string $botToken
    ) {
    }

    public function getUpdates(int $offset = 0, int $timeout = 30): array
    {
        try {
            $response = $this->httpClient->request('GET', $this->getApiUrl('getUpdates'), [
                'query' => [
                    'offset' => $offset,
                    'timeout' => $timeout,
                ],
            ]);

            $data = $response->toArray();

            if (!$data['ok']) {
                $this->logger->error('Telegram getUpdates error', ['response' => $data]);
                return [];
            }

            return $data['result'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Telegram getUpdates exception', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($replyMarkup) {
                $params['reply_markup'] = json_encode($replyMarkup);
            }

            $response = $this->httpClient->request('POST', $this->getApiUrl('sendMessage'), [
                'body' => $params,
            ]);

            $data = $response->toArray();

            if (!$data['ok']) {
                $this->logger->error('Telegram sendMessage error', ['response' => $data]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Telegram sendMessage exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        try {
            $params = [
                'callback_query_id' => $callbackQueryId,
            ];

            if ($text) {
                $params['text'] = $text;
            }

            $response = $this->httpClient->request('POST', $this->getApiUrl('answerCallbackQuery'), [
                'body' => $params,
            ]);

            $data = $response->toArray();

            return $data['ok'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('Telegram answerCallbackQuery exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getMainMenuKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '💝 Подписаться', 'callback_data' => 'subscribe'],
                    ['text' => '🚫 Отписаться', 'callback_data' => 'unsubscribe'],
                ],
                [
                    ['text' => '💌 Получить комплимент', 'callback_data' => 'compliment'],
                ],
            ],
        ];
    }

    private function getApiUrl(string $method): string
    {
        return self::API_URL . $this->botToken . '/' . $method;
    }
}
