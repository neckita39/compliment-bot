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
