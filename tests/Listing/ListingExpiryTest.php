<?php

namespace App\Tests\Listing;

use App\Listing\ListingExpiry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ListingExpiryTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testHideDateIncludesTheWholeLocalDayAcrossDaylightSaving(): void
    {
        self::mockTime('2026-09-01 00:00:00 UTC');
        self::bootKernel();
        $policy = self::getContainer()->get(ListingExpiry::class);
        $expiry = $policy->expiresAfter(new \DateTimeImmutable('2026-09-27'));
        self::assertSame('2026-09-27 11:00:00 +00:00', $expiry->format('Y-m-d H:i:s P'));
        self::assertSame('2026-09-27', $policy->dateFor($expiry)->format('Y-m-d'));
    }

    public function testBoundsUseTheNewZealandDateAndRejectBeyondAYear(): void
    {
        self::mockTime('2026-10-24 11:30:00 UTC');
        self::bootKernel();
        $policy = self::getContainer()->get(ListingExpiry::class);
        self::assertSame('2026-10-25', $policy->today()->format('Y-m-d'));
        self::assertSame('2027-10-25', $policy->latestDate()->format('Y-m-d'));
        self::assertSame('2027-10-26 00:00', $policy->expiresAfter($policy->latestDate())->setTimezone(new \DateTimeZone('Pacific/Auckland'))->format('Y-m-d H:i'));
        $this->expectException(\InvalidArgumentException::class);
        $policy->expiresAfter(new \DateTimeImmutable('2027-10-26'));
    }
}
