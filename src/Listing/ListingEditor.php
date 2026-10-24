<?php

namespace App\Listing;

use App\Audit\AuditLog;
use App\Entity\Listing;
use App\Entity\ListingPhoto;
use App\Enum\AuditType;
use App\Enum\ListingStatus;
use App\Photo\GeneratePhotoSizes;
use App\Photo\PhotoStorage;
use App\Settings\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Saves listings from the form, and publishes, refreshes, sells and archives them,
 * recording each in the audit log.
 */
class ListingEditor
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PhotoStorage $photos,
        private readonly SiteSettingsProvider $settings,
        private readonly MessageBusInterface $bus,
        private readonly AuditLog $audit,
        private readonly ListingExpiry $expiry,
    ) {
    }

    /**
     * @param UploadedFile[] $newPhotos
     * @param ListingPhoto[] $removedPhotos
     */
    public function save(Listing $listing, bool $publish, array $newPhotos = [], array $removedPhotos = [], ?\DateTimeImmutable $hideAfter = null): void
    {
        $isNew = null === $listing->getId();
        $wasLive = !$isNew && $listing->isLive();

        // Their files go when Doctrine removes them (RemovePhotoFiles).
        $removedIds = [];
        foreach ($removedPhotos as $photo) {
            $removedIds[] = $photo->getId();
            $listing->removePhoto($photo);
        }

        $added = [];
        foreach ($newPhotos as $file) {
            $added[] = $this->photos->add($listing, $file);
        }

        if (!$publish) {
            $listing->setStatus(ListingStatus::Draft);
        } elseif (null !== $listing->getPublishedAt() && $listing->getExpiresAt() > new \DateTimeImmutable()) {
            // Keep its place in the list; an explicitly chosen hide date is applied below.
            $listing->setStatus(ListingStatus::Active);
        } else {
            // A new draft, or re-listing an expired one.
            $listing->publish($this->settings->listingLifetimeDays());
        }

        // A newly chosen hide date moves the expiry but never the publication date. The form
        // shows the current date, so leaving it alone doesn't resend the expiry reminder.
        if (null !== $hideAfter && $hideAfter != $this->expiry->dateFor($listing->getExpiresAt())) {
            $listing->setExpiresAt($this->expiry->expiresAfter($hideAfter))->setExpiryReminderSentAt(null);
        }

        $this->manager->persist($listing);
        $this->manager->flush();

        $this->audit->record($isNew ? AuditType::ListingCreated : AuditType::ListingUpdated, $listing, ['status' => $listing->getStatus()->value, 'expiresAt' => $listing->getExpiresAt()?->format(\DATE_ATOM)]);
        if (!$wasLive && $listing->isLive()) {
            $this->audit->record(AuditType::ListingPublished, $listing, ['expiresAt' => $listing->getExpiresAt()?->format(\DATE_ATOM)]);
        }
        foreach ($removedIds as $id) {
            $this->audit->record(AuditType::PhotoRemoved, $listing, ['photo' => $id]);
        }

        // The sizes pages show are made in the background, now the photos have ids.
        foreach ($added as $photo) {
            $this->audit->record(AuditType::PhotoAdded, $listing, ['photo' => $photo->getId()]);
            $this->bus->dispatch(new GeneratePhotoSizes((int) $photo->getId()));
        }
    }

    public function refresh(Listing $listing): void
    {
        $listing->refresh($this->settings->listingLifetimeDays());
        $this->manager->flush();
        $this->audit->record(AuditType::ListingRefreshed, $listing, ['expiresAt' => $listing->getExpiresAt()?->format(\DATE_ATOM)]);
    }

    public function markSold(Listing $listing): void
    {
        $listing->markSold();
        $this->manager->flush();
        $this->audit->record(AuditType::ListingSold, $listing);
    }

    public function archive(Listing $listing): void
    {
        $listing->setStatus(ListingStatus::Archived);
        $this->manager->flush();
        $this->audit->record(AuditType::ListingArchived, $listing);
    }
}
