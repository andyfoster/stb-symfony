<?php

namespace App\Command;

use App\Database\DatabaseCleaner;
use App\Entity\User;
use App\Listing\PaperCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Empties every table (keeping the schema and migration history), deletes the
 * photo files, reloads the Otago paper list and creates one admin, user #1.
 * For rehearsing the move from the old site: clear, then app:legacy:import.
 */
#[AsCommand(name: 'app:database:clear', description: 'Delete all data and photos, reload the paper list and create admin user #1')]
final class ClearDatabaseCommand
{
    public const string DEFAULT_EMAIL = 'admin@scarfietextbooks.com';
    public const string DEFAULT_PASSWORD = 'password';

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PaperCatalog $papers,
        private readonly DatabaseCleaner $cleaner,
        #[Autowire('%kernel.project_dir%/data/otago-papers.csv')]
        private readonly string $papersFile,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Email address of the admin account to create')]
        string $email = self::DEFAULT_EMAIL,
        #[Option('Password for the admin account')]
        string $password = self::DEFAULT_PASSWORD,
        #[Option('Don\'t ask for confirmation')]
        bool $force = false,
    ): int {
        $database = $this->manager->getConnection()->getDatabase();

        if (!$force && !$io->confirm(\sprintf('Delete every user, listing, message and photo in "%s"?', $database), false)) {
            $io->note('Nothing was changed.');

            return Command::FAILURE;
        }

        try {
            $cleared = $this->cleaner->clear();
        } catch (\LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $paperCounts = $this->papers->importCsv($this->papersFile);

        $admin = (new User())
            ->setEmail($email)
            ->setDisplayName('Admin')
            ->setVerified(true)
            ->setAdmin(true);
        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $this->manager->persist($admin);
        $this->manager->flush();

        $io->success([
            \sprintf('Emptied %d tables and removed %d photo folders.', $cleared['tables'], $cleared['photos']),
            \sprintf('Loaded %d papers.', $paperCounts['added']),
            \sprintf('Admin user #%d: %s, password %s.', $admin->getId(), $admin->getEmail(), $password === self::DEFAULT_PASSWORD ? '"'.self::DEFAULT_PASSWORD.'" (change it)' : 'as given'),
        ]);

        return Command::SUCCESS;
    }
}
