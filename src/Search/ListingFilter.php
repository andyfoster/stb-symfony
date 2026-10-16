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
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $q = null,
        public ?ListingType $type = null,
        public ?Category $category = null,
        /** Whole dollars. */
        #[Assert\PositiveOrZero]
        public ?int $maxPrice = null,
    ) {
    }
}
