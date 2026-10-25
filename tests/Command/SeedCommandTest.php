<?php

namespace App\Tests\Command;

use App\Command\SeedCommand;
use App\Database\DatabaseCleaner;
use App\Entity\Listing;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Category;
use App\Enum\ListingType;
use App\Seed\ExampleData;
use App\Tests\DatabaseTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class SeedCommandTest extends WebTestCase
{
    use DatabaseTestTrait;

    private string $uploadDir;
    private ?string $previousUploadDir;

    protected function setUp(): void
    {
        // The command deletes every photo folder, so never point it at var/uploads.
        $this->uploadDir         = sys_get_temp_dir().'/stb-test-uploads-'.bin2hex(random_bytes(4));
        $this->previousUploadDir = $_ENV['UPLOAD_DIR'] ?? null;
        $_ENV['UPLOAD_DIR']      = $_SERVER['UPLOAD_DIR'] = $this->uploadDir;
        $this->bootClient();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->uploadDir);
        if ($this->previousUploadDir === null) {
            unset($_ENV['UPLOAD_DIR'], $_SERVER['UPLOAD_DIR']);
        } else {
            $_ENV['UPLOAD_DIR'] = $_SERVER['UPLOAD_DIR'] = $this->previousUploadDir;
        }
        parent::tearDown();
    }

    public function testReplacesEverythingWithExampleData(): void
    {
        $this->createListing($this->createUser('jo@example.com'), 'Old listing');

        $command = $this->command();
        $command->execute(['--force' => true]);

        $command->assertCommandIsSuccessful();
        self::assertStringContainsString('password "password"', $command->getDisplay());
        self::assertNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'jo@example.com']));
        self::assertNull($this->manager->getRepository(Listing::class)->findOneBy(['title' => 'Old listing']));

        // Every category, both types, textbooks with papers, photos and messages.
        $live = array_filter($this->manager->getRepository(Listing::class)->findAll(), static fn(Listing $listing): bool => $listing->isLive());
        self::assertGreaterThan(40, \count($live));
        foreach (Category::cases() as $category) {
            self::assertNotEmpty(array_filter($live, static fn(Listing $listing): bool => $listing->getCategory() === $category), $category->value);
        }
        self::assertNotEmpty(array_filter($live, static fn(Listing $listing): bool => $listing->getType() === ListingType::Wanted));
        self::assertNotEmpty(array_filter($live, static fn(Listing $listing): bool => $listing->getBook()?->getPapers()->count() > 0));
        self::assertGreaterThan(0, $this->manager->getRepository(Message::class)->count());

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.listing-card');
        self::assertAnySelectorTextContains('.listing-card', 'Campbell Biology');

        $photo = array_values(array_filter($live, static fn(Listing $listing): bool => $listing->arePhotosPublic() && !$listing->getPhotos()->isEmpty()))[0]->getPhotos()->first();
        self::assertNotFalse($photo);
        $this->client->request('GET', \sprintf('/photos/%d/thumb', $photo->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'sam@example.com', 'password' => 'password']);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Is this still available?');
    }

    public function testRefusesOutsideDevAndTestUnlessAllowed(): void
    {
        $this->createUser('jo@example.com');

        $command = $this->command(environment: 'prod', allowed: false);
        $command->execute(['--force' => true]);

        self::assertSame(1, $command->getStatusCode());
        self::assertStringContainsString('never in production', $command->getDisplay());
        self::assertSame(1, $this->manager->getRepository(User::class)->count());
    }

    public function testPicksARandomPasswordWhereAllowed(): void
    {
        $command = $this->command(environment: 'prod', allowed: true);
        $command->execute(['--force' => true]);

        $command->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/password "[0-9a-f]{12}"/', $command->getDisplay());
        self::assertStringNotContainsString('password "password"', $command->getDisplay());
    }

    public function testChangesNothingUnlessConfirmed(): void
    {
        $this->createUser('jo@example.com');

        $command = $this->command();
        $command->setInputs(['no']);
        $command->execute([]);

        self::assertSame(1, $command->getStatusCode());
        self::assertSame(1, $this->manager->getRepository(User::class)->count());
    }

    /** The command as the container builds it, or for another environment. */
    private function command(?string $environment = null, bool $allowed = false): CommandTester
    {
        $application = new Application(self::$kernel);
        if ($environment !== null) {
            $container = static::getContainer();
            $application->addCommand(new SeedCommand(
                $container->get(EntityManagerInterface::class),
                $container->get(DatabaseCleaner::class),
                $container->get(ExampleData::class),
                $environment,
                $allowed,
            ));
        }

        return new CommandTester($application->find('app:seed'));
    }
}
