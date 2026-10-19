<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Kept short on purpose: few enough to show as one row of filters.
 */
enum Category: string implements TranslatableInterface
{
    case Textbooks         = 'textbooks';
    case StudyAndTech      = 'study_and_tech';
    case FlatAndHome       = 'flat_and_home';
    case BikesAndTransport = 'bikes_and_transport';
    case ClothesAndOther   = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Textbooks         => 'Textbooks',
            self::StudyAndTech      => 'Study & tech',
            self::FlatAndHome       => 'Flat & home',
            self::BikesAndTransport => 'Bikes & transport',
            self::ClothesAndOther   => 'Clothes & other',
        };
    }

    /** Lets EasyAdmin (and forms) show label() instead of the case name. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), locale: $locale);
    }
}
