<?php

namespace App\Service;

use App\Enum\Role;
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

    public function sendMessageWithResult(string $chatId, string $text, ?array $replyMarkup = null): ?array
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
                $this->logger->error('Telegram sendMessageWithResult error', ['response' => $data]);
                return null;
            }

            return $data['result'] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('Telegram sendMessageWithResult exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function editMessageText(string $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        try {
            $params = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ];

            if ($replyMarkup) {
                $params['reply_markup'] = json_encode($replyMarkup);
            }

            $response = $this->httpClient->request('POST', $this->getApiUrl('editMessageText'), [
                'body' => $params,
            ]);

            $data = $response->toArray();

            if (!$data['ok']) {
                $this->logger->error('Telegram editMessageText error', ['response' => $data]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Telegram editMessageText exception', ['error' => $e->getMessage()]);
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

    public function getMainMenuKeyboard(?bool $weekendEnabled = null): array
    {
        $weekendLabel = $weekendEnabled === false
            ? '📅 Выходные: ВЫКЛ'
            : '📅 Выходные: ВКЛ';

        return [
            'inline_keyboard' => [
                [
                    ['text' => '💝 Подписаться', 'callback_data' => 'subscribe'],
                    ['text' => '🚫 Отписаться', 'callback_data' => 'unsubscribe'],
                ],
                [
                    ['text' => '💌 Получить комплимент', 'callback_data' => 'compliment'],
                ],
                [
                    ['text' => '🎭 Выбрать роль', 'callback_data' => 'choose_role'],
                ],
                [
                    ['text' => $weekendLabel, 'callback_data' => 'toggle_weekend'],
                ],
            ],
        ];
    }

    public function getRoleKeyboard(?string $currentRole = null): array
    {
        $buttons = [];
        foreach (Role::cases() as $role) {
            $label = $role->label();
            if ($currentRole === $role->value) {
                $label = '✓ ' . $label;
            }
            $buttons[] = [['text' => $label, 'callback_data' => 'role_' . $role->value]];
        }

        return ['inline_keyboard' => $buttons];
    }

    /**
     * @param \App\Entity\Subscription[] $subscriptions
     */
    public function getAdminListKeyboard(array $subscriptions, int $page = 0, int $perPage = 5): array
    {
        $total = count($subscriptions);
        $pages = (int) ceil($total / $perPage);
        $offset = $page * $perPage;
        $slice = array_slice($subscriptions, $offset, $perPage);

        $buttons = [];
        foreach ($slice as $sub) {
            $status = $sub->isActive() ? '✅' : '❌';
            $name = $sub->getTelegramFirstName() ?: 'ID ' . $sub->getTelegramChatId();
            $username = $sub->getTelegramUsername() ? ' (@' . $sub->getTelegramUsername() . ')' : '';
            $buttons[] = [['text' => "{$status} {$name}{$username}", 'callback_data' => 'admin_sub_' . $sub->getId()]];
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = ['text' => '<< Назад', 'callback_data' => 'admin_page_' . ($page - 1)];
            }
            if ($page < $pages - 1) {
                $nav[] = ['text' => 'Вперёд >>', 'callback_data' => 'admin_page_' . ($page + 1)];
            }
            $buttons[] = $nav;
        }

        return ['inline_keyboard' => $buttons];
    }

    public function getAdminSubscriberKeyboard(int $id, bool $isActive): array
    {
        $toggleText = $isActive ? '⏸ Деактивировать' : '▶️ Активировать';

        return [
            'inline_keyboard' => [
                [['text' => $toggleText, 'callback_data' => 'admin_toggle_' . $id]],
                [['text' => '⏰ Время (будни)', 'callback_data' => 'admin_chwdt_' . $id]],
                [['text' => '⏰ Время (выходные)', 'callback_data' => 'admin_chwet_' . $id]],
                [['text' => '📜 История', 'callback_data' => 'admin_hist_' . $id]],
                [['text' => '💌 Отправить комплимент', 'callback_data' => 'admin_send_' . $id]],
                [['text' => '◀️ Назад к списку', 'callback_data' => 'admin_list']],
            ],
        ];
    }

    public function getAdminTimeKeyboard(int $id, string $type, ?string $currentTime = null): array
    {
        $presets = ['07:00', '08:00', '09:00', '10:00', '10:25', '11:00', '12:00', '14:00', '18:00'];
        $prefix = $type === 'weekday' ? 'admin_swdt' : 'admin_swet';

        $rows = [];
        $row = [];
        foreach ($presets as $i => $time) {
            $label = ($currentTime === $time) ? "✓ {$time}" : $time;
            $row[] = ['text' => $label, 'callback_data' => "{$prefix}_{$id}_{$time}"];
            if (count($row) === 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if (!empty($row)) {
            $rows[] = $row;
        }

        $rows[] = [['text' => '◀️ Назад', 'callback_data' => 'admin_sub_' . $id]];

        return ['inline_keyboard' => $rows];
    }

    public function getAdminHistoryKeyboard(int $id, int $offset, bool $hasMore): array
    {
        $nav = [];
        if ($hasMore) {
            $nav[] = ['text' => '📜 Ещё', 'callback_data' => "admin_hist_{$id}_{$offset}"];
        }
        $nav[] = ['text' => '◀️ Назад', 'callback_data' => 'admin_sub_' . $id];

        return ['inline_keyboard' => [$nav]];
    }

    private function getApiUrl(string $method): string
    {
        return self::API_URL . $this->botToken . '/' . $method;
    }
}
