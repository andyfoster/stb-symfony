<?php

namespace App\Repository;

use App\Entity\Listing;
use App\Entity\User;
use App\Enum\ListingStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Listing>
 */
class ListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Listing::class);
    }

    /**
     * Every live listing (active, not expired), both for sale and wanted. The
     * home page filters them in the browser; see ListingFilter::matches().
     *
     * @return Listing[] most recently published or refreshed first
     */
    public function findLive(?\DateTimeImmutable $now = null): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')
            ->addSelect('b')
            ->leftJoin('b.papers', 'bp')
            ->addSelect('bp')
            ->leftJoin('l.seller', 's')
            ->addSelect('s')
            ->leftJoin('l.photos', 'p')
            ->addSelect('p')
            ->andWhere('l.status = :active')
            ->andWhere('l.expiresAt > :now')
            ->setParameter('active', ListingStatus::Active)
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->orderBy('l.publishedAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->addOrderBy('bp.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything a seller has posted, whatever its status.
     *
     * @return Listing[] newest first
     */
    public function findBySeller(User $seller): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')
            ->addSelect('b')
            ->leftJoin('l.photos', 'p')
            ->addSelect('p')
            ->andWhere('l.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Live listings expiring within $days whose seller hasn't been reminded yet.
     *
     * @return Listing[] soonest first
     */
    public function findNeedingExpiryReminder(int $days, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        return $this->createQueryBuilder('l')
            ->join('l.seller', 's')
            ->addSelect('s')
            ->andWhere('l.status = :active')
            ->andWhere('l.expiresAt > :now')
            ->andWhere('l.expiresAt <= :soon')
            ->andWhere('l.expiryReminderSentAt IS NULL')
            ->setParameter('active', ListingStatus::Active)
            ->setParameter('now', $now)
            ->setParameter('soon', $now->modify(\sprintf('+%d days', $days)))
            ->orderBy('l.expiresAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
