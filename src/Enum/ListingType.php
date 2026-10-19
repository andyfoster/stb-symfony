<?php

namespace App\Enum;

enum ListingType: string
{
    case ForSale = 'for_sale';
    case Wanted  = 'wanted';

    public function label(): string
    {
        return match ($this) {
            self::ForSale => 'For sale',
            self::Wanted  => 'Wanted',
        };
    }
}
