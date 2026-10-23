<?php

namespace App\Scheduler;

use App\Audit\AuditLog;
use App\Repository\AuditEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneAuditLogHandler
{
    public function __construct(
        private readonly AuditEventRepository $events,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PruneAuditLog $message): void
    {
        $deleted = $this->events->deleteOlderThan(new \DateTimeImmutable(\sprintf('-%d days', AuditLog::RETENTION_DAYS)));
        $this->logger->info('Deleted {count} audit events older than {days} days.', ['count' => $deleted, 'days' => AuditLog::RETENTION_DAYS]);
    }
}
