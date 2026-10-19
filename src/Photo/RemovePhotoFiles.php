<?php

namespace App\Photo;

use App\Entity\ListingPhoto;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Whenever a photo is deleted through Doctrine (from the listing form, the
 * admin, or with its listing), its files go too.
 */
#[AsEntityListener(event: Events::postRemove, entity: ListingPhoto::class)]
final class RemovePhotoFiles
{
    public function __construct(private readonly PhotoStorage $storage)
    {
    }

    public function postRemove(ListingPhoto $photo): void
    {
        $this->storage->delete($photo);
    }
}
