<?php

namespace App\Tests\Controller;

use App\Entity\Paper;
use App\Listing\PaperCatalog;
use App\Repository\PaperRepository;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Otago papers: the imported list, suggestions, and typing your own.
 */
final class PaperTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testImportIsRepeatableAndKeepsTypedInPapers(): void
    {
        $catalog = static::getContainer()->get(PaperCatalog::class);
        $catalog->get('ZOOL101'); // someone typed it in before the import
        $this->manager->flush();

        $counts = $catalog->import([['CHEM191', 'The Chemical Basis of Biology and Human Health'], ['zool 101', 'Animal Biology'], ['not a code', '']]);
        self::assertSame(['added' => 1, 'updated' => 1, 'skipped' => 1], $counts);
        self::assertSame(['added' => 0, 'updated' => 2, 'skipped' => 0], $catalog->import([['CHEM191', null], ['ZOOL101', null]]));

        $zool = $this->paperByCode('ZOOL101');
        self::assertTrue($zool->isOfficial());
        self::assertSame('Animal Biology', $zool->getTitle());
        self::assertSame('The Chemical Basis of Biology and Human Health', $this->paperByCode('CHEM191')->getTitle());
    }

    public function testStarterListImports(): void
    {
        $counts = static::getContainer()->get(PaperCatalog::class)->importCsv(static::getContainer()->getParameter('kernel.project_dir').'/data/otago-papers.csv');

        self::assertGreaterThan(2000, $counts['added']);
        self::assertSame(0, $counts['skipped']);
        self::assertSame('Foundations of Biochemistry', $this->paperByCode('BIOC192')->getTitle());
    }

    public function testImportReadsSemicolonAndCommaFiles(): void
    {
        $catalog = static::getContainer()->get(PaperCatalog::class);
        $file = tempnam(sys_get_temp_dir(), 'papers');

        file_put_contents($file, "BIOC192; Foundations of Biochemistry\nCHEM191; Chemistry, Biology and Health\n");
        self::assertSame(['added' => 2, 'updated' => 0, 'skipped' => 0], $catalog->importCsv($file));
        self::assertSame('Chemistry, Biology and Health', $this->paperByCode('CHEM191')->getTitle());

        file_put_contents($file, "code,title\nZOOL101,Animal Biology\n");
        self::assertSame(['added' => 1, 'updated' => 0, 'skipped' => 0], $catalog->importCsv($file));
        self::assertSame('Animal Biology', $this->paperByCode('ZOOL101')->getTitle());

        unlink($file);
    }

    public function testSuggestionsMatchCodesThenTitles(): void
    {
        $catalog = static::getContainer()->get(PaperCatalog::class);
        $catalog->import([['CHEM191', 'The Chemical Basis of Biology and Human Health'], ['CHEM111', 'Chemistry'], ['BIOL112', 'Cell and Molecular Biology']]);

        $this->client->request('GET', '/papers/search', ['q' => 'chem 1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['CHEM111', 'CHEM191'], array_column($this->json(), 'code'));

        $this->client->request('GET', '/papers/search', ['q' => 'molecular']);
        self::assertSame([['code' => 'BIOL112', 'title' => 'Cell and Molecular Biology']], $this->json());
    }

    public function testTypedPapersBecomeTagsAndBadCodesAreRejected(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell');
        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => 'Chemistry',
            'listing_form[category]' => 'textbooks',
            'listing_form[book][author]' => 'Brown',
            'listing_form[condition]' => 'good',
            'listing_form[book][papers]' => 'CHEM191, chemistry',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertAnySelectorTextContains('.form-field', '"chemistry" isn’t a paper code');

        $this->client->submitForm('Publish listing', ['listing_form[book][papers]' => 'CHEM191 & chem 192; CHEM191']);
        self::assertResponseRedirects();

        $this->manager->clear();
        $papers = static::getContainer()->get(PaperRepository::class)->findBy([], ['code' => 'ASC']);
        self::assertSame(['CHEM191', 'CHEM192'], array_map(static fn (Paper $p) => $p->getCode(), $papers));
        self::assertFalse($papers[0]->isOfficial(), 'Typed-in papers are added, but not as official ones.');
    }

    private function paperByCode(string $code): Paper
    {
        $this->manager->clear();

        return static::getContainer()->get(PaperRepository::class)->findOneBy(['code' => $code]);
    }

    /** @return list<array{code: string, title: ?string}> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
