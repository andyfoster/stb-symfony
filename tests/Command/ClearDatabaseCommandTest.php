<?php

namespace App\Tests\Command;

use App\Entity\Listing;
use App\Entity\Paper;
use App\Entity\User;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ClearDatabaseCommandTest extends WebTestCase
{
    use DatabaseTestTrait;

    private string $uploadDir;
    private ?string $previousUploadDir;
    private CommandTester $command;

    protected function setUp(): void
    {
        // The command deletes every photo folder, so never point it at var/uploads.
        $this->uploadDir         = sys_get_temp_dir().'/stb-test-uploads-'.bin2hex(random_bytes(4));
        $this->previousUploadDir = $_ENV['UPLOAD_DIR'] ?? null;
        $_ENV['UPLOAD_DIR']      = $_SERVER['UPLOAD_DIR'] = $this->uploadDir;
        $this->bootClient();
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:database:clear'));
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

    public function testEmptiesEverythingAndCreatesAdminUserOne(): void
    {
        $this->createListing($this->createUser('sam@example.com'));
        $this->createUser('jo@example.com');
        (new Filesystem())->mkdir($this->uploadDir.'/ab/abcdef');

        $this->command->execute(['--force' => true]);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('Admin user #1: admin@scarfietextbooks.com, password "password"', $this->command->getDisplay());
        self::assertSame(0, $this->manager->getRepository(Listing::class)->count());
        self::assertDirectoryDoesNotExist($this->uploadDir.'/ab/abcdef');
        self::assertGreaterThan(1000, $this->manager->getRepository(Paper::class)->count());

        $users = $this->manager->getRepository(User::class)->findAll();
        self::assertCount(1, $users);
        self::assertSame(1, $users[0]->getId());
        self::assertTrue($users[0]->isVerified());
        self::assertContains('ROLE_ADMIN', $users[0]->getRoles());

        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'admin@scarfietextbooks.com', 'password' => 'password']);
        self::assertResponseRedirects('/');
        $this->client->request('GET', '/admin/user');
        self::assertResponseIsSuccessful();
    }

    public function testTakesAnotherEmailAndPassword(): void
    {
        $this->command->execute(['--force' => true, '--email' => 'Andy@Example.com', '--password' => 'tui and kererū']);

        $this->command->assertCommandIsSuccessful();
        self::assertStringNotContainsString('tui and kererū', $this->command->getDisplay());
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'andy@example.com', 'password' => 'tui and kererū']);
        self::assertResponseRedirects('/');
    }

    public function testChangesNothingUnlessConfirmed(): void
    {
        $this->createUser('sam@example.com');

        $this->command->setInputs(['no']);
        $this->command->execute([]);

        self::assertSame(1, $this->command->getStatusCode());
        self::assertSame(1, $this->manager->getRepository(User::class)->count());
        self::assertSame('sam@example.com', $this->manager->getRepository(User::class)->findAll()[0]->getEmail());
    }
}
