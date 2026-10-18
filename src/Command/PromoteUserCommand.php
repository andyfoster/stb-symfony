<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Makes an account an admin (or not), e.g. to set up the first admin in production.
 */
#[AsCommand(name: 'app:user:promote', description: 'Give an account admin access (or take it away with --demote)')]
final class PromoteUserCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $manager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address of the account')] string $email,
        #[Option('Remove admin access instead')] bool $demote = false,
    ): int {
        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error(\sprintf('There is no account for %s.', $email));

            return Command::FAILURE;
        }

        $user->setAdmin(!$demote);
        $this->manager->flush();

        $io->success(\sprintf('%s is %s an admin.', $user->getEmail(), $demote ? 'no longer' : 'now'));

        return Command::SUCCESS;
    }
}
