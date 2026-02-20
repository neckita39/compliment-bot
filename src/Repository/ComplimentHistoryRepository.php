<?php

namespace App\Repository;

use App\Entity\ComplimentHistory;
use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplimentHistory>
 */
class ComplimentHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplimentHistory::class);
    }

    /**
     * @return ComplimentHistory[]
     */
    public function findPaginated(Subscription $subscription, int $offset = 0, int $limit = 5): array
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

    public function countBySubscription(Subscription $subscription): int
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
    public function findRecentTexts(Subscription $subscription, int $limit = 50): array
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
