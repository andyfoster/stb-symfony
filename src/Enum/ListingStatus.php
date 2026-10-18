<?php

namespace App\Enum;

/**
 * Where a listing is in its life. "Expired" isn't a status: an active listing
 * past its expiresAt is expired (see Listing::isExpired()).
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Sold => 'Sold',
        };
    }
}
