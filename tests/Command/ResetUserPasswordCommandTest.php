<?php

namespace App\Tests\Command;

use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetUserPasswordCommandTest extends WebTestCase
{
    use DatabaseTestTrait;

    private CommandTester $command;

    protected function setUp(): void
    {
        $this->bootClient();
        $this->command = new CommandTester((new Application(self::$kernel))->find('app:user:reset-password'));
    }

    public function testResetsToTheDefaultPasswordAndTheUserCanLogIn(): void
    {
        $this->createUser('sam@example.com');

        $this->command->execute(['email' => 'SAM@example.com']);

        $this->command->assertCommandIsSuccessful();
        self::assertStringContainsString('sam@example.com can now log in with "Password1!"', $this->command->getDisplay());
        $this->logIn('sam@example.com', 'Password1!');
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.nav-user', 'Sam');
    }

    public function testSetsAChosenPassword(): void
    {
        $this->createUser('sam@example.com');

        $this->command->execute(['email' => 'sam@example.com', '--password' => 'tui and kererū']);

        $this->command->assertCommandIsSuccessful();
        self::assertStringNotContainsString('tui and kererū', $this->command->getDisplay());
        $this->logIn('sam@example.com', 'tui and kererū');
        self::assertResponseRedirects('/');
    }

    public function testUnknownEmailFails(): void
    {
        $this->command->execute(['email' => 'nobody@example.com']);

        self::assertSame(1, $this->command->getStatusCode());
        self::assertStringContainsString('There is no account for nobody@example.com.', $this->command->getDisplay());
    }

    private function logIn(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }
}
