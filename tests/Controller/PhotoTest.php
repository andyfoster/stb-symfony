<?php

namespace App\Tests\Controller;

use App\Entity\ListingPhoto;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploading listing photos, and who can see them.
 */
final class PhotoTest extends WebTestCase
{
    use DatabaseTestTrait;

    private string $uploadDir;

    protected function setUp(): void
    {
        $this->uploadDir = sys_get_temp_dir().'/stb-test-uploads-'.bin2hex(random_bytes(4));
        $_ENV['UPLOAD_DIR'] = $_SERVER['UPLOAD_DIR'] = $this->uploadDir;
        $this->bootClient();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->uploadDir);
        unset($_ENV['UPLOAD_DIR'], $_SERVER['UPLOAD_DIR']);
        parent::tearDown();
    }

    public function testUploadedPhotosAreResizedAndShownForVerifiedSellers(): void
    {
        $sam = $this->createUser('sam@example.com');
        $this->client->loginUser($sam);

        $this->post('/sell', [
            'type' => 'for_sale',
            'title' => 'Desk lamp',
            'category' => 'flat_and_home',
        ], [$this->image(2400, 1800), $this->image(400, 300)]);

        self::assertResponseRedirects();
        $photos = $this->photos();
        self::assertCount(2, $photos);

        $large = $this->uploadDir.'/'.substr($photos[0]->getFilename(), 0, 2).'/'.$photos[0]->getFilename().'/large.webp';
        self::assertFileExists($large);
        self::assertSame([1600, 1200], \array_slice(getimagesize($large), 0, 2));
        self::assertSame('image/webp', getimagesize($large)['mime']);

        // Anyone can see a verified seller's photos.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        self::assertSelectorExists('.listing-card .card-photo');
        $this->client->request('GET', '/photos/'.$photos[0]->getId().'/thumb');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
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
        $values['listing_form'] = $fields + $values['listing_form'];
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

    /** @return ListingPhoto[] */
    private function photos(): array
    {
        $this->manager->clear();

        return $this->manager->getRepository(ListingPhoto::class)->findBy([], ['id' => 'ASC']);
    }
}
