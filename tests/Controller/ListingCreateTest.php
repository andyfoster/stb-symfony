<?php

namespace App\Tests\Controller;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Enum\ListingStatus;
use App\Repository\ListingRepository;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Posting, editing and managing your own listings.
 */
final class ListingCreateTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testGuestsAreSentToLogIn(): void
    {
        $this->client->request('GET', '/sell');

        self::assertResponseRedirects('/login');
    }

    public function testPublishTextbookShowsOnHomePage(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => 'Chemistry: The Central Science',
            'listing_form[category]' => 'textbooks',
            'listing_form[priceCents]' => '85.50',
            'listing_form[condition]' => 'good',
            'listing_form[description]' => 'No writing inside.',
            'listing_form[book][isbn]' => '978-0137542970',
            'listing_form[book][author]' => 'Brown & LeMay',
            'listing_form[book][courseCode]' => 'chem191',
        ]);

        $listing = $this->onlyListing();
        self::assertResponseRedirects('/listings/'.$listing->getId());
        self::assertSame(ListingStatus::Active, $listing->getStatus());
        self::assertSame(8550, $listing->getPriceCents());
        self::assertSame('sam@example.com', $listing->getSeller()->getEmail());
        self::assertEqualsWithDelta(new \DateTimeImmutable('+42 days'), $listing->getExpiresAt(), 5);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Your listing is live.');
        self::assertSelectorTextContains('h1', 'Chemistry: The Central Science');
        self::assertSelectorTextContains('.contact-panel h2', 'Your listing');

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.listing-card .card-link', 'Chemistry: The Central Science');
        self::assertSelectorTextContains('.listing-card .course-code', 'CHEM191');
        self::assertSelectorTextContains('.listing-card .listing-price', '$85.50');
    }

    public function testSaveDraftIsHiddenFromEveryoneElse(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        $this->client->submitForm('Save draft', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => 'Desk lamp',
            'listing_form[category]' => 'furniture',
        ]);

        self::assertResponseRedirects('/account/listings');
        $listing = $this->onlyListing();
        self::assertSame(ListingStatus::Draft, $listing->getStatus());
        self::assertNull($listing->getExpiresAt());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.my-listing .status', 'Draft');

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.listing-card');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/listings/'.$listing->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testOtherCategoriesDropBookDetails(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'wanted',
            'listing_form[title]' => 'Single bed frame',
            'listing_form[category]' => 'furniture',
            // Left over from picking Textbooks first; ignored, so no author is needed.
            'listing_form[book][isbn]' => '9780137542970',
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->onlyListing()->getBook());
        self::assertCount(0, $this->manager->getRepository(BookDetails::class)->findAll());
    }

    public function testInvalidListingShowsErrors(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => '',
            'listing_form[category]' => 'textbooks',
            'listing_form[book][isbn]' => '12345',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.form-field', 'This value should not be blank.');
        self::assertAnySelectorTextContains('.form-field', 'This value is neither a valid ISBN-10 nor a valid ISBN-13.');
    }

    public function testOnlyTheSellerCanEdit(): void
    {
        $listing = $this->createListing($this->createUser('sam@example.com'));
        $this->client->loginUser($this->createUser('alex@example.com', name: 'Alex'));

        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/listings/'.$listing->getId().'/delete', ['_csrf_token' => 'csrf-token']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEditMarkSoldAndDelete(): void
    {
        $sam = $this->createUser('sam@example.com');
        $listing = $this->createListing($sam);
        $id = $listing->getId();
        $this->client->loginUser($sam);

        $this->client->request('GET', "/listings/$id/edit");
        $this->client->submitForm('Save changes', ['listing_form[title]' => 'Chemistry (15th edition)']);
        self::assertResponseRedirects("/listings/$id");
        self::assertSame('Chemistry (15th edition)', $this->listing($id)->getTitle());

        $this->client->request('GET', '/account/listings');
        $this->client->submitForm('Mark sold');
        self::assertResponseRedirects('/account/listings');
        self::assertTrue($this->listing($id)->isSold());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.my-listing .status', 'Sold');

        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.listing-card');

        $this->client->request('GET', '/account/listings');
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/account/listings');
        $this->manager->clear();
        self::assertNull($this->manager->find(Listing::class, $id));
    }

    private function onlyListing(): Listing
    {
        $this->manager->clear();
        $listings = static::getContainer()->get(ListingRepository::class)->findAll();
        self::assertCount(1, $listings);

        return $listings[0];
    }

    private function listing(int $id): Listing
    {
        $this->manager->clear();

        return $this->manager->find(Listing::class, $id);
    }
}
