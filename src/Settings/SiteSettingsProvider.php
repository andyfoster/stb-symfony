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
    ) {}

    public function get(): SiteSettings
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $settings = $this->repository->findOneBy([], ['id' => 'ASC']);
        if ($settings === null) {
            $settings = new SiteSettings();
            $this->manager->persist($settings);
            $this->manager->flush();
        }

        return $this->settings = $settings;
    }

    public function listingLifetimeDays(): int
    {
        return $this->get()->getListingLifetimeDays();
    }
}
