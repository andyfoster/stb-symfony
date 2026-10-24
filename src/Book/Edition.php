<?php

namespace App\Book;

/**
 * Book editions are stored as plain numbers (12) and shown as ordinals ("12th").
 */
final class Edition
{
    private const array WORDS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
        'eleventh' => 11, 'twelfth' => 12, 'thirteenth' => 13, 'fourteenth' => 14, 'fifteenth' => 15,
        'sixteenth' => 16, 'seventeenth' => 17, 'eighteenth' => 18, 'nineteenth' => 19, 'twentieth' => 20,
    ];

    /**
     * The number in what people type: "12", "12th", "12e", "12th ed.", "Twelfth edition".
     * Null when there's no edition number in it.
     */
    public static function parse(?string $edition): ?int
    {
        $edition = mb_strtolower(trim((string) $edition));
        $edition = trim((string) preg_replace('/[\s.,]*\b(edition|edn|ed)\b\.?$/', '', $edition));

        if (preg_match('/^(\d{1,3})\s*(st|nd|rd|th|e)?\.?$/', $edition, $match)) {
            return (int) $match[1] ?: null;
        }

        return self::WORDS[$edition] ?? null;
    }

    /** 1 → "1st", 12 → "12th", 22 → "22nd". */
    public static function ordinal(int $edition): string
    {
        $suffix = \in_array($edition % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$edition % 10] ?? 'th');

        return $edition . $suffix;
    }
}
