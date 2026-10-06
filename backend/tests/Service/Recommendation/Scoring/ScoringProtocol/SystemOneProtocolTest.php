<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\ScoringProtocol;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\ScoringStateFactory;
use App\Service\Recommendation\Scoring\Factory\SystemOneRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use App\Service\Recommendation\Scoring\Model\SystemOneRequestModel;
use App\Service\Recommendation\Scoring\ScoringBatchPacker;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\Support\CompactJson;
use App\Service\Recommendation\Support\TokenEstimate;
use App\Tests\Support\StubSystemOneClient;
use PHPUnit\Framework\TestCase;

final class SystemOneProtocolTest extends TestCase
{
    /** Clef refuses more than 64 questions; the reader's share scales with the model's window. */
    public function testARequestCarriesAtMost64QuestionsAndTheReaderAShareOfTheWindow(): void
    {
        $protocol = self::protocol(new StubSystemOneClient());

        self::assertEquals(new ScoringBudgetModel(32_000, 64, 9_600, 2_000), $protocol->budget(32_000));
        self::assertEquals(new ScoringBudgetModel(8_192, 64, 2_457, 2_000), $protocol->budget(8_192));
    }

    /** Short Latin articles fit the token budget by far: the question cap closes each request. */
    public function testShortArticlesFillRequestsUpToTheQuestionCapInPoolOrder(): void
    {
        $candidates = self::candidates(250, 'Short title', 'Short description.');

        $batches = self::protocol(new StubSystemOneClient())->pack(self::jevBudget(), $candidates);

        self::assertSame([64, 64, 64, 58], array_map(\count(...), $batches));
        self::assertSame(range(1, 250), array_merge(...$batches));
    }

    /**
     * Three-byte characters in every field, 731 tokens a question: 20,400 tokens (32k less 2k framing and 9.6k state)
     * hold 27 of them, so the token budget closes each request before the question cap.
     */
    public function testHeavyArticlesFillRequestsUpToTheTokenBudget(): void
    {
        $candidates = self::candidates(120, str_repeat('漢', 300), str_repeat('漢', 600));

        $batches = self::protocol(new StubSystemOneClient())->pack(self::jevBudget(), $candidates);

        self::assertSame([27, 27, 27, 27, 12], array_map(\count(...), $batches));
        self::assertSame(range(1, 120), array_merge(...$batches));
        $factory = self::requestFactory();
        foreach ($batches as $batch) {
            $tokens = 0;
            foreach ($batch as $entryId) {
                $tokens += TokenEstimate::of(CompactJson::encode($factory->question($candidates[$entryId - 1])));
            }
            self::assertLessThanOrEqual(20_400, $tokens);
        }
    }

    public function testTheRunLogGetsTheSystemOneBodyPrettyPrinted(): void
    {
        $request = self::request([new ArticleLineModel(41, 'Kernel 6.18', 'LWN', '2026-10-01', null)]);

        self::assertSame(
            self::requestFactory()->create($request)->toRenderedRequest(),
            self::protocol(new StubSystemOneClient())->renderedRequest($request),
        );
    }

    public function testEveryRequestIsAskedAsItsSystemOneRequestAndAnsweredInItsPlace(): void
    {
        $client = new StubSystemOneClient();
        $client->queueNouls(static fn (int $entryId): float => 0.25);
        $client->queueNouls(static fn (int $entryId): float => 0.75);

        $outcomes = self::protocol($client)->scoreMany(
            ProviderCredentialsModel::fromStoredConfiguration('https://api.typesafe.test/v1', 'sk-jev'),
            [self::request([self::article(41)]), self::request([self::article(7), self::article(9)])],
        );

        self::assertSame(
            [['entry-41'], ['entry-7', 'entry-9']],
            array_map(
                static fn (SystemOneRequestModel $request): array => array_keys($request->questions),
                $client->requests(),
            ),
        );
        self::assertSame([41 => 0.25], $outcomes[0]->reply()->scores);
        self::assertSame([7 => 0.75, 9 => 0.75], $outcomes[1]->reply()->scores);
    }

    private static function protocol(StubSystemOneClient $client): SystemOneProtocol
    {
        return new SystemOneProtocol(new ScoringBatchPacker(), self::requestFactory(), $client);
    }

    private static function requestFactory(): SystemOneRequestFactory
    {
        return new SystemOneRequestFactory(new ScoringStateFactory());
    }

    private static function jevBudget(): ScoringBudgetModel
    {
        return self::protocol(new StubSystemOneClient())->budget(32_000);
    }

    /** @param list<ArticleLineModel> $articles */
    private static function request(array $articles): ScoringRequestModel
    {
        return new ScoringRequestModel(
            'jev-latest',
            new ScoringReaderModel('Likes Rust.', null, []),
            self::jevBudget(),
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
