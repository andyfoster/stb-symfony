<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suspended accounts can't log in (UserChecker), are signed out of any
 * session they have (SuspendedUserListener) and their listings are hidden.
 * Nothing is deleted, so unsuspending puts everything back.
 */
class UserSuspender
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly AuditLog $audit,
    ) {}

    public function suspend(User $user): void
    {
        if ($user->isSuspended()) {
            return;
        }
        $user->setSuspendedAt(new \DateTimeImmutable());
        $this->manager->flush();
        $this->audit->record(AuditType::UserSuspended, $user);
    }

    public function unsuspend(User $user): void
    {
        if (!$user->isSuspended()) {
            return;
        }
        $user->setSuspendedAt(null);
        $this->manager->flush();
        $this->audit->record(AuditType::UserUnsuspended, $user);
    }
}
