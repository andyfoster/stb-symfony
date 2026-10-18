<?php

namespace App\Tests\Controller;

use App\Entity\SiteSettings;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The EasyAdmin panel at /admin is for ROLE_ADMIN only.
 */
final class AdminTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testOnlyAdminsSeeTheMenuLinkAndPanel(): void
    {
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');

        $this->client->loginUser($this->createUser('sam@example.com'));
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.nav-admin');
        $this->client->request('GET', '/admin/listing');
        self::assertResponseStatusCodeSame(403);

        $admin = $this->createUser('admin@example.com', admin: true, name: 'Admin');
        $this->createListing($admin, 'Desk lamp');
        $this->client->loginUser($admin);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.nav-admin', 'Admin');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/listing');
        foreach (['/admin/listing', '/admin/user', '/admin/message'] as $page) {
            $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        self::assertSelectorTextContains('body', 'Settings');
    }

    public function testAdminChangesListingLifetime(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/listing');
        $this->client->click($crawler->selectLink('Settings')->link());
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save settings', ['SiteSettings[listingLifetimeDays]' => '28']);
        self::assertResponseRedirects();

        $this->manager->clear();
        self::assertSame(28, $this->manager->getRepository(SiteSettings::class)->findAll()[0]->getListingLifetimeDays());
    }
}
