<?php

namespace App\Search;

use App\Enum\Category;
use App\Enum\ListingType;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Filters for browsing listings, bound from the home page's query string.
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
    ) {
    }

    /** Anything narrowing the list beyond the tab. */
    public function isNarrowed(): bool
    {
        return '' !== trim((string) $this->q) || [] !== $this->categories;
    }

    /** The same search on the other tab. */
    public function withType(ListingType $type): self
    {
        return new self($this->q, $type, $this->categories);
    }
}
