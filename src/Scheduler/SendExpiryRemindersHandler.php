<?php

namespace App\Scheduler;

use App\Listing\ExpiryReminder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendExpiryRemindersHandler
{
    public function __construct(
        private readonly ExpiryReminder $reminder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendExpiryReminders $message): void
    {
        $this->logger->info('Sent listing expiry reminders to {count} sellers.', ['count' => $this->reminder->send()]);
    }
}
