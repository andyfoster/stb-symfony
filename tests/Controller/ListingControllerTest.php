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

    public function testHomePageShowsThingsForSaleAToZ(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('ScarfieTextbooks');
        self::assertSame(['Bookshelf', 'Chemistry: The Central Science', 'Desk and office chair'], $this->titles());
        self::assertSame(['For sale 3', 'Wanted 2'], $this->tabs());
        self::assertSelectorExists('input[name="type"][value="for_sale"][checked]');
        self::assertSelectorExists('.results-note[hidden]');

        $chemistry = $this->client->getCrawler()->filter('.listing-card')->reduce(static fn ($card) => str_contains($card->text(), 'Chemistry'));
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
        self::assertSame(['Bookshelf', 'Desk and office chair'], $this->titles());
        $this->client->request('GET', '/', ['categories' => ['flat_and_home', 'textbooks']]);
        self::assertSame(['Bookshelf', 'Chemistry: The Central Science', 'Desk and office chair'], $this->titles());
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

    public function testPaginationKeepsFiltersAndDoesNotRenderOtherPages(): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 1; $i <= 30; ++$i) {
            $manager->persist($this->listing(ListingType::Wanted, Category::StudyAndTech, sprintf('Monitor %02d', $i), null, '-1 day'));
        }
        // Neither drafts nor expired listings should affect the count or page contents.
        $manager->persist($this->listing(ListingType::Wanted, Category::StudyAndTech, 'Monitor expired', null, '-50 days'));
        $manager->persist($this->listing(ListingType::Wanted, Category::StudyAndTech, 'Monitor draft', null, '-1 day')->setStatus(\App\Enum\ListingStatus::Draft));
        $manager->flush();
        $manager->clear();

        $crawler = $this->client->request('GET', '/', ['q' => 'monitor', 'type' => 'wanted', 'categories' => ['study_and_tech']]);
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(24, '.listing-card');
        self::assertSame('Monitor 01', $this->titles()[0]);
        self::assertSame(['For sale 0', 'Wanted 31'], $this->tabs());
        self::assertSelectorTextContains('.results-note', '31 matches');
        $this->client->click($crawler->selectLink('Next')->link());
        self::assertResponseIsSuccessful();
        self::assertSame(['Monitor 25', 'Monitor 26', 'Monitor 27', 'Monitor 28', 'Monitor 29', 'Monitor 30', 'Second monitor'], $this->titles());
        self::assertSelectorCount(7, '.listing-card');
        self::assertSelectorTextContains('.pagination', 'Page 2 of 2');
        self::assertSelectorNotExists('a[rel="next"]');
        self::assertSelectorExists('input[name="categories[]"][value="study_and_tech"][checked]');
        $this->client->clickLink('Previous');
        self::assertSelectorCount(24, '.listing-card');

        $this->client->request('GET', '/', ['q' => 'monitor', 'type' => 'wanted', 'page' => '999']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination', 'Page 2 of 2');

        foreach (['0', '-3', 'nonsense', '999999999999999999999999999999'] as $page) {
            $this->client->request('GET', '/', ['page' => $page]);
            self::assertResponseIsSuccessful();
            self::assertSame('Bookshelf', $this->titles()[0]);
        }
    }

    public function testSearchTreatsSqlWildcardsAsLiteralText(): void
    {
        foreach (['%', '_', '!'] as $search) {
            $this->client->request('GET', '/', ['q' => $search]);
            self::assertResponseIsSuccessful();
            self::assertSelectorCount(0, '.listing-card');
        }
        $this->client->request('GET', '/', ['q' => 'chem111']);
        self::assertSelectorCount(2, '.course-code', 'Matching one paper must keep the book’s other papers.');
    }

    public function testLazyBatchesIncludeBothTabsAndOnlyLiveListings(): void
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 1; $i <= 25; ++$i) {
            $manager->persist($this->listing(ListingType::Wanted, Category::StudyAndTech, sprintf('Monitor %02d', $i), null, '-1 day'));
        }
        $manager->persist($this->listing(ListingType::ForSale, Category::FlatAndHome, 'Expired', null, '-50 days'));
        $manager->persist($this->listing(ListingType::ForSale, Category::FlatAndHome, 'Draft', null, '-1 day')->setStatus(\App\Enum\ListingStatus::Draft));
        $manager->flush();
        $manager->clear();

        $this->client->request('GET', '/listing-cards', ['q' => 'ignored', 'type' => 'wanted']);
        self::assertResponseIsSuccessful();
        $batch = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $crawler = new \Symfony\Component\DomCrawler\Crawler($batch['html']);
        self::assertCount(24, $crawler->filter('.listing-card'));
        self::assertGreaterThan(0, $crawler->filter('[data-type="for_sale"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-type="wanted"]')->count());
        self::assertStringNotContainsString('Expired', $batch['html']);
        self::assertStringNotContainsString('Draft', $batch['html']);
        self::assertStringContainsString('chem191', $crawler->filter('[data-search*="chem191"]')->attr('data-search'));
        $firstIds = $crawler->filter('[data-listing-id]')->each(static fn ($node) => $node->attr('data-listing-id'));

        $this->client->request('GET', $batch['next']);
        self::assertResponseIsSuccessful();
        $last = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertNull($last['next']);
        $crawler = new \Symfony\Component\DomCrawler\Crawler($last['html']);
        self::assertCount(6, $crawler->filter('.listing-card'));
        $lastIds = $crawler->filter('[data-listing-id]')->each(static fn ($node) => $node->attr('data-listing-id'));
        self::assertSame([], array_values(array_intersect($firstIds, $lastIds)));

        $this->client->request('GET', '/listing-cards', ['page' => '-1']);
        self::assertResponseStatusCodeSame(404);
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
