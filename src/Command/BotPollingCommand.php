<?php

namespace App\Command;

use App\Entity\Bitrix24ComplimentHistory;
use App\Entity\Bitrix24Subscription;
use App\Entity\ComplimentHistory;
use App\Entity\Subscription;
use App\Enum\Role;
use App\Repository\Bitrix24ComplimentHistoryRepository;
use App\Repository\Bitrix24SubscriptionRepository;
use App\Repository\ComplimentHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Bitrix24Service;
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
    private array $pendingAction = [];

    public function __construct(
        private TelegramService $telegramService,
        private ComplimentGeneratorInterface $complimentGenerator,
        private SubscriptionRepository $subscriptionRepository,
        private ComplimentHistoryRepository $complimentHistoryRepository,
        private Bitrix24SubscriptionRepository $b24SubscriptionRepository,
        private Bitrix24ComplimentHistoryRepository $b24ComplimentHistoryRepository,
        private Bitrix24Service $bitrix24Service,
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

        // Handle pending actions (text input from admin)
        if (isset($this->pendingAction[$chatId]) && $this->isAdmin($message['from'] ?? [])) {
            $this->handlePendingAction($chatId, $text);
            return;
        }

        if ($text === '/start') {
            $this->handleStartCommand($chatId, $message);
        } elseif ($text === '/admin') {
            if ($this->isAdmin($message['from'] ?? [])) {
                $this->handleAdminHome($chatId, null);
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

        if (str_starts_with($data, 'admin_') || str_starts_with($data, 'b24_')) {
            if (!$this->isAdmin($callbackQuery['from'] ?? [])) {
                $this->telegramService->answerCallbackQuery($callbackQueryId, 'Нет доступа');
                return;
            }
            // Clear pending action on any button press
            unset($this->pendingAction[$chatId]);
            $this->telegramService->answerCallbackQuery($callbackQueryId);
            if (str_starts_with($data, 'admin_')) {
                $this->handleAdminCallback($chatId, $data, $messageId);
            } else {
                $this->handleB24Callback($chatId, $data, $messageId);
            }
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
            $keyboard['inline_keyboard'][] = [['text' => '🔧 Панель админа', 'callback_data' => 'admin_home']];
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

    // ─── Pending action handler ────────────────────────────────

    private function handlePendingAction(string $chatId, string $text): void
    {
        $action = $this->pendingAction[$chatId];
        unset($this->pendingAction[$chatId]);

        if ($action === 'b24_add') {
            $this->handleB24AddUser($chatId, $text);
            return;
        }

        // Time setting for TG subscribers
        if (preg_match('/^set_(weekday|weekend)_time_(\d+)$/', $action, $m)) {
            $this->handleSetTimeInput($chatId, (int) $m[2], $m[1], $text, 'telegram');
            return;
        }

        // Time setting for B24 subscribers
        if (preg_match('/^b24_set_(weekday|weekend)_time_(\d+)$/', $action, $m)) {
            $this->handleSetTimeInput($chatId, (int) $m[2], $m[1], $text, 'bitrix24');
            return;
        }
    }

    private function handleB24AddUser(string $chatId, string $text): void
    {
        $userId = (int) trim($text);
        if ($userId <= 0) {
            $this->telegramService->sendMessage($chatId, "❌ Введите числовой ID пользователя.");
            return;
        }

        if (!$this->bitrix24Service->isConfigured()) {
            $this->telegramService->sendMessage($chatId, "❌ Битрикс24 не настроен. Заполните BITRIX24_* в .env");
            return;
        }

        // Check if already exists
        $existing = $this->b24SubscriptionRepository->findByBitrix24UserId($userId);
        if ($existing) {
            $this->telegramService->sendMessage(
                $chatId,
                "⚠️ Пользователь уже добавлен: {$existing->getBitrix24UserName()} ({$userId})",
                ['inline_keyboard' => [[['text' => '◀️ Главное меню', 'callback_data' => 'admin_home']]]]
            );
            return;
        }

        $userInfo = $this->bitrix24Service->getUserInfo($userId);

        $subscription = new Bitrix24Subscription();
        $subscription->setBitrix24UserId($userId);
        $subscription->setPortalUrl($this->bitrix24Service->getPortalUrl());

        if ($userInfo) {
            $subscription->setBitrix24UserName($userInfo['name'] ?: null);
        }

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $name = $subscription->getBitrix24UserName() ?: 'ID ' . $userId;
        $portal = $subscription->getPortalUrl();
        $this->telegramService->sendMessage(
            $chatId,
            "✅ Добавлен: {$name} ({$userId}/{$portal})",
            ['inline_keyboard' => [[['text' => '◀️ Главное меню', 'callback_data' => 'admin_home']]]]
        );
    }

    private function handleSetTimeInput(string $chatId, int $subscriptionId, string $type, string $text, string $platform): void
    {
        $text = trim($text);

        if (!preg_match('/^\d{1,2}:\d{2}$/', $text)) {
            $this->telegramService->sendMessage($chatId, "❌ Неверный формат. Введите время в формате HH:MM");
            // Re-set pending action for retry
            $prefix = $platform === 'bitrix24' ? 'b24_set_' : 'set_';
            $this->pendingAction[$chatId] = "{$prefix}{$type}_time_{$subscriptionId}";
            return;
        }

        $dateTime = \DateTime::createFromFormat('H:i', $text);
        if (!$dateTime) {
            $this->telegramService->sendMessage($chatId, "❌ Неверный формат. Введите время в формате HH:MM");
            $prefix = $platform === 'bitrix24' ? 'b24_set_' : 'set_';
            $this->pendingAction[$chatId] = "{$prefix}{$type}_time_{$subscriptionId}";
            return;
        }

        if ($platform === 'bitrix24') {
            $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
            if (!$subscription) {
                return;
            }

            if ($type === 'weekday') {
                $subscription->setWeekdayTime($dateTime);
            } else {
                $subscription->setWeekendTime($dateTime);
            }
            $this->entityManager->flush();

            $this->handleB24SubscriberDetail($chatId, $subscriptionId, null);
        } else {
            $subscription = $this->subscriptionRepository->find($subscriptionId);
            if (!$subscription) {
                return;
            }

            if ($type === 'weekday') {
                $subscription->setWeekdayTime($dateTime);
            } else {
                $subscription->setWeekendTime($dateTime);
            }
            $this->entityManager->flush();

            $this->handleAdminSubscriberDetail($chatId, $subscriptionId, null);
        }
    }

    // ─── Admin handlers ────────────────────────────────────────

    private function handleAdminHome(string $chatId, ?int $messageId): void
    {
        $tgCount = count($this->subscriptionRepository->findBy([], ['id' => 'ASC']));
        $b24Count = count($this->b24SubscriptionRepository->findBy([], ['id' => 'ASC']));

        $text = "🔧 Панель управления";
        $keyboard = $this->telegramService->getAdminHomeKeyboard($tgCount, $b24Count);

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleAdminCallback(string $chatId, string $data, ?int $messageId): void
    {
        if ($data === 'admin_home') {
            $this->handleAdminHome($chatId, $messageId);
            return;
        }

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

    }

    private function handleAdminList(string $chatId, int $page, ?int $messageId): void
    {
        $subscriptions = $this->subscriptionRepository->findBy([], ['id' => 'ASC']);
        $total = count($subscriptions);

        $text = "📱 Telegram подписчики ({$total}):";
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
Будни: {$weekdayTime} (МСК) | Выходные: {$weekendTime} (МСК)
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

        $this->pendingAction[$chatId] = "set_{$type}_time_{$subscriptionId}";

        $text = "⏰ Время ({$typeLabel}) для {$name}:\nТекущее: {$currentTime} (МСК)\n\nВведите время в формате HH:MM (МСК):";
        $keyboard = ['inline_keyboard' => [[['text' => '◀️ Отмена', 'callback_data' => 'admin_sub_' . $subscriptionId]]]];

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    // ─── Bitrix24 admin handlers ───────────────────────────────

    private function handleB24Callback(string $chatId, string $data, ?int $messageId): void
    {
        if ($data === 'b24_list') {
            $this->handleB24List($chatId, 0, $messageId);
            return;
        }

        if ($data === 'b24_add') {
            $this->handleB24AddPrompt($chatId, $messageId);
            return;
        }

        if (preg_match('/^b24_page_(\d+)$/', $data, $m)) {
            $this->handleB24List($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^b24_sub_(\d+)$/', $data, $m)) {
            $this->handleB24SubscriberDetail($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^b24_toggle_(\d+)$/', $data, $m)) {
            $this->handleB24Toggle($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^b24_send_(\d+)$/', $data, $m)) {
            $this->handleB24SendCompliment($chatId, (int) $m[1], $messageId);
            return;
        }

        if (preg_match('/^b24_hist_(\d+)_(\d+)$/', $data, $m)) {
            $this->handleB24History($chatId, (int) $m[1], (int) $m[2], $messageId);
            return;
        }

        if (preg_match('/^b24_hist_(\d+)$/', $data, $m)) {
            $this->handleB24History($chatId, (int) $m[1], 0, $messageId);
            return;
        }

        if (preg_match('/^b24_chwdt_(\d+)$/', $data, $m)) {
            $this->handleB24TimePrompt($chatId, (int) $m[1], 'weekday', $messageId);
            return;
        }

        if (preg_match('/^b24_chwet_(\d+)$/', $data, $m)) {
            $this->handleB24TimePrompt($chatId, (int) $m[1], 'weekend', $messageId);
            return;
        }

        if (preg_match('/^b24_delete_(\d+)$/', $data, $m)) {
            $this->handleB24Delete($chatId, (int) $m[1], $messageId);
            return;
        }
    }

    private function handleB24List(string $chatId, int $page, ?int $messageId): void
    {
        $subscriptions = $this->b24SubscriptionRepository->findBy([], ['id' => 'ASC']);
        $total = count($subscriptions);

        $text = "💼 Битрикс24 подписчики ({$total}):";
        $keyboard = $this->telegramService->getB24ListKeyboard($subscriptions, $page);

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleB24AddPrompt(string $chatId, ?int $messageId): void
    {
        if (!$this->bitrix24Service->isConfigured()) {
            $text = "❌ Битрикс24 не настроен. Заполните BITRIX24_* в .env";
            $keyboard = ['inline_keyboard' => [[['text' => '◀️ Главное меню', 'callback_data' => 'admin_home']]]];

            if ($messageId) {
                $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
            } else {
                $this->telegramService->sendMessage($chatId, $text, $keyboard);
            }
            return;
        }

        $this->pendingAction[$chatId] = 'b24_add';

        $this->telegramService->sendMessage(
            $chatId,
            "Введите ID пользователя Битрикс24:",
            ['inline_keyboard' => [[['text' => '◀️ Отмена', 'callback_data' => 'admin_home']]]]
        );
    }

    private function handleB24SubscriberDetail(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $name = $subscription->getBitrix24UserName() ?: 'Без имени';
        $b24Id = $subscription->getBitrix24UserId();
        $portal = $subscription->getPortalUrl();
        $status = $subscription->isActive() ? '✅ Активна' : '❌ Неактивна';
        $weekdayTime = $subscription->getWeekdayTime()?->format('H:i') ?? '—';
        $weekendTime = $subscription->getWeekendTime()?->format('H:i') ?? '—';
        $lastCompliment = $subscription->getLastComplimentAt()?->format('d.m.Y') ?? 'нет';

        $text = <<<TEXT
💼 {$name}
Б24 ID: {$b24Id} | {$portal}
Статус: {$status}
Будни: {$weekdayTime} (МСК) | Выходные: {$weekendTime} (МСК)
Последний комплимент: {$lastCompliment}
TEXT;

        $keyboard = $this->telegramService->getB24SubscriberKeyboard($subscriptionId, $subscription->isActive());

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleB24Toggle(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $subscription->setIsActive(!$subscription->isActive());
        $this->entityManager->flush();

        $this->handleB24SubscriberDetail($chatId, $subscriptionId, $messageId);
    }

    private function handleB24SendCompliment(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        if (!$this->bitrix24Service->isConfigured()) {
            if ($messageId) {
                $this->telegramService->editMessageText(
                    $chatId,
                    $messageId,
                    "❌ Битрикс24 не настроен",
                    ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'b24_sub_' . $subscriptionId]]]]
                );
            }
            return;
        }

        $userName = $subscription->getBitrix24UserName();
        $previousCompliments = $this->b24ComplimentHistoryRepository->findRecentTexts(
            $subscription,
            $subscription->getHistoryContextSize()
        );

        try {
            $compliment = $this->complimentGenerator->generateCompliment($userName, 'teammate', $previousCompliments);

            $result = $this->bitrix24Service->sendMessage(
                $subscription->getBitrix24UserId(),
                $compliment
            );

            if ($result) {
                $subscription->setLastComplimentAt(new \DateTime());
                $history = new Bitrix24ComplimentHistory();
                $history->setSubscription($subscription);
                $history->setComplimentText($compliment);
                $this->entityManager->persist($history);
                $this->entityManager->flush();

                $name = $userName ?: 'подписчику';
                if ($messageId) {
                    $this->telegramService->editMessageText(
                        $chatId,
                        $messageId,
                        "✅ Комплимент отправлен {$name} в Б24!\n\n🤝 {$compliment}",
                        ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'b24_sub_' . $subscriptionId]]]]
                    );
                }
            } else {
                if ($messageId) {
                    $this->telegramService->editMessageText(
                        $chatId,
                        $messageId,
                        "❌ Не удалось отправить сообщение в Б24",
                        ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'b24_sub_' . $subscriptionId]]]]
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('B24 send compliment failed', ['error' => $e->getMessage()]);
            if ($messageId) {
                $this->telegramService->editMessageText(
                    $chatId,
                    $messageId,
                    "❌ Ошибка отправки: " . $e->getMessage(),
                    ['inline_keyboard' => [[['text' => '◀️ Назад', 'callback_data' => 'b24_sub_' . $subscriptionId]]]]
                );
            }
        }
    }

    private function handleB24History(string $chatId, int $subscriptionId, int $offset, ?int $messageId): void
    {
        $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $limit = 5;
        $total = $this->b24ComplimentHistoryRepository->countBySubscription($subscription);
        $entries = $this->b24ComplimentHistoryRepository->findPaginated($subscription, $offset, $limit);
        $name = $subscription->getBitrix24UserName() ?: 'Без имени';

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
        $keyboard = $this->telegramService->getB24HistoryKeyboard($subscriptionId, $offset + $limit, $hasMore);

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleB24TimePrompt(string $chatId, int $subscriptionId, string $type, ?int $messageId): void
    {
        $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $name = $subscription->getBitrix24UserName() ?: 'Без имени';
        $typeLabel = $type === 'weekday' ? 'будни' : 'выходные';
        $currentTime = $type === 'weekday'
            ? $subscription->getWeekdayTime()?->format('H:i')
            : $subscription->getWeekendTime()?->format('H:i');

        $this->pendingAction[$chatId] = "b24_set_{$type}_time_{$subscriptionId}";

        $text = "⏰ Время ({$typeLabel}) для {$name}:\nТекущее: {$currentTime} (МСК)\n\nВведите время в формате HH:MM (МСК):";
        $keyboard = ['inline_keyboard' => [[['text' => '◀️ Отмена', 'callback_data' => 'b24_sub_' . $subscriptionId]]]];

        if ($messageId) {
            $this->telegramService->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $this->telegramService->sendMessage($chatId, $text, $keyboard);
        }
    }

    private function handleB24Delete(string $chatId, int $subscriptionId, ?int $messageId): void
    {
        $subscription = $this->b24SubscriptionRepository->find($subscriptionId);
        if (!$subscription) {
            return;
        }

        $this->entityManager->remove($subscription);
        $this->entityManager->flush();

        $this->handleB24List($chatId, 0, $messageId);
    }
}
