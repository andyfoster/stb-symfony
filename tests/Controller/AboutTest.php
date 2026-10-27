<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AboutTest extends WebTestCase
{
    use DatabaseTestTrait;

    public function testAboutExplainsTheSiteAndIsLinkedFromTheFooter(): void
    {
        // The contact form reads the feature flags.
        $this->bootClient();
        $this->client->request('GET', '/about');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('About');
        self::assertSelectorTextContains('h1', 'About ScarfieTextbooks');
        self::assertSelectorTextContains('.about-page', 'default to a year');
        self::assertSelectorTextContains('.about-page', 'next July');
        self::assertSelectorTextContains('.about-page', 'No listing fees or commission');
        self::assertSelectorTextContains('.about-page', 'doesn’t process payments');
        self::assertSelectorExists('.about-page a[href="/sell?type=wanted"]');
        self::assertSelectorExists('.site-footer a[href="/about"]');
    }
}
