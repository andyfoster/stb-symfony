<?php

namespace App\Tests\Controller;

use App\Entity\Listing;
use App\Settings\SiteSettingsProvider;
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
