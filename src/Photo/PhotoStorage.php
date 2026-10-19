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
 * Saves listing photos to disk (a Kamal volume in production).
 *
 * An upload is kept as an "original": auto-rotated, at most ORIGINAL_EDGE
 * pixels, re-encoded as WebP (which drops EXIF data such as GPS). The sizes
 * pages show (SIZES) are made from it by generate(): in the background after
 * an upload (GeneratePhotoSizes), on demand when one is missing (ensure()),
 * or all again with app:photos:regenerate, e.g. after changing SIZES.
 */
class PhotoStorage
{
    /** Longest edge in pixels, by size name. Change these, then run app:photos:regenerate. */
    public const array SIZES = ['large' => 1600, 'thumb' => 600];

    public const int ORIGINAL_EDGE = 2400;

    private const string ORIGINAL = 'original';

    private readonly Filesystem $filesystem;

    public function __construct(
        #[Autowire('%env(resolve:UPLOAD_DIR)%')]
        private readonly string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Stores the original. The sizes come later: dispatch GeneratePhotoSizes
     * once the photo has an id, or call generate().
     */
    public function add(Listing $listing, File $file): ListingPhoto
    {
        $name = bin2hex(random_bytes(16));
        $this->filesystem->mkdir($this->directoryFor($name));

        $this->manager()->decodePath($file->getPathname())
            ->scaleDown(self::ORIGINAL_EDGE, self::ORIGINAL_EDGE)
            ->encode(new WebpEncoder(quality: 90, strip: true))
            ->save($this->path($name, self::ORIGINAL));

        $photo = (new ListingPhoto())
            ->setFilename($name)
            ->setPosition(($listing->getPhotos()->last() ?: null)?->getPosition() + 1);
        $listing->addPhoto($photo);

        return $photo;
    }

    /**
     * (Re)makes sizes from the original. Photos uploaded before originals
     * were kept use their largest size instead.
     *
     * @param list<string>|null $sizes null for all of them
     *
     * @throws \RuntimeException when there's nothing to make them from
     */
    public function generate(ListingPhoto $photo, ?array $sizes = null): void
    {
        $name = (string) $photo->getFilename();
        $source = $this->sourcePath($photo) ?? throw new \RuntimeException(\sprintf('%s has no image file to make sizes from.', $photo));
        $wanted = array_intersect_key(self::SIZES, array_flip($sizes ?? array_keys(self::SIZES)));
        arsort($wanted); // Largest first: each size scales down the one before.

        $image = $this->manager()->decodePath($source);
        foreach ($wanted as $size => $edge) {
            $path = $this->path($name, $size);
            // Write next to it, then swap in, so nobody is served half a file.
            $image->scaleDown($edge, $edge)
                ->encode(new WebpEncoder(quality: 82, strip: true))
                ->save($path.'.tmp');
            $this->filesystem->rename($path.'.tmp', $path, true);
        }
    }

    /**
     * The file for a size, made now if it's missing.
     *
     * @throws \RuntimeException when the photo's files are gone
     */
    public function ensure(ListingPhoto $photo, string $size): string
    {
        $path = $this->path((string) $photo->getFilename(), $size);
        if (!is_file($path)) {
            $this->generate($photo, [$size]);
        }

        return $path;
    }

    /** @return list<string> sizes that haven't been made yet */
    public function missingSizes(ListingPhoto $photo): array
    {
        return array_values(array_filter(
            array_keys(self::SIZES),
            fn (string $size): bool => !is_file($this->path((string) $photo->getFilename(), $size)),
        ));
    }

    public function hasOriginal(ListingPhoto $photo): bool
    {
        return is_file($this->path((string) $photo->getFilename(), self::ORIGINAL));
    }

    public function delete(ListingPhoto $photo): void
    {
        $this->filesystem->remove($this->directoryFor((string) $photo->getFilename()));
    }

    /**
     * Removes photo folders with no photo in the database, e.g. after a user
     * was deleted (the database removes their listings and photos by itself).
     *
     * @param list<string> $known filenames of every photo in the database
     *
     * @return int how many folders were removed
     */
    public function prune(array $known): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $known = array_flip($known);
        $removed = 0;
        foreach (glob($this->directory.'/??/*', \GLOB_ONLYDIR) ?: [] as $folder) {
            if (!isset($known[basename($folder)])) {
                $this->filesystem->remove($folder);
                ++$removed;
            }
        }

        return $removed;
    }

    public function path(string $filename, string $size): string
    {
        if ((self::ORIGINAL !== $size && !isset(self::SIZES[$size])) || !ctype_xdigit($filename)) {
            throw new \InvalidArgumentException(\sprintf('No "%s" photo called "%s".', $size, $filename));
        }

        return \sprintf('%s/%s.webp', $this->directoryFor($filename), $size);
    }

    private function sourcePath(ListingPhoto $photo): ?string
    {
        $name = (string) $photo->getFilename();
        $candidates = [self::ORIGINAL, ...array_keys(self::SIZES)];
        usort($candidates, static fn (string $a, string $b): int => (self::SIZES[$b] ?? \PHP_INT_MAX) <=> (self::SIZES[$a] ?? \PHP_INT_MAX));
        foreach ($candidates as $size) {
            if (is_file($path = $this->path($name, $size))) {
                return $path;
            }
        }

        return null;
    }

    private function directoryFor(string $filename): string
    {
        return \sprintf('%s/%s/%s', $this->directory, substr($filename, 0, 2), $filename);
    }

    private function manager(): ImageManager
    {
        return ImageManager::usingDriver(Driver::class);
    }
}
