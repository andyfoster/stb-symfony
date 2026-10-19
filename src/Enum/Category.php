<?php

namespace App\Enum;

/**
 * Kept short on purpose: few enough to show as one row of filters.
 */
enum Category: string
{
    case Textbooks = 'textbooks';
    case StudyAndTech = 'study_and_tech';
    case FlatAndHome = 'flat_and_home';
    case BikesAndTransport = 'bikes_and_transport';
    case ClothesAndOther = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Textbooks => 'Textbooks',
            self::StudyAndTech => 'Study & tech',
            self::FlatAndHome => 'Flat & home',
            self::BikesAndTransport => 'Bikes & transport',
            self::ClothesAndOther => 'Clothes & other',
        };
    }
}
