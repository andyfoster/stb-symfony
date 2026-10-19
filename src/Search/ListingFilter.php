<?php

namespace App\Search;

use App\Entity\Listing;
use App\Enum\Category;
use App\Enum\ListingType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters for browsing listings, bound from the home page's query string.
 * The listing-filter Stimulus controller applies the same rules in the
 * browser; matches() is for links with filters in them and for no JavaScript.
 */
final class ListingFilter
{
    /**
     * @param list<Category> $categories none means every category
     */
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
        /** Which tab: things for sale, or things people want. */
        public ListingType $type = ListingType::ForSale,
        public array $categories = [],
    ) {}

    /** Anything narrowing the list beyond the tab. */
    public function isNarrowed(): bool
    {
        return trim((string) $this->q) !== '' || $this->categories !== [];
    }

    /** The same search on the other tab. */
    public function withType(ListingType $type): self
    {
        return new self($this->q, $type, $this->categories);
    }

    public function matches(Listing $listing): bool
    {
        return $listing->getType() === $this->type && $this->matchesSearchAndCategories($listing);
    }

    /** The same search, on either tab (for the tab counts). */
    public function matchesSearchAndCategories(Listing $listing): bool
    {
        if ($this->categories !== [] && !\in_array($listing->getCategory(), $this->categories, true)) {
            return false;
        }

        $q = mb_strtolower(trim((string) $this->q));
        if ($q === '') {
            return true;
        }

        // "chem 191" finds CHEM191 and "978-0-13…" finds the ISBN, so also try without spaces and dashes.
        $text = self::searchText($listing);

        return str_contains($text, $q) || str_contains($text, self::squash($q));
    }

    /**
     * Everything a search looks at, lower-case. Also rendered as the card's
     * data-search attribute for the browser to match against.
     */
    public static function searchText(Listing $listing): string
    {
        $parts = [$listing->getTitle(), $listing->getDescription()];
        if (null !== $book = $listing->getBook()) {
            $parts[] = $book->getAuthor();
            $parts[] = self::squash((string) $book->getIsbn());
            foreach ($book->getPapers() as $paper) {
                $parts[] = $paper->getCode();
            }
        }

        return mb_strtolower(implode(' ', array_filter($parts)));
    }

    private static function squash(string $text): string
    {
        return (string) preg_replace('/[\s\-]+/', '', $text);
    }
}
