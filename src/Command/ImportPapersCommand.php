<?php

namespace App\Command;

use App\Listing\PaperCatalog;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads Otago papers from a CSV of "code,title" rows (with a header row).
 * Safe to run again: known papers get their titles updated and are marked
 * official; papers people typed in that turn out to be real are kept.
 */
#[AsCommand(name: 'app:papers:import', description: 'Import University of Otago papers from a code,title CSV')]
final class ImportPapersCommand
{
    public function __construct(
        private readonly PaperCatalog $catalog,
        #[Autowire('%kernel.project_dir%/data/otago-papers.csv')]
        private readonly string $defaultFile,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('CSV file with code,title rows (defaults to data/otago-papers.csv)')]
        ?string $file = null,
    ): int {
        try {
            $counts = $this->catalog->importCsv($file ?? $this->defaultFile);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d papers added, %d updated, %d rows skipped.', $counts['added'], $counts['updated'], $counts['skipped']));

        return Command::SUCCESS;
    }
}
