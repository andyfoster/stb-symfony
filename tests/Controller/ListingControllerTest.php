<?php

namespace App\Tests\Controller;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\Paper;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListingControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        // An isolated in-memory database: never touch the application's listings.
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';

        $this->client = static::createClient();
        $this->client->disableReboot();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($manager))->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $manager->persist($this->listing(ListingType::ForSale, Category::Textbooks, 'Chemistry: The Central Science', 9500, '-1 day',
            (new BookDetails())->setAuthor('Brown & LeMay')->setEdition('15th')->setIsbn('9780137542970')
                ->addPaper(new Paper('chem191'))->addPaper(new Paper('CHEM111'))));
        $manager->persist($this->listing(ListingType::Wanted, Category::Textbooks, 'Psychology for PSYC111', 5000, '-2 days',
            (new BookDetails())->setAuthor('Gleitman')));
        $manager->persist($this->listing(ListingType::ForSale, Category::Furniture, 'Desk and office chair', 6000, '-3 days'));
        $manager->persist($this->listing(ListingType::ForSale, Category::Furniture, 'Bookshelf', 0, '-4 days'));
        $manager->persist($this->listing(ListingType::Wanted, Category::Electronics, 'Second monitor', null, '-5 days'));
        $manager->flush();
        // Like a fresh request: nothing loaded yet.
        $manager->clear();
    }

    public function testHomePageListsEverythingNewestFirst(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('ScarfieTextbooks');
        self::assertSelectorTextContains('#listings .count', '5');
        self::assertSame(
            ['Chemistry: The Central Science', 'Psychology for PSYC111', 'Desk and office chair', 'Bookshelf', 'Second monitor'],
            $this->titles(),
        );

        $chemistry = $this->client->getCrawler()->filter('.listing-card')->first();
        self::assertStringContainsString('$95', $chemistry->filter('.listing-price')->text());
        self::assertSame(['CHEM111', 'CHEM191'], $chemistry->filter('.course-code')->each(static fn ($node) => $node->text()));
        // Only wanted listings are marked; for sale is the default.
        self::assertCount(0, $chemistry->filter('.badge'));
        self::assertSelectorTextContains('.listing-wanted .badge', 'Wanted');

        self::assertSelectorTextContains('.listing-wanted .listing-price', 'Budget up to $50');
        self::assertAnySelectorTextContains('.listing-price', 'Free');
        self::assertAnySelectorTextContains('.listing-price', 'Name your price');
    }

    public function testFilterBySearchTypeCategoryAndPrice(): void
    {
        $this->client->request('GET', '/', ['q' => 'chem 111']);
        self::assertSame(['Chemistry: The Central Science'], $this->titles());

        $this->client->request('GET', '/', ['q' => 'CHEM191']);
        self::assertSame(['Chemistry: The Central Science'], $this->titles());
        self::assertSelectorTextContains('.clear-link', 'Clear filters');

        $this->client->request('GET', '/', ['q' => 'gleitman']);
        self::assertSame(['Psychology for PSYC111'], $this->titles());

        $this->client->request('GET', '/', ['q' => '978-0137-542970']);
        self::assertSame(['Chemistry: The Central Science'], $this->titles());

        $this->client->request('GET', '/', ['type' => 'wanted']);
        self::assertSame(['Psychology for PSYC111', 'Second monitor'], $this->titles());

        $this->client->request('GET', '/', ['type' => 'for_sale', 'category' => 'furniture']);
        self::assertSame(['Desk and office chair', 'Bookshelf'], $this->titles());

        $this->client->request('GET', '/', ['maxPrice' => '60']);
        self::assertSame(['Psychology for PSYC111', 'Desk and office chair', 'Bookshelf'], $this->titles());
    }

    public function testFilterFormSubmitsAsGet(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Filter')->form(['category' => 'electronics']));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('category=electronics', (string) $this->client->getRequest()->getQueryString());
        self::assertSame(['Second monitor'], $this->titles());
    }

    public function testNoMatchesAndInvalidFilters(): void
    {
        $this->client->request('GET', '/', ['q' => 'unicycle']);
        self::assertSame([], $this->titles());
        self::assertSelectorTextContains('.empty-state', 'Nothing matches those filters.');

        // Invalid values show an error and fall back to the full list.
        $this->client->request('GET', '/', ['maxPrice' => '-5', 'category' => 'spaceships']);
        self::assertResponseIsSuccessful();
        self::assertCount(5, $this->titles());
        self::assertSelectorExists('.filter-bar ul li');
    }

    private function listing(ListingType $type, Category $category, string $title, ?int $priceCents, string $created, ?BookDetails $book = null): Listing
    {
        return (new Listing())
            ->setType($type)
            ->setCategory($category)
            ->setTitle($title)
            ->setPriceCents($priceCents)
            ->setCondition(ListingType::ForSale === $type ? Condition::Good : null)
            ->setLocation('North Dunedin')
            ->setBook($book)
            ->setCreatedAt(new \DateTimeImmutable($created))
            ->publish(42, new \DateTimeImmutable($created));
    }

    /** @return string[] */
    private function titles(): array
    {
        return $this->client->getCrawler()->filter('.listing-card .card-link')->each(static fn ($node) => $node->text());
    }
}
