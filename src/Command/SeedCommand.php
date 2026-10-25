<?php

namespace App\Command;

use App\Database\DatabaseCleaner;
use App\Seed\ExampleData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Replaces all data with the example sellers, listings, photos and messages
 * (App\Seed\ExampleData). Runs in dev and test, and in prod only where
 * ALLOW_SEED is set (the test site), never on the live site.
 */
#[AsCommand(name: 'app:seed', description: 'Replace all data with example listings, sellers, photos and messages (not in production)')]
final class SeedCommand
{
    public const string DEV_PASSWORD = 'password';

    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly DatabaseCleaner $cleaner,
        private readonly ExampleData $exampleData,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%env(bool:ALLOW_SEED)%')]
        private readonly bool $allowed,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Password for every example account (default "password" locally, a random one elsewhere)')]
        ?string $password = null,
        #[Option('Don\'t ask for confirmation')]
        bool $force = false,
    ): int {
        $local = \in_array($this->environment, ['dev', 'test'], true);
        if (!$local && !$this->allowed) {
            $io->error('Seeding deletes everything, so it only runs locally or where ALLOW_SEED=1 (the test site), never in production.');

            return Command::FAILURE;
        }

        $database = $this->manager->getConnection()->getDatabase();
        if (!$force && !$io->confirm(\sprintf('Delete everything in "%s" and load the example data?', $database), false)) {
            $io->note('Nothing was changed.');

            return Command::FAILURE;
        }

        // On a server anyone can reach, the example logins mustn't have a guessable password.
        $given = $password !== null;
        $password ??= $local ? self::DEV_PASSWORD : bin2hex(random_bytes(6));

        try {
            $cleared = $this->cleaner->clear();
        } catch (\LogicException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $loaded = $this->exampleData->load($password);

        $io->success([
            \sprintf('Emptied %d tables and removed %d photo folders.', $cleared['tables'], $cleared['photos']),
            \sprintf('Loaded %d users, %d listings, %d photos and %d messages.', $loaded['users'], $loaded['listings'], $loaded['photos'], $loaded['messages']),
            \sprintf('Log in as sam@example.com (or admin@example.com, the admin) with the password %s.', $given ? 'you gave' : '"'.$password.'"'),
        ]);

        return Command::SUCCESS;
    }
}
