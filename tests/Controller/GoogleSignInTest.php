<?php

namespace App\Tests\Controller;

use App\Entity\AuditEvent;
use App\Entity\User;
use App\Enum\AuditType;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Google;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Continue with Google": new accounts, linking to an existing one by email,
 * and the ways it fails. Google's token and profile endpoints are answered by
 * a Guzzle mock on the provider, so nothing leaves the test.
 */
final class GoogleSignInTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testLoginAndSignUpPagesOfferGoogle(): void
    {
        foreach (['/login', '/register'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorTextContains('.google-button', 'Continue with Google');
        }
    }

    public function testFirstSignInMakesAVerifiedAccountWithoutAPassword(): void
    {
        $this->signInWithGoogle(['sub' => '1001', 'email' => 'Jo@Gmail.com', 'email_verified' => true, 'name' => 'Jo Bloggs']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nav-user', 'Jo Bloggs');
        self::assertSelectorTextContains('.flash', 'Welcome, Jo Bloggs!');

        $user = $this->user('jo@gmail.com');
        self::assertSame('1001', $user->getGoogleId());
        self::assertTrue($user->isVerified());
        self::assertNull($user->getPassword());
        self::assertSame([AuditType::UserRegistered, AuditType::LoginSucceeded], $this->auditTypes());
        self::assertSame(['firewall' => 'main', 'via' => 'google'], $this->auditEvents()[1]->getContext());
    }

    public function testLinksTheAccountWithTheSameEmailAndKeepsAConfirmedPassword(): void
    {
        $this->createUser('sam@example.com', verified: true);

        $this->signInWithGoogle(['sub' => '2002', 'email' => 'sam@example.com', 'email_verified' => true, 'name' => 'Samantha']);

        self::assertResponseRedirects('/');
        $user = $this->user('sam@example.com');
        self::assertSame('2002', $user->getGoogleId());
        self::assertSame('Sam', $user->getDisplayName());
        self::assertNotNull($user->getPassword());
        self::assertCount(1, static::getContainer()->get(UserRepository::class)->findAll());
        self::assertSame([AuditType::GoogleLinked, AuditType::LoginSucceeded], $this->auditTypes());
    }

    public function testLinkingAnUnconfirmedAccountConfirmsItAndClearsItsPassword(): void
    {
        // Whoever set this password never proved they own the address.
        $this->createUser('sam@example.com', verified: false);

        $this->signInWithGoogle(['sub' => '3003', 'email' => 'sam@example.com', 'email_verified' => true, 'name' => 'Sam']);

        self::assertResponseRedirects('/');
        $user = $this->user('sam@example.com');
        self::assertTrue($user->isVerified());
        self::assertNull($user->getPassword());
        self::assertSame(['wasVerified' => false, 'passwordCleared' => true], $this->auditEvents()[0]->getContext());
    }

    public function testFindsALinkedAccountByGoogleIdAfterAnEmailChange(): void
    {
        $user = $this->createUser('sam@example.com')->setGoogleId('4004');
        $this->manager->flush();

        $this->signInWithGoogle(['sub' => '4004', 'email' => 'sam.old@gmail.com', 'email_verified' => true, 'name' => 'Sam']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nav-user', 'Sam');
        self::assertNull(static::getContainer()->get(UserRepository::class)->findOneByEmail('sam.old@gmail.com'));
        self::assertSame([AuditType::LoginSucceeded], $this->auditTypes());
    }

    public function testRefusesAnEmailGoogleHasNotConfirmed(): void
    {
        $this->createUser('sam@example.com');

        $this->signInWithGoogle(['sub' => '5005', 'email' => 'sam@example.com', 'email_verified' => false, 'name' => 'Sam']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.login-error', 'Google hasn’t confirmed the email');
        self::assertNull($this->user('sam@example.com')->getGoogleId());
        self::assertSame([AuditType::LoginFailed], $this->auditTypes());
    }

    public function testRejectsACallbackWithTheWrongState(): void
    {
        $this->client->request('GET', '/connect/google');
        $this->client->request('GET', '/connect/google/check', ['code' => 'abc', 'state' => 'forged']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.login-error');
        self::assertCount(0, static::getContainer()->get(UserRepository::class)->findAll());
    }

    /**
     * Starts at /connect/google, then comes back the way Google would, with
     * $profile as what Google's userinfo endpoint says about the account.
     *
     * @param array<string, mixed> $profile
     */
    private function signInWithGoogle(array $profile): void
    {
        $this->client->request('GET', '/connect/google');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        $google = static::getContainer()->get('knpu.oauth2.provider.google');
        \assert($google instanceof Google);
        $google->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($profile)),
        ]))]));

        $this->client->request('GET', '/connect/google/check', ['code' => 'abc', 'state' => $query['state']]);
    }

    private function user(string $email): User
    {
        $this->manager->clear();

        return static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }

    /** @return list<AuditEvent> oldest first */
    private function auditEvents(): array
    {
        return $this->manager->getRepository(AuditEvent::class)->findBy([], ['id' => 'ASC']);
    }

    /** @return list<AuditType> */
    private function auditTypes(): array
    {
        return array_map(static fn (AuditEvent $event) => $event->getType(), $this->auditEvents());
    }
}
