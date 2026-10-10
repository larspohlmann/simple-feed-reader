<?php

declare(strict_types=1);

namespace App\Tests\Service\Text\Support;

use App\Service\Text\Support\WordBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function texts(): iterable
    {
        yield 'a text within the length stays whole' => ['one two', 7, 'one two'];
        yield 'a longer text ends before the last space that fits' => ['one two three', 9, 'one two'];
        yield 'a text without a space is cut hard' => ['abcdefgh', 5, 'abcde'];
        yield 'length counts characters, not bytes' => ['ää öö üü', 6, 'ää öö'];
    }

    #[DataProvider('texts')]
    public function testCutsAtAWordBoundary(string $text, int $maxLength, string $expected): void
    {
        self::assertSame($expected, WordBoundary::cut($text, $maxLength));
    }
}
