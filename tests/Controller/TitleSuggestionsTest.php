<?php

namespace App\Tests\Controller;

use App\Entity\BookDetails;
use App\Enum\Category;
use App\Enum\ListingStatus;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Textbook title suggestions on the listing form, from books listed before.
 */
final class TitleSuggestionsTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testSuggestsTitlesStartingWithTheTermThenTheMostSold(): void
    {
        $seller = $this->createUser('sam@example.com');
        $this->createListing($seller, 'Chemistry: The Central Science');
        $this->createListing($seller, 'chemistry: the central science'); // same book, other casing
        $this->createListing($seller, 'Organic Chemistry')->setStatus(ListingStatus::Sold);
        $this->createListing($seller, 'Organic Chemistry')->setStatus(ListingStatus::Sold);
        $this->createListing($seller, 'Organic Chemistry')->setStatus(ListingStatus::Sold);
        $this->createListing($seller, 'General Chemistry');
        $this->createListing($seller, 'Chemistry lab coat', category: Category::ClothesAndOther);
        $this->createListing($seller, 'Chemistry Workbook', published: null); // a draft
        $this->manager->flush();

        $this->client->loginUser($seller);
        $this->client->request('GET', '/sell/suggest-titles', ['q' => 'CHEM']);

        self::assertResponseIsSuccessful();
        $suggestions = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['chemistry: the central science', 'organic chemistry', 'general chemistry'], array_map(mb_strtolower(...), array_column($suggestions, 'title')));
        self::assertSame(['Brown & LeMay', 2, 0], [$suggestions[0]['author'], $suggestions[0]['listings'], $suggestions[0]['sold']]);
        self::assertSame(3, $suggestions[1]['sold']);
    }

    public function testSameTitleByDifferentAuthorsIsSuggestedSeparately(): void
    {
        $seller = $this->createUser('sam@example.com');
        $this->createListing($seller, 'Chemistry');
        $this->createListing($seller, 'Chemistry')->setBook((new BookDetails())->setAuthor('Blackman'));
        $this->manager->flush();

        $this->client->loginUser($seller);
        $this->client->request('GET', '/sell/suggest-titles', ['q' => 'ch']);

        $suggestions = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertEqualsCanonicalizing(['Blackman', 'Brown & LeMay'], array_column($suggestions, 'author'));
    }

    public function testShortTermsAndWildcardsMatchNothing(): void
    {
        $seller = $this->createUser('sam@example.com');
        $this->createListing($seller);

        $this->client->loginUser($seller);
        foreach (['c', '%%', '__'] as $term) {
            $this->client->request('GET', '/sell/suggest-titles', ['q' => $term]);
            self::assertSame('[]', $this->client->getResponse()->getContent(), $term);
        }
    }

    public function testNeedsToBeLoggedIn(): void
    {
        $this->client->request('GET', '/sell/suggest-titles', ['q' => 'chem']);

        self::assertResponseRedirects('/login');
    }

    public function testListingFormWiresUpSuggestions(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->client->request('GET', '/sell');

        self::assertSelectorExists('form[data-controller~="title-suggest"][data-title-suggest-url-value="/sell/suggest-titles"]');
        self::assertSelectorExists('input[data-title-suggest-target="input"]');
        self::assertSelectorExists('select[data-title-suggest-target="category"]');
        self::assertSelectorExists('input[data-title-suggest-target="author"]');
    }
}
