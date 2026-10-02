<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev\Factory;

use App\Service\Recommendation\Jev\Factory\JevStateFactory;
use App\Service\Recommendation\Jev\Support\JevTokenEstimate;
use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Pool\Model\RecommendationHistoryModel;
use PHPUnit\Framework\TestCase;

final class JevStateFactoryTest extends TestCase
{
    public function testTheStateHoldsTheGuidanceThenTheHistoryAsStructuredArticles(): void
    {
        $state = (new JevStateFactory())->create(
            'More self-hosting, less crypto.',
            new RecommendationHistoryModel(
                favorites: [new ArticleLineModel(1, 'Home Assistant 2026.10', 'heise', '2026-10-01', null)],
                kept: [],
                viewed: [new ArticleLineModel(2, 'Rust 1.90', 'LWN', '2026-09-30', str_repeat('ä', 300))],
            ),
        );

        self::assertSame(['guidance', 'history'], array_keys($state));
        self::assertSame('More self-hosting, less crypto.', $state['guidance']);
        self::assertSame(
            [
                'favorites' => [['title' => 'Home Assistant 2026.10', 'feedName' => 'heise', 'date' => '2026-10-01']],
                'kept' => [],
                'viewed' => [[
                    'title' => 'Rust 1.90',
                    'feedName' => 'LWN',
                    'date' => '2026-09-30',
                    'description' => str_repeat('ä', 280) . '…',
                ]],
            ],
            $state['history'],
        );
    }

    /** The LLM's default guidance is a chat instruction; System One gets no guidance at all instead. */
    public function testWithoutGuidanceTheStateIsTheHistoryAlone(): void
    {
        $state = (new JevStateFactory())->create(null, new RecommendationHistoryModel([], [], []));

        self::assertSame(['history'], array_keys($state));
    }

    public function testGuidanceWithAnInvalidByteSequenceStillEncodes(): void
    {
        $state = (new JevStateFactory())->create("Mehr \xC3 Rust", new RecommendationHistoryModel([], [], []));

        self::assertSame('Mehr ? Rust', $state['guidance']);
        self::assertJson(json_encode($state, \JSON_THROW_ON_ERROR));
    }

    /** 400 long viewed lines are far over budget: the oldest viewed lines go, favorites and kept stay whole. */
    public function testOverBudgetTheOldestViewedLinesGoFirst(): void
    {
        $line = static fn (string $title): ArticleLineModel
            => new ArticleLineModel(1, $title, 'Feed', '2026-10-01', str_repeat('x', 600));
        $viewed = array_map(static fn (int $index): ArticleLineModel => $line('viewed ' . $index), range(0, 399));

        $state = (new JevStateFactory())->create(
            null,
            new RecommendationHistoryModel(
                favorites: [$line('favorite 0'), $line('favorite 1'), $line('favorite 2')],
                kept: [$line('kept 0'), $line('kept 1'), $line('kept 2')],
                viewed: $viewed,
            ),
        );

        /** @var array{favorites: list<array<string, string>>, kept: list<array<string, string>>, viewed: list<array<string, string>>} $history */
        $history = $state['history'];
        self::assertCount(3, $history['favorites']);
        self::assertCount(3, $history['kept']);
        self::assertGreaterThan(0, \count($history['viewed']));
        self::assertLessThan(400, \count($history['viewed']));
        self::assertSame('viewed 0', $history['viewed'][0]['title']);
        self::assertLessThanOrEqual(JevStateFactory::STATE_TOKEN_BUDGET, JevTokenEstimate::ofJson($state));
    }
}
