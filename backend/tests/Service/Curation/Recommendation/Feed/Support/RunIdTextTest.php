<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Feed\Support;

use App\Service\Curation\Recommendation\Feed\Support\RunIdText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunIdTextTest extends TestCase
{
    public function testParsesTheSmallestId(): void
    {
        self::assertSame(1, RunIdText::parse('1'));
    }

    public function testParsesAnEighteenDigitId(): void
    {
        self::assertSame(123456789012345678, RunIdText::parse('123456789012345678'));
    }

    #[DataProvider('provideNoId')]
    public function testIsNoId(string $text): void
    {
        self::assertNull(RunIdText::parse($text));
    }

    /** @return iterable<string, array{string}> */
    public static function provideNoId(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'all zeros' => ['000'];
        yield 'negative' => ['-3'];
        yield 'letters' => ['12a'];
        yield 'nineteen digits' => ['1234567890123456789'];
    }
}
