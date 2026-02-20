<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Bitrix24ComplimentHistory;
use App\Entity\Bitrix24Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Bitrix24ComplimentHistory>
 */
class Bitrix24ComplimentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Bitrix24ComplimentHistory::class);
    }

    /**
     * @return Bitrix24ComplimentHistory[]
     */
    public function findPaginated(Bitrix24Subscription $subscription, int $offset = 0, int $limit = 5): array
    {
        return $this->createQueryBuilder('ch')
            ->where('ch.subscription = :subscription')
            ->setParameter('subscription', $subscription)
            ->orderBy('ch.sentAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countBySubscription(Bitrix24Subscription $subscription): int
    {
        return (int) $this->createQueryBuilder('ch')
            ->select('COUNT(ch.id)')
            ->where('ch.subscription = :subscription')
            ->setParameter('subscription', $subscription)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return string[]
     */
    public function findRecentTexts(Bitrix24Subscription $subscription, int $limit = 50): array
    {
        return $this->createQueryBuilder('ch')
            ->select('ch.complimentText')
            ->where('ch.subscription = :subscription')
            ->setParameter('subscription', $subscription)
            ->orderBy('ch.sentAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
