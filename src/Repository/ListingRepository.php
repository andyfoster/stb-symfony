<?php

namespace App\Repository;

use App\Entity\Listing;
use App\Entity\User;
use App\Enum\ListingStatus;
use App\Search\ListingFilter;
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
     * Live listings (active, not expired) matching the filter.
     *
     * @return Listing[] most recently published or refreshed first
     */
    public function findByFilter(ListingFilter $filter, ?\DateTimeImmutable $now = null): array
    {
        $qb = $this->createQueryBuilder('l')
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
            ->addOrderBy('bp.code', 'ASC');

        $q = trim((string) $filter->q);
        if ('' !== $q) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(l.title) LIKE :q',
                'LOWER(l.description) LIKE :q',
                'LOWER(b.author) LIKE :q',
                'b.isbn LIKE :isbn',
                // "chem 191" finds CHEM191, without narrowing the papers fetched for display.
                'EXISTS (SELECT qp.id FROM App\Entity\Paper qp WHERE qp MEMBER OF b.papers AND qp.code LIKE :code)',
            ))
                ->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_').'%')
                ->setParameter('code', '%'.addcslashes(strtoupper((string) preg_replace('/\s+/', '', $q)), '%_').'%')
                ->setParameter('isbn', '%'.addcslashes(str_replace(['-', ' '], '', $q), '%_').'%');
        }

        if (null !== $filter->type) {
            $qb->andWhere('l.type = :type')->setParameter('type', $filter->type);
        }

        if (null !== $filter->category) {
            $qb->andWhere('l.category = :category')->setParameter('category', $filter->category);
        }

        if (null !== $filter->maxPrice) {
            $qb->andWhere('l.priceCents <= :maxPrice')->setParameter('maxPrice', $filter->maxPrice * 100);
        }

        return $qb->getQuery()->getResult();
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
