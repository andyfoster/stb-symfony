<?php

namespace App\Listing;

use App\Entity\Listing;
use App\Entity\ListingPhoto;
use App\Enum\ListingStatus;
use App\Photo\PhotoStorage;
use App\Settings\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Saves listings from the form, and publishes, refreshes, sells and deletes them.
 */
class ListingEditor
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PhotoStorage $photos,
        private readonly SiteSettingsProvider $settings,
    ) {
    }

    /**
     * @param UploadedFile[] $newPhotos
     * @param ListingPhoto[] $removedPhotos
     */
    public function save(Listing $listing, bool $publish, array $newPhotos = [], array $removedPhotos = []): void
    {
        foreach ($removedPhotos as $photo) {
            $this->photos->delete($photo);
            $listing->removePhoto($photo);
        }

        foreach ($newPhotos as $file) {
            $this->photos->add($listing, $file);
        }

        if (!$publish) {
            $listing->setStatus(ListingStatus::Draft);
        } elseif ($listing->getExpiresAt() > new \DateTimeImmutable()) {
            // Back from drafts (or sold) before it expired: same place and expiry as before,
            // so moving to drafts and back isn't a way around the refresh window.
            $listing->setStatus(ListingStatus::Active);
        } else {
            // A new draft, or re-listing an expired one.
            $listing->publish($this->settings->listingLifetimeDays());
        }

        $this->manager->persist($listing);
        $this->manager->flush();
    }

    public function refresh(Listing $listing): void
    {
        $listing->refresh($this->settings->listingLifetimeDays());
        $this->manager->flush();
    }

    public function markSold(Listing $listing): void
    {
        $listing->markSold();
        $this->manager->flush();
    }

    public function delete(Listing $listing): void
    {
        foreach ($listing->getPhotos() as $photo) {
            $this->photos->delete($photo);
        }

        $this->manager->remove($listing);
        $this->manager->flush();
    }
}
