<?php

namespace App\Scheduler;

/**
 * Scheduled nightly (see App\Schedule): delete audit events older than AuditLog::RETENTION_DAYS.
 */
final readonly class PruneAuditLog {}
