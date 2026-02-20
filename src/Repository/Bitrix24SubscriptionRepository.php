<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bitrix24Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bitrix24Subscription>
 */
class Bitrix24SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bitrix24Subscription::class);
    }

    public function findByBitrix24UserId(int $userId): ?Bitrix24Subscription
    {
        return $this->findOneBy(['bitrix24UserId' => $userId]);
    }

    /**
     * @return Bitrix24Subscription[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true]);
    }

    public function save(Bitrix24Subscription $subscription, bool $flush = false): void
    {
        $this->getEntityManager()->persist($subscription);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Bitrix24Subscription $subscription, bool $flush = false): void
    {
        $this->getEntityManager()->remove($subscription);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
