<?php

namespace App\Repository;

use App\Entity\Paper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paper>
 */
class PaperRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paper::class);
    }

    /**
     * Papers whose code starts with, or title contains, what's been typed.
     *
     * @return Paper[] codes matching first, then official before typed-in ones
     */
    public function search(string $term, int $limit = 10): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $code = strtoupper((string) preg_replace('/\s+/', '', $term));

        return $this->createQueryBuilder('p')
            ->addSelect('CASE WHEN p.code LIKE :code THEN 0 ELSE 1 END AS HIDDEN codeFirst')
            ->andWhere('p.code LIKE :code OR LOWER(p.title) LIKE :title')
            ->setParameter('code', addcslashes($code, '%_') . '%')
            ->setParameter('title', '%' . addcslashes(mb_strtolower($term), '%_') . '%')
            ->orderBy('codeFirst', 'ASC')
            ->addOrderBy('p.official', 'DESC')
            ->addOrderBy('p.code', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
