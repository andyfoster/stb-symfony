<?php

namespace App\Tests\Controller;

use App\Entity\AuditEvent;
use App\Enum\AuditType;
use App\Messaging\AdminContact;
use App\Messaging\ContactMessage;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

/**
 * The contact form on /about, which emails the site admins. Turnstile is
 * mocked in App\Tests\MockHttpResponses: every token passes except "fail".
 */
final class ContactAdminTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testAboutLinksFromHeaderAndShowsTheUpgradeNotice(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorExists('.main-nav a[href="/about"]');

        $this->client->request('GET', '/about');
        self::assertSelectorTextContains('.about-notice', 'upgraded the site’s infrastructure');
        self::assertSelectorExists('.about-notice a[href="#contact"]');
        self::assertSelectorExists('#contact');
        self::assertSelectorExists('.about-contact [data-controller="turnstile"]');
    }

    public function testGuestMessageIsEmailedToTheAdmins(): void
    {
        $this->sendMessage();

        self::assertResponseRedirects('/about');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'andy@andyfoster.net');
        self::assertEmailAddressContains($email, 'From', 'noreply@scarfietextbooks.com');
        self::assertEmailAddressContains($email, 'Reply-To', 'jo@example.com');
        self::assertEmailSubjectContains($email, 'Contact form: Jo');
        self::assertEmailHtmlBodyContains($email, 'The photos page shows an error.');

        $event = $this->manager->getRepository(AuditEvent::class)->findOneBy(['type' => AuditType::ContactSent]);
        self::assertNotNull($event);
        self::assertSame('jo@example.com', $event->getEmail());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'your message has been sent');
    }

    public function testFailedCaptchaSendsNothing(): void
    {
        $this->sendMessage(captcha: 'fail');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.about-contact', 'Please confirm you’re not a robot');
        self::assertQueuedEmailCount(0);
    }

    public function testEmailIsRequired(): void
    {
        $this->sendMessage(email: '');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.about-contact', 'Please give an email address');
        self::assertQueuedEmailCount(0);
    }

    public function testHoneypotPretendsToSendButSendsNothing(): void
    {
        $this->sendMessage(honeypot: 'https://spam.example');

        self::assertResponseRedirects('/about');
        self::assertQueuedEmailCount(0);
    }

    public function testVerifiedUsersSkipTheCaptchaAndAreFilledIn(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $crawler = $this->client->request('GET', '/about');

        self::assertSelectorNotExists('.about-contact [data-controller="turnstile"]');
        self::assertSame('sam@example.com', $crawler->filter('#contact_admin_email')->attr('value'));
        self::assertSame('Sam', $crawler->filter('#contact_admin_name')->attr('value'));
    }

    public function testRateLimit(): void
    {
        // Through the service: the test cache (and so the limiter) resets between requests.
        $contact = static::getContainer()->get(AdminContact::class);
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.9']);

        foreach (range(1, 5) as $i) {
            self::assertTrue($contact->allow($request, new ContactMessage()));
        }
        self::assertFalse($contact->allow($request, new ContactMessage()));
        self::assertTrue($contact->allow(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.10']), new ContactMessage()));
    }

    private function sendMessage(?string $captcha = 'ok', ?string $honeypot = null, string $email = 'jo@example.com'): void
    {
        $crawler = $this->client->request('GET', '/about');
        $form = $crawler->selectButton('Send message')->form();
        $values = $form->getPhpValues();
        $values['contact_admin'] = [
            'name' => 'Jo',
            'email' => $email,
            'body' => 'Hi, The photos page shows an error.',
            'website' => (string) $honeypot,
        ] + $values['contact_admin'];
        if (null !== $captcha) {
            // The Turnstile widget posts its token under the field's name.
            $values['contact_admin']['captcha'] = $captcha;
        }

        $this->client->request('POST', $form->getUri(), $values);
    }
}
