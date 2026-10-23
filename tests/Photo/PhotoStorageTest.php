<?php

namespace App\Tests\Photo;

use App\Entity\Listing;
use App\Photo\PhotoStorage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\Process;

final class PhotoStorageTest extends KernelTestCase
{
    public function testGenerationWaitsForAnotherProcessAndReleasesItsLock(): void
    {
        self::bootKernel();
        $directory = sys_get_temp_dir().'/stb-concurrent-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory);
        $factory = new LockFactory(new FlockStore($directory.'/.locks'));
        $storage = new PhotoStorage($directory, $factory);
        $image = imagecreatetruecolor(800, 600);
        imagepng($image, $directory.'/source.png');
        $photo = $storage->add(new Listing(), new File($directory.'/source.png'));
        $lock = $factory->createLock('photo-'.$photo->getFilename());
        $lock->acquire();
        $process = new Process([PHP_BINARY, '-r', <<<'CHILD'
require $argv[1].'/vendor/autoload.php';
$storage = new App\Photo\PhotoStorage($argv[2], new Symfony\Component\Lock\LockFactory(new Symfony\Component\Lock\Store\FlockStore($argv[2].'/.locks')));
$photo = (new App\Entity\ListingPhoto())->setFilename($argv[3]);
echo "ready\n";
$storage->generate($photo);
echo "done\n";
CHILD,
            self::getContainer()->getParameter('kernel.project_dir'), $directory, $photo->getFilename(),
        ]);
        $process->setTimeout(10);
        try {
            $process->start();
            self::assertTrue($process->waitUntil(static fn ($type, $output) => str_contains($output, 'ready')));
            // Give the child time to attempt generation while we hold the shared lock.
            usleep(100000);
            self::assertTrue($process->isRunning());
            self::assertFileDoesNotExist($storage->path($photo->getFilename(), 'large'));
            $lock->release();
            self::assertSame(0, $process->wait(), $process->getErrorOutput());
            self::assertSame([800, 600], array_slice(getimagesize($storage->path($photo->getFilename(), 'large')), 0, 2));
            self::assertSame([600, 450], array_slice(getimagesize($storage->path($photo->getFilename(), 'thumb')), 0, 2));
            self::assertSame([], glob(dirname($storage->path($photo->getFilename(), 'large')).'/*.tmp'));
            self::assertTrue($lock->acquire(), 'The child releases the lock after generation.');
            $lock->release();

            $filesystem->remove(dirname($storage->path($photo->getFilename(), 'original')));
            try {
                $storage->generate($photo);
                self::fail('Missing source files must fail.');
            } catch (\RuntimeException) {
                self::assertTrue($lock->acquire(), 'Generation also releases the lock on failure.');
            }
        } finally {
            $lock->release();
            if ($process->isRunning()) {
                $process->stop();
            }
            $filesystem->remove($directory);
        }
    }
}
