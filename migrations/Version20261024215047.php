<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024215047 extends AbstractMigration
{
    private const array WORDS = [
        'first', 'second', 'third', 'fourth', 'fifth', 'sixth', 'seventh', 'eighth', 'ninth', 'tenth',
        'eleventh', 'twelfth', 'thirteenth', 'fourteenth', 'fifteenth', 'sixteenth', 'seventeenth', 'eighteenth', 'nineteenth', 'twentieth',
    ];

    public function getDescription(): string
    {
        return 'Store book editions as numbers: "12th", "12e" and "Twelfth" become 12';
    }

    public function up(Schema $schema): void
    {
        $words = '';
        foreach (self::WORDS as $i => $word) {
            $words .= \sprintf(" WHEN '%s' THEN %d", $word, $i + 1);
        }
        $number = "COALESCE(CAST(substring(lower(edition) from '^\\s*(\\d{1,3})') AS INT),"
            . " CASE trim(regexp_replace(lower(edition), '[\\s.,]*\\m(edition|edn|ed)\\M\\.?$', '')){$words} END)";

        // The old text default (NULL::character varying) can't be cast, so drop it first.
        $this->addSql('ALTER TABLE book_details ALTER edition DROP DEFAULT');
        $this->addSql("ALTER TABLE book_details ALTER edition TYPE SMALLINT USING CASE WHEN $number BETWEEN 1 AND 99 THEN $number END");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE book_details ALTER edition TYPE VARCHAR(50) USING CASE
            WHEN edition IS NULL THEN NULL
            WHEN edition % 100 IN (11, 12, 13) THEN edition || 'th'
            WHEN edition % 10 = 1 THEN edition || 'st'
            WHEN edition % 10 = 2 THEN edition || 'nd'
            WHEN edition % 10 = 3 THEN edition || 'rd'
            ELSE edition || 'th' END");
    }
}
