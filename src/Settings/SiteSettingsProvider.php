<?php

namespace App\Settings;

use App\Entity\SiteSettings;
use App\Repository\SiteSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The single SiteSettings row, created with defaults the first time it's needed.
 */
class SiteSettingsProvider
{
    private ?SiteSettings $settings = null;

    public function __construct(
        private readonly SiteSettingsRepository $repository,
        private readonly EntityManagerInterface $manager,
    ) {
    }

    public function get(): SiteSettings
    {
        if (null !== $this->settings) {
            return $this->settings;
        }

        $settings = $this->repository->findOneBy([], ['id' => 'ASC']);
        if (null === $settings) {
            $settings = new SiteSettings();
            $this->manager->persist($settings);
            $this->manager->flush();
        }

        return $this->settings = $settings;
    }

    public function listingLifetimeDays(): int
    {
        // Clamped as well as validated: a row saved before the one-year limit may be longer.
        return min(SiteSettings::MAX_LISTING_LIFETIME_DAYS, max(1, $this->get()->getListingLifetimeDays()));
    }
}
