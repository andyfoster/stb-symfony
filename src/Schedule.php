<?php

namespace App;

use App\Scheduler\SendExpiryReminders;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring jobs, run by the Messenger worker consuming the "scheduler_default"
 * transport (`symfony serve` locally, the Kamal "job" role in production).
 */
#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            // Every morning, New Zealand time. Safe to run again: each listing is only reminded once.
            ->add(RecurringMessage::every('1 day', new SendExpiryReminders(), new \DateTimeImmutable('today 09:00', new \DateTimeZone('Pacific/Auckland'))))
        ;
    }
}
