<?php

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\PasswordSetter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sets an account's password, e.g. for someone locked out who can't get the
 * reset email. They should change it straight away (Your details links to the
 * reset flow). Changing it also signs out their remembered devices.
 */
#[AsCommand(name: 'app:user:reset-password', description: 'Set an account\'s password (Password1! unless you pass --password)')]
final class ResetUserPasswordCommand
{
    public const string DEFAULT_PASSWORD = PasswordSetter::DEFAULT_PASSWORD;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordSetter $passwords,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address of the account')]
        string $email,
        #[Option('The new password')]
        string $password = self::DEFAULT_PASSWORD,
    ): int {
        $user = $this->users->findOneByEmail($email);
        if ($user === null) {
            $io->error(\sprintf('There is no account for %s.', $email));

            return Command::FAILURE;
        }

        $this->passwords->set($user, $password);

        $io->success(\sprintf('%s can now log in with %s. Ask them to change it.', $user->getEmail(), $password === self::DEFAULT_PASSWORD ? '"' . self::DEFAULT_PASSWORD . '"' : 'the new password'));

        return Command::SUCCESS;
    }
}
