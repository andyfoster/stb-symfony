<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where a listing is in its life. "Expired" isn't a status: an active listing
 * past its expiresAt is expired (see Listing::isExpired()).
 */
enum ListingStatus: string implements TranslatableInterface
{
    case Draft = 'draft';
    case Active = 'active';
    case Sold = 'sold';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Sold => 'Sold',
            self::Archived => 'Archived',
        };
    }

    /** Lets EasyAdmin (and forms) show label() instead of the case name. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), locale: $locale);
    }
}
