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

    public function testIsbnLookupIsAnOptionalSidebarShortcut(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->client->request('GET', '/sell');

        self::assertSelectorExists('.listing-form-side .optional-lookup:not([open])');
        self::assertSelectorNotExists('.listing-form-main input[name="listing_form[book][isbn]"]');
        self::assertSelectorTextContains('.optional-lookup summary', 'ISBN lookup');
        self::assertSelectorNotExists('.listing-intent, .listing-readiness, .listing-lifetime-note');
        self::assertSelectorExists('.photo-panel[open]');
        $this->client->submitForm('Publish listing', [
            'listing_form[title]' => 'Chemistry textbook',
            'listing_form[category]' => 'textbooks',
            'listing_form[condition]' => 'good',
            'listing_form[book][author]' => 'Brown & LeMay',
        ]);
        self::assertResponseRedirects();
        self::assertNull($this->onlyListing()->getBook()->getIsbn());
    }

    public function testWantedEditorFocusesOnBudgetAndRequirements(): void
    {
        $seller = $this->createUser('sam@example.com');
        $listing = $this->createListing($seller, 'Looking for a desk', category: \App\Enum\Category::FlatAndHome)
            ->setType(\App\Enum\ListingType::Wanted)->setCondition(null);
        $this->manager->flush();
        $this->client->loginUser($seller);
        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('label[for="listing_form_title"]', 'Looking for');
        self::assertSelectorTextContains('label[for="listing_form_priceCents"]', 'Budget');
        self::assertSelectorTextContains('label[for="listing_form_description"]', 'Requirements');
        self::assertSelectorExists('.condition-field[hidden]');
        self::assertSelectorExists('.photo-panel:not([open])');
        self::assertSelectorTextContains('.photo-panel summary', 'Reference photos');
        self::assertSelectorNotExists('.listing-readiness');
        $this->client->submitForm('Save changes', ['listing_form[priceCents]' => '45']);
        self::assertResponseRedirects();
        self::assertSame(4500, $this->onlyListing()->getPriceCents());
        self::assertNull($this->onlyListing()->getCondition());
    }

    public function testWantedListingCanBeStartedDirectlyWithoutPhotosOrCondition(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->client->request('GET', '/sell?type=wanted');
        self::assertSelectorExists('input[name="listing_form[type]"][value="wanted"][checked]');
        self::assertSelectorExists('.condition-field[hidden]');
        $this->client->submitForm('Post wanted listing', [
            'listing_form[title]' => 'Looking for a small desk',
            'listing_form[category]' => 'flat_and_home',
            'listing_form[description]' => 'Happy with some wear. I can collect this week.',
        ]);
        self::assertResponseRedirects();
        self::assertSame(\App\Enum\ListingType::Wanted, $this->onlyListing()->getType());
        self::assertNull($this->onlyListing()->getPriceCents());
        self::assertNull($this->onlyListing()->getCondition());
        self::assertCount(0, $this->onlyListing()->getPhotos());
    }

    public function testConditionStarsKeepLabelsAndOneSelectedDescription(): void
    {
        $seller = $this->createUser('sam@example.com');
        $listing = $this->createListing($seller);
        $this->client->loginUser($seller);
        $this->client->request('GET', '/listings/'.$listing->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(5, '.condition-star');
        self::assertSame(['Well used', 'Fair', 'Good', 'Like new', 'New'], $this->client->getCrawler()->filter('.condition-option strong')->each(static fn ($node) => $node->text()));
        self::assertSelectorCount(3, '.condition-option.is-rated');
        self::assertSelectorTextContains('#condition-description', 'Some signs of use');
        self::assertSelectorNotExists('.condition-option span:not(.condition-star)');
        $this->client->submitForm('Save changes', ['listing_form[condition]' => 'fair']);
        self::assertResponseRedirects();
        $this->manager->clear();
        self::assertSame(\App\Enum\Condition::Fair, $this->manager->find(Listing::class, $listing->getId())->getCondition());
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
            'listing_form[book][isbn]' => '978–0 137542970',
            'listing_form[book][year]' => '2024',
            'listing_form[book][author]' => 'Brown & LeMay',
            // Typed in by hand: a known paper and one that isn't in the list yet.
            'listing_form[book][papers]' => 'chem 191, CHEM111',
        ]);

        $listing = $this->onlyListing();
        self::assertResponseRedirects('/listings/'.$listing->getId());
        self::assertSame(ListingStatus::Active, $listing->getStatus());
        self::assertSame(8550, $listing->getPriceCents());
        self::assertSame('9780137542970', $listing->getBook()->getIsbn());
        self::assertSame(2024, $listing->getBook()->getYear());
        self::assertSame('sam@example.com', $listing->getSeller()->getEmail());
        self::assertEqualsWithDelta(new \DateTimeImmutable('+365 days'), $listing->getExpiresAt(), 5);

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Your listing is live.');
        self::assertSelectorTextContains('h1', 'Chemistry: The Central Science');
        self::assertSelectorTextContains('.listing-byline', 'Brown & LeMay');
        self::assertSelectorTextContains('.listing-byline', '2024');
        self::assertSelectorTextContains('.listing-condition .condition', 'Good');
        self::assertSelectorTextContains('.listing-comments h2', 'Comments from Sam');
        self::assertSelectorTextContains('.listing-comments .listing-body', 'No writing inside.');
        self::assertSelectorTextContains('.owner-bar h2', 'Your listing');
        self::assertSelectorTextContains('.owner-actions a', 'Edit listing');
        self::assertSelectorTextContains('.contact-panel h2', 'Message Sam');
        self::assertSelectorExists('.contact-panel .contact-open[disabled]');
        self::assertSelectorNotExists('.contact-panel form');

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/listings/'.$listing->getId());
        self::assertSelectorNotExists('.owner-bar');
        self::assertSelectorExists('.contact-panel .contact-open:not([disabled])');

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.listing-card .card-link', 'Chemistry: The Central Science');
        self::assertAnySelectorTextContains('.listing-card .course-code', 'CHEM191');
        self::assertAnySelectorTextContains('.listing-card .course-code', 'CHEM111');
        self::assertSelectorTextContains('.listing-card .listing-price', '$85.50');
    }

    public function testSaveDraftIsHiddenFromEveryoneElse(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        $this->client->submitForm('Save draft', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => 'Desk lamp',
            'listing_form[category]' => 'flat_and_home',
            'listing_form[condition]' => 'fair',
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
            'listing_form[category]' => 'flat_and_home',
            // Left over from picking Textbooks first; ignored, so no author is needed.
            'listing_form[book][isbn]' => '9780137542970',
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->onlyListing()->getBook());
        self::assertCount(0, $this->manager->getRepository(BookDetails::class)->findAll());
    }

    public function testThingsForSaleNeedAConditionWantedOnesDont(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        self::assertSelectorExists('.condition-option[title="Never used, as it came from the shop."]');
        self::assertSelectorCount(5, '.condition-star');
        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => 'Desk lamp',
            'listing_form[category]' => 'flat_and_home',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.condition-field', 'Choose the condition');

        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'wanted',
            'listing_form[condition]' => 'good',
        ]);
        self::assertResponseRedirects();
        self::assertNull($this->onlyListing()->getCondition(), 'Wanted listings don’t keep a condition.');
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
        self::assertSelectorExists('.optional-lookup[open]');
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
