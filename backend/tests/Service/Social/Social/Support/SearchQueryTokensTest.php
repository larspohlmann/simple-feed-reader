<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\SearchQueryTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryTokensTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function words(): iterable
    {
        yield 'a filter' => ['from:ada', true];
        yield 'a filter with a unicode name' => ['über:ada', true];
        yield 'a plain word' => ['rss', false];
        yield 'a colon with nothing after it' => ['rss:', false];
        yield 'a colon first' => [':rss', false];
        yield 'a digit before the colon' => ['2026:rss', false];
    }

    #[DataProvider('words')]
    public function testAFilterIsAWordOfLettersThenAColonThenAValue(string $word, bool $isFilter): void
    {
        self::assertSame($isFilter, SearchQueryTokens::isFilter($word));
    }
}
