<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy\Support;

use App\Service\Social\Lemmy\Support\LemmySearchTerms;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Pass\SearchQueryAlternatives;
use App\Service\Social\Social\Support\SearchQueryTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LemmySearchTermsTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function queries(): iterable
    {
        yield 'one word' => ['rss', ['rss']];
        yield 'the longest word' => ['rss reader', ['reader']];
        yield 'a phrase as one term' => ['"rss reader" atom', ['rss reader']];
        yield 'one term per alternative' => ['rss || atom', ['rss', 'atom']];
        yield 'a group crossed with a word' => ['rss (reader || feed)', ['reader', 'feed']];
        yield 'a shared longest term is asked once' => ['feed (rss || atom)', ['feed']];
        yield 'case does not matter' => ['Reader || reader', ['reader']];
        yield 'an exclusion is no term' => ['reader -gazette', ['reader']];
        yield 'a negated phrase is no term' => ['reader -"daily gazette"', ['reader']];
        yield 'an operator is no term' => ['reader from:ada lang:de', ['reader']];
        yield 'the first on a tie' => ['atom feed', ['atom']];
        yield 'a hyphenated word as its words' => ['rss-reader', ['rss reader']];
        yield 'a phrase as its words' => ['"rss, reader" atom', ['rss reader']];
        yield 'a hashtag as its word' => ['#rust', ['rust']];
        yield 'a dotted name as its words' => ['node.js', ['node js']];
        yield 'a phrase without a word is no term' => ['"!!!!!!" rss', ['rss']];
        yield 'a word without a letter is no term' => ['rss !!!!!!', ['rss']];
        yield 'a url as its words' => ['https://example.com/a', ['https example com a']];
        yield 'a colon word is a term' => ['re:zero', ['re zero']];
        yield 'a filter word yields to a checked word' => ['reader lang:de', ['reader']];
        yield 'a url yields to a checked word' => ['rust https://example.com/a', ['rust']];
        yield 'a filter word alone is the term' => ['lang:de from:ada', ['lang de']];
        yield 'a filter word per alternative' => ['reader || lang:de', ['reader', 'lang de']];
        yield 'an author filter is no term' => ['reader from:ada', ['reader']];
        yield 'the own author filter is no term' => ['rust from:me', ['rust']];
        yield 'a quoted author filter is a phrase' => ['"from:ada"', ['from ada']];
    }

    /** @param list<string> $terms */
    #[DataProvider('queries')]
    public function testEachAlternativeAsksItsLongestTerm(string $query, array $terms): void
    {
        self::assertSame($terms, LemmySearchTerms::of(self::factorsOf($query)));
    }

    /** @return iterable<string, array{string}> */
    public static function termless(): iterable
    {
        yield 'only an exclusion' => ['-gazette'];
        yield 'only an operator' => ['from:ada'];
        yield 'only the own author filter' => ['from:me'];
        yield 'only punctuation' => ['!!!'];
        yield 'one alternative without a term' => ['rss || -gazette'];
    }

    #[DataProvider('termless')]
    public function testAnAlternativeWithoutATermIsTooBroad(string $query): void
    {
        $this->expectException(SearchQueryTooBroadException::class);
        $this->expectExceptionMessage('Lemmy needs a word to search for in every alternative.');

        LemmySearchTerms::of(self::factorsOf($query));
    }

    /** @return list<list<string>> */
    private static function factorsOf(string $query): array
    {
        return new SearchQueryAlternatives(SearchQueryTokens::of($query))->factors();
    }
}
