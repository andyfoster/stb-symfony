<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Sign up, email confirmation, log in/out and password reset.
 */
final class AccountTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        // An isolated in-memory database: never touch the application's users.
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        $this->client = static::createClient();
        $this->client->disableReboot();
        // Stateless CSRF accepts same-origin requests; browsers send this header.
        $this->client->setServerParameter('HTTP_SEC_FETCH_SITE', 'same-origin');
        $this->manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($this->manager))->createSchema($this->manager->getMetadataFactory()->getAllMetadata());
    }

    public function testSignUpLogsInAndConfirmationLinkVerifiesEmail(): void
    {
        $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Sign up', [
            'registration_form[displayName]' => 'Sam',
            'registration_form[email]' => 'Sam@Example.com',
            'registration_form[plainPassword]' => 'correct horse',
        ]);

        self::assertResponseRedirects('/');
        self::assertQueuedEmailCount(1);
        $email = $this->lastEmail();
        self::assertEmailAddressContains($email, 'To', 'sam@example.com');
        $confirmUrl = $this->linkIn($email);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.nav-user', 'Sam');
        self::assertSelectorTextContains('.flash', 'Welcome, Sam!');
        self::assertSelectorTextContains('.flash-notice', 'Please confirm your email address');

        $user = $this->user('sam@example.com');
        self::assertFalse($user->isVerified());
        self::assertNotSame('correct horse', $user->getPassword());

        $this->client->request('GET', $confirmUrl);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'your email is confirmed');
        self::assertSelectorNotExists('.flash-notice');
        self::assertTrue($this->user('sam@example.com')->isVerified());
    }

    public function testSignUpRejectsDuplicateEmailAndShortPassword(): void
    {
        $this->createUser('sam@example.com', 'correct horse');

        $this->client->request('GET', '/register');
        $this->client->submitForm('Sign up', [
            'registration_form[displayName]' => 'Sam again',
            'registration_form[email]' => 'SAM@example.com',
            'registration_form[plainPassword]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.form-field', 'There is already an account with this email.');
        self::assertAnySelectorTextContains('.form-field', 'Use at least 8 characters.');
        self::assertQueuedEmailCount(0);
    }

    public function testConfirmationLinkMustBeSigned(): void
    {
        $user = $this->createUser('sam@example.com', 'correct horse');

        $this->client->request('GET', '/verify/email', ['id' => $user->getId(), 'signature' => 'forged', 'expires' => time() + 3600, 'token' => 'x']);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
        self::assertFalse($this->user('sam@example.com')->isVerified());
    }

    public function testLogInWithAnyCaseEmailThenLogOut(): void
    {
        $this->createUser('sam@example.com', 'correct horse');

        $this->logIn('SAM@example.com', 'correct horse');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nav-user', 'Sam');

        $this->client->submitForm('Log out');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorNotExists('.nav-user');
        self::assertSelectorTextContains('.site-nav', 'Log in');
    }

    public function testLogInWithWrongPasswordShowsError(): void
    {
        $this->createUser('sam@example.com', 'correct horse');

        $this->logIn('sam@example.com', 'wrong horse');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.login-error', 'Invalid credentials.');
        self::assertInputValueSame('email', 'sam@example.com');
        self::assertSelectorNotExists('.nav-user');
    }

    public function testRememberMeSetsCookie(): void
    {
        $this->createUser('sam@example.com', 'correct horse');

        $this->logIn('sam@example.com', 'correct horse', rememberMe: true);

        self::assertResponseRedirects('/');
        self::assertResponseHasCookie('REMEMBERME');
    }

    public function testResetPasswordByEmailLink(): void
    {
        $this->createUser('sam@example.com', 'old password');

        $this->client->request('GET', '/login');
        $this->client->clickLink('Forgot your password?');
        $this->client->submitForm('Send reset link', ['reset_password_request_form[email]' => 'Sam@example.com']);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(1);
        $resetUrl = $this->linkIn($this->lastEmail());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Check your email');

        // The token moves from the URL into the session.
        $this->client->request('GET', $resetUrl);
        self::assertResponseRedirects('/reset-password/reset');
        $this->client->followRedirect();
        $this->client->submitForm('Change password', [
            'change_password_form[plainPassword][first]' => 'new password',
            'change_password_form[plainPassword][second]' => 'new password',
        ]);
        self::assertResponseRedirects('/login');

        $this->logIn('sam@example.com', 'old password');
        self::assertResponseRedirects('/login');

        $this->logIn('sam@example.com', 'new password');
        self::assertResponseRedirects('/');
        self::assertTrue($this->user('sam@example.com')->isVerified());

        // Each link works once.
        $this->client->request('GET', $resetUrl);
        $this->client->followRedirect();
        self::assertResponseRedirects('/reset-password');
    }

    public function testResetPasswordForUnknownEmailLooksTheSame(): void
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Send reset link', ['reset_password_request_form[email]' => 'nobody@example.com']);

        self::assertResponseRedirects('/reset-password/check-email');
        self::assertQueuedEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Check your email');
    }

    private function logIn(string $email, string $password, bool $rememberMe = false): void
    {
        $this->client->request('GET', '/login');
        $form = $this->client->getCrawler()->selectButton('Log in')->form(['email' => $email, 'password' => $password]);
        if ($rememberMe) {
            $form['_remember_me']->tick();
        }
        $this->client->submit($form);
    }

    private function createUser(string $email, string $password): User
    {
        $user = (new User())->setEmail($email)->setDisplayName('Sam');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->manager->persist($user);
        $this->manager->flush();

        return $user;
    }

    private function user(string $email): User
    {
        $this->manager->clear();

        return static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
    }

    private function lastEmail(): Email
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function linkIn(Email $email): string
    {
        return (new Crawler((string) $email->getHtmlBody()))->filter('a')->attr('href');
    }
}
