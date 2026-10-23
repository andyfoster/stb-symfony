<?php

namespace App\Legacy;

use App\Entity\Book;
use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\Paper;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use App\Listing\PaperCatalog;
use App\Repository\BookRepository;
use App\Repository\ListingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Moves the old vanilla PHP site over from its MariaDB dump. Only listings it
 * still shows (`launch_table`) come across, with the accounts selling them
 * (`member_list`); everyone else's account is left behind. Every listing it
 * ever had goes into the Book list, which keeps no personal details.
 *
 * Safe to run again: accounts are matched by email and listings by their old
 * `listing_id`, and ones already here are left as they are, so changes made on
 * this site since are kept. Books are matched by Book::key() and get fresh
 * counts, keeping any title or author an admin fixed.
 */
class LegacyImporter
{
    /** The old site showed a listing for 8 weeks after it was added or refreshed. */
    public const int OLD_LIFETIME_DAYS = 56;

    /** `access` on the old site: 0 blocked the account from logging in (1 was an admin). */
    private const string ACCESS_DEACTIVATED = '0';

    /** Rows saved per flush; the entity manager is cleared between them to keep memory down. */
    private const int BATCH_SIZE = 500;

    /** @var array<string, Paper> by code, until the entity manager is next cleared */
    private array $paperCache = [];

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly UserRepository $users,
        private readonly ListingRepository $listings,
        private readonly BookRepository $books,
        private readonly PaperCatalog $papers,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param int $listedWithinDays listings added or refreshed this many days ago or since are current
     *
     * @return array{users_added: int, users_merged: int, users_existing: int, users_left: int, listings_added: int, listings_existing: int, listings_without_seller: int, books_added: int, books_updated: int}
     */
    public function import(string $file, bool $dryRun = false, int $listedWithinDays = self::OLD_LIFETIME_DAYS): array
    {
        $dump   = new MysqlDumpReader($file);
        $counts = array_fill_keys(['users_added', 'users_merged', 'users_existing', 'users_left', 'listings_added', 'listings_existing', 'listings_without_seller', 'books_added', 'books_updated'], 0);

        $listingRows = iterator_to_array($dump->rows('launch_table'), false);
        $since       = $this->clock->now()->modify(\sprintf('-%d days', $listedWithinDays));
        $current     = array_values(array_filter($listingRows, static fn (array $row): bool => $row['for_sale'] === 'y' && self::listedAt($row) >= $since));

        // The old site chose "show my number" per listing; this one has it per account,
        // so each account takes the choice from its latest listing.
        $showPhone = [];
        foreach ($current as $row) {
            $showPhone[(int) $row['user_id']] = $row['number_visible'] === 'y';
        }
        $resetEmails = [];
        foreach ($dump->rows('hash_table') as $row) {
            if ($row['used'] === '1') {
                $resetEmails[(int) $row['user_id']] = true;
            }
        }

        $this->manager->beginTransaction();
        try {
            $sellers = $this->importUsers($dump, $showPhone, $resetEmails, $counts);
            $this->importListings($current, $sellers, $counts);
            $this->importBooks($listingRows, $counts);
            $dryRun ? $this->manager->rollback() : $this->manager->commit();
        } catch (\Throwable $e) {
            $this->manager->rollback();
            throw $e;
        } finally {
            $this->manager->clear();
            $this->paperCache = [];
        }

        return $counts;
    }

    /**
     * Accounts with a current listing ($showPhone has their ids). Old listings
     * name sellers by legacy id, and several ids can share one email.
     *
     * @param array<int, bool>   $showPhone   by legacy user id
     * @param array<int, true>   $resetEmails legacy user ids that finished a password reset
     * @param array<string, int> $counts
     *
     * @return array<int, int> User ids by legacy user id
     */
    private function importUsers(MysqlDumpReader $dump, array $showPhone, array $resetEmails, array &$counts): array
    {
        /** @var array<string, list<array<string, string|null>>> $byEmail */
        $byEmail = [];
        foreach ($dump->rows('member_list') as $row) {
            $email = mb_strtolower(trim((string) $row['email']));
            if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 180) {
                $email = '#' . $row['user_id']; // left behind below: it has no account to sell from
            }
            $byEmail[$email][] = $row;
        }

        $existing = [];
        foreach ($this->users->findAll() as $user) {
            $existing[(string) $user->getEmail()] = $user;
        }

