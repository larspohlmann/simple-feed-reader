<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Scoring\Support;

use App\Service\Content\LinkCard\Model\StoryLinkModel;
use App\Service\Curation\Recommendation\Pool\Model\ArticleLineModel;
use App\Service\Curation\Recommendation\Scoring\Support\ScoringArticle;
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

    public function testALineEndsWithTheArticleItsPostShares(): void
    {
        self::assertSame(
            'This 👇 — Mastodon, 2026-11-10. This 👇 Shares: Council votes on budget (Example News): Approved.',
            ScoringArticle::line(new ArticleLineModel(
                7,
                'This 👇',
                'Mastodon',
                '2026-11-10',
                'This 👇',
                self::councilArticle('Approved.'),
            )),
        );
    }

    public function testASharedArticleWithoutADescriptionEndsAtItsSite(): void
    {
        self::assertSame(
            ['title' => 'Post', 'feedName' => 'Mastodon', 'date' => '2026-11-10',
                'sharedArticle' => 'Council votes on budget (Example News)'],
            ScoringArticle::buildFields(
                new ArticleLineModel(7, 'Post', 'Mastodon', '2026-11-10', null, self::councilArticle(null)),
            ),
        );
    }

    public function testThePostTextGivesWayToTheSharedArticle(): void
    {
        $line = new ArticleLineModel(
            7,
            'Post',
            'Mastodon',
            '2026-11-10',
            str_repeat('p', 700),
            self::councilArticle(str_repeat('c', 400)),
        );

        $article = ScoringArticle::buildFields($line);

        self::assertSame(str_repeat('p', 600 - mb_strlen($article['sharedArticle'])) . '…', $article['description']);
        self::assertSame(
            'Council votes on budget (Example News): ' . str_repeat('c', 300 - 40) . '…',
            $article['sharedArticle'],
        );
        self::assertStringEndsWith(' Shares: ' . $article['sharedArticle'], ScoringArticle::line($line));
    }

    public function testAnArticleWithoutASharedArticleHasNoSuchKey(): void
    {
        self::assertSame(
            ['title' => 'Post', 'feedName' => 'Mastodon', 'date' => '2026-11-10', 'description' => 'Text'],
            ScoringArticle::buildFields(new ArticleLineModel(7, 'Post', 'Mastodon', '2026-11-10', 'Text')),
        );
    }

    private static function councilArticle(?string $description): StoryLinkModel
    {
        return new StoryLinkModel(str_repeat('a', 64), 'Council votes on budget', $description, 'Example News');
    }
}
