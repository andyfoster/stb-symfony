<?php

namespace App\Tests\Controller;

use App\Entity\ListingPhoto;
use App\Photo\GeneratePhotoSizes;
use App\Photo\GeneratePhotoSizesHandler;
use App\Enum\Feature;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Uploading listing photos, and who can see them.
 */
final class PhotoTest extends WebTestCase
{
    use DatabaseTestTrait;

    private string $uploadDir;
    private ?string $previousUploadDir;

    protected function setUp(): void
    {
        $this->uploadDir         = sys_get_temp_dir().'/stb-test-uploads-'.bin2hex(random_bytes(4));
        $this->previousUploadDir = $_ENV['UPLOAD_DIR'] ?? null;
        $_ENV['UPLOAD_DIR']      = $_SERVER['UPLOAD_DIR'] = $this->uploadDir;
        $this->bootClient();
        // Posting through the crawler can't fill in Turnstile; FeatureFlagTest covers it.
        $this->setFeature(Feature::ListingCaptcha, false);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->uploadDir);
        // Put back the .env value; later tests that render the listing form need it.
        if ($this->previousUploadDir === null) {
            unset($_ENV['UPLOAD_DIR'], $_SERVER['UPLOAD_DIR']);
        } else {
            $_ENV['UPLOAD_DIR'] = $_SERVER['UPLOAD_DIR'] = $this->previousUploadDir;
        }
        parent::tearDown();
    }

    public function testUploadKeepsAnOriginalAndTheWorkerMakesTheSizes(): void
    {
        $sam = $this->createUser('sam@example.com');
        $this->client->loginUser($sam);

        $this->post('/sell', [
            'type' => 'for_sale',
            'title' => 'Desk lamp',
            'category' => 'flat_and_home',
        ], [$this->image(3000, 2250), $this->image(400, 300)]);

        self::assertResponseRedirects();
        $photos = $this->photos();
        self::assertCount(2, $photos);

        // The upload keeps a cleaned-up original, capped at 2400 pixels...
        $original = $this->file($photos[0], 'original');
        self::assertSame([2400, 1800], \array_slice(getimagesize($original), 0, 2));
        self::assertSame('image/webp', getimagesize($original)['mime']);
        self::assertFileDoesNotExist($this->file($photos[0], 'large'));

        // ...and queues the sizes for the worker.
        $queued = $this->queuedSizeJobs();
        self::assertSame([$photos[0]->getId(), $photos[1]->getId()], array_map(static fn (GeneratePhotoSizes $m) => $m->photoId, $queued));
        $handler = static::getContainer()->get(GeneratePhotoSizesHandler::class);
        array_map($handler, $queued);

        self::assertSame([1600, 1200], \array_slice(getimagesize($this->file($photos[0], 'large')), 0, 2));
        self::assertSame([600, 450], \array_slice(getimagesize($this->file($photos[0], 'thumb')), 0, 2));
        // Small photos aren't blown up.
        self::assertSame([400, 300], \array_slice(getimagesize($this->file($photos[1], 'large')), 0, 2));

        // Anyone can see a verified seller's photos.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.listing-card .card-photo');
        $this->client->request('GET', '/photos/'.$photos[0]->getId().'/thumb');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
    }

    public function testMissingSizesAreMadeWhenAskedFor(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->post('/sell', ['type' => 'for_sale', 'title' => 'Desk lamp', 'category' => 'flat_and_home'], [$this->image(800, 600)]);
        $photo = $this->photos()[0];

        // Before the worker gets to it.
        $this->client->request('GET', '/photos/'.$photo->getId().'/thumb');
        self::assertResponseIsSuccessful();
        self::assertFileExists($this->file($photo, 'thumb'));
        self::assertFileDoesNotExist($this->file($photo, 'large'), 'Only the size that was asked for.');

        // Gone altogether: not found, rather than an error.
        (new Filesystem())->remove(\dirname($this->file($photo, 'original')));
        $this->client->request('GET', '/photos/'.$photo->getId().'/large');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminsLookThroughRegenerateAndDeletePhotos(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->post('/sell', ['type' => 'for_sale', 'title' => 'Desk lamp', 'category' => 'flat_and_home'], [$this->image(800, 600), $this->image(600, 600)]);
        [$first, $second] = $this->photos();
        $listingId = $first->getListing()->getId();
        $this->client->loginUser($this->createUser('admin@example.com', admin: true, name: 'Admin'));

        // The listings admin shows the first photo, and the detail page all of them.
        $this->client->request('GET', '/admin/listing');
        self::assertSelectorExists('.stb-admin-photos img[src="/photos/'.$first->getId().'/thumb"]');
        $this->client->request('GET', '/admin/listing/'.$listingId);
        self::assertSelectorCount(2, '.stb-admin-photos .ea-lightbox-thumbnail img');

        // The Photos admin lists every photo.
        $crawler = $this->client->request('GET', '/admin/listing-photo');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(2, 'img[src$="/thumb"]');
        self::assertAnySelectorTextContains('td', 'sam@example.com');

        // Regenerate queues the work for the worker.
        $before = \count($this->queuedSizeJobs());
        $this->client->request('POST', $crawler->filter('form[action*="/listing-photo/'.$first->getId().'/regenerate"]')->attr('action'));
        self::assertResponseRedirects('/admin/listing-photo/'.$first->getId());
        self::assertCount($before + 1, $this->queuedSizeJobs());

        // Deleting a photo removes its files.
        $crawler = $this->client->request('GET', '/admin/listing-photo');
        $this->client->request('POST', '/admin/listing-photo/'.$second->getId().'/delete', [
            'token' => $crawler->filter('#action-confirmation-form input[name="token"]')->attr('value'),
        ]);
        self::assertResponseRedirects();
        self::assertSame([$first->getId()], array_map(static fn (ListingPhoto $p) => $p->getId(), $this->photos()));
        self::assertDirectoryDoesNotExist(\dirname($this->file($second, 'original')));
    }

    public function testRegenerateAndPruneCommands(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->post('/sell', ['type' => 'for_sale', 'title' => 'Desk lamp', 'category' => 'flat_and_home'], [$this->image(800, 600)]);
        $photo = $this->photos()[0];
        $application = new Application(self::$kernel);

        $regenerate = new CommandTester($application->find('app:photos:regenerate'));
        $regenerate->execute(['--missing' => true]);
        $regenerate->assertCommandIsSuccessful();
        self::assertStringContainsString('Remade 1 of 1 photos.', $regenerate->getDisplay());
        self::assertFileExists($this->file($photo, 'large'));
        $regenerate->execute(['--missing' => true]);
        self::assertStringContainsString('Remade 0 of 1 photos.', $regenerate->getDisplay());

        $orphan = $this->uploadDir.'/ab/'.str_repeat('ab', 16);
        (new Filesystem())->dumpFile($orphan.'/large.webp', 'left over');
        $prune = new CommandTester($application->find('app:photos:prune'));
        $prune->execute([]);
        self::assertStringContainsString('Removed 1 unused photo folders.', $prune->getDisplay());
        self::assertDirectoryDoesNotExist($orphan);
        self::assertFileExists($this->file($photo, 'original'));
    }

    public function testUnverifiedSellersPhotosAreHiddenFromOthers(): void
    {
        $alex = $this->createUser('alex@example.com', verified: false);
        $this->client->loginUser($alex);
        $this->post('/sell', ['type' => 'for_sale', 'title' => 'Desk lamp', 'category' => 'flat_and_home'], [$this->image(800, 600)]);
        $photo = $this->photos()[0];

        // The seller can see their own photos, with a note about why others can't.
        $this->client->request('GET', '/listings/'.$photo->getListing()->getId());
        self::assertSelectorExists('.gallery img');
        self::assertSelectorTextContains('.photo-notice', 'hidden from other people until you confirm your email');
        $this->client->request('GET', '/photos/'.$photo->getId().'/thumb');
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/listings/'.$photo->getListing()->getId());
        self::assertSelectorNotExists('.gallery');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.card-photo');
        $this->client->request('GET', '/photos/'.$photo->getId().'/thumb');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAtMostFourPhotos(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->post('/sell', ['type' => 'for_sale', 'title' => 'Desk lamp', 'category' => 'flat_and_home'], array_map(fn () => $this->image(200, 200), range(1, 5)));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.photo-panel', 'You can add up to 4 photos.');
        self::assertCount(0, $this->photos());
    }

    public function testRemovePhotoWhenEditing(): void
    {
        $sam = $this->createUser('sam@example.com');
        $this->client->loginUser($sam);
        $this->post('/sell', ['type' => 'for_sale', 'title' => 'Desk lamp', 'category' => 'flat_and_home'], [$this->image(200, 200), $this->image(300, 300)]);
        [$first, $second] = $this->photos();
        $id = $first->getListing()->getId();

        $crawler = $this->client->request('GET', "/listings/$id/edit");
        $form = $crawler->selectButton('Save changes')->form();
        $form['listing_form[removePhotos][0]']->tick();
        $this->client->submit($form);

        self::assertResponseRedirects("/listings/$id");
        self::assertSame([$second->getId()], array_map(static fn (ListingPhoto $p) => $p->getId(), $this->photos()));
        self::assertDirectoryDoesNotExist($this->uploadDir.'/'.substr($first->getFilename(), 0, 2).'/'.$first->getFilename());
    }

    /**
     * @param array<string, string> $fields
     * @param UploadedFile[]        $photos
     */
    private function post(string $uri, array $fields, array $photos): void
    {
        $crawler = $this->client->request('GET', $uri);
        $form = $crawler->selectButton('Publish listing')->form();
        $values = $form->getPhpValues();
        $values['listing_form'] = $fields + ['condition' => 'good'] + $values['listing_form'];
        $values['listing_form']['publish'] = '';

        $this->client->request('POST', $uri, $values, ['listing_form' => ['photos' => $photos]]);
    }

    private function image(int $width, int $height): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stb').'.png';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 43, 85, 71));
        imagepng($image, $path);

        return new UploadedFile($path, 'photo.png', 'image/png', test: true);
    }

    private function file(ListingPhoto $photo, string $size): string
    {
        return \sprintf('%s/%s/%s/%s.webp', $this->uploadDir, substr($photo->getFilename(), 0, 2), $photo->getFilename(), $size);
    }

    /** @return list<GeneratePhotoSizes> */
    private function queuedSizeJobs(): array
    {
        /** @var InMemoryTransport $async */
        $async = static::getContainer()->get('messenger.transport.async');

        return array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $async->getSent()),
            static fn (object $message): bool => $message instanceof GeneratePhotoSizes,
        ));
    }

    /** @return ListingPhoto[] */
    private function photos(): array
    {
        $this->manager->clear();

        return $this->manager->getRepository(ListingPhoto::class)->findBy([], ['id' => 'ASC']);
    }
}
