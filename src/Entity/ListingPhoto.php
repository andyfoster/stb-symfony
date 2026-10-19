<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A photo of a listing. The files live in PhotoStorage under a random name,
 * one per size, and are served by PhotoController.
 */
#[ORM\Entity]
class ListingPhoto extends Base
{
    #[ORM\ManyToOne(inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Listing $listing = null;

    /** Random hex name without size or extension, e.g. "3f9a…". */
    #[ORM\Column(length: 64)]
    private ?string $filename = null;

    #[ORM\Column]
    private int $position = 0;

    public function getListing(): ?Listing
    {
        return $this->listing;
    }

    public function setListing(?Listing $listing): static
    {
        $this->listing = $listing;

        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /** Whether everyone can see it: the listing is live and its seller has confirmed their email. */
    public function isPublic(): bool
    {
        return null !== $this->listing && $this->listing->arePhotosPublic() && $this->listing->isLive();
    }
}
