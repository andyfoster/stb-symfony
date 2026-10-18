<?php

namespace App\Tests;

use App\Book\BookLookup;
use App\Captcha\TurnstileValidator;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Canned answers for every outside HTTP call in tests (framework.http_client
 * mock_response_factory in config/packages/test). Tests never hit the network.
 *
 * - Turnstile accepts any token except "fail".
 * - Open Library knows ISBN 9780137542970; Google Books knows 9780135188743.
 */
final class MockHttpResponses
{
    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (str_starts_with($url, TurnstileValidator::VERIFY_URL)) {
            return new JsonMockResponse(['success' => !str_contains((string) ($options['body'] ?? ''), 'response=fail')]);
        }

        if (str_starts_with($url, BookLookup::OPEN_LIBRARY_URL)) {
            return new JsonMockResponse(str_contains($url, '9780137542970') ? ['ISBN:9780137542970' => [
                'title' => 'Chemistry',
                'subtitle' => 'The Central Science',
                'authors' => [['name' => 'Theodore L. Brown'], ['name' => 'H. Eugene LeMay']],
                'edition_name' => '15th ed.',
                'publish_date' => 'March 2022',
            ]] : []);
        }

        if (str_starts_with($url, BookLookup::GOOGLE_BOOKS_URL)) {
            return new JsonMockResponse(str_contains($url, '9780135188743') ? ['totalItems' => 1, 'items' => [['volumeInfo' => [
                'title' => 'Campbell Biology',
                'authors' => ['Lisa A. Urry', 'Michael L. Cain'],
                'publishedDate' => '2020-01-01',
            ]]]] : ['totalItems' => 0]);
        }

        throw new \LogicException(\sprintf('Unexpected HTTP request in a test: %s %s', $method, $url));
    }
}
