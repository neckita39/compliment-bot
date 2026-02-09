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
        private LoggerInterface $logger
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
        }
    }

    private function handleCallbackQuery(array $callbackQuery, SymfonyStyle $io): void
    {
        $chatId = (string) $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'] ?? '';
        $callbackQueryId = $callbackQuery['id'];

        $io->writeln(sprintf(
            '[%s] Callback from %s: %s',
            date('H:i:s'),
            $callbackQuery['from']['first_name'] ?? 'Unknown',
            $data
        ));

        match ($data) {
            'subscribe' => $this->handleSubscribe($chatId, $callbackQuery, $callbackQueryId),
            'unsubscribe' => $this->handleUnsubscribe($chatId, $callbackQueryId),
            'compliment' => $this->handleComplimentNow($chatId, $callbackQuery, $callbackQueryId),
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

        $this->telegramService->sendMessage(
            $chatId,
            $welcomeText,
            $this->telegramService->getMainMenuKeyboard()
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
        $role = $subscription ? $subscription->getRole() : 'wife';

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
}
