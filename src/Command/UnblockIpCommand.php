<?php

namespace App\Command;

use App\Security\IpBlocklist;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Unblocks an address from the command line, for when an admin's own
 * connection got blocked and they can't log in to /admin to undo it.
 */
#[AsCommand(name: 'app:ip:unblock', description: 'Unblock an IP address or range (Blocked IPs in /admin)')]
final class UnblockIpCommand
{
    public function __construct(private readonly IpBlocklist $blocklist) {}

    public function __invoke(SymfonyStyle $io, #[Argument('The address or range, exactly as listed under Blocked IPs')] string $address): int
    {
        if (!$this->blocklist->remove($address)) {
            $io->error(\sprintf('%s isn’t blocked.', $address));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Unblocked %s.', $address));

        return Command::SUCCESS;
    }
}
