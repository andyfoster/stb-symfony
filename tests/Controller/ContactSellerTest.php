<?php

namespace App\Tests\Controller;

use App\Entity\Listing;
use App\Entity\Message;
use App\Entity\User;
use App\Messaging\SellerMessenger;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;

/**
 * Messaging a seller from a listing, and the seller's inbox. Turnstile is
 * mocked in App\Tests\MockHttpResponses: every token passes except "fail".
 */
final class ContactSellerTest extends WebTestCase
{
    use DatabaseTestTrait;

    private User $seller;
    private Listing $listing;

    protected function setUp(): void
    {
        $this->bootClient();
        $this->seller = $this->createUser('sam@example.com');
        $this->listing = $this->createListing($this->seller);
    }

    public function testGuestMessageIsEmailedAndShownInInbox(): void
    {
        $this->sendMessage();

        self::assertResponseRedirects('/listings/'.$this->listing->getId());
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'sam@example.com');
        self::assertEmailAddressContains($email, 'From', 'noreply@scarfietextbooks.com');
        self::assertEmailAddressContains($email, 'Reply-To', 'jo@example.com');
        self::assertEmailSubjectContains($email, 'Jo asked about “Chemistry: The Central Science”');
        self::assertEmailHtmlBodyContains($email, 'aria-label="ScarfieTextbooks"');
        self::assertEmailHtmlBodyContains($email, '/remove#sold');
        self::assertEmailHtmlBodyContains($email, '/remove#archive');
        self::assertEmailHtmlBodyContains($email, 'Is this still available?');

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Message sent to Sam.');

        $this->client->loginUser($this->seller);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.nav-user .nav-badge', '1');

        $crawler = $this->client->request('GET', '/account/messages');
        self::assertSelectorTextContains('.message-unread .message-from', 'Jo');
        self::assertSelectorTextContains('.message-about', 'Chemistry: The Central Science');

