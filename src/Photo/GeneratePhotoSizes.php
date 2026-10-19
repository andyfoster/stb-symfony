<?php

namespace App\Photo;

/**
 * Make (or remake) a photo's sizes, in the background on the Messenger worker.
 */
final readonly class GeneratePhotoSizes
{
    /**
     * @param list<string>|null $sizes null for all of PhotoStorage::SIZES
     */
    public function __construct(
        public int $photoId,
        public ?array $sizes = null,
    ) {
    }
}
