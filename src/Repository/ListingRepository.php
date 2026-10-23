<?php

namespace App\Repository;

use App\Entity\Listing;
use App\Entity\User;
use App\Enum\ListingStatus;
use App\Enum\ListingType;
use App\Search\ListingFilter;
use App\Enum\Category;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Listing>
 */
class ListingRepository extends ServiceEntityRepository
{
    public const int PAGE_SIZE = 24;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Listing::class);
    }

    /** @return array<string, int> Counts for both tabs, with search and categories applied. */
    public function countMatching(ListingFilter $filter): array
    {
        $rows = $this->matchingQuery($filter)
            ->select('l.type AS type, COUNT(DISTINCT l.id) AS total')
            ->groupBy('l.type')->executeQuery()->fetchAllAssociative();
        $counts = array_fill_keys(array_column(ListingType::cases(), 'value'), 0);
        foreach ($rows as $row) {
            $counts[$row['type']] = (int) $row['total'];
        }

        return $counts;
    }

    /** @return Listing[] One page, with complete collections even when a paper matched the search. */
    public function findPage(ListingFilter $filter, int $page, bool $allTypes = false): array
    {
        // Limit IDs first: applying a SQL limit to collection fetch joins truncates listings.
        $query = $this->matchingQuery($filter);
        if (!$allTypes) {
            $query->andWhere('l.type = :type')->setParameter('type', $filter->type->value);
        }
        $rows = $query
            ->select('l.id AS id', 'l.title AS title')->distinct()
            ->orderBy('l.title', 'ASC')->addOrderBy('l.id', 'ASC')
            ->setFirstResult((max(1, $page) - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return [];
        }

        return $this->createQueryBuilder('l')
            ->leftJoin('l.book', 'b')->addSelect('b')
            ->leftJoin('b.papers', 'bp')->addSelect('bp')
            ->leftJoin('l.seller', 's')->addSelect('s')
            ->leftJoin('l.photos', 'p')->addSelect('p')
            ->andWhere('l.id IN (:ids)')->setParameter('ids', array_column($rows, 'id'))
            ->orderBy('l.title', 'ASC')->addOrderBy('l.id', 'ASC')
            ->addOrderBy('bp.code', 'ASC')->addOrderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Textbook titles people have listed before that contain what's been typed,
     * one per title and author, for the title suggestions on the listing form.
     * Drafts are private, so only published (expired too) and sold listings count. Titles starting
     * with the term come first, then the most sold.
     *
     * @return list<array{title: string, author: string, listings: int, sold: int}>
     */
    public function suggestBookTitles(string $term, int $limit = 8): array
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) < 2) {
            return [];
        }
        $escaped = strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']);

        $rows = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('MAX(l.title) AS title', 'MAX(b.author) AS author', 'COUNT(*) AS listings')
            ->addSelect("SUM(CASE WHEN l.status = :sold THEN 1 ELSE 0 END) AS sold")
            ->addSelect("MIN(CASE WHEN LOWER(l.title) LIKE :prefix ESCAPE '!' THEN 0 ELSE 1 END) AS prefix_rank")
            ->from('listing', 'l')
            ->join('l', 'book_details', 'b', 'l.book_id = b.id')
            ->andWhere('l.status IN (:statuses)')
            ->andWhere("LOWER(l.title) LIKE :search ESCAPE '!'")
            ->groupBy('LOWER(l.title)', 'LOWER(b.author)')
            ->orderBy('prefix_rank', 'ASC')->addOrderBy('sold', 'DESC')->addOrderBy('listings', 'DESC')->addOrderBy('title', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('sold', ListingStatus::Sold->value)
            ->setParameter('statuses', [ListingStatus::Active->value, ListingStatus::Sold->value], ArrayParameterType::STRING)
            ->setParameter('prefix', $escaped.'%')
            ->setParameter('search', '%'.$escaped.'%')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'title'    => (string) $row['title'],
            'author'   => (string) $row['author'],
            'listings' => (int) $row['listings'],
            'sold'     => (int) $row['sold'],
        ], $rows);
    }

    private function matchingQuery(ListingFilter $filter): QueryBuilder
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from('listing', 'l')
            ->leftJoin('l', 'book_details', 'b', 'l.book_id = b.id')
            ->leftJoin('b', 'book_details_paper', 'link', 'link.book_details_id = b.id')
            ->leftJoin('link', 'paper', 'bp', 'bp.id = link.paper_id')
            ->andWhere('l.status = :active')->andWhere('l.expires_at > :now')
            ->setParameter('active', ListingStatus::Active->value)
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE);
        if ($filter->categories !== []) {
            $query->andWhere('l.category IN (:categories)')
                ->setParameter('categories', array_map(static fn (Category $category) => $category->value, $filter->categories), ArrayParameterType::STRING);
        }
        $search = mb_strtolower(trim((string) $filter->q));
        if ($search !== '') {
            $escaped = static fn (string $value): string => '%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $squashed = (string) preg_replace('/[\s\-]+/', '', $search);
            $parts = [];
            foreach (['l.title', 'l.description', 'b.author', 'bp.code', "REPLACE(REPLACE(b.isbn, '-', ''), ' ', '')"] as $field) {
                $parts[] = "(LOWER($field) LIKE :search ESCAPE '!' OR LOWER($field) LIKE :squashed ESCAPE '!')";
            }
            $query->andWhere(implode(' OR ', $parts))
                ->setParameter('search', $escaped($search))->setParameter('squashed', $escaped($squashed));
        }

        return $query;
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
