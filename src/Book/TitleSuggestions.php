<?php

namespace App\Book;

use App\Entity\Book;
use App\Repository\BookRepository;
use App\Repository\ListingRepository;

/**
 * Textbook titles for the listing form: the Book list (every book the old site
 * had) together with what's been listed here, counted once per book.
 */
class TitleSuggestions
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly ListingRepository $listings,
    ) {}

    /**
     * @return list<array{title: string, author: string, listings: int, sold: int}>
     */
    public function suggest(string $term, int $limit = 8): array
    {
        $merged = [];
        foreach ([...$this->books->suggestTitles($term, $limit), ...$this->listings->suggestBookTitles($term, $limit)] as $row) {
            foreach ($merged as $i => $book) {
                if (Book::sameBook($book['title'], $book['author'], $row['title'], $row['author'])) {
                    $merged[$i]['listings'] += $row['listings'];
                    $merged[$i]['sold'] += $row['sold'];
                    continue 2;
                }
            }
            $merged[] = $row;
        }

        $term = mb_strtolower(trim($term));
        usort($merged, static fn (array $a, array $b): int => [!str_starts_with(mb_strtolower($a['title']), $term), -$a['sold'], -$a['listings'], mb_strtolower($a['title'])]
            <=> [!str_starts_with(mb_strtolower($b['title']), $term), -$b['sold'], -$b['listings'], mb_strtolower($b['title'])]);

        return \array_slice($merged, 0, $limit);
    }
}
