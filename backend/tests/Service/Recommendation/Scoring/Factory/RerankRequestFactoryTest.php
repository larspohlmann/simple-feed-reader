<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Factory;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Factory\RerankQueryFactory;
use App\Service\Recommendation\Scoring\Factory\RerankRequestFactory;
use App\Service\Recommendation\Scoring\Model\ScoringBudgetModel;
use App\Service\Recommendation\Scoring\Model\ScoringReaderModel;
use App\Service\Recommendation\Scoring\Model\ScoringRequestModel;
use PHPUnit\Framework\TestCase;

final class RerankRequestFactoryTest extends TestCase
{
    public function testEachArticleIsOneDocumentKeyedByItsEntryInBatchOrder(): void
    {
        $request = self::factory()->create(new ScoringRequestModel(
            'cohere/rerank-4-fast',
            new ScoringReaderModel('Likes Rust.', null, []),
            new ScoringBudgetModel(32_768, 100, 9_830, 2_000),
            [
                new ArticleLineModel(9, 'Kernel 6.18', 'LWN', '2026-10-01', null),
                new ArticleLineModel(7, 'Soup', 'Kitchen', '2026-10-02', 'Pumpkin.'),
            ],
        ));

        self::assertSame('cohere/rerank-4-fast', $request->model);
        self::assertSame(RerankQueryFactory::QUESTION . "\nProfile: Likes Rust.", $request->query);
        self::assertSame(
            [9 => 'Kernel 6.18 — LWN, 2026-10-01.', 7 => 'Soup — Kitchen, 2026-10-02. Pumpkin.'],
            $request->documents,
        );
    }

    /**
     * The reader's share (50 tokens, 199 bytes) and a document's (2,150 − 2,000 − 50 = 100 tokens, 399 bytes) disagree:
     * the query takes the first, each document the second. The line is 621 bytes; "—" is three of them.
     */
    public function testTheQueryGetsTheReadersShareAndEachDocumentTheItemShare(): void
    {
        $request = self::factory()->create(new ScoringRequestModel(
            'cohere/rerank-4-fast',
            new ScoringReaderModel(str_repeat('p', 1_000), null, []),
            new ScoringBudgetModel(2_150, 64, 50, 2_000),
            [new ArticleLineModel(7, 'T', 'F', '2026-10-01', str_repeat('d', 600))],
        ));

        self::assertSame(RerankQueryFactory::QUESTION . "\nProfile: " . str_repeat('p', 95), $request->query);
        self::assertSame([7 => 'T — F, 2026-10-01. ' . str_repeat('d', 378)], $request->documents);
    }

    private static function factory(): RerankRequestFactory
    {
        return new RerankRequestFactory(new RerankQueryFactory());
    }
}
