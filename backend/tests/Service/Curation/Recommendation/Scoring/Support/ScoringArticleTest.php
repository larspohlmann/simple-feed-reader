<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring\Support;

use App\Service\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Recommendation\Scoring\Support\ScoringArticle;
use PHPUnit\Framework\TestCase;

final class ScoringArticleTest extends TestCase
{
    public function testALineNamesTheTitleTheFeedAndTheDate(): void
    {
        self::assertSame(
            'Kernel 6.18 — LWN, 2026-10-01.',
            ScoringArticle::line(new ArticleLineModel(41, 'Kernel 6.18', 'LWN', '2026-10-01', null)),
        );
    }

    public function testADescriptionFollowsTheDate(): void
    {
        self::assertSame(
            'Kernel 6.18 — LWN, 2026-10-01. Rust lands in the kernel.',
            ScoringArticle::line(
                new ArticleLineModel(41, 'Kernel 6.18', 'LWN', '2026-10-01', 'Rust lands in the kernel.'),
            ),
        );
    }

    /** One character over each cap: the line clips like the System One article (300, 120, 600). */
    public function testALineKeepsTheArticlesCaps(): void
    {
        $line = ScoringArticle::line(new ArticleLineModel(
            41,
            str_repeat('t', 301),
            str_repeat('f', 121),
            '2026-10-01',
            str_repeat('d', 601),
        ));

        self::assertSame(
            str_repeat('t', 300) . '… — ' . str_repeat('f', 120) . '…, 2026-10-01. ' . str_repeat('d', 600) . '…',
            $line,
        );
    }
}
