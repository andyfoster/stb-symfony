<?php

namespace App\Tests\Book;

use App\Book\Edition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EditionTest extends TestCase
{
    #[DataProvider('typed')]
    public function testParsesWhatPeopleType(string $typed, ?int $edition): void
    {
        self::assertSame($edition, Edition::parse($typed));
    }

    public static function typed(): iterable
    {
        yield ['12', 12];
        yield ['12th', 12];
        yield ['12e', 12];
        yield [' 12TH ', 12];
        yield ['12th ed.', 12];
        yield ['12th edition', 12];
        yield ['2nd Edition', 2];
        yield ['Twelfth', 12];
        yield ['second edition', 2];
        yield ['', null];
        yield ['0', null];
        yield ['Global', null];
        yield ['12th global', null];
    }

    public function testOrdinals(): void
    {
        self::assertSame(['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '101st'], array_map(Edition::ordinal(...), [1, 2, 3, 4, 11, 12, 13, 21, 22, 101]));
    }
}
