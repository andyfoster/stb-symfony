<?php

namespace App\Enum;

enum Condition: string
{
    case New = 'new';
    case LikeNew = 'like_new';
    case Good = 'good';
    case Fair = 'fair';
    case WellUsed = 'well_used';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::LikeNew => 'Like new',
            self::Good => 'Good',
            self::Fair => 'Fair',
            self::WellUsed => 'Well used',
        };
    }
}
