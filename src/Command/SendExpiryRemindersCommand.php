<?php

namespace App\Command;

use App\Listing\ExpiryReminder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The daily scheduled job (App\Schedule), on demand.
 */
#[AsCommand(name: 'app:listings:send-expiry-reminders', description: 'Email sellers whose listings expire soon')]
final class SendExpiryRemindersCommand
{
    public function __construct(private readonly ExpiryReminder $reminder) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $count = $this->reminder->send();
        $io->success(\sprintf('Emailed %d %s.', $count, $count === 1 ? 'seller' : 'sellers'));

        return Command::SUCCESS;
    }
}