        $this->client->click($crawler->filter('.message-row a')->link());
        self::assertSelectorTextContains('.message-body', 'Is this still available?');
        self::assertSelectorExists('a[href^="mailto:jo@example.com"]');
        self::assertSelectorNotExists('.nav-user .nav-badge');
    }

    public function testFailedCaptchaSendsNothing(): void
    {
        $this->sendMessage(captcha: 'fail');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.contact-panel', 'Please confirm you’re not a robot');
        // The form is in a popup, which opens again so the error can be fixed.
        self::assertSelectorExists('[data-controller="modal"][data-modal-open-value="true"]');
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $this->manager->getRepository(Message::class)->findAll());
    }

    public function testFormStartsClosed(): void
    {
        $this->client->request('GET', '/listings/'.$this->listing->getId());

        self::assertSelectorTextContains('.contact-open', 'Message Sam');
        self::assertSelectorExists('[data-controller="modal"][data-modal-open-value="false"]');
    }

    public function testMissingCaptchaSendsNothing(): void
    {
        $this->sendMessage(captcha: null);

        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
    }

    public function testHoneypotLooksSentButSendsNothing(): void
    {
        $this->sendMessage(honeypot: 'http://spam.example');

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $this->manager->getRepository(Message::class)->findAll());
    }

    public function testRateLimits(): void
    {
        // Through the service: the test cache (and so the limiter) resets between requests.
        $messenger = static::getContainer()->get(SellerMessenger::class);
        $message = static fn (string $email): Message => (new Message())->setSenderEmail($email);
        $request = Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.9']);

        // Three messages per sender per listing a day...
        foreach (range(1, 3) as $i) {
            self::assertTrue($messenger->allow($request, $this->listing, $message('jo@example.com')));
        }
        self::assertFalse($messenger->allow($request, $this->listing, $message('jo@example.com')));

        // ...and five per IP address an hour.
        self::assertTrue($messenger->allow($request, $this->listing, $message('kim@example.com')));
        self::assertFalse($messenger->allow($request, $this->listing, $message('lee@example.com')));
        self::assertTrue($messenger->allow(Request::create('/', server: ['REMOTE_ADDR' => '203.0.113.10']), $this->listing, $message('lee@example.com')));
    }

    public function testVerifiedUsersSkipTheCaptcha(): void
    {
        $this->client->loginUser($this->createUser('jo@example.com', name: 'Jo'));

        $crawler = $this->client->request('GET', '/listings/'.$this->listing->getId());
        self::assertSelectorNotExists('.turnstile');
        $this->client->submit($crawler->selectButton('Send message')->form(['contact_seller[body]' => 'Can I pick it up tomorrow?']));

        self::assertResponseRedirects();
        self::assertQueuedEmailCount(1);
        $message = $this->manager->getRepository(Message::class)->findAll()[0];
        self::assertSame('jo@example.com', $message->getSender()?->getEmail());
        self::assertSame('Jo', $message->getSenderName());
    }

    public function testGuestsCanLeaveAPhoneNumberInsteadOfAnEmail(): void
    {
        $this->sendMessage(email: '', phone: '021 123 4567');

        self::assertResponseRedirects();
        $message = $this->manager->getRepository(Message::class)->findAll()[0];
        self::assertNull($message->getSenderEmail());
        self::assertSame('021 123 4567', $message->getSenderPhone());

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([], $email->getReplyTo());
        self::assertEmailHtmlBodyContains($email, 'href="tel:0211234567"');

        $this->client->loginUser($this->seller);
        $this->client->request('GET', '/account/messages/'.$message->getId());
        self::assertSelectorExists('a[href="sms:0211234567"]');
        self::assertSelectorNotExists('a[href^="mailto:"]');
    }

    public function testGuestsMustGiveAnEmailOrAPhone(): void
    {
        $this->sendMessage(email: '', phone: '');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.contact-panel', 'Add an email address or a phone number so the seller can reply.');
        self::assertQueuedEmailCount(0);
    }

    public function testLoggedInSendersGetTheirPhoneFilledIn(): void
    {
        $sender = $this->createUser('jo@example.com', name: 'Jo')->setPhone('021 765 4321');
        $this->manager->flush();
        $this->client->loginUser($sender);

        $crawler = $this->client->request('GET', '/listings/'.$this->listing->getId());
        self::assertInputValueSame('contact_seller[senderPhone]', '021 765 4321');
        $this->client->submit($crawler->selectButton('Send message')->form(['contact_seller[body]' => 'Can I pick it up tomorrow?']));

        self::assertResponseRedirects();
        $message = $this->manager->getRepository(Message::class)->findAll()[0];
        self::assertSame('jo@example.com', $message->getSenderEmail());
        self::assertSame('021 765 4321', $message->getSenderPhone());
    }

    public function testSellersCantMessageThemselves(): void
    {
        $this->client->loginUser($this->seller);

        $this->client->request('GET', '/listings/'.$this->listing->getId());
        self::assertSelectorNotExists('.contact-form');

        $this->client->request('POST', '/listings/'.$this->listing->getId().'/contact');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPhoneNumberOnlyWhenSellerChoosesToShowIt(): void
    {
        $this->seller->setPhone('021 555 0123');
        $this->manager->flush();

        $this->client->request('GET', '/listings/'.$this->listing->getId());
        self::assertSelectorNotExists('.phone-reveal');
        self::assertSelectorTextNotContains('body', '021 555 0123');

        $this->seller->setShowPhone(true);
        $this->manager->flush();

        $this->client->request('GET', '/listings/'.$this->listing->getId());
        self::assertSelectorTextContains('.phone-reveal button', 'Show phone number');
        self::assertSelectorExists('.phone-number[href="tel:0215550123"]');
    }

    public function testSellerControlsPhoneFromAccountPage(): void
    {
        $this->client->loginUser($this->seller);

        $this->client->request('GET', '/account');
        $this->client->submitForm('Save', [
            'profile_form[phone]' => '021 555 0123',
            'profile_form[showPhone]' => true,
        ]);
        self::assertResponseRedirects('/account');

        $this->manager->clear();
        $seller = $this->manager->find(User::class, $this->seller->getId());
        self::assertSame('021 555 0123', $seller->getPublicPhone());

        $this->client->request('GET', '/account');
        $this->client->submitForm('Save', ['profile_form[phone]' => 'call me maybe']);
        self::assertResponseStatusCodeSame(422);
    }

    private function sendMessage(?string $captcha = 'ok', ?string $honeypot = null, string $email = 'jo@example.com', string $phone = ''): void
    {
        $crawler = $this->client->request('GET', '/listings/'.$this->listing->getId());
        $form = $crawler->selectButton('Send message')->form();
        $values = $form->getPhpValues();
        $values['contact_seller'] = [
            'senderName' => 'Jo',
            'senderEmail' => $email,
            'senderPhone' => $phone,
            'body' => 'Hi! Is this still available? I can pick it up this week.',
            'website' => (string) $honeypot,
        ] + $values['contact_seller'];
        if (null !== $captcha) {
            // The Turnstile widget posts its token under the field's name.
            $values['contact_seller']['captcha'] = $captcha;
        }

        $this->client->request('POST', $form->getUri(), $values);
    }
}
