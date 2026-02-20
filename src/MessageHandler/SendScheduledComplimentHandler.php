<?php

namespace App\MessageHandler;

use App\Entity\Bitrix24ComplimentHistory;
use App\Entity\ComplimentHistory;
use App\Enum\Role;
use App\Message\SendScheduledCompliment;
use App\Repository\Bitrix24ComplimentHistoryRepository;
use App\Repository\Bitrix24SubscriptionRepository;
use App\Repository\ComplimentHistoryRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Bitrix24Service;
use App\Service\ComplimentGeneratorInterface;
use App\Service\TelegramService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendScheduledComplimentHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private ComplimentHistoryRepository $complimentHistoryRepository,
        private Bitrix24SubscriptionRepository $b24SubscriptionRepository,
        private Bitrix24ComplimentHistoryRepository $b24ComplimentHistoryRepository,
        private TelegramService $telegramService,
        private Bitrix24Service $bitrix24Service,
        private ComplimentGeneratorInterface $complimentGenerator,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(SendScheduledCompliment $message): void
    {
        $now = new \DateTime('now', new \DateTimeZone('Europe/Moscow'));
        $currentTime = $now->format('H:i');
        $isWeekend = in_array($now->format('N'), ['6', '7']); // Saturday = 6, Sunday = 7

        $this->logger->info('Checking scheduled compliments', [
            'time' => $currentTime,
            'is_weekend' => $isWeekend,
        ]);

        $this->sendTelegramCompliments($now, $currentTime, $isWeekend);
        $this->sendBitrix24Compliments($now, $currentTime, $isWeekend);
    }

    private function sendTelegramCompliments(\DateTime $now, string $currentTime, bool $isWeekend): void
    {
        $activeSubscriptions = $this->subscriptionRepository->findAllActive();

        $successCount = 0;
        $errorCount = 0;

        foreach ($activeSubscriptions as $subscription) {
            // Skip weekend sends for users who disabled weekends
            if ($isWeekend && !$subscription->isWeekendEnabled()) {
                continue;
            }

            // Determine which time to use based on day of week
            $targetTime = $isWeekend
                ? $subscription->getWeekendTime()
                : $subscription->getWeekdayTime();

            $targetTimeStr = $targetTime->format('H:i');

            // Check if current time matches target time
            if ($currentTime !== $targetTimeStr) {
                continue;
            }

            // Send compliment
            try {
                $firstName = $subscription->getTelegramFirstName();
                $role = $subscription->getRole();
                $previousCompliments = $this->complimentHistoryRepository->findRecentTexts($subscription, $subscription->getHistoryContextSize());
                $compliment = $this->complimentGenerator->generateCompliment($firstName, $role, $previousCompliments);

                $emoji = Role::from($role)->emoji();
                $result = $this->telegramService->sendMessage(
                    $subscription->getTelegramChatId(),
                    "{$emoji} {$compliment}"
                );

                if ($result) {
                    $subscription->setLastComplimentAt($now);

                    $history = new ComplimentHistory();
                    $history->setSubscription($subscription);
                    $history->setComplimentText($compliment);
                    $this->entityManager->persist($history);

                    $successCount++;

                    $this->logger->info('Compliment sent', [
                        'platform' => 'telegram',
                        'chat_id' => $subscription->getTelegramChatId(),
                        'name' => $firstName,
                    ]);
                } else {
                    $errorCount++;
                    $this->logger->warning('Failed to send compliment', [
                        'platform' => 'telegram',
                        'chat_id' => $subscription->getTelegramChatId(),
                    ]);
                }
            } catch (\Symfony\Component\HttpClient\Exception\ClientException $e) {
                // AI API error - send error message to user
                try {
                    $response = $e->getResponse();
                    $data = $response->toArray(false);
                    $errorMsg = $data['error']['message'] ?? $data['message'] ?? json_encode($data);

                    $this->telegramService->sendMessage(
                        $subscription->getTelegramChatId(),
                        "❌ Не удалось сгенерировать сообщение:\n\n{$errorMsg}"
                    );
                } catch (\Exception $ex) {
                    $this->telegramService->sendMessage(
                        $subscription->getTelegramChatId(),
                        "❌ Ошибка API: " . $e->getMessage()
                    );
                }

                $errorCount++;
                $this->logger->error('AI API error', [
                    'platform' => 'telegram',
                    'chat_id' => $subscription->getTelegramChatId(),
                    'error' => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                $errorCount++;

                // Send error to user
                $this->telegramService->sendMessage(
                    $subscription->getTelegramChatId(),
                    "❌ Ошибка: " . $e->getMessage()
                );

                $this->logger->error('Error sending compliment', [
                    'platform' => 'telegram',
                    'chat_id' => $subscription->getTelegramChatId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($successCount > 0 || $errorCount > 0) {
            $this->entityManager->flush();

            $this->logger->info('Telegram scheduled compliments completed', [
                'platform' => 'telegram',
                'time' => $currentTime,
                'checked' => count($activeSubscriptions),
                'success' => $successCount,
                'errors' => $errorCount,
            ]);
        }
    }

    private function sendBitrix24Compliments(\DateTime $now, string $currentTime, bool $isWeekend): void
    {
        if (!$this->bitrix24Service->isConfigured()) {
            return;
        }

        $activeSubscriptions = $this->b24SubscriptionRepository->findAllActive();

        $successCount = 0;
        $errorCount = 0;

        foreach ($activeSubscriptions as $subscription) {
            if ($isWeekend && !$subscription->isWeekendEnabled()) {
                continue;
            }

            $targetTime = $isWeekend
                ? $subscription->getWeekendTime()
                : $subscription->getWeekdayTime();

            $targetTimeStr = $targetTime->format('H:i');

            if ($currentTime !== $targetTimeStr) {
                continue;
            }

            try {
                $userName = $subscription->getBitrix24UserName();
                $previousCompliments = $this->b24ComplimentHistoryRepository->findRecentTexts(
                    $subscription,
                    $subscription->getHistoryContextSize()
                );
                $compliment = $this->complimentGenerator->generateCompliment($userName, 'teammate', $previousCompliments);

                $result = $this->bitrix24Service->sendMessage(
                    $subscription->getBitrix24UserId(),
                    $compliment
                );

                if ($result) {
                    $subscription->setLastComplimentAt($now);

                    $history = new Bitrix24ComplimentHistory();
                    $history->setSubscription($subscription);
                    $history->setComplimentText($compliment);
                    $this->entityManager->persist($history);

                    $successCount++;

                    $this->logger->info('Compliment sent', [
                        'platform' => 'bitrix24',
                        'b24_user_id' => $subscription->getBitrix24UserId(),
                        'name' => $userName,
                    ]);
                } else {
                    $errorCount++;
                    $this->logger->warning('Failed to send compliment', [
                        'platform' => 'bitrix24',
                        'b24_user_id' => $subscription->getBitrix24UserId(),
                    ]);
                }

                // Rate limit: ~2 req/sec for Bitrix24
                usleep(500000);
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Error sending B24 compliment', [
                    'platform' => 'bitrix24',
                    'b24_user_id' => $subscription->getBitrix24UserId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($successCount > 0 || $errorCount > 0) {
            $this->entityManager->flush();

            $this->logger->info('Bitrix24 scheduled compliments completed', [
                'platform' => 'bitrix24',
                'time' => $currentTime,
                'checked' => count($activeSubscriptions),
                'success' => $successCount,
                'errors' => $errorCount,
            ]);
        }
    }
}
