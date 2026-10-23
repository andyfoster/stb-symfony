<?php

namespace App\Command;

use App\Legacy\LegacyImporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves the old site's current listings and their sellers over from its
 * MariaDB dump, and every book it ever listed into the Book list. Safe to run
 * again: accounts and listings already here are left alone.
 */
#[AsCommand(name: 'app:legacy:import', description: 'Import current listings, their sellers and the book list from the old site’s MariaDB dump')]
final class ImportLegacyCommand
{
    public function __construct(
        private readonly LegacyImporter $importer,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The .sql dump with member_list, launch_table and hash_table')]
        string $file,
        #[Option('Show what would be imported, then roll it back')]
        bool $dryRun = false,
        #[Option('Bring over listings added or refreshed within this many days (default: what the old site still shows)')]
        int $listedWithin = LegacyImporter::OLD_LIFETIME_DAYS,
    ): int {
        try {
            $counts = $this->importer->import($file, $dryRun, $listedWithin);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Listings added' => $counts['listings_added']],
            ['Listings already here' => $counts['listings_existing']],
            ['Listings with no seller' => $counts['listings_without_seller']],
            ['Sellers added' => $counts['users_added']],
            ['Sellers already here' => $counts['users_existing']],
            ['Duplicate emails merged' => $counts['users_merged']],
            ['Accounts left behind' => $counts['users_left']],
            ['Books added to the list' => $counts['books_added']],
            ['Books updated' => $counts['books_updated']],
        );
        $dryRun ? $io->note('Dry run: nothing was saved.') : $io->success('Imported.');

        return Command::SUCCESS;
    }
}
