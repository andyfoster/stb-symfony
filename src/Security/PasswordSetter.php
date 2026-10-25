<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets an account's password for someone who can't use the reset email,
 * from the console (app:user:reset-password) or the admin panel, and changes
 * it for people from their Account page. Changing the password also signs out
 * their remembered devices and other sessions.
 */
class PasswordSetter
{
    /** What admins reset to; tell the person to change it straight away. */
    public const string DEFAULT_PASSWORD = 'Password1!';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $manager,
        private readonly AuditLog $audit,
    ) {
    }

    public function set(User $user, string $password = self::DEFAULT_PASSWORD): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->manager->flush();
        $this->audit->record(AuditType::PasswordSet, $user, ['default' => $password === self::DEFAULT_PASSWORD]);
    }

    /** Hashed up front, so a change waiting on Google (GoogleController) never keeps the password itself. */
    public function hash(User $user, string $password): string
    {
        return $this->hasher->hashPassword($user, $password);
    }

    /** Someone changing (or, after signing in with Google, setting) their own password. */
    public function change(User $user, string $hashedPassword): void
    {
        $hadPassword = $user->getPassword() !== null;
        $user->setPassword($hashedPassword);
        $this->manager->flush();
        $this->audit->record(AuditType::PasswordChanged, $user, ['hadPassword' => $hadPassword], $user);
    }
}
