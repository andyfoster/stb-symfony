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
 * the ways it fails, and confirming an email change with Google instead of a
 * password. Google's token and profile endpoints are answered by
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

    public function testPasswordlessAccountConfirmsAnEmailChangeWithGoogle(): void
    {
        $user = $this->googleOnlyUser('sam@gmail.com', '6006');
        $this->createUser('jo@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/details');
        self::assertSelectorNotExists('[name="change_email_form[currentPassword]"]');
        $this->client->submitForm('Confirm with Google', ['change_email_form[email]' => 'jo@example.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.form-field', 'There is already an account with this email.');

        $this->client->submitForm('Confirm with Google', ['change_email_form[email]' => 'Sam.New@example.com']);
        self::assertResponseRedirects('/account/email/google');
        $this->client->followRedirect();
        $query = $this->googleQuery();
        self::assertSame('0', $query['max_age']);
        self::assertStringEndsWith('/account/email/google/check', $query['redirect_uri']);
        self::assertSame('sam@gmail.com', $this->user('sam@gmail.com')->getEmail());

        $this->comeBackFromGoogle('google_confirm', '/account/email/google/check', $query['state'], ['sub' => '6006', 'email' => 'sam@gmail.com', 'email_verified' => true]);

        self::assertResponseRedirects('/account/details');
        self::assertQueuedEmailCount(2);
        self::assertEmailAddressContains(self::getMailerMessage(0), 'To', 'sam.new@example.com');
        self::assertEmailAddressContains(self::getMailerMessage(1), 'To', 'sam@gmail.com');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Your email is now sam.new@example.com.');
        self::assertSame('6006', $this->user('sam.new@example.com')->getGoogleId());
    }

    public function testAnotherGoogleAccountCannotConfirmTheChange(): void
    {
        $this->client->loginUser($this->googleOnlyUser('sam@gmail.com', '7007'));

        $this->client->request('GET', '/account/details');
        $this->client->submitForm('Confirm with Google', ['change_email_form[email]' => 'sam.new@example.com']);
        $this->client->followRedirect();
        $this->comeBackFromGoogle('google_confirm', '/account/email/google/check', $this->googleQuery()['state'], ['sub' => '9999', 'email' => 'someone@gmail.com', 'email_verified' => true]);

        self::assertResponseRedirects('/account/details');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'That isn’t the Google account you sign in with');
        self::assertSame('sam@gmail.com', $this->user('sam@gmail.com')->getEmail());
        self::assertQueuedEmailCount(0);

        // The pending address is gone, so coming back again changes nothing.
        $this->client->request('GET', '/account/email/google/check', ['code' => 'abc', 'state' => 'x']);
        self::assertResponseRedirects('/account/details');
        self::assertSame('sam@gmail.com', $this->user('sam@gmail.com')->getEmail());
    }

    public function testAccountsWithAPasswordStillConfirmWithIt(): void
    {
        $user = $this->createUser('sam@example.com')->setGoogleId('8008');
        $this->manager->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/account/details');
        self::assertSelectorExists('[name="change_email_form[currentPassword]"]');
        self::assertSelectorTextContains('.dashboard-section button', 'Change email');
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
        $this->comeBackFromGoogle('google', '/connect/google/check', $this->googleQuery()['state'], $profile);
    }

    /** The query string of the redirect to Google's sign-in page that was just sent. */
    private function googleQuery(): array
    {
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);

        return $query;
    }

    /** @param array<string, mixed> $profile */
    private function comeBackFromGoogle(string $client, string $callback, string $state, array $profile): void
    {
        $google = static::getContainer()->get('knpu.oauth2.provider.' . $client);
        \assert($google instanceof Google);
        $google->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode($profile)),
        ]))]));

        $this->client->request('GET', $callback, ['code' => 'abc', 'state' => $state]);
    }

    private function googleOnlyUser(string $email, string $googleId): User
    {
        $user = $this->createUser($email)->setGoogleId($googleId)->setPassword(null);
        $this->manager->flush();

        return $user;
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
