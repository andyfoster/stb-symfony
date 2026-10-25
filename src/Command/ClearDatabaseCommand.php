<?php

namespace App\Command;

use App\Entity\User;
use App\Listing\PaperCatalog;
use App\Photo\PhotoStorage;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
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

    /** Doctrine Migrations' own table: the schema stays, so its history does too. */
    private const array KEEP_TABLES = ['doctrine_migration_versions'];

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly PaperCatalog $papers,
        private readonly PhotoStorage $photos,
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
        $connection = $this->manager->getConnection();
        $database = $connection->getDatabase();

        if (!$force && !$io->confirm(\sprintf('Delete every user, listing, message and photo in "%s"?', $database), false)) {
            $io->note('Nothing was changed.');

            return Command::FAILURE;
        }

        $tables = array_values(array_diff($connection->createSchemaManager()->listTableNames(), self::KEEP_TABLES));
        $platform = $connection->getDatabasePlatform();
        $quoted = array_map($platform->quoteSingleIdentifier(...), $tables);

        if ($platform instanceof PostgreSQLPlatform) {
            // RESTART IDENTITY starts every id at 1 again, so the admin below is user #1.
            $connection->executeStatement(\sprintf('TRUNCATE %s RESTART IDENTITY CASCADE', implode(', ', $quoted)));
        } elseif ($platform instanceof SQLitePlatform) {
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
            foreach ($quoted as $table) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
            if ($connection->fetchOne("SELECT 1 FROM sqlite_master WHERE name = 'sqlite_sequence'")) {
                $connection->executeStatement('DELETE FROM sqlite_sequence');
            }
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        } else {
            $io->error(\sprintf('Clearing a %s database isn\'t supported.', $platform::class));

            return Command::FAILURE;
        }
        $this->manager->clear();

        $removedPhotos = $this->photos->prune([]);
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
            \sprintf('Emptied %d tables and removed %d photo folders.', \count($tables), $removedPhotos),
            \sprintf('Loaded %d papers.', $paperCounts['added']),
            \sprintf('Admin user #%d: %s, password %s.', $admin->getId(), $admin->getEmail(), $password === self::DEFAULT_PASSWORD ? '"'.self::DEFAULT_PASSWORD.'" (change it)' : 'as given'),
        ]);

        return Command::SUCCESS;
    }
}
