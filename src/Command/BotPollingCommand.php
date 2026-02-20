<?php

namespace App\Command;

use App\Entity\ComplimentHistory;
use App\Entity\Subscription;
use App\Enum\Role;
use App\Repository\ComplimentHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\ComplimentGeneratorInterface;
use App\Service\TelegramService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bot:polling',
    description: 'Start Telegram bot long polling',
)]
class BotPollingCommand extends Command
{
    public function __construct(
        private TelegramService $telegramService,
        private ComplimentGeneratorInterface $complimentGenerator,
        private SubscriptionRepository $subscriptionRepository,
        private ComplimentHistoryRepository $complimentHistoryRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private string $adminUsername = ''
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Compliment Bot - Long Polling Started');
        $io->info('Press Ctrl+C to stop');

        $offset = 0;

        while (true) {
            try {
                $updates = $this->telegramService->getUpdates($offset);

                foreach ($updates as $update) {
                    $this->processUpdate($update, $io);
                    $offset = $update['update_id'] + 1;
                }

                // Small delay to avoid hammering the API
                usleep(100000); // 0.1 second
            } catch (\Exception $e) {
                $this->logger->error('Polling error', ['error' => $e->getMessage()]);
                $io->error('Error: ' . $e->getMessage());
                sleep(5); // Wait 5 seconds before retry
            }
        }

        return Command::SUCCESS;
    }

    private function processUpdate(array $update, SymfonyStyle $io): void
    {
        // Handle callback queries (button presses)
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query'], $io);
            return;
        }

