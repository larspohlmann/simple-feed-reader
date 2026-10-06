<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Ai\Model\ProviderCallUsageModel;
use App\Service\Recommendation\Scoring\Support\RerankReplyDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RerankReplyDecoderTest extends TestCase
{
    private const string COHERE = '{"id":"gen-rerank-1759740000-cohere","model":"cohere/rerank-4-fast-20260901",'
        . '"provider":"Cohere","results":[{"index":1,"relevance_score":0.874,"document":{"text":"Postgres 19"}},'
        . '{"index":0,"relevance_score":0.0731,"document":{"text":"Pumpkin soup"}}],'
        . '"usage":{"search_units":1,"total_tokens":38,"cost":0.002}}';

    private const string VOYAGE = '{"id":"gen-rerank-1759740001-voyage","model":"voyageai/rerank-3",'
        . '"provider":"Voyage","results":[{"index":0,"relevance_score":0.6523,"document":{"text":"Pumpkin soup"}}],'
        . '"usage":{"total_tokens":412,"cost":0.0000206}}';

    /** Results come sorted by relevance: index 1 first. Mapped by index, not by position in `results`. */
    public function testEachResultScoresTheEntryItsIndexNames(): void
    {
        $reply = RerankReplyDecoder::decode(self::COHERE, [7, 9]);

        self::assertSame([9 => 0.874, 7 => 0.0731], $reply->scores);
        self::assertSame(self::COHERE, $reply->body);
    }

    public function testTheReceiptReadsTheBodysIdAnsweringModelAndCost(): void
    {
        $receipt = RerankReplyDecoder::decode(self::COHERE, [7, 9])->receipt;

        self::assertSame('gen-rerank-1759740000-cohere', $receipt->requestId);
        self::assertSame('cohere/rerank-4-fast-20260901', $receipt->answeringModel);
        self::assertSame(2_000_000, $receipt->usage?->costNanoCredits);
    }

    public function testTheTotalTokensAreThePromptTokensWhateverElseIsBilled(): void
    {
        $usage = self::usageOf(self::COHERE);

        self::assertSame(38, $usage->promptTokens);
    }

    public function testATokenBilledRerankerReportsItsTotalAsPromptTokens(): void
    {
        $usage = self::usageOf(self::VOYAGE);

        self::assertSame(412, $usage->promptTokens);
        self::assertSame(0, $usage->completionTokens);
        self::assertSame(0, $usage->reasoningTokens);
        self::assertSame(0, $usage->cachedTokens);
        self::assertSame(20_600, $usage->costNanoCredits);
    }

    /** @return iterable<string, array{string}> */
    public static function usagesWithoutUsableTotal(): iterable
    {
        yield 'a total of zero' => ['{"usage":{"total_tokens":0,"cost":0.002}}'];
        yield 'a negative total' => ['{"usage":{"total_tokens":-5,"cost":0.002}}'];
        yield 'a total that is no integer' => ['{"usage":{"total_tokens":"412","cost":0.002}}'];
        yield 'search units only' => ['{"usage":{"search_units":1,"cost":0.002}}'];
    }

    #[DataProvider('usagesWithoutUsableTotal')]
    public function testAnUnusableTotalReadsZeroPromptTokensAndKeepsTheCost(string $body): void
    {
        $usage = self::usageOf($body);

        self::assertSame(0, $usage->promptTokens);
        self::assertSame(2_000_000, $usage->costNanoCredits);
    }

    /** @return iterable<string, array{string}> */
    public static function replyWithoutIdOrModel(): iterable
    {
        yield 'empty strings' => ['{"id":"","model":"","results":[]}'];
        yield 'non-strings' => ['{"id":12,"model":["cohere/rerank-4-fast"],"results":[]}'];
        yield 'absent' => ['{"results":[]}'];
    }

    #[DataProvider('replyWithoutIdOrModel')]
    public function testAnIdOrModelThatIsNoTextIsNone(string $body): void
    {
        $receipt = RerankReplyDecoder::decode($body, [7])->receipt;

        self::assertNull($receipt->requestId);
        self::assertNull($receipt->answeringModel);
    }

    public function testAReplyWithoutUsageHasNone(): void
    {
        self::assertNull(RerankReplyDecoder::decode('{"results":[]}', [7])->receipt->usage);
    }

    /**
     * Every entry has a score in the repeated case, so only the repeat check refuses it.
     *
     * @return iterable<string, array{string}>
     */
    public static function spoiledReplies(): iterable
    {
        yield 'a repeated index' => [
            '{"results":[{"index":0,"relevance_score":0.9},{"index":1,"relevance_score":0.4},'
            . '{"index":0,"relevance_score":0.2}]}',
        ];
        yield 'no results' => ['{"model":"cohere/rerank-4-fast"}'];
        yield 'results that are no list' => ['{"results":"none"}'];
        yield 'no JSON' => ['<html>Bad Gateway</html>'];
    }

    #[DataProvider('spoiledReplies')]
    public function testASpoiledReplyScoresNothing(string $body): void
    {
        self::assertSame([], RerankReplyDecoder::decode($body, [7, 9])->scores);
    }

    /** @return iterable<string, array{string, array<int, float>}> */
    public static function partlyReadableReplies(): iterable
    {
        yield 'an index outside the request is ignored' => [
            '{"results":[{"index":0,"relevance_score":0.5},{"index":5,"relevance_score":0.9}]}',
            [7 => 0.5],
        ];
        yield 'a result without a number is skipped' => [
            '{"results":[{"index":0,"relevance_score":"high"},{"index":1,"relevance_score":0.4}]}',
            [9 => 0.4],
        ];
        yield 'an index that is no integer is skipped' => [
            '{"results":[{"index":"0","relevance_score":0.3},{"index":1,"relevance_score":0.4}]}',
            [9 => 0.4],
        ];
        yield 'a raw logit passes, for the parser to refuse' => [
            '{"results":[{"index":0,"relevance_score":3.2},{"index":1,"relevance_score":1}]}',
            [7 => 3.2, 9 => 1.0],
        ];
    }

    /** @param array<int, float> $scores */
    #[DataProvider('partlyReadableReplies')]
    public function testOnlyTheReadableResultsScore(string $body, array $scores): void
    {
        self::assertSame($scores, RerankReplyDecoder::decode($body, [7, 9])->scores);
    }

    private static function usageOf(string $body): ProviderCallUsageModel
    {
        $usage = RerankReplyDecoder::decode($body, [7, 9])->receipt->usage;
        self::assertNotNull($usage);

        return $usage;
    }
}
