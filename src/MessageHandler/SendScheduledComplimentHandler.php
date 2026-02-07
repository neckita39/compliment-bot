<?php

namespace App\MessageHandler;

use App\Message\SendScheduledCompliment;
use App\Repository\SubscriptionRepository;
use App\Service\DeepSeekService;
use App\Service\TelegramService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendScheduledComplimentHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptionRepository,
        private TelegramService $telegramService,
        private DeepSeekService $deepSeekService,
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

        $activeSubscriptions = $this->subscriptionRepository->findAllActive();

        $successCount = 0;
        $errorCount = 0;

        foreach ($activeSubscriptions as $subscription) {
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
                $compliment = $this->deepSeekService->generateCompliment($firstName, $role);

                $emoji = $role === 'sister' ? '✨' : '💝';
                $result = $this->telegramService->sendMessage(
                    $subscription->getTelegramChatId(),
                    "{$emoji} {$compliment}"
                );

                if ($result) {
                    $subscription->setLastComplimentAt($now);
                    $successCount++;
                    
                    $this->logger->info('Compliment sent', [
                        'chat_id' => $subscription->getTelegramChatId(),
                        'name' => $firstName,
                    ]);
                } else {
                    $errorCount++;
                    $this->logger->warning('Failed to send compliment', [
                        'chat_id' => $subscription->getTelegramChatId(),
                    ]);
                }
            } catch (\Exception $e) {
                $errorCount++;
                $this->logger->error('Error sending compliment', [
                    'chat_id' => $subscription->getTelegramChatId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($successCount > 0 || $errorCount > 0) {
            $this->entityManager->flush();

            $this->logger->info('Scheduled compliment sending completed', [
                'time' => $currentTime,
                'checked' => count($activeSubscriptions),
                'success' => $successCount,
                'errors' => $errorCount,
            ]);
        }
    }
}
