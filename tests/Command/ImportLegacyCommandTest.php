<?php

namespace App\Tests\Command;

use App\Entity\Listing;
use App\Entity\User;
use App\Enum\Condition;
use App\Enum\ListingStatus;
use App\Legacy\LegacyImporter;
use App\Repository\ListingRepository;
use App\Repository\UserRepository;
use App\Tests\DatabaseTestTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Imports tests/Fixtures/legacy-dump.sql, a few rows shaped like the old site's
 * MariaDB dump.
 */
final class ImportLegacyCommandTest extends WebTestCase
{
    use DatabaseTestTrait;

    private const string DUMP = __DIR__ . '/../Fixtures/legacy-dump.sql';

    private CommandTester $command;

    protected function setUp(): void
    {
        $this->bootClient();
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:legacy:import'));
    }

    public function testMergesEmailsThatDifferOnlyByCase(): void
    {
        $this->import();

        $display = $this->command->getDisplay();
        self::assertMatchesRegularExpression('/Accounts added\s+2\b/', $display);
        self::assertMatchesRegularExpression('/Duplicate emails merged\s+1\b/', $display);
        self::assertMatchesRegularExpression('/Rows skipped \(no valid email\)\s+1\b/', $display);

        $jo = $this->user('jo@example.com');
        // The account logged into most recently wins.
        self::assertSame(['Jo B', '0215550101', true], [$jo->getDisplayName(), $jo->getPhone(), $jo->isShowPhone()]);
        self::assertSame(strtolower(sha1('hunter2')), $jo->getPassword());
        // Its older twin finished a password reset, so the address gets mail.
        self::assertTrue($jo->isVerified());
        self::assertSame('2012-01-01', $jo->getCreatedAt()?->format('Y-m-d'));

        $aicha = $this->user('aicha@example.com');
        self::assertSame('Aïcha', $aicha->getDisplayName());
        self::assertNull($aicha->getPhone());
        self::assertFalse($aicha->isVerified());
        self::assertFalse($aicha->isAdmin());
        self::assertNull($this->users()->findOneByEmail('test@test'));
    }

    public function testLegacyPasswordLogsInAfterImport(): void
    {
        $this->import();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'JO@example.com', 'password' => 'hunter2']);

        self::assertResponseRedirects('/');
        self::assertStringStartsWith('$', $this->user('jo@example.com')->getPassword());
    }

    public function testImportsListingsAsTextbooks(): void
    {
        $this->import();

        $sold = $this->listing(100);
        self::assertSame(ListingStatus::Sold, $sold->getStatus());
        self::assertSame('jo@example.com', $sold->getSeller()?->getEmail());
        self::assertSame(12000, $sold->getPriceCents());
        self::assertSame(Condition::LikeNew, $sold->getCondition());
        self::assertSame("Don't get caught up in the pre-exam library rush!\r\nHardly used.", $sold->getDescription());
        self::assertSame(['Tortora & Derrickson', '13th'], [$sold->getBook()?->getAuthor(), $sold->getBook()?->getEdition()]);
        self::assertSame(['HUBS191', 'HUBS192'], $this->paperCodes($sold));
        self::assertSame('2011-03-14 04:50:00', $sold->getPublishedAt()?->format('Y-m-d H:i:s'));

        // Its seller signed up twice; both rows' listings land on the one account.
        $other = $this->listing(101);
        self::assertSame($sold->getSeller(), $other->getSeller());
        self::assertSame(['HUBS191', 'HUBS192'], $this->paperCodes($other));
        self::assertSame("Some (light) highlighting, 'tidy'", $other->getDescription());
        self::assertSame(Condition::Fair, $other->getCondition());

        $active = $this->listing(102);
        self::assertSame(ListingStatus::Active, $active->getStatus());
        self::assertSame("O'Shea, Moss", $active->getBook()?->getAuthor());
        self::assertSame('1st', $active->getBook()?->getEdition());
        self::assertSame('An introduction to Māori culture.', $active->getDescription());
        self::assertSame([0, Condition::WellUsed, []], [$active->getPriceCents(), $active->getCondition(), $this->paperCodes($active)]);
        // Published when it was last refreshed, for the site's listing lifetime.
        self::assertEquals(new \DateTimeImmutable('@1760000000'), $active->getPublishedAt());
        self::assertEquals(new \DateTimeImmutable('@1760000000')->modify('+42 days'), $active->getExpiresAt());
        self::assertEquals(new \DateTimeImmutable('@1700000000'), $active->getCreatedAt());

        $orphan = $this->listing(103);
        self::assertNull($orphan->getSeller());
        self::assertNull($orphan->getDescription());
        self::assertSame("O'Shaughnessy", $orphan->getBook()?->getAuthor());
    }

    public function testSoldListingsAreSuggestedAsTitles(): void
    {
        $this->import();

        $this->client->loginUser($this->user('aicha@example.com'));
        $this->client->request('GET', '/sell/suggest-titles', ['q' => 'anatomy']);

        self::assertResponseIsSuccessful();
        $suggestions = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([['title' => 'Principles of Anatomy and Physiology', 'author' => 'Tortora & Derrickson', 'listings' => 2, 'sold' => 2]], $suggestions);
    }

    public function testRunningAgainAddsNothingAndKeepsChanges(): void
    {
        $this->import();
        $jo = $this->user('jo@example.com')->setDisplayName('Jo Bloggs');
        $this->listing(102)->markSold();
        $this->manager->flush();

        $this->import();

        $display = $this->command->getDisplay();
        self::assertMatchesRegularExpression('/Accounts added\s+0\b/', $display);
        self::assertMatchesRegularExpression('/Accounts already here\s+2\b/', $display);
        self::assertMatchesRegularExpression('/Listings added\s+0\b/', $display);
        self::assertMatchesRegularExpression('/Listings already here\s+4\b/', $display);
        self::assertCount(2, $this->users()->findAll());
        self::assertCount(4, $this->listings()->findAll());
        self::assertSame('Jo Bloggs', $this->user('jo@example.com')->getDisplayName());
        self::assertTrue($this->listing(102)->isSold());
    }

    public function testDryRunSavesNothing(): void
    {
        $this->command->execute(['file' => self::DUMP, '--dry-run' => true]);

        $this->command->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/Listings added\s+4\b/', $this->command->getDisplay());
        self::assertStringContainsString('nothing was saved', $this->command->getDisplay());
        self::assertSame([], $this->users()->findAll());
        self::assertSame([], $this->listings()->findAll());
    }

    public function testMissingFileFails(): void
    {
        $this->command->execute(['file' => __DIR__ . '/nope.sql']);

        self::assertSame(1, $this->command->getStatusCode());
        self::assertStringContainsString('read', $this->command->getDisplay());
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

    private function import(): void
    {
        $this->command->execute(['file' => self::DUMP]);
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

    private function user(string $email): User
    {
        return $this->users()->findOneByEmail($email) ?? self::fail("No account for $email.");
    }

    private function listing(int $legacyId): Listing
    {
        return $this->listings()->findOneBy(['legacyId' => $legacyId]) ?? self::fail("No listing $legacyId.");
    }

    /**
     * @return list<string>
     */
    private function paperCodes(Listing $listing): array
    {
        return $listing->getBook()?->getPapers()->map(static fn ($paper): string => (string) $paper->getCode())->getValues() ?? [];
    }
}
