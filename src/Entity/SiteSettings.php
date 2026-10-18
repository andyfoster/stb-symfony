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
    public const int DEFAULT_LISTING_LIFETIME_DAYS = 42;

    /** How long a published or refreshed listing stays up. */
    #[ORM\Column]
    #[Assert\Range(min: 1, max: 365)]
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
