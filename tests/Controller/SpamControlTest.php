<?php

namespace App\Tests\Controller;

use App\Entity\BlockedIp;
use App\Entity\User;
use App\Enum\Feature;
use App\Security\IpBlocklist;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Blocking IP addresses (Blocked IPs in /admin, IpBlocklist) and suspending
 * accounts (Users in /admin, UserSuspender).
 */
final class SpamControlTest extends WebTestCase
{
    use DatabaseTestTrait;

    private const string SPAMMER_IP = '203.0.113.7';

    protected function setUp(): void
    {
        $this->bootClient();
        // Signing up through the crawler can't fill in Turnstile; FeatureFlagTest covers it.
        $this->setFeature(Feature::SignUpCaptcha, false);
    }

    public function testAdminsPasteAListOfAddressesToBlock(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $crawler = $this->client->request('GET', '/admin/blocked-ip');
        $this->client->click($crawler->selectLink('Block addresses')->link());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Block', [
            'form[addresses]' => "203.0.113.7\n198.51.100.0/24, 2001:db8::1 # from the sign-up log\nnonsense\n104.16.1.1\n10.0.0.0/8\n203.0.113.7",
            'form[note]' => 'Fake sign-ups',
        ]);
        // Some were rejected, so the page says which and why.
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.blocked-rejected', 'nonsense');
        self::assertSelectorTextContains('.blocked-rejected', '104.16.1.1');
        self::assertSelectorTextContains('.blocked-rejected', 'Cloudflare');

        $addresses = array_map(static fn (BlockedIp $ip): string => $ip->getAddress(), $this->manager->getRepository(BlockedIp::class)->findAll());
        sort($addresses);
        self::assertSame(['198.51.100.0/24', '2001:db8::1', '203.0.113.7'], $addresses);

        $this->client->request('GET', '/admin/blocked-ip');
        self::assertAnySelectorTextContains('td', 'Fake sign-ups');
    }

    public function testBlockedAddressesCanBrowseButNotSendForms(): void
    {
        static::getContainer()->get(IpBlocklist::class)->addFromText('203.0.113.0/24');
        $server = ['REMOTE_ADDR' => self::SPAMMER_IP];

        $this->client->request('GET', '/', server: $server);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/register', server: $server);
        self::assertResponseIsSuccessful();

        $this->signUp('bot@example.com', $server);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'can’t accept that from your network');
        self::assertNull($this->manager->getRepository(User::class)->findOneBy(['email' => 'bot@example.com']));

        $this->createUser('sam@example.com');
        $this->client->request('POST', '/login', ['email' => 'sam@example.com', 'password' => 'correct horse'], server: $server);
        self::assertResponseStatusCodeSame(403);

        // Everyone else is unaffected.
        $this->signUp('jo@example.com', ['REMOTE_ADDR' => '198.51.100.20']);
        self::assertResponseRedirects('/');
    }

    public function testBlockingTheAddressesTickedAccountsSignedUpFrom(): void
    {
        $this->signUp('bot@example.com', ['REMOTE_ADDR' => self::SPAMMER_IP]);
        $this->client->getCookieJar()->clear();
        $bot = $this->manager->getRepository(User::class)->findOneBy(['email' => 'bot@example.com']);

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->client->request('GET', '/admin/user/'.$bot->getId());
        self::assertAnySelectorTextContains('dd, .field-value, body', self::SPAMMER_IP);

        $this->batch('blockSignUpIps', '/admin/user/block-sign-up-ips', [$bot->getId()]);
        self::assertResponseRedirects('/admin/blocked-ip');
        self::assertTrue(static::getContainer()->get(IpBlocklist::class)->isBlocked(self::SPAMMER_IP));
    }

    public function testAnAdminCanUnblockFromTheCommandLine(): void
    {
        $blocklist = static::getContainer()->get(IpBlocklist::class);
        $blocklist->addFromText(self::SPAMMER_IP);

        $tester = new CommandTester(new Application(self::$kernel)->find('app:ip:unblock'));
        self::assertSame(0, $tester->execute(['address' => self::SPAMMER_IP]));
        self::assertFalse($blocklist->isBlocked(self::SPAMMER_IP));
        self::assertSame(1, $tester->execute(['address' => self::SPAMMER_IP]));
    }

    public function testSuspendedAccountsAreSignedOutAndTheirListingsHidden(): void
    {
        $bot = $this->createUser('bot@example.com', name: 'Totally Real');
        $listing = $this->createListing($bot, 'Cheap essays');
        $this->client->loginUser($bot);
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->client->loginUser($this->createUser('admin@example.com', admin: true), 'main');
        $crawler = $this->client->request('GET', '/admin/user/'.$bot->getId());
        $form = $crawler->filter('form[action*="/suspend"]');
        self::assertCount(1, $form, 'The detail page has a Suspend action.');
        $this->client->request('POST', '/admin/user/'.$bot->getId().'/suspend?token=wrong');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $form->attr('action'));
        self::assertResponseRedirects('/admin/user/'.$bot->getId());

        $this->manager->clear();
        self::assertTrue($this->manager->getRepository(User::class)->find($bot->getId())->isSuspended());

        // Their listing is gone for everyone else.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        self::assertSelectorTextNotContains('body', 'Cheap essays');
        $this->client->request('GET', '/listings/'.$listing->getId());
        self::assertResponseStatusCodeSame(404);

        // They can't log in again.
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'bot@example.com', 'password' => 'correct horse']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.login-error', 'This account has been suspended');

        // And a session they already had is signed out on its next request.
        $this->client->loginUser($this->manager->getRepository(User::class)->find($bot->getId()));
        $this->client->request('GET', '/account');
        self::assertResponseRedirects();
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/login');
    }

    public function testUnsuspendingPutsEverythingBack(): void
    {
        $sam = $this->createUser('sam@example.com');
        $this->createListing($sam, 'Organic Chemistry');
        $sam->setSuspendedAt(new \DateTimeImmutable());
        $this->manager->flush();

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $crawler = $this->client->request('GET', '/admin/user/'.$sam->getId());
        $this->client->request('POST', $crawler->filter('form[action*="/unsuspend"]')->attr('action'));
        self::assertResponseRedirects();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('body', 'Organic Chemistry');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => 'sam@example.com', 'password' => 'correct horse']);
        self::assertResponseRedirects('/');
    }

    public function testSuspendingTickedAccountsLeavesAdminsAlone(): void
    {
        $bots = [$this->createUser('bot1@example.com'), $this->createUser('bot2@example.com')];
        $admin = $this->createUser('admin@example.com', admin: true);
        $this->client->loginUser($admin);

        $this->batch('suspendSelected', '/admin/user/suspend-selected', [$bots[0]->getId(), $bots[1]->getId(), $admin->getId()]);
        self::assertResponseRedirects('/admin/user');

        $this->manager->clear();
        $users = $this->manager->getRepository(User::class);
        self::assertTrue($users->find($bots[0]->getId())->isSuspended());
        self::assertTrue($users->find($bots[1]->getId())->isSuspended());
        self::assertFalse($users->find($admin->getId())->isSuspended());
    }

    /** @param array<string, string> $server */
    private function signUp(string $email, array $server): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->selectButton('Sign up')->form();
        $values = $form->getPhpValues();
        $values['registration_form'] = [
            'displayName' => 'Jo',
            'email' => $email,
            'plainPassword' => ['first' => 'correct horse', 'second' => 'correct horse'],
        ] + $values['registration_form'];

        $this->client->request('POST', '/register', $values, server: $server);
    }

    /** @param list<int|null> $ids */
    private function batch(string $action, string $url, array $ids): void
    {
        $token = $this->client->request('GET', '/admin/user')->filter('[data-action-name="'.$action.'"]')->attr('data-action-csrf-token');
        $this->client->request('POST', $url, [
            'batchActionName' => $action,
            'batchActionEntityIds' => $ids,
            'entityFqcn' => User::class,
            'batchActionCsrfToken' => $token,
        ]);
    }
}
