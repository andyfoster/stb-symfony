<?php

namespace App\Enum;

/**
 * How worn something for sale is. Buyers lean on this, so each level has a
 * plain description that the listing form and listing page show.
 */
enum Condition: string
{
    case New      = 'new';
    case LikeNew  = 'like_new';
    case Good     = 'good';
    case Fair     = 'fair';
    case WellUsed = 'well_used';

    public function label(): string
    {
        return match ($this) {
            self::New      => 'New',
            self::LikeNew  => 'Like new',
            self::Good     => 'Good',
            self::Fair     => 'Fair',
            self::WellUsed => 'Well used',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::New      => 'Never used, as it came from the shop.',
            self::LikeNew  => 'Used a little, with no marks or wear you’d notice.',
            self::Good     => 'Some signs of use, like a few notes or worn corners. Nothing missing.',
            self::Fair     => 'Clearly used: highlighting, notes or scuffs. Still does the job.',
            self::WellUsed => 'Heavily used or a bit battered, but still usable.',
        };
    }
}
