<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Entity\User;
use App\Enum\AuditType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\GoogleUser;

/**
 * Finds or makes the account for someone who signed in with Google: the one
 * already linked to their Google account, else the one with their email (now
 * linked), else a new one. Google has confirmed the email, so it counts as verified.
 */
final class GoogleAccounts
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLog $audit,
    ) {}

    /** Callers check GoogleUser::getEmailVerified() first. Sets $created when it made a new account. */
    public function userFor(GoogleUser $google, ?bool &$created = null): User
    {
        $created  = false;
        $googleId = (string) $google->getId();

        $user = $this->users->findOneBy(['googleId' => $googleId]);
        if ($user !== null) {
            // Their Google address may have changed since they last signed in.
            if ($user->getGoogleEmail() !== mb_strtolower((string) $google->getEmail())) {
                $user->setGoogleEmail($google->getEmail());
                $this->entityManager->flush();
            }

            return $user;
        }

        $user = $this->users->findOneByEmail((string) $google->getEmail());
        if ($user === null) {
            $created = true;
            $user    = (new User())
                ->setEmail((string) $google->getEmail())
                ->setDisplayName(self::displayName($google))
                ->setGoogleId($googleId)
                ->setGoogleEmail($google->getEmail())
                ->setVerified(true);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->audit->record(AuditType::UserRegistered, $user, ['via' => 'google'], $user);

            return $user;
        }

        $context = ['wasVerified' => $user->isVerified()];
        if (!$user->isVerified()) {
            // Nobody proved they owned this address before, so whoever set the
            // password may not be its owner. The owner can set a new one.
            $context['passwordCleared'] = $user->getPassword() !== null;
            $user->setVerified(true)->setPassword(null);
        }
        $user->setGoogleId($googleId)->setGoogleEmail($google->getEmail());
        $this->entityManager->flush();
        $this->audit->record(AuditType::GoogleLinked, $user, $context, $user);

        return $user;
    }

    private static function displayName(GoogleUser $google): string
    {
        $name = trim($google->getName() ?: (string) $google->getFirstName());
        if ($name === '') {
            $name = strstr((string) $google->getEmail(), '@', true) ?: 'Student';
        }

        return mb_substr($name, 0, 80);
    }
}
