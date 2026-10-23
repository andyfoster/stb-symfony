<?php

namespace App\Legacy;

use App\Entity\BookDetails;
use App\Entity\Listing;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\Condition;
use App\Enum\ListingType;
use App\Listing\PaperCatalog;
use App\Repository\ListingRepository;
use App\Repository\UserRepository;
use App\Settings\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Brings accounts (`member_list`) and listings (`launch_table`) over from the
 * old vanilla PHP site's MariaDB dump. Safe to run again: accounts are matched
 * by email and listings by their old `listing_id`, and ones already here are
 * left as they are, so changes made on this site since are kept.
 */
class LegacyImporter
{
    /** `access` on the old site: 0 blocked the account from logging in (1 was an admin). */
    private const string ACCESS_DEACTIVATED = '0';

    /** Listings saved per flush; the entity manager is cleared between them to keep memory down. */
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly UserRepository $users,
        private readonly ListingRepository $listings,
        private readonly PaperCatalog $papers,
        private readonly SiteSettingsProvider $settings,
    ) {}

    /**
     * @return array{users_added: int, users_merged: int, users_existing: int, users_skipped: int, listings_added: int, listings_existing: int, listings_without_seller: int}
     */
    public function import(string $file, bool $dryRun = false): array
    {
        $dump   = new MysqlDumpReader($file);
        $counts = array_fill_keys(['users_added', 'users_merged', 'users_existing', 'users_skipped', 'listings_added', 'listings_existing', 'listings_without_seller'], 0);

        $listingRows = iterator_to_array($dump->rows('launch_table'), false);
        // The old site chose "show my number" per listing; this one has it per account,
        // so each account takes the choice from its latest listing.
        $showPhone = [];
        foreach ($listingRows as $row) {
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
            $this->importListings($listingRows, $sellers, $counts);
            $dryRun ? $this->manager->rollback() : $this->manager->commit();
        } catch (\Throwable $e) {
            $this->manager->rollback();
            throw $e;
        } finally {
            $this->manager->clear();
        }

        return $counts;
    }

    /**
     * Old listings name sellers by legacy id; several ids can share one email.
     *
     * @param array<int, bool>  $showPhone   by legacy user id
     * @param array<int, true>  $resetEmails legacy user ids that finished a password reset
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
                ++$counts['users_skipped'];
                continue;
            }
            $byEmail[$email][] = $row;
        }

        $existing = [];
        foreach ($this->users->findAll() as $user) {
            $existing[(string) $user->getEmail()] = $user;
        }

        $sellers = [];
        foreach ($byEmail as $email => $rows) {
            $counts['users_merged'] += \count($rows) - 1;
            // Duplicates keep the password and details of the one used most recently.
            usort($rows, static fn (array $a, array $b): int => [(int) $b['last_login'], (int) $b['user_id']] <=> [(int) $a['last_login'], (int) $a['user_id']]);
            $ids = array_map(static fn (array $row): int => (int) $row['user_id'], $rows);

            $user = $existing[$email] ?? null;
            if ($user !== null) {
                ++$counts['users_existing'];
            } else {
                $user = $this->newUser($email, $rows[0], $showPhone[$ids[0]] ?? false, array_any($ids, static fn (int $id): bool => isset($resetEmails[$id])));
                $this->manager->persist($user);
                ++$counts['users_added'];
            }
            foreach ($ids as $id) {
                $sellers[$id] = $user;
            }
        }
        $this->manager->flush();
        $this->manager->clear();

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
     * @param list<array<string, string|null>> $rows
     * @param array<int, int>                  $sellers User ids by legacy user id
     * @param array<string, int>               $counts
     */
    private function importListings(array $rows, array $sellers, array &$counts): void
    {
        $known = array_flip(array_map(intval(...), $this->listings->createQueryBuilder('l')
            ->select('l.legacyId')->where('l.legacyId IS NOT NULL')
            ->getQuery()->getSingleColumnResult()));
        $lifetime = $this->settings->listingLifetimeDays();

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
                $book->addPaper($this->papers->get($code));
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

            // Listings showed for a while after they were added or last refreshed; ones
            // the seller took down ("for_sale = n") are the nearest thing to sold.
            $added     = self::timestamp($row['date_added']) ?? self::dateTime($row['created_at']) ?? self::dateTime($row['server_date']);
            $published = max(self::timestamp($row['date_refreshed']), $added);
            if ($published !== null) {
                $listing->publish($lifetime, $published);
                $listing->setCreatedAt($added ?? $published)->setUpdatedAt(max(self::timestamp($row['date_removed']), $published));
            }
            if ($row['for_sale'] !== 'y' || $published === null) {
                $listing->markSold();
            }

            $this->manager->persist($listing);
            ++$counts['listings_added'];
            if ($listing->getSeller() === null) {
                ++$counts['listings_without_seller'];
            }
            if ($counts['listings_added'] % self::BATCH_SIZE === 0) {
                $this->manager->flush();
                $this->manager->clear();
            }
        }
        $this->manager->flush();
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

    /** The old site rated copies 1 ("falling apart") to 5 ("looks like new"). */
    private static function condition(int $quality): Condition
    {
        return match (true) {
            $quality >= 5 => Condition::LikeNew,
            $quality === 4 => Condition::Good,
            $quality === 3 => Condition::Fair,
            default        => Condition::WellUsed,
        };
    }

    /** 6 → "6th", as people type it here; 0 meant none given. */
    private static function edition(int $edition): ?string
    {
        if ($edition <= 0) {
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
