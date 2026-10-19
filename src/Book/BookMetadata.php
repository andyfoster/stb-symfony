<?php

namespace App\Book;

/**
 * What a book API knows about an ISBN, for prefilling the listing form.
 */
final readonly class BookMetadata
{
    /**
     * @param list<string> $authors
     */
    public function __construct(
        public string $isbn,
        public string $title,
        public array $authors = [],
        public ?string $edition = null,
        public ?string $publishedYear = null,
        public string $source = '',
    ) {}
}