        // Handle regular messages
        if (isset($update['message'])) {
            $this->handleMessage($update['message'], $io);
            return;
        }
    }

    private function isAdmin(array $from): bool
    {
        if (empty($this->adminUsername)) {
            return false;
        }
        $username = $from['username'] ?? '';
        return strcasecmp($username, $this->adminUsername) === 0;
    }

    private function handleMessage(array $message, SymfonyStyle $io): void
    {
        $chatId = (string) $message['chat']['id'];
        $text = $message['text'] ?? '';

        $io->writeln(sprintf(
            '[%s] Message from %s: %s',
            date('H:i:s'),
            $message['from']['first_name'] ?? 'Unknown',
            $text
        ));

        if ($text === '/start') {
            $this->handleStartCommand($chatId, $message);
        } elseif ($text === '/admin') {
            if ($this->isAdmin($message['from'] ?? [])) {
                $this->handleAdminCommand($chatId);
            }
        }
    }

    private function handleCallbackQuery(array $callbackQuery, SymfonyStyle $io): void
    {
        $chatId = (string) $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'] ?? '';
        $callbackQueryId = $callbackQuery['id'];
        $messageId = $callbackQuery['message']['message_id'] ?? null;

        $io->writeln(sprintf(
            '[%s] Callback from %s: %s',
            date('H:i:s'),
            $callbackQuery['from']['first_name'] ?? 'Unknown',
            $data
        ));

        if (str_starts_with($data, 'admin_')) {
            if (!$this->isAdmin($callbackQuery['from'] ?? [])) {
                $this->telegramService->answerCallbackQuery($callbackQueryId, 'Нет доступа');
                return;
            }
            $this->telegramService->answerCallbackQuery($callbackQueryId);
            $this->handleAdminCallback($chatId, $data, $messageId);
            return;
        }

        if (str_starts_with($data, 'role_')) {
            $this->handleRoleSelect($chatId, substr($data, 5), $callbackQueryId);
            return;
        }

        match ($data) {
            'subscribe' => $this->handleSubscribe($chatId, $callbackQuery, $callbackQueryId),
            'unsubscribe' => $this->handleUnsubscribe($chatId, $callbackQueryId),
            'compliment' => $this->handleComplimentNow($chatId, $callbackQuery, $callbackQueryId),
            'choose_role' => $this->handleChooseRole($chatId, $callbackQueryId),
            'toggle_weekend' => $this->handleToggleWeekend($chatId, $callbackQueryId),
            default => null,
        };
    }

    private function handleStartCommand(string $chatId, array $message): void
    {
        $firstName = $message['from']['first_name'] ?? 'друг';

        $welcomeText = <<<TEXT
Привет, {$firstName}! 👋

Я бот, который будет радовать тебя тёплыми словами каждый день! 💌

✨ Каждый день в установленное время ты получишь приятное сообщение — комплимент, поддержку или мотивацию!

Используй кнопки ниже для управления подпиской.
TEXT;

        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);
        $weekendEnabled = $subscription?->isWeekendEnabled();

        $keyboard = $this->telegramService->getMainMenuKeyboard($weekendEnabled);

        if ($this->isAdmin($message['from'] ?? [])) {
            $keyboard['inline_keyboard'][] = [['text' => '🔧 Панель админа', 'callback_data' => 'admin_list']];
        }

        $this->telegramService->sendMessage(
            $chatId,
            $welcomeText,
            $keyboard
        );
    }

    private function handleSubscribe(string $chatId, array $callbackQuery, string $callbackQueryId): void
    {
        $user = $callbackQuery['from'];

        // Check if already subscribed
        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);

        if ($subscription && $subscription->isActive()) {
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                'Ты уже подписан! 💝'
            );
            return;
        }

        // Create or reactivate subscription
        if (!$subscription) {
            $subscription = new Subscription();
            $subscription->setTelegramChatId($chatId);
            $subscription->setTelegramUsername($user['username'] ?? null);
            $subscription->setTelegramFirstName($user['first_name'] ?? null);
        } else {
            $subscription->setIsActive(true);
        }

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $this->telegramService->answerCallbackQuery(
            $callbackQueryId,
            'Отлично! Теперь ты будешь получать приятные сообщения! 💌'
        );

        $this->telegramService->sendMessage(
            $chatId,
            "✅ Подписка активирована!\n\nБуду радовать тебя каждый день! 💕"
        );
    }

    private function handleUnsubscribe(string $chatId, string $callbackQueryId): void
    {
        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);

        if (!$subscription || !$subscription->isActive()) {
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                'Ты и так не подписан.'
            );
            return;
        }

        $subscription->setIsActive(false);
        $this->entityManager->flush();

        $this->telegramService->answerCallbackQuery(
            $callbackQueryId,
            'Подписка отменена 😢'
        );

        $this->telegramService->sendMessage(
            $chatId,
            "❌ Подписка отменена.\n\nБуду скучать! Возвращайся, когда захочешь! 💔"
        );
    }

    private function handleComplimentNow(string $chatId, array $callbackQuery, string $callbackQueryId): void
    {
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $firstName = $callbackQuery['from']['first_name'] ?? null;

        // Get role from subscription or default to 'wife'
        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);
        $role = $subscription ? $subscription->getRole() : 'neutral';

        $previousCompliments = $subscription
            ? $this->complimentHistoryRepository->findRecentTexts($subscription, $subscription->getHistoryContextSize())
            : [];

        try {
            $compliment = $this->complimentGenerator->generateCompliment($firstName, $role, $previousCompliments);

            $emoji = Role::from($role)->emoji();
            $this->telegramService->sendMessage($chatId, "{$emoji} {$compliment}");

            // Update last compliment timestamp and save history
            if ($subscription) {
                $subscription->setLastComplimentAt(new \DateTime());

                $history = new ComplimentHistory();
                $history->setSubscription($subscription);
                $history->setComplimentText($compliment);
                $this->entityManager->persist($history);

                $this->entityManager->flush();
            }
        } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
            // Parse API error response
            try {
                $response = $e->getResponse();
                $data = $response->toArray(false);
                $errorMsg = $data['error']['message'] ?? $data['message'] ?? json_encode($data);
                $this->telegramService->sendMessage(
                    $chatId,
                    "❌ Ошибка AI API:\n\n{$errorMsg}"
                );
            } catch (\Exception $ex) {
                $this->telegramService->sendMessage($chatId, "❌ Ошибка API: " . $e->getMessage());
            }
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($chatId, "❌ Ошибка: " . $e->getMessage());
        }
    }

    private function handleChooseRole(string $chatId, string $callbackQueryId): void
    {
        $this->telegramService->answerCallbackQuery($callbackQueryId);

        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);
        $currentRole = $subscription?->getRole();

        $this->telegramService->sendMessage(
            $chatId,
            "🎭 Выбери роль для сообщений:",
            $this->telegramService->getRoleKeyboard($currentRole)
        );
    }

    private function handleToggleWeekend(string $chatId, string $callbackQueryId): void
    {
        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);

        if (!$subscription) {
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                'Сначала подпишись! 💝'
            );
            return;
        }

        $subscription->setWeekendEnabled(!$subscription->isWeekendEnabled());
        $this->entityManager->flush();

        $status = $subscription->isWeekendEnabled() ? 'включены' : 'отключены';
        $this->telegramService->answerCallbackQuery(
            $callbackQueryId,
            "Выходные {$status}!"
        );

        $statusEmoji = $subscription->isWeekendEnabled() ? '✅' : '❌';
        $this->telegramService->sendMessage(
            $chatId,
            "{$statusEmoji} Комплименты по выходным {$status}.",
            $this->telegramService->getMainMenuKeyboard($subscription->isWeekendEnabled())
        );
    }

    private function handleRoleSelect(string $chatId, string $roleValue, string $callbackQueryId): void
    {
        $role = Role::tryFrom($roleValue);
        if (!$role) {
            $this->telegramService->answerCallbackQuery($callbackQueryId, 'Неизвестная роль');
            return;
        }

        $subscription = $this->subscriptionRepository->findOneByChatId($chatId);
        if (!$subscription) {
            $this->telegramService->answerCallbackQuery(
                $callbackQueryId,
                'Сначала подпишись! 💝'
            );
            return;
        }

        $subscription->setRole($role->value);
        $this->entityManager->flush();

        $this->telegramService->answerCallbackQuery(
            $callbackQueryId,
            "Роль изменена: {$role->label()}"
        );

        $this->telegramService->sendMessage(
            $chatId,
            "✅ Роль изменена на {$role->label()}\n\nТеперь сообщения будут в этом стиле!"
        );
    }

    // ─── Admin handlers ────────────────────────────────────────

    private function handleAdminCommand(string $chatId): void
    {
        $subscriptions = $this->subscriptionRepository->findBy([], ['id' => 'ASC']);
        $total = count($subscriptions);

        $text = "👥 Подписчики ({$total}):";
        $keyboard = $this->telegramService->getAdminListKeyboard($subscriptions, 0);

        $this->telegramService->sendMessage($chatId, $text, $keyboard);
    }

    private function handleAdminCallback(string $chatId, string $data, ?int $messageId): void
    {
        if ($data === 'admin_list') {
            $this->handleAdminList($chatId, 0, $messageId);
            return;
        }

        if (preg_match('/^admin_page_(\d+)$/', $data, $m)) {
            $this->handleAdminList($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^admin_sub_(\d+)$/', $data, $m)) {
            $this->handleAdminSubscriberDetail($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^admin_toggle_(\d+)$/', $data, $m)) {
            $this->handleAdminToggle($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^admin_send_(\d+)$/', $data, $m)) {
            $this->handleAdminSendCompliment($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^admin_hist_(\d+)_(\d+)$/', $data, $m)) {
            $this->handleAdminHistory($chatId, (int) $m[1], (int) $m[2], $messageId);
            return;
        }

        if (preg_match('/^admin_hist_(\d+)$/', $data, $m)) {
            $this->handleAdminHistory($chatId, (int) $m[1], 0, $messageId);
            return;
        }

        if (preg_match('/^admin_chwdt_(\d+)$/', $data, $m)) {
            $this->handleAdminTimePrompt($chatId, (int) $m[1], 'weekday', $messageId);
            return;
        }

        if (preg_match('/^admin_chwet_(\d+)$/', $data, $m)) {
            $this->handleAdminTimePrompt($chatId, (int) $m[1], 'weekend', $messageId);
            return;
        }

        if (preg_match('/^admin_swdt_(\d+)_(\d{2}:\d{2})$/', $data, $m)) {
            $this->handleAdminSetTime($chatId, (int) $m[1], 'weekday', $m[2], $messageId);
            return;
        }

        if (preg_match('/^admin_swet_(\d+)_(\d{2}:\d{2})$/', $data, $m)) {
            $this->handleAdminSetTime($chatId, (int) $m[1], 'weekend', $m[2], $messageId);
            return;
        }
    }

    private function handleAdminList(string $chatId, int $page, ?int $messageId): void
    {
        $subscriptions = $this->subscriptionRepository->findBy([], ['id' => 'ASC']);
        $total = count($subscriptions);

        $text = "👥 Подписчики ({$total}):";
        $keyboard = $this->telegramService->getAdminListKeyboard($subscriptions, $page);

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleAdminSubscriberDetail(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $name = $subscription->getTelegramFirstName() ?: 'Без имени';
        $username = $subscription->getTelegramUsername() ? ' (@' . $subscription->getTelegramUsername() . ')' : '';
        $status = $subscription->isActive() ? '✅ Активна' : '❌ Неактивна';
        $role = Role::tryFrom($subscription->getRole());
        $roleLabel = $role ? $role->label() : $subscription->getRole();
        $weekdayTime = $subscription->getWeekdayTime()?->format('H:i') ?? '—';
        $weekendTime = $subscription->getWeekendTime()?->format('H:i') ?? '—';
        $lastCompliment = $subscription->getLastComplimentAt()?->format('d.m.Y') ?? 'нет';

        $text = <<<TEXT
👤 {$name}{$username}
Статус: {$status}
Роль: {$roleLabel}
Будни: {$weekdayTime} | Выходные: {$weekendTime}
Последний комплимент: {$lastCompliment}
TEXT;

        $keyboard = $this->telegramService->getAdminSubscriberKeyboard($subscriptionId, $subscription->isActive());

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleAdminToggle(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $subscription->setIsActive(!$subscription->isActive());
        $this->entityManager->flush();

        $this->handleAdminSubscriberDetail($chatId, $subscriptionId, $messageId);
    }

    private function handleAdminSendCompliment(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $targetChatId = $subscription->getTelegramChatId();
        $firstName = $subscription->getTelegramFirstName();
        $role = $subscription->getRole();

        $previousCompliments = $this->complimentHistoryRepository->findRecentTexts(
            $subscription,
            $subscription->getHistoryContextSize()
        );

        try {
            $compliment = $this->complimentGenerator->generateCompliment($firstName, $role, $previousCompliments);

            $emoji = Role::tryFrom($role)?->emoji() ?? '💬';
            $this->telegramService->sendMessage($targetChatId, "{$emoji} {$compliment}");

            $subscription->setLastComplimentAt(new \DateTime());
            $history = new ComplimentHistory();
            $history->setSubscription($subscription);
            $history->setComplimentText($compliment);
            $this->entityManager->persist($history);
            $this->entityManager->flush();

            $name = $firstName ?: 'подписчику';
            if ($messageId) {
                $this->telegramService->editMessageText(
                    $chatId,
                    $messageId,
                    "✅ Комплимент отправлен {$name}!\n\n{$emoji} {$compliment}",
                    ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'admin_sub_' . $subscriptionId]]]]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Admin send compliment failed', ['error' => $e->getMessage()]);
            if ($messageId) {
                $this->telegramService->editMessageText(
                    $chatId,
                    $messageId,
                    "❌ Ошибка отправки: " . $e->getMessage(),
                    ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'admin_sub_' . $subscriptionId]]]]
                );
            }
        }
    }

    private function handleAdminHistory(string $chatId, int $subscriptionId, int $offset, ?int $messageId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $limit = 5;
        $total = $this->complimentHistoryRepository->countBySubscription($subscription);
        $entries = $this->complimentHistoryRepository->findPaginated($subscription, $offset, $limit);
        $name = $subscription->getTelegramFirstName() ?: 'Без имени';

        $text = "📜 История: {$name} ({$total} комплиментов)\n";

        if (empty($entries)) {
            $text .= "\nИстория пуста.";
        } else {
            foreach ($entries as $entry) {
                $date = $entry->getSentAt()?->format('d.m.Y H:i') ?? '—';
                $snippet = mb_substr($entry->getComplimentText(), 0, 100);
                if (mb_strlen($entry->getComplimentText()) > 100) {
                    $snippet .= '...';
                }
                $text .= "\n📅 {$date}\n{$snippet}\n";
            }
        }

        $hasMore = ($offset + $limit) < $total;
        $keyboard = $this->telegramService->getAdminHistoryKeyboard($subscriptionId, $offset + $limit, $hasMore);

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleAdminTimePrompt(string $chatId, int $subscriptionId, string $type, ?int $messageId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $name = $subscription->getTelegramFirstName() ?: 'Без имени';
        $typeLabel = $type === 'weekday' ? 'будни' : 'выходные';
        $currentTime = $type === 'weekday'
            ? $subscription->getWeekdayTime()?->format('H:i')
            : $subscription->getWeekendTime()?->format('H:i');

        $text = "⏰ Время ({$typeLabel}) для {$name}:\nТекущее: {$currentTime}";
        $keyboard = $this->telegramService->getAdminTimeKeyboard($subscriptionId, $type, $currentTime);

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleAdminSetTime(string $chatId, int $subscriptionId, string $type, string $time, ?int $messageId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $dateTime = \DateTime::createFromFormat('H:i', $time);
        if (!$dateTime) {
            return;
        }

        if ($type === 'weekday') {
            $subscription->setWeekdayTime($dateTime);
        } else {
            $subscription->setWeekendTime($dateTime);
        }

        $this->entityManager->flush();

        $this->handleAdminSubscriberDetail($chatId, $subscriptionId, $messageId);
    }
}
