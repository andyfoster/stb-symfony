<?php

namespace App\Entity;

use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Site-wide settings admins can change. There is only ever one row; get it
 * from SiteSettingsProvider.
 */
#[ORM\Entity(repositoryClass: SiteSettingsRepository::class)]
class SiteSettings extends Base
{
    /** Listings stay up for at most a year, the same limit as the hide date sellers choose. */
    public const int MAX_LISTING_LIFETIME_DAYS = 365;
    public const int DEFAULT_LISTING_LIFETIME_DAYS = self::MAX_LISTING_LIFETIME_DAYS;

    /** How long a published or refreshed listing stays up. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: self::MAX_LISTING_LIFETIME_DAYS)]
    private int $listingLifetimeDays = self::DEFAULT_LISTING_LIFETIME_DAYS;

    public function getListingLifetimeDays(): int
    {
        return $this->listingLifetimeDays;
    }

    public function setListingLifetimeDays(int $listingLifetimeDays): static
    {
        $this->listingLifetimeDays = $listingLifetimeDays;

        return $this;
    }

    public function getUnique(): string
    {
        return 'Site settings';
    }
}
