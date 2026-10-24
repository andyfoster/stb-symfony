<?php

namespace App\Book;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds a book's title and authors by ISBN using free APIs: Open Library
 * first, then Google Books. Answers (including "not found") are cached.
 */
class BookLookup
{
    public const string OPEN_LIBRARY_URL = 'https://openlibrary.org/api/books';
    public const string GOOGLE_BOOKS_URL = 'https://www.googleapis.com/books/v1/volumes';

    /** Set when an API call failed, so a miss isn't cached for long. */
    private bool $failed = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /** Digits (and a trailing X) only, or null if it can't be an ISBN-10 or ISBN-13. */
    public static function normalise(string $isbn): ?string
    {
        $isbn = strtoupper((string) preg_replace('/[^0-9Xx]/', '', $isbn));

        return preg_match('/^(\d{9}[\dX]|\d{13})$/', $isbn) ? $isbn : null;
    }

    public function find(string $isbn): ?BookMetadata
    {
        $isbn = self::normalise($isbn);
        if ($isbn === null) {
            return null;
        }

        return $this->cache->get('book_lookup_' . $isbn, function (ItemInterface $item) use ($isbn): ?BookMetadata {
            $this->failed = false;
            $book         = $this->fromOpenLibrary($isbn) ?? $this->fromGoogleBooks($isbn);
            $item->expiresAfter(match (true) {
                $book !== null => 30 * 86400,
                $this->failed  => 300,
                default        => 86400,
            });

            return $book;
        });
    }

    private function fromOpenLibrary(string $isbn): ?BookMetadata
    {
        $data = $this->fetch(self::OPEN_LIBRARY_URL, [
            'bibkeys' => 'ISBN:' . $isbn,
            'format'  => 'json',
            'jscmd'   => 'data',
        ]);
        $book = $data['ISBN:' . $isbn] ?? null;
        if (!\is_array($book) || empty($book['title'])) {
            return null;
        }

        $title = trim($book['title'] . (empty($book['subtitle']) ? '' : ': ' . $book['subtitle']));

        return new BookMetadata(
            isbn: $isbn,
            title: $title,
            authors: self::authors(array_map(static fn(array $a): string => (string) ($a['name'] ?? ''), $book['authors'] ?? [])),
            edition: Edition::parse($book['edition_name'] ?? null),
            publishedYear: self::year($book['publish_date'] ?? null),
            source: 'Open Library',
        );
    }

    private function fromGoogleBooks(string $isbn): ?BookMetadata
    {
        $data = $this->fetch(self::GOOGLE_BOOKS_URL, ['q' => 'isbn:' . $isbn]);
        $book = $data['items'][0]['volumeInfo'] ?? null;
        if (!\is_array($book) || empty($book['title'])) {
            return null;
        }

        $title = trim($book['title'] . (empty($book['subtitle']) ? '' : ': ' . $book['subtitle']));

        return new BookMetadata(
            isbn: $isbn,
            title: $title,
            authors: self::authors($book['authors'] ?? []),
            edition: null,
            publishedYear: self::year($book['publishedDate'] ?? null),
            source: 'Google Books',
        );
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function fetch(string $url, array $query): array
    {
        try {
            return $this->httpClient->request('GET', $url, [
                'query'   => $query,
                'timeout' => 8,
                'headers' => ['User-Agent' => 'ScarfieTextbooks (https://stb.gitforked.com)'],
            ])->toArray();
        } catch (ExceptionInterface $e) {
            $this->failed = true;
            $this->logger->warning('Book lookup at {url} failed: {message}', ['url' => $url, 'message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Distinct, cleaned-up names. Open Library sometimes lists the same people
     * again as one "Surname, First; Surname, First" entry, which is dropped.
     *
     * @param array<mixed> $names
     *
     * @return list<string>
     */
    private static function authors(array $names): array
    {
        $authors = [];
        foreach ($names as $name) {
            $name = trim((string) preg_replace('/\s+/', ' ', (string) $name));
            if ($name !== '' && !str_contains($name, ';')) {
                $authors[mb_strtolower($name)] ??= $name;
            }
        }

        return array_values($authors);
    }


    private static function year(?string $date): ?string
    {
        return $date !== null && preg_match('/\b(\d{4})\b/', $date, $m) ? $m[1] : null;
    }
}