        $sellers = [];
        foreach ($byEmail as $email => $rows) {
            $ids = array_map(static fn (array $row): int => (int) $row['user_id'], $rows);
            if (str_starts_with($email, '#') || !array_any($ids, static fn (int $id): bool => isset($showPhone[$id]))) {
                ++$counts['users_left'];
                continue;
            }

            $counts['users_merged'] += \count($rows) - 1;
            // Duplicates keep the password and details of the one used most recently.
            usort($rows, static fn (array $a, array $b): int => [(int) $b['last_login'], (int) $b['user_id']] <=> [(int) $a['last_login'], (int) $a['user_id']]);

            $user = $existing[$email] ?? null;
            if ($user !== null) {
                ++$counts['users_existing'];
            } else {
                $selling = array_values(array_filter($ids, static fn (int $id): bool => isset($showPhone[$id])));
                $user    = $this->newUser($email, $rows[0], $showPhone[$selling[0]], array_any($ids, static fn (int $id): bool => isset($resetEmails[$id])));
                $this->manager->persist($user);
                ++$counts['users_added'];
            }
            foreach ($ids as $id) {
                $sellers[$id] = $user;
            }
        }
        $this->flushAndClear();

        return array_map(static fn (User $user): int => (int) $user->getId(), $sellers);
    }

    /**
     * @param array<string, string|null> $row
     */
    private function newUser(string $email, array $row, bool $showPhone, bool $verified): User
    {
        $name  = mb_substr(self::text($row['first_name']), 0, 80) ?: strstr($email, '@', true);
        $phone = self::text($row['phone']);
        $phone = preg_match('/^\+?[0-9 ()-]{6,}$/', $phone) ? $phone : null;
        $hash  = strtolower((string) $row['pass']);

        $user = (new User())
            ->setEmail($email)
            ->setDisplayName($name)
            // LegacySha1PasswordHasher checks these and swaps in a modern hash at first login.
            ->setPassword(preg_match('/^[0-9a-f]{40}$/', $hash) && $row['access'] !== self::ACCESS_DEACTIVATED ? $hash : null)
            ->setPhone($phone)
            ->setShowPhone($showPhone && $phone !== null)
            ->setVerified($verified);

        $joined = self::timestamp($row['join_time']) ?? self::dateTime($row['join_date']);
        if ($joined !== null) {
            $user->setCreatedAt($joined)->setUpdatedAt($joined);
        }

        return $user;
    }

    /**
     * @param list<array<string, string|null>> $rows    current listings
     * @param array<int, int>                  $sellers User ids by legacy user id
     * @param array<string, int>               $counts
     */
    private function importListings(array $rows, array $sellers, array &$counts): void
    {
        $known = array_flip(array_map(intval(...), $this->listings->createQueryBuilder('l')
            ->select('l.legacyId')->where('l.legacyId IS NOT NULL')
            ->getQuery()->getSingleColumnResult()));

        foreach ($rows as $row) {
            $legacyId = (int) $row['listing_id'];
            if (isset($known[$legacyId])) {
                ++$counts['listings_existing'];
                continue;
            }
            $known[$legacyId] = true;

            $book = (new BookDetails())
                ->setAuthor(mb_substr(self::text($row['author']), 0, 255))
                ->setEdition(self::edition((int) $row['edition']));
            foreach (self::paperCodes(self::text($row['papers'])) as $code) {
                $book->addPaper($this->paper($code));
            }

            $listing = (new Listing())
                ->setLegacyId($legacyId)
                ->setSeller(isset($sellers[(int) $row['user_id']]) ? $this->manager->getReference(User::class, $sellers[(int) $row['user_id']]) : null)
                ->setType(ListingType::ForSale)
                ->setCategory(Category::Textbooks)
                ->setTitle(mb_substr(self::text($row['title']), 0, 255))
                ->setDescription(self::text($row['comments']) ?: null)
                ->setPriceCents(max(0, (int) $row['price']) * 100)
                ->setCondition(self::condition((int) $row['quality']))
                ->setBook($book);

            // Expires when it would have on the old site; the seller can refresh it from then.
            $listedAt = self::listedAt($row) ?? $this->clock->now();
            $listing->publish(self::OLD_LIFETIME_DAYS, $listedAt);
            $listing->setCreatedAt(self::addedAt($row) ?? $listedAt)->setUpdatedAt($listedAt);

            $this->manager->persist($listing);
            ++$counts['listings_added'];
            if ($listing->getSeller() === null) {
                ++$counts['listings_without_seller'];
            }
            if ($counts['listings_added'] % self::BATCH_SIZE === 0) {
                $this->flushAndClear();
            }
        }
        $this->flushAndClear();
    }

    /**
     * One Book per book in every listing the old site had: listings with the
     * same Book::titleKey() whose authors share a surname. Ones taken down
     * ("for_sale = n") are the nearest thing to sold it recorded.
     *
     * @param list<array<string, string|null>> $rows
     * @param array<string, int>               $counts
     */
    private function importBooks(array $rows, array &$counts): void
    {
        // Books here by match key, including keys of Books an admin merged.
        $known = [];
        foreach ($this->books->createQueryBuilder('b')->select('b.id', 'b.matchKey', 'b.aliases')->getQuery()->getArrayResult() as $book) {
            foreach ([$book['matchKey'], ...$book['aliases']] as $key) {
                $known[$key] = $book['id'];
            }
        }

        // Several keys can land on one Book, so collect each Book's rows first.
        $targets = [];
        foreach (self::groupBooks($rows) as $key => $group) {
            $target = isset($known[$key]) ? 'id:' . $known[$key] : $key;
            $targets[$target] = [...$targets[$target] ?? [], ...$group];
        }

        $done = 0;
        foreach ($targets as $target => $group) {
            $book = str_starts_with((string) $target, 'id:') ? $this->books->find((int) substr((string) $target, 3)) : null;
            if ($book === null) {
                $book = new Book((string) $target);
                $book->setTitle(mb_substr(self::mostCommon($group, 'title'), 0, 255))
                    ->setAuthor(mb_substr(self::mostCommon($group, 'author'), 0, 255));
                $this->manager->persist($book);
                ++$counts['books_added'];
            } else {
                ++$counts['books_updated'];
            }

            $editions = array_unique(array_filter(array_map(static fn (array $row): ?string => self::edition((int) $row['edition']), $group)));
            natsort($editions);
            $prices = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['price'], $group), static fn (int $price): bool => $price > 0));
            sort($prices);
            $listed = array_filter(array_map(self::listedAt(...), $group));
            $added  = array_filter(array_map(self::addedAt(...), $group));

            $book->setEditions(mb_substr(implode(', ', $editions), 0, 255) ?: null)
                ->setTimesListed(\count($group))
                ->setTimesSold(\count(array_filter($group, static fn (array $row): bool => $row['for_sale'] !== 'y')))
                ->setMedianPriceCents($prices === [] ? null : $prices[intdiv(\count($prices), 2)] * 100)
                ->setFirstListedAt($added === [] ? null : min($added))
                ->setLastListedAt($listed === [] ? null : max($listed));
            foreach ($group as $row) {
                foreach (self::paperCodes(self::text($row['papers'])) as $code) {
                    $book->addPaper($this->paper($code));
                }
            }

            if (++$done % self::BATCH_SIZE === 0) {
                $this->flushAndClear();
            }
        }
        $this->manager->flush();
    }

    /**
     * Listings grouped into books (see Book::sameBook()), keyed by Book::titleKey()
     * and the group's most common surname. Ones with no author join the title's
     * biggest group.
     *
     * @param list<array<string, string|null>> $rows
     *
     * @return array<string, list<array<string, string|null>>>
     */
    private static function groupBooks(array $rows): array
    {
        /** @var array<string, list<array{surnames: array<string, int>, rows: list<array<string, string|null>>}>> $byTitle */
        $byTitle    = [];
        $authorless = [];
        foreach ($rows as $row) {
            $titleKey = Book::titleKey(self::text($row['title']));
            if ($titleKey === '') {
                continue;
            }
            $surnames = Book::surnames(self::text($row['author']));
            if ($surnames === []) {
                $authorless[$titleKey][] = $row;
                continue;
            }

            // Join every group sharing a surname with this listing into one.
            $merged = ['surnames' => array_fill_keys($surnames, 1), 'rows' => [$row]];
            foreach ($byTitle[$titleKey] ?? [] as $i => $group) {
                if (array_intersect_key($group['surnames'], $merged['surnames']) !== []) {
                    foreach ($group['surnames'] as $surname => $count) {
                        $merged['surnames'][$surname] = ($merged['surnames'][$surname] ?? 0) + $count;
                    }
                    $merged['rows'] = [...$group['rows'], ...$merged['rows']];
                    unset($byTitle[$titleKey][$i]);
                }
            }
            $byTitle[$titleKey][] = $merged;
        }

        foreach ($authorless as $titleKey => $listings) {
            $groups = $byTitle[$titleKey] ?? [];
            usort($groups, static fn (array $a, array $b): int => \count($b['rows']) <=> \count($a['rows']));
            if ($groups === []) {
                $groups = [['surnames' => [], 'rows' => []]];
            }
            $groups[0]['rows'] = [...$groups[0]['rows'], ...$listings];
            $byTitle[$titleKey] = $groups;
        }

        $books = [];
        foreach ($byTitle as $titleKey => $groups) {
            foreach ($groups as $group) {
                uksort($group['surnames'], static fn (string $a, string $b): int => [$group['surnames'][$b], $a] <=> [$group['surnames'][$a], $b]);
                $key         = mb_substr($titleKey . '|' . (array_key_first($group['surnames']) ?? ''), 0, 255);
                $books[$key] = [...$books[$key] ?? [], ...$group['rows']];
            }
        }

        return $books;
    }

    private function paper(string $code): Paper
    {
        return $this->paperCache[$code] ??= $this->papers->get($code);
    }

    private function flushAndClear(): void
    {
        $this->manager->flush();
        $this->manager->clear();
        $this->paperCache = [];
    }

    /**
     * Paper codes in the old free-text field: "HUBS191 & HUBS192", "PSYC111, 112"
     * (the subject carries over), "chem 191". Anything else is ignored.
     *
     * @return list<string>
     */
    public static function paperCodes(string $papers): array
    {
        preg_match_all('/(?<![A-Za-z])([A-Za-z]{4})?[\s-]*(?<!\d)(\d{3})(?!\d)/', $papers, $matches, \PREG_SET_ORDER);

        $codes   = [];
        $subject = null;
        foreach ($matches as $match) {
            $subject = $match[1] !== '' ? strtoupper($match[1]) : $subject;
            if ($subject !== null) {
                $codes[] = $subject . $match[2];
            }
        }

        return \array_slice(array_values(array_unique($codes)), 0, 8);
    }

    /**
     * The spelling most listings used, e.g. of a title; the latest listing's on a tie.
     *
     * @param list<array<string, string|null>> $rows
     */
    private static function mostCommon(array $rows, string $field): string
    {
        $tally = [];
        foreach ($rows as $row) {
            $value = self::text($row[$field]);
            $tally[$value] = [($tally[$value][0] ?? 0) + 1, max($tally[$value][1] ?? 0, (int) $row['listing_id'])];
        }
        uasort($tally, static fn (array $a, array $b): int => $b <=> $a);

        return (string) array_key_first($tally);
    }

    /** The old site rated copies 1 ("falling apart") to 5 ("looks like new"). */
    private static function condition(int $quality): Condition
    {
        return match (true) {
            $quality >= 5  => Condition::LikeNew,
            $quality === 4 => Condition::Good,
            $quality === 3 => Condition::Fair,
            default        => Condition::WellUsed,
        };
    }

    /** 6 → "6th", as people type it here; 0 meant none given, and some typed the year. */
    private static function edition(int $edition): ?string
    {
        if ($edition <= 0 || $edition > 30) {
            return null;
        }
        $suffix = \in_array($edition % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$edition % 10] ?? 'th');

        return $edition . $suffix;
    }

    /**
     * The old site escaped quotes again on top of MySQL's escaping, sometimes
     * more than once ("Don\'t", "O\\\'Shea").
     */
    private static function text(?string $value): string
    {
        return trim((string) preg_replace('/\\\\+([\'"])/', '$1', (string) $value));
    }

    /**
     * @param array<string, string|null> $row
     */
    private static function addedAt(array $row): ?\DateTimeImmutable
    {
        return self::timestamp($row['date_added']) ?? self::dateTime($row['created_at']) ?? self::dateTime($row['server_date']);
    }

    /**
     * When a listing last went up: refreshed, or else added.
     *
     * @param array<string, string|null> $row
     */
    private static function listedAt(array $row): ?\DateTimeImmutable
    {
        return max(self::timestamp($row['date_refreshed']), self::addedAt($row));
    }

    private static function timestamp(?string $value): ?\DateTimeImmutable
    {
        return $value !== null && ctype_digit($value) && (int) $value > 1 ? new \DateTimeImmutable('@' . $value) : null;
    }

    /** The dump's datetimes are UTC (it sets TIME_ZONE='+00:00'). */
    private static function dateTime(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) || str_starts_with($value, '0000')) {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
