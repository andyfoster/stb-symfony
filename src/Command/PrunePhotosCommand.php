<?php

namespace App\Command;

use App\Entity\ListingPhoto;
use App\Photo\PhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes photo files that no listing photo points at any more, e.g. after
 * a user was deleted (the database removes their photos without Doctrine).
 */
#[AsCommand(name: 'app:photos:prune', description: 'Delete photo files with no photo in the database')]
final class PrunePhotosCommand
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PhotoStorage $storage,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $known = array_column(
            $this->manager->createQuery('SELECT p.filename FROM '.ListingPhoto::class.' p')->getScalarResult(),
            'filename',
        );

        $io->success(\sprintf('Removed %d unused photo folders.', $this->storage->prune($known)));

        return Command::SUCCESS;
    }
}
