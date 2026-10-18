<?php

namespace App\Tests\Controller;

use App\Entity\Listing;
use App\Scheduler\SendExpiryReminders;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Email;

/**
 * The daily "your listings expire soon" emails.
 */
final class ExpiryReminderTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testOneEmailPerSellerAboutListingsExpiringSoon(): void
    {
        $sam = $this->createUser('sam@example.com');
        $alex = $this->createUser('alex@example.com', name: 'Alex');
        // Published 40 days ago with a 42-day lifetime: 2 days left.
        $this->createListing($sam, 'Campbell Biology', '-40 days');
        $this->createListing($sam, 'Desk lamp', '-41 days');
        $this->createListing($alex, 'Calculator', '-40 days');
        $this->createListing($alex, 'Bike', '-10 days');     // not soon
        $this->createListing($alex, 'Heater', '-50 days');   // already expired
        $this->createListing($alex, 'Draft', null);          // not published

        $this->dispatch();

        self::assertQueuedEmailCount(2);
        [$first, $second] = self::getMailerMessages();
        self::assertInstanceOf(Email::class, $first);
        self::assertEmailAddressContains($first, 'To', 'sam@example.com');
        self::assertEmailSubjectContains($first, '2 of your listings expire soon');
        self::assertEmailHtmlBodyContains($first, 'Campbell Biology');
        self::assertEmailHtmlBodyContains($first, 'Desk lamp');
        self::assertEmailHtmlBodyContains($first, '/account/listings');
        self::assertEmailAddressContains($second, 'To', 'alex@example.com');
        self::assertEmailSubjectContains($second, '“Calculator” expires soon');
    }

    public function testEachListingIsRemindedOnceUntilRefreshed(): void
    {
        $sam = $this->createUser('sam@example.com');
        $listing = $this->createListing($sam, 'Campbell Biology', '-40 days');
        $this->client->loginUser($sam);

        $this->dispatch();
        self::assertQueuedEmailCount(1);

        $this->dispatch();
        self::assertQueuedEmailCount(1, message: 'No second reminder for the same listing.');

        // Refreshing starts a new lifetime, so it gets reminded again next time round.
        $this->client->request('POST', '/listings/'.$listing->getId().'/refresh', ['_csrf_token' => 'csrf-token']);
        $this->manager->clear();
        self::assertNull($this->manager->find(Listing::class, $listing->getId())->getExpiryReminderSentAt());
    }

    /** Runs the scheduled message the way the worker would. */
    private function dispatch(): void
    {
        $this->manager->clear();
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new SendExpiryReminders());
    }
}
