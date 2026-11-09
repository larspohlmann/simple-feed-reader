<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Service\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Pass\SearchQueryAlternatives;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryAlternativesTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function queries(): iterable
    {
        yield 'no alternatives' => ['rss reader', ['rss reader']];
        yield 'a group of phrases and words' => [
            '("rss reader" || "feed reader" || rssreader || feedreader)',
            ['"rss reader"', '"feed reader"', 'rssreader', 'feedreader'],
        ];
        yield 'shared exclusions go with every alternative' => [
            '(sternschanze || schanzenviertel) -"fc st. pauli" -from:hamburgnews.bsky.social',
            [
                'sternschanze -"fc st. pauli" -from:hamburgnews.bsky.social',
                'schanzenviertel -"fc st. pauli" -from:hamburgnews.bsky.social',
            ],
        ];
        yield 'two groups cross' => ['(a || b) (c || d)', ['a c', 'a d', 'b c', 'b d']];
        yield 'or binds looser than and' => ['a || b c', ['a', 'b c']];
        yield 'a negated group of alternatives is left out' => ['rss -(atom || json)', ['rss']];
        yield 'a negated group of two terms is left out' => ['rss -(atom json)', ['rss']];
        yield 'a negated group of one term is that term negated' => ['rss -(atom)', ['rss -atom']];
        yield 'repeated alternatives once' => ['rss || rss', ['rss']];
        yield 'an empty phrase is nothing' => ['rss ""', ['rss']];
        yield 'a stray closing bracket is nothing' => ['rss ) reader', ['rss reader']];
        yield 'an empty query' => ['', ['']];
    }

    /** @param list<string> $alternatives */
    #[DataProvider('queries')]
    public function testAQueryIsSpelledOutAsTheQueriesItIsTheAlternativesOf(string $query, array $alternatives): void
    {
        self::assertSame($alternatives, (new SearchQueryAlternatives($query))->queries());
    }

    public function testAsManyAlternativesAsTheCapAreSpelledOut(): void
    {
        self::assertCount(
            SearchQueryAlternatives::MAX_ALTERNATIVES,
            (new SearchQueryAlternatives('(a || b) (c || d) (e || f)'))->queries(),
        );
    }

    public function testOneAlternativeMoreThanTheCapIsTooBroad(): void
    {
        $this->expectException(SearchQueryTooBroadException::class);
        $this->expectExceptionMessage('more than 8');

        (new SearchQueryAlternatives('(a || b || c) (d || e || f)'))->queries();
    }
}
