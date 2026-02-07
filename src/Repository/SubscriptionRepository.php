<?php

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function findByTelegramChatId(string $chatId): ?Subscription
    {
        return $this->findOneBy(['telegramChatId' => $chatId]);
    }

    public function findOneByChatId(string $chatId): ?Subscription
    {
        return $this->findByTelegramChatId($chatId);
    }

    /**
     * @return Subscription[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }

    public function save(Subscription $subscription, bool $flush = false): void
    {
        $this->getEntityManager()->persist($subscription);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Subscription $subscription, bool $flush = false): void
    {
        $this->getEntityManager()->remove($subscription);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
