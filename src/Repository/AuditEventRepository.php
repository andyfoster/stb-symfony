<?php

namespace App\Repository;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditEvent>
 */
class AuditEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditEvent::class);
    }

    /**
     * Newest first: what the account did (or was tried with), and what admins did to it.
     *
     * @return list<AuditEvent>
     */
    public function findRecentFor(User $user, int $limit = 25): array
    {
        return $this->createQueryBuilder('event')
            ->where('event.actor = :user')
            ->orWhere('event.subjectType = :type AND event.subjectId = :id')
            ->setParameter('user', $user)
            ->setParameter('type', 'User')
            ->setParameter('id', $user->getId())
            ->orderBy('event.createdAt', 'DESC')
            ->addOrderBy('event.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** The IP address $user signed up from, if it was recorded. */
    public function findSignUpIp(User $user): ?string
    {
        $event = $this->createQueryBuilder('event')
            ->where('event.type = :type')
            ->andWhere('event.subjectType = :subject AND event.subjectId = :id')
            ->setParameter('type', AuditType::UserRegistered)
            ->setParameter('subject', 'User')
            ->setParameter('id', $user->getId())
            ->orderBy('event.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $event?->getIp();
    }

    /** Deletes events older than $before; returns how many. */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('event')
            ->delete()
            ->where('event.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
