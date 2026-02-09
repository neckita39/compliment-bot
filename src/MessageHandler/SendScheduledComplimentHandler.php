<?php

namespace App\MessageHandler;

use App\Entity\ComplimentHistory;
use App\Message\SendScheduledCompliment;
use App\Repository\ComplimentHistoryRepository;
use App\Repository\SubscriptionRepository;
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
        private TelegramService $telegramService,
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
                $previousCompliments = $this->complimentHistoryRepository->findRecentTexts($subscription, $subscription->getHistoryContextSize());
                $compliment = $this->complimentGenerator->generateCompliment($firstName, $role, $previousCompliments);

                $emoji = $role === 'sister' ? '✨' : '💝';
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
                        'chat_id' => $subscription->getTelegramChatId(),
                        'name' => $firstName,
                    ]);
                } else {
                    $errorCount++;
                    $this->logger->warning('Failed to send compliment', [
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
