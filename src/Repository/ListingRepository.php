<?php

namespace App\Repository;

use App\Entity\Listing;
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
     * @return Listing[] newest first
     */
    public function findByFilter(ListingFilter $filter): array
    {
        $qb = $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')
            ->addSelect('b')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC');

        $q = trim((string) $filter->q);
        if ('' !== $q) {
            $qb->andWhere($qb->expr()->orX(
                'LOWER(l.title) LIKE :q',
                'LOWER(l.description) LIKE :q',
                'LOWER(b.author) LIKE :q',
                'LOWER(b.courseCode) LIKE :q',
                'b.isbn LIKE :isbn',
            ))
                ->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_').'%')
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
}
