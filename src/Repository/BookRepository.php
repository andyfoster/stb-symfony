<?php

namespace App\Repository;

use App\Entity\Book;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Book>
 */
class BookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Book::class);
    }

    /**
     * Books whose title contains what's been typed, starting with it first, then the most sold.
     *
     * @return list<array{title: string, author: string, listings: int, sold: int}>
     */
    public function suggestTitles(string $term, int $limit = 8): array
    {
        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) < 2) {
            return [];
        }
        $escaped = strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']);

        $books = $this->createQueryBuilder('b')
            ->addSelect("CASE WHEN LOWER(b.title) LIKE :prefix ESCAPE '!' THEN 0 ELSE 1 END AS HIDDEN prefix_rank")
            ->andWhere("LOWER(b.title) LIKE :search ESCAPE '!'")
            ->orderBy('prefix_rank', 'ASC')->addOrderBy('b.timesSold', 'DESC')->addOrderBy('b.timesListed', 'DESC')->addOrderBy('b.title', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('prefix', $escaped . '%')
            ->setParameter('search', '%' . $escaped . '%')
            ->getQuery()->getResult();

        return array_map(static fn (Book $book): array => [
            'title'    => (string) $book->getTitle(),
            'author'   => $book->getAuthor(),
            'listings' => $book->getTimesListed(),
            'sold'     => $book->getTimesSold(),
        ], $books);
    }
}
