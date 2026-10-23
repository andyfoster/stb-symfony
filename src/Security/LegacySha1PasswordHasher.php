<?php

namespace App\Security;

use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Checks passwords from the old vanilla PHP site, which stored unsalted SHA-1
 * hex. Its login hashed the trimmed password (MySQL `SHA(trim(...))`), but its
 * reset page didn't trim, so both are tried.
 *
 * Only used through `migrate_from` in security.yaml: a match signs the person
 * in and Symfony stores a modern hash in its place (UserRepository::upgradePassword()).
 */
class LegacySha1PasswordHasher implements PasswordHasherInterface
{
    use CheckPasswordLengthTrait;

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        throw new \LogicException('Legacy SHA-1 hashes are only verified, never created.');
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if (!preg_match('/^[0-9a-f]{40}$/i', $hashedPassword) || $plainPassword === '' || $this->isPasswordTooLong($plainPassword)) {
            return false;
        }

        $hashedPassword = strtolower($hashedPassword);

        return hash_equals($hashedPassword, sha1(trim($plainPassword)))
            || hash_equals($hashedPassword, sha1($plainPassword));
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return true;
    }
}
