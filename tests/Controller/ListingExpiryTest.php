<?php

namespace App\Tests\Controller;

use App\Entity\Listing;
use App\Settings\SiteSettingsProvider;
use App\Enum\Feature;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Listings expire after the admin-set lifetime; sellers refresh unsold ones.
 */
final class ListingExpiryTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
        // Posting through the crawler can't fill in Turnstile; FeatureFlagTest covers it.
        $this->setFeature(Feature::ListingCaptcha, false);
    }

    public function testNovemberListingIsStillAvailableForNextJuly(): void
    {
        $settings = static::getContainer()->get(SiteSettingsProvider::class);
        // A legacy setting above the limit must not create a listing longer than a year.
        $settings->get()->setListingLifetimeDays(730);
        $this->manager->flush();
        self::assertSame(365, $settings->listingLifetimeDays());

        $seller = $this->createUser('sam@example.com');
        $listing = $this->createListing($seller, 'Book for next July', published: null)
            ->publish($settings->listingLifetimeDays(), new \DateTimeImmutable('2026-11-15 12:00:00 UTC'));
        $this->manager->flush();
        self::assertTrue($listing->isLive(new \DateTimeImmutable('2027-07-01 00:00:00 UTC')));
        self::assertFalse($listing->canRefresh(new \DateTimeImmutable('2027-07-01 00:00:00 UTC')));
        self::assertSame('2027-11-15', $listing->getExpiresAt()->format('Y-m-d'));
        $reminders = static::getContainer()->get(\App\Repository\ListingRepository::class)
            ->findNeedingExpiryReminder(3, new \DateTimeImmutable('2027-07-01 00:00:00 UTC'));
        self::assertSame([], $reminders, 'No renewal nagging before the July course.');
    }

    public function testYearLongMigrationExtendsActiveListingsWithoutShorteningOthers(): void
    {
        require_once dirname(__DIR__, 2).'/migrations/Version20261024042123.php';
        $seller = $this->createUser('sam@example.com');
        $posted = new \DateTimeImmutable('2026-11-01 00:00:00 UTC');
        $short = $this->createListing($seller, 'Short')->publish(42, $posted)
            ->setExpiryReminderSentAt($posted);
        $long = $this->createListing($seller, 'Long')->publish(730, $posted);
        $sold = $this->createListing($seller, 'Sold')->publish(42, $posted)->markSold();
        $draft = $this->createListing($seller, 'Draft')->publish(42, $posted)
            ->setStatus(\App\Enum\ListingStatus::Draft);
        static::getContainer()->get(SiteSettingsProvider::class)->get()->setListingLifetimeDays(42);
        $this->manager->flush();
        $ids = array_map(static fn (Listing $listing) => $listing->getId(), [$short, $long, $sold, $draft]);

        $migration = new \DoctrineMigrations\Version20261024042123($this->manager->getConnection(), new \Psr\Log\NullLogger());
        $migration->up(new \Doctrine\DBAL\Schema\Schema());
        // Run the generated statements against the isolated test database, twice for idempotency.
        for ($run = 0; $run < 2; ++$run) {
            foreach ($migration->getSql() as $query) {
                $this->manager->getConnection()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }
        $this->manager->clear();
        [$short, $long, $sold, $draft] = array_map(fn ($id) => $this->manager->find(Listing::class, $id), $ids);
        self::assertSame('2027-11-01', $short->getExpiresAt()->format('Y-m-d'));
        self::assertNull($short->getExpiryReminderSentAt());
        self::assertSame('2028-10-31', $long->getExpiresAt()->format('Y-m-d'));
        self::assertSame('2026-12-13', $sold->getExpiresAt()->format('Y-m-d'));
        self::assertTrue($sold->isSold());
        self::assertSame('2026-12-13', $draft->getExpiresAt()->format('Y-m-d'));
        self::assertTrue($draft->isDraft());
        self::assertSame(365, $this->manager->getRepository(\App\Entity\SiteSettings::class)->findAll()[0]->getListingLifetimeDays());
    }

    public function testSellersAndWantersCanChooseTheirHideDate(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $date = static::getContainer()->get(\App\Listing\ListingExpiry::class)->today()->modify('+2 months');
        foreach (['for_sale', 'wanted'] as $type) {
            $this->client->request('GET', '/sell?type='.$type);
            $this->client->submitForm('wanted' === $type ? 'Post wanted listing' : 'Publish listing', [
                'listing_form[title]' => 'Desk '.$type,
                'listing_form[category]' => 'flat_and_home',
                'listing_form[condition]' => 'good',
                'listing_form[hideAfter]' => $date->format('Y-m-d'),
            ]);
            self::assertResponseRedirects();
            $listing = $this->manager->getRepository(Listing::class)->findOneBy(['title' => 'Desk '.$type]);
            $expiry = $date->modify('+1 day');
            self::assertEquals($expiry, $listing->getExpiresAt());
            self::assertTrue($listing->isLive($expiry->modify('-1 microsecond')));
            self::assertFalse($listing->isLive($expiry));
        }
    }

    public function testHideDateRejectsPastDatesAndDatesBeyondAYear(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $policy = static::getContainer()->get(\App\Listing\ListingExpiry::class);
        foreach ([$policy->today()->modify('-1 day'), $policy->latestDate()->modify('+1 day')] as $date) {
            $this->client->request('GET', '/sell');
            $this->client->submitForm('Publish listing', [
                'listing_form[title]' => 'Desk',
                'listing_form[category]' => 'flat_and_home',
                'listing_form[condition]' => 'good',
                'listing_form[hideAfter]' => $date->format('Y-m-d'),
            ]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('body', 'Choose');
            self::assertSame(0, $this->manager->getRepository(Listing::class)->count([]));
        }
    }

    public function testOwnerCanExtendDateAtAnyTimeWithoutBumpingTheListing(): void
    {
        $seller = $this->createUser('sam@example.com');
        $listing = $this->createListing($seller, 'Desk', '-1 day');
        $published = $listing->getPublishedAt();
        $listing->setExpiryReminderSentAt(new \DateTimeImmutable());
        $this->manager->flush();
        $this->client->loginUser($seller);
        $date = static::getContainer()->get(\App\Listing\ListingExpiry::class)->latestDate();
        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        self::assertSelectorExists('input[type="date"][name="listing_form[hideAfter]"][max="'.$date->format('Y-m-d').'"]');
        $this->client->submitForm('Save changes', ['listing_form[hideAfter]' => $date->format('Y-m-d')]);
        self::assertResponseRedirects();
        $listing = $this->reload($listing);
        self::assertEquals($date->modify('+1 day'), $listing->getExpiresAt());
        self::assertEqualsWithDelta($published, $listing->getPublishedAt(), 1);
        self::assertNull($listing->getExpiryReminderSentAt());
    }

    public function testSavingOtherChangesKeepsTheExactExpiryAndReminder(): void
    {
        $seller = $this->createUser('sam@example.com');
        $listing = $this->createListing($seller, 'Desk', '-40 days');
        $expiry = $listing->getExpiresAt();
        $reminder = new \DateTimeImmutable('-1 day');
        $listing->setExpiryReminderSentAt($reminder);
        $this->manager->flush();
        $this->client->loginUser($seller);
        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        $this->client->submitForm('Save changes', ['listing_form[title]' => 'Desk with drawers']);
        self::assertResponseRedirects();
        $listing = $this->reload($listing);
        self::assertEqualsWithDelta($expiry, $listing->getExpiresAt(), 1);
        self::assertEqualsWithDelta($reminder, $listing->getExpiryReminderSentAt(), 1);
    }

    public function testExpiredListingCanBeRepublishedForTheRestOfToday(): void
    {
        $seller = $this->createUser('sam@example.com');
        $policy = static::getContainer()->get(\App\Listing\ListingExpiry::class);
        // In UTC like the app stores it: Doctrine saves the wall-clock time and reads it back as UTC.
        $listing = $this->createListing($seller, 'Desk')->setExpiresAt($policy->today()->setTimezone(new \DateTimeZone('UTC')));
        $this->manager->flush();
        $this->client->loginUser($seller);
        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        $this->client->submitForm('Publish listing', ['listing_form[hideAfter]' => $policy->today()->format('Y-m-d')]);
        self::assertResponseRedirects();
        self::assertEquals($policy->today()->modify('+1 day'), $this->reload($listing)->getExpiresAt());
    }

    public function testDraftKeepsChosenDateWhenFirstPublished(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $date = static::getContainer()->get(\App\Listing\ListingExpiry::class)->today()->modify('+4 months');
        $this->client->request('GET', '/sell');
        $this->client->submitForm('Save draft', [
            'listing_form[title]' => 'Desk',
            'listing_form[category]' => 'flat_and_home',
            'listing_form[condition]' => 'good',
            'listing_form[hideAfter]' => $date->format('Y-m-d'),
        ]);
        self::assertResponseRedirects();
        $listing = $this->manager->getRepository(Listing::class)->findOneBy(['title' => 'Desk']);
        self::assertTrue($listing->isDraft());
        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        $this->client->submitForm('Publish listing');
        self::assertResponseRedirects();
        $listing = $this->reload($listing);
        self::assertNotNull($listing->getPublishedAt());
        self::assertEquals($date->modify('+1 day'), $listing->getExpiresAt());
        self::assertTrue($listing->isLive());
    }

    public function testExpiredListingsAreHidden(): void
    {
        $sam = $this->createUser('sam@example.com');
        $this->createListing($sam, 'Fresh', '-41 days');
        $expired = $this->createListing($sam, 'Stale', '-43 days');

        $this->client->request('GET', '/');
        self::assertSame(['Fresh'], $this->client->getCrawler()->filter('.listing-card:not([hidden]) .card-link')->each(static fn ($n) => $n->text()));

        $this->client->request('GET', '/listings/'.$expired->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($sam);
        $this->client->request('GET', '/listings/'.$expired->getId());
        self::assertSelectorTextContains('.owner-bar', 'Expired');
    }

    public function testRefreshOnlyNearExpiryAndUsesTheConfiguredLifetime(): void
    {
        $sam = $this->createUser('sam@example.com');
        $new = $this->createListing($sam, 'New', '-1 day');
        $old = $this->createListing($sam, 'Old', '-50 days');
        static::getContainer()->get(SiteSettingsProvider::class)->get()->setListingLifetimeDays(30);
        $this->manager->flush();
        $this->client->loginUser($sam);

        $this->client->request('GET', '/account/listings');
        self::assertSelectorCount(1, '.my-listing form[action$="/refresh"]');
        self::assertSelectorExists('.my-listing-expired form[action="/listings/'.$old->getId().'/refresh"]');

        $this->client->submitForm('Refresh');
        self::assertResponseRedirects('/account/listings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', '“Old” is refreshed until');
        $old = $this->reload($old);
        self::assertTrue($old->isLive());
        self::assertEqualsWithDelta(new \DateTimeImmutable('+30 days'), $old->getExpiresAt(), 5);

        // It's back on the home page.
        $this->client->request('GET', '/');
        self::assertSame(['New', 'Old'], $this->client->getCrawler()->filter('.listing-card:not([hidden]) .card-link')->each(static fn ($n) => $n->text()));

        // Too early: the button isn't offered, and posting anyway is refused.
        $this->client->request('POST', '/listings/'.$new->getId().'/refresh', ['_csrf_token' => 'csrf-token']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'in the last 7 days before it expires');
        self::assertEqualsWithDelta(new \DateTimeImmutable('+41 days'), $this->reload($new)->getExpiresAt(), 5);
    }

    public function testMovingToDraftsAndBackKeepsTheExpiry(): void
    {
        $sam = $this->createUser('sam@example.com');
        $listing = $this->createListing($sam, 'Lamp', '-10 days');
        $expiresAt = $listing->getExpiresAt();
        $this->client->loginUser($sam);

        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        $this->client->submitForm('Move to drafts');
        self::assertTrue($this->reload($listing)->isDraft());

        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        $this->client->submitForm('Publish listing');
        $listing = $this->reload($listing);
        self::assertTrue($listing->isLive());
        self::assertEqualsWithDelta($expiresAt, $listing->getExpiresAt(), 1);
    }

    public function testSoldAndDraftListingsCantBeRefreshed(): void
    {
        $now = new \DateTimeImmutable();
        $listing = (new Listing())->publish(42, $now->modify('-40 days'));
        self::assertTrue($listing->canRefresh($now));

        self::assertFalse((clone $listing)->markSold()->canRefresh($now));
        self::assertFalse(new Listing()->canRefresh($now));
        $this->expectException(\LogicException::class);
        $listing->markSold()->refresh(42, $now);
    }

    private function reload(Listing $listing): Listing
    {
        $this->manager->clear();

        return $this->manager->find(Listing::class, $listing->getId());
    }
}
