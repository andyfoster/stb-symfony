<?php

namespace App\Tests\Controller;

use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The ISBN lookup behind the listing form's "Look up" button. Book APIs are
 * mocked in App\Tests\MockHttpResponses.
 */
final class IsbnLookupTest extends WebTestCase
{
    use DatabaseTestTrait;

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testFindsBookOnOpenLibrary(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell/lookup-isbn', ['isbn' => '978-0-13-754297-0']);

        self::assertResponseIsSuccessful();
        self::assertSame([
            'isbn' => '9780137542970',
            'title' => 'Chemistry: The Central Science',
            'author' => 'Theodore L. Brown, H. Eugene LeMay',
            'edition' => 15,
            'year' => '2022',
            'source' => 'Open Library',
        ], $this->json());
    }

    public function testFallsBackToGoogleBooks(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell/lookup-isbn', ['isbn' => '9780135188743']);

        self::assertResponseIsSuccessful();
        self::assertSame('Campbell Biology', $this->json()['title']);
        self::assertSame('Lisa A. Urry, Michael L. Cain', $this->json()['author']);
        self::assertSame('Google Books', $this->json()['source']);
    }

    public function testUnknownAndInvalidIsbns(): void
    {
        $this->client->loginUser($this->createUser('sam@example.com'));

        $this->client->request('GET', '/sell/lookup-isbn', ['isbn' => '9780000000002']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/sell/lookup-isbn', ['isbn' => 'chemistry']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testGuestsCantUseIt(): void
    {
        $this->client->request('GET', '/sell/lookup-isbn', ['isbn' => '9780137542970']);

        self::assertResponseRedirects('/login');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
