<?php

namespace App\Listing;

use Psr\Clock\ClockInterface;

/** Dates are inclusive in New Zealand; expiresAt is the instant they become hidden. */
class ListingExpiry
{
    private const string TIMEZONE = 'Pacific/Auckland';

    public function __construct(private readonly ClockInterface $clock) {}

    public function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);
    }

    public function latestDate(): \DateTimeImmutable
    {
        return $this->today()->modify('+1 year');
    }

    public function dateFor(?\DateTimeImmutable $expiresAt): ?\DateTimeImmutable
    {
        return $expiresAt?->setTimezone(new \DateTimeZone(self::TIMEZONE))->modify('-1 microsecond')->setTime(0, 0);
    }

    public function expiresAfter(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $date = new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone(self::TIMEZONE));
        if ($date < $this->today() || $date > $this->latestDate()) {
            throw new \InvalidArgumentException('Choose a date between today and one year from today.');
        }

        return $date->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
    }
}
