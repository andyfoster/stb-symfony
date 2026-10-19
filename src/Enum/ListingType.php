<?php

namespace App\Enum;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum ListingType: string implements TranslatableInterface
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

    /** Lets EasyAdmin (and forms) show label() instead of the case name. */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->label(), locale: $locale);
    }
}
