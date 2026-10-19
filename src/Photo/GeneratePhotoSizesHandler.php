<?php

namespace App\Photo;

use App\Entity\ListingPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GeneratePhotoSizesHandler
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PhotoStorage $storage,
    ) {
    }

    public function __invoke(GeneratePhotoSizes $message): void
    {
        // Deleted since it was queued: nothing to do.
        $photo = $this->manager->find(ListingPhoto::class, $message->photoId);
        if (null !== $photo) {
            $this->storage->generate($photo, $message->sizes);
        }
    }
}
