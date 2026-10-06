<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\ScoringProtocol;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\RerankQueryFactory;
use App\Service\Recommendation\Scoring\Factory\RerankRequestFactory;
use App\Service\Recommendation\Scoring\Model\RerankRequestModel;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\ScoringProtocol\RerankProtocol;
use App\Service\Recommendation\Support\PrettyJson;
use App\Tests\Support\StubRerankClient;
use PHPUnit\Framework\TestCase;

final class RerankProtocolTest extends TestCase
{
    public function testARequestCarriesAtMost100DocumentsAndTheQueryAShareOfTheWindow(): void
    {
        $protocol = self::protocol(new StubRerankClient());

        self::assertEquals(new ScoringBudgetModel(32_768, 100, 9_830, 2_000), $protocol->budget(32_768));
        self::assertEquals(new ScoringBudgetModel(4_096, 100, 1_228, 2_000), $protocol->budget(4_096));
    }

    public function testThePoolIsPackedInHundredsInPoolOrder(): void
    {
        $batches = self::protocol(new StubRerankClient())->pack(
            self::protocol(new StubRerankClient())->budget(32_768),
            self::candidates(250, 'Short title', 'Short description.'),
        );

        self::assertSame([100, 100, 50], array_map(\count(...), $batches));
        self::assertSame(range(1, 250), array_merge(...$batches));
    }

    /** 681-token articles against 868 tokens a document: summed, every request would close after one article. */
    public function testHeavyArticlesStillPackInHundreds(): void
    {
        $protocol = self::protocol(new StubRerankClient());

        $batches = $protocol->pack(
            $protocol->budget(4_096),
            self::candidates(120, str_repeat('漢', 300), str_repeat('漢', 600)),
        );

        self::assertSame([100, 20], array_map(\count(...), $batches));
    }

    public function testTheRequestIsWordedOnceAsARerankRequestAndPrettyPrintedForTheRunLog(): void
    {
        $request = self::request([self::article(41)]);

        $protocol = self::protocol(new StubRerankClient());
        $worded = $protocol->word($request);

        self::assertEquals(self::requestFactory()->create($request), $worded);
        self::assertSame(PrettyJson::of($worded->payload()), $protocol->renderedRequest($worded));
    }

    public function testEveryRequestIsAskedAsItsRerankRequestAndAnsweredInItsPlace(): void
    {
        $client = new StubRerankClient();
        $client->queueRelevances(static fn (int $entryId): float => 0.25);
        $client->queueRelevances(static fn (int $entryId): float => 7 === $entryId ? 0.75 : 0.5);

        $protocol = self::protocol($client);

        $outcomes = $protocol->scoreMany(
            ProviderCredentialsModel::fromStoredConfiguration('https://openrouter.test/api/v1', 'sk-or'),
            [
                $protocol->word(self::request([self::article(41)])),
                $protocol->word(self::request([self::article(9), self::article(7)])),
            ],
        );

        self::assertSame(
            [[41], [9, 7]],
            array_map(static fn (RerankRequestModel $request): array => $request->entryIds(), $client->requests()),
        );
        self::assertSame([41 => 0.25], $outcomes[0]->reply()->scores);
        self::assertSame([7 => 0.75, 9 => 0.5], $outcomes[1]->reply()->scores);
    }

    private static function protocol(StubRerankClient $client): RerankProtocol
    {
        return new RerankProtocol(self::requestFactory(), $client);
    }

    private static function requestFactory(): RerankRequestFactory
    {
        return new RerankRequestFactory(new RerankQueryFactory());
    }

    /** @param non-empty-list<ArticleLineModel> $articles */
    private static function request(array $articles): ScoringRequestModel
    {
        return new ScoringRequestModel(
            'cohere/rerank-4-fast',
            new ScoringReaderModel('Likes Rust.', null, []),
            self::protocol(new StubRerankClient())->budget(32_768),
            $articles,
        );
    }

    private static function article(int $entryId): ArticleLineModel
    {
        return new ArticleLineModel($entryId, 'Title ' . $entryId, 'Feed', '2026-10-01', null);
    }

    /** @return list<ArticleLineModel> entry ids 1…$count */
    private static function candidates(int $count, string $title, string $description): array
    {
        return array_map(
            static fn (int $entryId): ArticleLineModel
                => new ArticleLineModel($entryId, $title, 'Feed', '2026-10-01', $description),
            range(1, $count),
        );
    }
}
