<?php

namespace App\Tests\Controller;

use App\Audit\AuditLog;
use App\Entity\AuditEvent;
use App\Enum\AuditType;
use App\Scheduler\PruneAuditLog;
use App\Scheduler\PruneAuditLogHandler;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Logins, account changes and listing changes are written to the audit log,
 * which admins read at /admin/audit-event and on each user's admin page.
 */
final class AuditLogTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testLoginAndLogoutAreRecorded(): void
    {
        $user = $this->createUser('sam@example.com');

        $this->logIn('Sam@Example.com', 'correct horse');
        self::assertResponseRedirects('/');
        $this->client->request('POST', '/logout', ['_csrf_token' => 'csrf-token']);

        [$login, $logout] = $this->events();
        self::assertSame(AuditType::LoginSucceeded, $login->getType());
        self::assertSame($user->getId(), $login->getActor()?->getId());
        self::assertSame('sam@example.com', $login->getEmail());
        self::assertSame('127.0.0.1', $login->getIp());
        self::assertSame('Symfony BrowserKit', $login->getUserAgent());
        self::assertSame(['firewall' => 'main'], $login->getContext());
        self::assertSame(AuditType::Logout, $logout->getType());
        self::assertSame($user->getId(), $logout->getActor()?->getId());
    }

    public function testFailedLoginRecordsTheEmailButNeverThePassword(): void
    {
        $user = $this->createUser('sam@example.com');

        $this->logIn('SAM@example.com', 'wrong horse');
        $this->logIn('nobody@example.com', 'wrong horse');
        self::assertResponseRedirects('/login');

        [$known, $unknown] = $this->events();
        self::assertSame(AuditType::LoginFailed, $known->getType());
        self::assertSame('sam@example.com', $known->getEmail());
        self::assertSame($user->getId(), $known->getActor()?->getId());
        self::assertSame(['reason' => 'Invalid credentials.', 'account' => true], $known->getContext());
        self::assertSame(AuditType::LoginFailed, $unknown->getType());
        self::assertSame('nobody@example.com', $unknown->getEmail());
        self::assertNull($unknown->getActor());
        self::assertFalse($unknown->getContext()['account']);

        $rows = json_encode($this->manager->getConnection()->fetchAllAssociative('SELECT * FROM audit_event'));
        self::assertStringNotContainsString('wrong horse', (string) $rows);
    }

    public function testPublishingAListingIsRecorded(): void
    {
        $user = $this->createUser('sam@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/sell');
        $this->client->submitForm('Publish listing', [
            'listing_form[type]' => 'for_sale',
            'listing_form[title]' => 'Desk lamp',
            'listing_form[category]' => 'flat_and_home',
            'listing_form[priceCents]' => '10',
            'listing_form[condition]' => 'good',
        ]);
        self::assertResponseRedirects();

        [$created, $published] = $this->events();
        self::assertSame(AuditType::ListingCreated, $created->getType());
        self::assertSame(AuditType::ListingPublished, $published->getType());
        self::assertSame('Listing', $published->getSubjectType());
        self::assertSame('Desk lamp', $published->getSubjectLabel());
        self::assertNotNull($published->getSubjectId());
        self::assertSame($user->getId(), $published->getActor()?->getId());
        self::assertArrayHasKey('expiresAt', $published->getContext());
    }

    public function testDeletingAListingKeepsWhichOneItWas(): void
    {
        $user = $this->createUser('sam@example.com');
        $listing = $this->createListing($user, 'Desk lamp');
        $id = $listing->getId();
        $this->client->loginUser($user);

        $this->client->request('POST', '/listings/'.$id.'/delete', ['_csrf_token' => 'csrf-token']);
        self::assertResponseRedirects('/account/listings');

        [$deleted] = $this->events();
        self::assertSame(AuditType::ListingDeleted, $deleted->getType());
        self::assertSame($id, $deleted->getSubjectId());
        self::assertSame('Desk lamp', $deleted->getSubjectLabel());
    }

    public function testAdminsSeeTheLogAndAUsersRecentActivity(): void
    {
        $user = $this->createUser('sam@example.com');
        $this->logIn('sam@example.com', 'wrong horse');
        $this->client->getCookieJar()->clear();
        $admin = $this->createUser('admin@example.com', admin: true, name: 'Admin');

        $this->client->loginUser($user);
        $this->client->request('GET', '/admin/audit-event');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/audit-event');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Login failed');
        self::assertSelectorTextContains('table', 'sam@example.com');

        $this->client->request('GET', '/admin/user/'.$user->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Recent activity');
        self::assertSelectorTextContains('body', 'Login failed');
    }

    public function testAdminEditsRecordChangedFieldsAndAdminAccess(): void
    {
        $user = $this->createUser('sam@example.com');
        $this->client->loginUser($this->createUser('admin@example.com', admin: true, name: 'Admin'));

        $this->client->request('GET', '/admin/user/'.$user->getId().'/edit');
        $this->client->submitForm('Save changes', ['User[displayName]' => 'Samantha', 'User[admin]' => true]);
        self::assertResponseRedirects();

        [$role, $updated] = $this->events();
        self::assertSame(AuditType::RoleChanged, $role->getType());
        self::assertSame(['admin' => true], $role->getContext());
        self::assertSame('admin@example.com', $role->getEmail());
        self::assertSame(AuditType::AdminUpdated, $updated->getType());
        self::assertSame(['displayName', 'roles'], $updated->getContext()['fields']);
        self::assertSame($user->getId(), $updated->getSubjectId());
    }

    public function testOldEventsArePruned(): void
    {
        $audit = static::getContainer()->get(AuditLog::class);
        $audit->record(AuditType::LoginFailed, email: 'old@example.com');
        $audit->record(AuditType::LoginFailed, email: 'new@example.com');
        $this->manager->getConnection()->executeStatement(
            'UPDATE audit_event SET created_at = ? WHERE email = ?',
            [(new \DateTimeImmutable(\sprintf('-%d days', AuditLog::RETENTION_DAYS + 1)))->format('Y-m-d H:i:s'), 'old@example.com'],
        );

        static::getContainer()->get(PruneAuditLogHandler::class)(new PruneAuditLog());

        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame('new@example.com', $events[0]->getEmail());
    }

    private function logIn(string $email, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }

    /** @return list<AuditEvent> oldest first */
    private function events(): array
    {
        $this->manager->clear();

        return $this->manager->getRepository(AuditEvent::class)->findBy([], ['id' => 'ASC']);
    }
}
