<?php

namespace App\Command;

use App\Entity\ListingPhoto;
use App\Photo\GeneratePhotoSizes;
use App\Photo\PhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Remakes listing photos' sizes, e.g. after changing PhotoStorage::SIZES or
 * the quality, or to fill in ones that are missing.
 */
#[AsCommand(name: 'app:photos:regenerate', description: 'Remake listing photo sizes (all, or only missing ones), now or on the worker')]
final class RegeneratePhotosCommand
{
    public function __construct(
        private readonly EntityManagerInterface $manager,
        private readonly PhotoStorage $storage,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * @param list<string> $size
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Only these sizes (repeatable), e.g. --size=thumb')] array $size = [],
        #[Option('Only photos with a size missing')] bool $missing = false,
        #[Option('Queue the work for the Messenger worker instead of doing it now')] bool $queue = false,
    ): int {
        $unknown = array_diff($size, array_keys(PhotoStorage::SIZES));
        if ([] !== $unknown) {
            $io->error(\sprintf('Unknown size "%s". Sizes: %s.', implode('", "', $unknown), implode(', ', array_keys(PhotoStorage::SIZES))));

            return Command::FAILURE;
        }

        $photos = $this->manager->getRepository(ListingPhoto::class)->findBy([], ['id' => 'ASC']);
        $done = $failed = 0;
        foreach ($io->progressIterate($photos) as $photo) {
            $sizes = $size ?: null;
            if ($missing) {
                $sizes = array_values(array_intersect($this->storage->missingSizes($photo), $size ?: array_keys(PhotoStorage::SIZES)));
                if ([] === $sizes) {
                    continue;
                }
            }

            if ($queue) {
                $this->bus->dispatch(new GeneratePhotoSizes((int) $photo->getId(), $sizes));
                ++$done;
                continue;
            }

            try {
                $this->storage->generate($photo, $sizes);
                ++$done;
            } catch (\RuntimeException $e) {
                ++$failed;
                $io->warning($e->getMessage());
            }
        }

        $io->success(\sprintf('%s %d of %d photos.%s', $queue ? 'Queued' : 'Remade', $done, \count($photos), $failed ? \sprintf(' %d had no files left.', $failed) : ''));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
