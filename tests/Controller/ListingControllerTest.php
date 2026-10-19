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
        $manager->persist($this->listing(ListingType::ForSale, Category::FlatAndHome, 'Desk and office chair', 6000, '-3 days'));
        $manager->persist($this->listing(ListingType::ForSale, Category::FlatAndHome, 'Bookshelf', 0, '-4 days'));
        $manager->persist($this->listing(ListingType::Wanted, Category::StudyAndTech, 'Second monitor', null, '-5 days'));
        $manager->flush();
        // Like a fresh request: nothing loaded yet.
        $manager->clear();
    }

    public function testHomePageShowsThingsForSaleNewestFirst(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('ScarfieTextbooks');
        self::assertSame(['Chemistry: The Central Science', 'Desk and office chair', 'Bookshelf'], $this->titles());
        self::assertSame(['For sale 3', 'Wanted 2'], $this->tabs());
        self::assertSelectorExists('input[name="type"][value="for_sale"][checked]');
        self::assertSelectorExists('.results-note[hidden]');

        $chemistry = $this->client->getCrawler()->filter('.listing-card')->first();
        self::assertStringContainsString('$95', $chemistry->filter('.listing-price')->text());
        self::assertSame(['CHEM111', 'CHEM191'], $chemistry->filter('.course-code')->each(static fn ($node) => $node->text()));
        self::assertCount(0, $chemistry->filter('.badge'));
        self::assertAnySelectorTextContains('.listing-price', 'Free');
    }

    public function testWantedTab(): void
    {
        $this->client->request('GET', '/', ['type' => 'wanted']);

        self::assertSame(['Psychology for PSYC111', 'Second monitor'], $this->titles());
        self::assertSelectorExists('input[name="type"][value="wanted"][checked]');
        self::assertSelectorTextContains('.listing-wanted .badge', 'Wanted');
        self::assertSelectorTextContains('.listing-wanted .listing-price', 'Budget up to $50');
        self::assertAnySelectorTextContains('.listing-price', 'Name your price');
    }

    public function testSearchAndCategories(): void
    {
        $this->client->request('GET', '/', ['q' => 'chem 111']);
        self::assertSame(['Chemistry: The Central Science'], $this->titles());

        $this->client->request('GET', '/', ['q' => 'CHEM191']);
        self::assertSame(['Chemistry: The Central Science'], $this->titles());
        self::assertSelectorTextContains('.results-note', '1 match for “CHEM191”');
        self::assertSelectorTextContains('.clear-link', 'Clear');

        $this->client->request('GET', '/', ['q' => '978-0137-542970']);
        self::assertSame(['Chemistry: The Central Science'], $this->titles());

        // The search applies to both tabs, and their counts follow it.
        $this->client->request('GET', '/', ['q' => 'gleitman']);
        self::assertSame([], $this->titles());
        self::assertSame(['For sale 0', 'Wanted 1'], $this->tabs());
        self::assertSelectorTextContains('.empty-state', 'or look at the other tab');
        $this->client->request('GET', '/', ['q' => 'gleitman', 'type' => 'wanted']);
        self::assertSame(['Psychology for PSYC111'], $this->titles());

        // Any number of categories.
        $this->client->request('GET', '/', ['categories' => ['flat_and_home']]);
        self::assertSame(['Desk and office chair', 'Bookshelf'], $this->titles());
        $this->client->request('GET', '/', ['categories' => ['flat_and_home', 'textbooks']]);
        self::assertSame(['Chemistry: The Central Science', 'Desk and office chair', 'Bookshelf'], $this->titles());
        self::assertSelectorExists('input[name="categories[]"][value="textbooks"][checked]');
        $this->client->request('GET', '/', ['categories' => ['study_and_tech'], 'type' => 'wanted']);
        self::assertSame(['Second monitor'], $this->titles());
    }

    public function testFilterFormSubmitsAsGet(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Search')->form();
        $form['categories'][0]->tick();
        $this->client->submit($form, ['q' => 'desk']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('q=desk', urldecode((string) $this->client->getRequest()->getQueryString()));
        self::assertSame([], $this->titles(), 'Textbooks only, so the desk is filtered out.');
    }

    public function testNoMatchesAndInvalidFilters(): void
    {
        $this->client->request('GET', '/', ['q' => 'unicycle']);
        self::assertSame([], $this->titles());
        self::assertSelectorTextContains('.empty-state', 'Nothing matches that.');

        // Invalid values show an error and fall back to the full list.
        $this->client->request('GET', '/', ['categories' => ['spaceships']]);
        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->titles());
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
            ->setBook($book)
            ->setCreatedAt(new \DateTimeImmutable($created))
            ->publish(42, new \DateTimeImmutable($created));
    }

    /** @return string[] e.g. "For sale 3" */
    private function tabs(): array
    {
        return $this->client->getCrawler()->filter('.tab')->each(static fn ($node) => $node->text());
    }

    /** @return string[] */
    private function titles(): array
    {
        return $this->client->getCrawler()->filter('.listing-card:not([hidden]) .card-link')->each(static fn ($node) => $node->text());
    }
}
