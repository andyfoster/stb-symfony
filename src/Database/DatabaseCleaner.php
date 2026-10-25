<?php

namespace App\Database;

use App\Photo\PhotoStorage;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties every table, keeping the schema and migration history, and deletes
 * the photo files. Used by app:database:clear and app:seed.
 */
final readonly class DatabaseCleaner
{
    /** Doctrine Migrations' own table: the schema stays, so its history does too. */
    private const array KEEP_TABLES = ['doctrine_migration_versions'];

    public function __construct(
        private EntityManagerInterface $manager,
        private PhotoStorage $photos,
    ) {}

    /**
     * @return array{tables: int, photos: int} how many tables were emptied and photo folders removed
     *
     * @throws \LogicException on a database other than Postgres or SQLite
     */
    public function clear(): array
    {
        $connection = $this->manager->getConnection();
        $tables = array_values(array_diff($connection->createSchemaManager()->listTableNames(), self::KEEP_TABLES));
        $platform = $connection->getDatabasePlatform();
        $quoted = array_map($platform->quoteSingleIdentifier(...), $tables);

        if ($platform instanceof PostgreSQLPlatform) {
            // RESTART IDENTITY starts every id at 1 again.
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
            throw new \LogicException(\sprintf('Clearing a %s database isn\'t supported.', $platform::class));
        }
        $this->manager->clear();

        return ['tables' => \count($tables), 'photos' => $this->photos->prune([])];
    }
}
