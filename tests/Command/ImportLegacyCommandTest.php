<?php

namespace App\Tests\Command;

use App\Entity\Book;
use App\Entity\Listing;
use App\Entity\Paper;
use App\Entity\User;
use App\Enum\Condition;
use App\Legacy\LegacyImporter;
use App\Repository\BookRepository;
use App\Repository\ListingRepository;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Imports tests/Fixtures/legacy-dump.sql, a few rows shaped like the old site's
 * MariaDB dump. Only listing 102 is current (refreshed 11 days before "now").
 */
final class ImportLegacyCommandTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use DatabaseTestTrait;

    private const string DUMP = __DIR__.'/../Fixtures/legacy-dump.sql';

    private CommandTester $command;

    protected function setUp(): void
    {
        $this->bootClient();
        self::mockTime('2025-10-20 00:00:00 UTC');
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:legacy:import'));
    }

    public function testBringsOverCurrentListingsAndTheirSellersOnly(): void
    {
        $this->import();

        $display = $this->command->getDisplay();
        self::assertMatchesRegularExpression('/Listings added\s+1\b/', $display);
        self::assertMatchesRegularExpression('/Sellers added\s+1\b/', $display);
        self::assertMatchesRegularExpression('/Accounts left behind\s+2\b/', $display);
        self::assertSame(['jo@example.com'], array_map(static fn (User $user): ?string => $user->getEmail(), $this->users()->findAll()));
        self::assertSame([102], array_map(static fn (Listing $listing): ?int => $listing->getLegacyId(), $this->listings()->findAll()));

        $listing = $this->listing(102);
        self::assertTrue($listing->isLive(new \DateTimeImmutable('2025-10-20 00:00:00 UTC')));
        self::assertSame('jo@example.com', $listing->getSeller()?->getEmail());
        self::assertSame("O'Shea, Moss", $listing->getBook()?->getAuthor());
        self::assertSame(1, $listing->getBook()?->getEdition());
        self::assertSame('An introduction to Māori culture.', $listing->getDescription());
        self::assertSame([0, Condition::WellUsed, []], [$listing->getPriceCents(), $listing->getCondition(), $this->paperCodes($listing->getBook()?->getPapers())]);
        // Published when it was last refreshed, with the new year-long lifetime.
        self::assertEquals(new \DateTimeImmutable('@1760000000'), $listing->getPublishedAt());
        self::assertEquals(new \DateTimeImmutable('@1760000000')->modify('+365 days'), $listing->getExpiresAt());
        self::assertEquals(new \DateTimeImmutable('@1700000000'), $listing->getCreatedAt());
    }

    public function testMergesEmailsThatDifferOnlyByCase(): void
    {
        $this->import();

        self::assertMatchesRegularExpression('/Duplicate emails merged\s+1\b/', $this->command->getDisplay());
        $jo = $this->user('jo@example.com');
        // The row logged into most recently wins.
        self::assertSame(['Jo B', '0215550101', true], [$jo->getDisplayName(), $jo->getPhone(), $jo->isShowPhone()]);
        self::assertSame(strtolower(sha1('hunter2')), $jo->getPassword());
        self::assertSame('2012-01-01', $jo->getCreatedAt()?->format('Y-m-d'));
        // Its older twin finished a password reset, so the address gets mail.
        self::assertTrue($jo->isVerified());
    }

    public function testLegacyPasswordLogsInAfterImport(): void
    {
        $this->import();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'JO@example.com', 'password' => 'hunter2']);

        self::assertResponseRedirects('/');
        self::assertStringStartsWith('$', $this->user('jo@example.com')->getPassword());
    }

    public function testAWiderWindowBringsOverOlderListings(): void
    {
        $this->import(['--listed-within' => 3 * 365]);

        self::assertMatchesRegularExpression('/Listings added\s+2\b/', $this->command->getDisplay());
        $aicha = $this->user('aicha@example.com');
        self::assertSame('Aïcha', $aicha->getDisplayName());
        self::assertNull($aicha->getPhone());
        self::assertFalse($aicha->isVerified());
        // Old admins aren't admins here.
        self::assertFalse($aicha->isAdmin());
        // Too old to show any more; the seller can refresh it.
        self::assertTrue($this->listing(104)->isExpired());
        self::assertSame(Condition::Good, $this->listing(104)->getCondition());
    }

    public function testListsEveryBookOnceWithItsHistory(): void
    {
        $this->import();

        self::assertMatchesRegularExpression('/Books added to the list\s+4\b/', $this->command->getDisplay());
        // Listings 100, 101 and 105 spell it three ways; the most common spelling wins.
        $book = $this->book('Principles of Anatomy and Physiology');
        self::assertSame(['Tortora & Derrickson', '13th', 3, 2, 10000], [$book->getAuthor(), $book->getEditions(), $book->getTimesListed(), $book->getTimesSold(), $book->getMedianPriceCents()]);
        self::assertSame(['HUBS191', 'HUBS192'], $this->paperCodes($book->getPapers()));
        self::assertEquals(new \DateTimeImmutable('@1300078200'), $book->getFirstListedAt());
        self::assertEquals(new \DateTimeImmutable('@1320000000'), $book->getLastListedAt());
        self::assertSame("O'Shaughnessy", $this->book('Media and Society')->getAuthor());
    }

    public function testBooksAreSuggestedAsTitles(): void
    {
        $this->import();

        $this->client->loginUser($this->user('jo@example.com'));
        $this->client->request('GET', '/sell/suggest-titles', ['q' => 'anatomy']);

        self::assertResponseIsSuccessful();
        $suggestions = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([['title' => 'Principles of Anatomy and Physiology', 'author' => 'Tortora & Derrickson', 'listings' => 3, 'sold' => 2]], $suggestions);
    }

    public function testBooksAreListedInTheAdmin(): void
    {
        $this->import();

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->client->request('GET', '/admin/book');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Principles of Anatomy and Physiology');
        self::assertSelectorTextContains('table', 'HUBS191, HUBS192');
    }

    public function testRunningAgainAddsNothingAndKeepsChanges(): void
    {
        $this->import();
        $this->user('jo@example.com')->setDisplayName('Jo Bloggs');
        $this->listing(102)->markSold();
        $this->book('Campbell Biology')->setTitle('Campbell Biology (Urry et al.)');
        $this->manager->flush();

        $this->import();

        $display = $this->command->getDisplay();
        self::assertMatchesRegularExpression('/Listings added\s+0\b/', $display);
        self::assertMatchesRegularExpression('/Listings already here\s+1\b/', $display);
        self::assertMatchesRegularExpression('/Sellers already here\s+1\b/', $display);
        self::assertMatchesRegularExpression('/Books added to the list\s+0\b/', $display);
        self::assertMatchesRegularExpression('/Books updated\s+4\b/', $display);
        self::assertCount(1, $this->users()->findAll());
        self::assertCount(4, $this->books()->findAll());
        self::assertSame('Jo Bloggs', $this->user('jo@example.com')->getDisplayName());
        self::assertTrue($this->listing(102)->isSold());
        self::assertSame(1, $this->book('Campbell Biology (Urry et al.)')->getTimesListed());
    }

    public function testDryRunSavesNothing(): void
    {
        $this->import(['--dry-run' => true]);

        self::assertMatchesRegularExpression('/Listings added\s+1\b/', $this->command->getDisplay());
        self::assertStringContainsString('nothing was saved', $this->command->getDisplay());
        self::assertSame([], $this->users()->findAll());
        self::assertSame([], $this->listings()->findAll());
        self::assertSame([], $this->books()->findAll());
    }

    public function testMissingFileFails(): void
    {
        $this->command->execute(['file' => __DIR__.'/nope.sql']);

        self::assertSame(1, $this->command->getStatusCode());
        self::assertStringContainsString('read', $this->command->getDisplay());
    }

    public function testLoginAndSignUpExplainOldAccountsWereDeleted(): void
    {
        foreach (['/login', '/register', '/reset-password'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorTextContains('.old-site-notice', 'we deleted old accounts', $page);
        }
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('paperFields')]
    public function testFindsPaperCodesInFreeText(string $papers, array $codes): void
    {
        self::assertSame($codes, LegacyImporter::paperCodes($papers));
    }

    /**
     * @return iterable<array{string, list<string>}>
     */
    public static function paperFields(): iterable
    {
        yield ['HUBS191 & HUBS192', ['HUBS191', 'HUBS192']];
        yield ['PSYC111, 112', ['PSYC111', 'PSYC112']];
        yield ['chem 191/ CHEM-192', ['CHEM191', 'CHEM192']];
        yield ['HUBS191 HUBS192 hubs191', ['HUBS191', 'HUBS192']];
        yield ['PUBH192 (HEAL192)', ['PUBH192', 'HEAL192']];
        yield ['Psych111, PHYSI191, Cells191', []];
        yield ['Nursing', []];
        yield ['', []];
    }

    #[DataProvider('sameBooks')]
    public function testMatchesTheSameBookTypedDifferently(string $title, string $author, string $otherTitle, string $otherAuthor): void
    {
        self::assertTrue(Book::sameBook($title, $author, $otherTitle, $otherAuthor));
    }

    /**
     * @return iterable<array{string, string, string, string}>
     */
    public static function sameBooks(): iterable
    {
        yield ['Campbell Biology (10th edition)', 'N. A. Campbell & Reece', 'campbell biology', 'Campbell, Reece'];
        yield ['Campbell Biology 9e CELS191', 'Campbell Reece', 'Campbell Biology: Global Edition 2013', 'Reece, Urry, Cain'];
        yield ['The Physiology', 'Berne and Levy', 'Physiology, 6th ed.', 'Berne & Levy'];
        yield ['Vis-à-Vis: Beginning French', 'Amon', 'Vis a Vis Beginning French', 'Amon et al'];
        yield ['Anatomy & Physiology', 'Patton, Thibodeau', 'Anatomy and Physiology', 'Thibideau, Patton'];
        yield ['Essential Epidemiology', 'Penny Webb and Chris Bain', 'Essential Epidemiology, second edition', 'Webb, Bain'];
        yield ['Marketing', '', 'Marketing', 'Kotler'];
    }

    public function testTellsDifferentBooksApart(): void
    {
        self::assertFalse(Book::sameBook('Chemistry', 'Blackman', 'Chemistry', 'Brown & LeMay'));
        self::assertFalse(Book::sameBook('Biology', 'Campbell', 'Human Biology', 'Campbell'));
    }

    public function testAdminsMergeBooksThatAreReallyOne(): void
    {
        $this->import();
        $kept = $this->book('Principles of Anatomy and Physiology');
        $merged = $this->book('Writing for Psychology');
        $ids = [$kept->getId(), $merged->getId()];

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $token = $this->client->request('GET', '/admin/book')->filter('[data-action-name="mergeSelected"]')->attr('data-action-csrf-token');
        $this->client->request('POST', '/admin/book/merge-selected', [
            'batchActionName' => 'mergeSelected',
            'batchActionEntityIds' => $ids,
            'entityFqcn' => Book::class,
            'batchActionCsrfToken' => $token,
        ]);

        self::assertResponseRedirects();
        $this->manager->clear();
        $kept = $this->book('Principles of Anatomy and Physiology');
        self::assertSame([4, 2, '1st, 13th'], [$kept->getTimesListed(), $kept->getTimesSold(), $kept->getEditions()]);
        self::assertNull($this->books()->findOneBy(['title' => 'Writing for Psychology']));

        // Importing again adds to the merged book instead of bringing the other back.
        $this->import();
        self::assertMatchesRegularExpression('/Books added to the list\s+0\b/', $this->command->getDisplay());
        self::assertSame(4, $this->book('Principles of Anatomy and Physiology')->getTimesListed());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function import(array $options = []): void
    {
        $this->command->execute(['file' => self::DUMP, ...$options]);
        $this->command->assertCommandIsSuccessful();
    }

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }

    private function listings(): ListingRepository
    {
        return static::getContainer()->get(ListingRepository::class);
    }

    private function books(): BookRepository
    {
        return static::getContainer()->get(BookRepository::class);
    }

    private function user(string $email): User
    {
        return $this->users()->findOneByEmail($email) ?? self::fail("No account for $email.");
    }

    private function listing(int $legacyId): Listing
    {
        return $this->listings()->findOneBy(['legacyId' => $legacyId]) ?? self::fail("No listing $legacyId.");
    }

    private function book(string $title): Book
    {
        return $this->books()->findOneBy(['title' => $title]) ?? self::fail("No book called $title.");
    }

    /**
     * @param iterable<Paper>|null $papers
     *
     * @return list<string>
     */
    private function paperCodes(?iterable $papers): array
    {
        $codes = [];
        foreach ($papers ?? [] as $paper) {
            $codes[] = (string) $paper->getCode();
        }

        return $codes;
    }
}
