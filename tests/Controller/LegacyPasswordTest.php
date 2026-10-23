<?php

namespace App\Tests\Controller;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Enum\AuditType;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Accounts imported from the old site keep their password, and get a modern
 * hash the first time they log in.
 */
final class LegacyPasswordTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testOldSha1PasswordLogsInAndIsRehashed(): void
    {
        $this->createLegacyUser('jo@example.com', sha1('hunter2'));

        $this->logIn('Jo@Example.com', 'hunter2');

        self::assertResponseRedirects('/');
        $hash = $this->user('jo@example.com')->getPassword();
        self::assertStringStartsWith('$', $hash);
        self::assertTrue(password_verify('hunter2', $hash));
        $upgrade = $this->manager->getRepository(AuditEvent::class)->findOneBy(['type' => AuditType::PasswordUpgraded]);
        self::assertSame(['from' => 'legacy_sha1'], $upgrade?->getContext());

        // The new hash works from then on.
        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token']);
        $this->client->getCookieJar()->clear();
        $this->logIn('jo@example.com', 'hunter2');
        self::assertResponseRedirects('/');
    }

    public function testOldSiteTrimmedPasswordsAtLogin(): void
    {
        // The old login hashed trim($password), so " hunter2 " was stored as sha1("hunter2").
        $this->createLegacyUser('jo@example.com', strtoupper(sha1('hunter2')));

        $this->logIn('jo@example.com', ' hunter2 ');

        self::assertResponseRedirects('/');
    }

    public function testWrongPasswordIsRejectedAndHashKept(): void
    {
        $this->createLegacyUser('jo@example.com', sha1('hunter2'));

        $this->logIn('jo@example.com', 'hunter3');

        self::assertResponseRedirects('/login');
        self::assertSame(sha1('hunter2'), $this->user('jo@example.com')->getPassword());
    }

    private function createLegacyUser(string $email, string $sha1): void
    {
        $user = (new User())->setEmail($email)->setDisplayName('Jo')->setPassword($sha1);
        $this->manager->persist($user);
        $this->manager->flush();
    }

    private function logIn(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }

    private function user(string $email): User
    {
        $this->manager->clear();

        return static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }
}
