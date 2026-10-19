<?php

namespace App\Photo;

use App\Entity\Listing;
use App\Entity\ListingPhoto;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Saves listing photos to disk (a Kamal volume in production). Each upload is
 * auto-rotated and re-encoded as WebP, which also drops EXIF data such as GPS.
 */
class PhotoStorage
{
    /** Longest edge in pixels, by size name. */
    public const array SIZES = ['large' => 1600, 'thumb' => 600];

    private readonly Filesystem $filesystem;

    public function __construct(
        #[Autowire('%env(resolve:UPLOAD_DIR)%')]
        private readonly string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    public function add(Listing $listing, File $file): ListingPhoto
    {
        $name  = bin2hex(random_bytes(16));
        $image = ImageManager::usingDriver(Driver::class)->decodePath($file->getPathname());
        $this->filesystem->mkdir(\dirname($this->path($name, 'large')));

        // Largest first: each size scales down the one before.
        foreach (self::SIZES as $size => $edge) {
            $image->scaleDown($edge, $edge)
                ->encode(new WebpEncoder(quality: 82, strip: true))
                ->save($this->path($name, $size));
        }

        $photo = (new ListingPhoto())
            ->setFilename($name)
            ->setPosition(($listing->getPhotos()->last() ?: null)?->getPosition() + 1);
        $listing->addPhoto($photo);

        return $photo;
    }

    public function delete(ListingPhoto $photo): void
    {
        $this->filesystem->remove(\dirname($this->path((string) $photo->getFilename(), 'large')));
    }

    public function path(string $filename, string $size): string
    {
        if (!isset(self::SIZES[$size]) || !ctype_xdigit($filename)) {
            throw new \InvalidArgumentException(\sprintf('No "%s" photo called "%s".', $size, $filename));
        }

        return \sprintf('%s/%s/%s/%s.webp', $this->directory, substr($filename, 0, 2), $filename, $size);
    }
}
