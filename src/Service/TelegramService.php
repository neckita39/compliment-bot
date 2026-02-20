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

        $buttons[] = [['text' => '◀️ Главное меню', 'callback_data' => 'admin_home']];

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

    public function getAdminHistoryKeyboard(int $id, int $offset, bool $hasMore): array
    {
        $nav = [];
        if ($hasMore) {
            $nav[] = ['text' => '📜 Ещё', 'callback_data' => "admin_hist_{$id}_{$offset}"];
        }
        $nav[] = ['text' => '◀️ Назад', 'callback_data' => 'admin_sub_' . $id];

        return ['inline_keyboard' => [$nav]];
    }

    public function getAdminHomeKeyboard(int $tgCount, int $b24Count): array
    {
        return [
            'inline_keyboard' => [
                [['text' => "📱 Telegram ({$tgCount})", 'callback_data' => 'admin_list']],
                [['text' => "💼 Битрикс24 ({$b24Count})", 'callback_data' => 'b24_list']],
                [['text' => '➕ Добавить Б24 подписчика', 'callback_data' => 'b24_add']],
            ],
        ];
    }

    /**
     * @param \App\Entity\Bitrix24Subscription[] $subscriptions
     */
    public function getB24ListKeyboard(array $subscriptions, int $page = 0, int $perPage = 5): array
    {
        $total = count($subscriptions);
        $pages = (int) ceil($total / $perPage);
        $offset = $page * $perPage;
        $slice = array_slice($subscriptions, $offset, $perPage);

        $buttons = [];
        foreach ($slice as $sub) {
            $status = $sub->isActive() ? '✅' : '❌';
            $name = $sub->getBitrix24UserName() ?: 'ID ' . $sub->getBitrix24UserId();
            $buttons[] = [['text' => "{$status} {$name} ({$sub->getBitrix24UserId()})", 'callback_data' => 'b24_sub_' . $sub->getId()]];
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = ['text' => '<< Назад', 'callback_data' => 'b24_page_' . ($page - 1)];
            }
            if ($page < $pages - 1) {
                $nav[] = ['text' => 'Вперёд >>', 'callback_data' => 'b24_page_' . ($page + 1)];
            }
            $buttons[] = $nav;
        }

        $buttons[] = [['text' => '◀️ Главное меню', 'callback_data' => 'admin_home']];

        return ['inline_keyboard' => $buttons];
    }

    public function getB24SubscriberKeyboard(int $id, bool $isActive): array
    {
        $toggleText = $isActive ? '⏸ Деактивировать' : '▶️ Активировать';

        return [
            'inline_keyboard' => [
                [['text' => $toggleText, 'callback_data' => 'b24_toggle_' . $id]],
                [['text' => '⏰ Время (будни)', 'callback_data' => 'b24_chwdt_' . $id]],
                [['text' => '⏰ Время (выходные)', 'callback_data' => 'b24_chwet_' . $id]],
                [['text' => '📜 История', 'callback_data' => 'b24_hist_' . $id]],
                [['text' => '💌 Отправить комплимент', 'callback_data' => 'b24_send_' . $id]],
                [['text' => '🗑 Удалить', 'callback_data' => 'b24_delete_' . $id]],
                [['text' => '◀️ Назад к списку', 'callback_data' => 'b24_list']],
            ],
        ];
    }

    public function getB24HistoryKeyboard(int $id, int $offset, bool $hasMore): array
    {
        $nav = [];
        if ($hasMore) {
            $nav[] = ['text' => '📜 Ещё', 'callback_data' => "b24_hist_{$id}_{$offset}"];
        }
        $nav[] = ['text' => '◀️ Назад', 'callback_data' => 'b24_sub_' . $id];

        return ['inline_keyboard' => [$nav]];
    }

    private function getApiUrl(string $method): string
    {
        return self::API_URL . $this->botToken . '/' . $method;
    }
}
