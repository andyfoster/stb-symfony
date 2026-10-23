<?php

namespace App\Repository;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry, private readonly AuditLog $audit)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Emails are stored lower-case, so "Sam@Example.com" signs in as "sam@example.com".
     */
    public function loadUserByIdentifier(string $identifier): ?User
    {
        return $this->findOneByEmail($identifier);
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * Rehashes the password on login when the hasher settings change, or when an
     * imported account still has an old-format hash.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        // Old-site hashes are 40 hex characters (LegacySha1PasswordHasher).
        $legacy = preg_match('/^[0-9a-f]{40}$/i', (string) $user->getPassword()) === 1;

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
        $this->audit->record(AuditType::PasswordUpgraded, $user, ['from' => $legacy ? 'legacy_sha1' : 'rehash'], $user);
    }
}
