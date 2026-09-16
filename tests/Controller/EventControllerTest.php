<?php

namespace App\Tests\Controller;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        // An isolated in-memory database: never touch the application's events.
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
    }

    public function testCreateAndEditEventFromHomePage(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(Event::class)]);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', (new \DateTimeImmutable())->format('l, j F Y'));
        self::assertSelectorTextContains('.empty-state', 'Good things start with a date.');

        $client->clickLink('Add your first event');
        self::assertSelectorCount(1, 'button[type="submit"]');
        $client->submitForm('Create event', [
            'event[title]' => 'A weekend away',
            'event[eventDate]' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
            'event[description]' => 'Pack a good book.',
        ]);
        self::assertResponseRedirects('/', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.event-card h3', 'A weekend away');
        self::assertSelectorTextContains('.countdown', '1');
        self::assertSelectorTextContains('.countdown-unit', 'day to go');
        self::assertSelectorTextContains('.event-description', 'Pack a good book.');

        $client->clickLink('Edit');
        self::assertInputValueSame('event[title]', 'A weekend away');
        $client->submitForm('Save changes', [
            'event[title]' => 'A new adventure',
            'event[eventDate]' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            'event[description]' => '',
        ]);
        self::assertResponseRedirects('/', 303);
        $client->followRedirect();
        self::assertSelectorCount(1, '.event-card');
        self::assertSelectorTextContains('.event-card h3', 'A new adventure');
        self::assertSelectorTextContains('.countdown', 'Today');
        self::assertSelectorNotExists('.event-description');
        $manager->clear();
        $saved = $manager->getRepository(Event::class)->findOneBy(['title' => 'A new adventure']);
        self::assertNotNull($saved);
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $saved->getEventDate()->format('Y-m-d'));
    }

    public function testInvalidFormAndMissingEvent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(Event::class)]);
        $client->request('GET', '/events/new');
        $client->submitForm('Create event', ['event[title]' => '   ', 'event[eventDate]' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.event-form', 'Give your event a name.');
        self::assertSelectorTextContains('.event-form', 'Choose a date for your event.');
        self::assertSame(0, $manager->getRepository(Event::class)->count([]));
        $client->request('GET', '/events/999/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPastEventsAndLegacyListing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($manager))->createSchema([$manager->getClassMetadata(Event::class)]);
        $event = (new Event())->setTitle('Yesterday')->setEventDate(new \DateTimeImmutable('yesterday'))->setCreatedAt(new \DateTimeImmutable());
        $manager->persist($event);
        $manager->flush();
        $client->request('GET', '/events/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.event-past .countdown', '1');
        self::assertSelectorTextContains('.countdown-unit', 'day ago');
    }
}
