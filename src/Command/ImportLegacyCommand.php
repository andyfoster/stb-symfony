<?php

namespace App\Command;

use App\Legacy\LegacyImporter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports accounts and listings from the old site's MariaDB dump. Safe to run
 * again: accounts and listings already here are left alone.
 */
#[AsCommand(name: 'app:legacy:import', description: 'Import accounts and listings from the old site’s MariaDB dump')]
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
    ): int {
        try {
            $counts = $this->importer->import($file, $dryRun);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Accounts added' => $counts['users_added']],
            ['Duplicate emails merged' => $counts['users_merged']],
            ['Accounts already here' => $counts['users_existing']],
            ['Rows skipped (no valid email)' => $counts['users_skipped']],
            ['Listings added' => $counts['listings_added']],
            ['Listings already here' => $counts['listings_existing']],
            ['Listings with no seller' => $counts['listings_without_seller']],
        );
        $dryRun ? $io->note('Dry run: nothing was saved.') : $io->success('Imported.');

        return Command::SUCCESS;
    }
}
