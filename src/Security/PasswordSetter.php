<?php

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sets an account's password for someone who can't use the reset email,
 * from the console (app:user:reset-password) or the admin panel. Changing
 * the password also signs out their remembered devices.
 */
class PasswordSetter
{
    /** What admins reset to; tell the person to change it straight away. */
    public const string DEFAULT_PASSWORD = 'Password1!';

    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $manager,
    ) {
    }

    public function set(User $user, string $password = self::DEFAULT_PASSWORD): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->manager->flush();
    }
}
