<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending\Support;

use App\Service\Curation\Trending\Support\HeadlineBody;
use PHPUnit\Framework\TestCase;

final class HeadlineBodyTest extends TestCase
{
    public function testTheBodyIsTheSummaryWithItsMarkupStripped(): void
    {
        self::assertSame(
            'Storm hits coast. More text & detail',
            self::body('<p>Storm hits coast.</p><p>More text &amp; detail</p>'),
        );
    }

    public function testTheBodyIsCutAtTheLastSpaceWithinThreeHundredCharacters(): void
    {
        self::assertSame(rtrim(str_repeat('ab ', 100)), self::body(str_repeat('ab ', 120) . 'tail'));
    }

    public function testAWordCrossingTheThreeHundredthCharacterIsDroppedWhole(): void
    {
        self::assertSame(str_repeat('a', 295), self::body(str_repeat('a', 295) . ' ' . str_repeat('b', 20)));
    }

    public function testAJunkSummaryIsAnEmptyBody(): void
    {
        self::assertSame('', self::body('<p>none</p>'));
    }

    public function testAnInlineImageSeparatesTheWordsAroundIt(): void
    {
        self::assertSame('Storm hits', self::body('<p>Storm<img src="https://example.org/storm.jpg" alt="">hits</p>'));
    }

    public function testNoSummaryIsAnEmptyBody(): void
    {
        self::assertSame('', self::body(null));
    }

    public function testADerivedTitleIsNotRepeatedAtTheStartOfTheBody(): void
    {
        self::assertSame('Ten dead.', self::bodyAfter('Storm hits coast.', '<p>Storm hits coast. Ten dead.</p>'));
    }

    public function testAMultibyteTitleIsCutByCharactersNotBytes(): void
    {
        self::assertSame(
            'Dziesięć ofiar.',
            self::bodyAfter('Łódź: pożar.', '<p>Łódź: pożar. Dziesięć ofiar.</p>'),
        );
    }

    public function testAClippedDerivedTitleLosesItsEllipsisBeforeTheComparison(): void
    {
        self::assertSame(
            'that goes on',
            self::bodyAfter('A very long first sentence…', '<p>A very long first sentence that goes on</p>'),
        );
    }

    public function testATitleTheBodyDoesNotStartWithLeavesTheBodyWhole(): void
    {
        self::assertSame('Other words here', self::bodyAfter('Storm hits coast.', '<p>Other words here</p>'));
    }

    public function testTheBodyAfterTheTitleIsCutAtTheLastSpaceWithinThreeHundredCharacters(): void
    {
        self::assertSame(
            rtrim(str_repeat('ab ', 100)),
            self::bodyAfter('Title', 'Title ' . str_repeat('ab ', 120) . 'tail'),
        );
    }

    public function testAJunkSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', self::bodyAfter('Title', '<p>null</p>'));
    }

    public function testNoSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', self::bodyAfter('Title', null));
    }

    public function testAnExcerptKeepsUpToAThousandCharactersOnAWordBoundary(): void
    {
        $excerpt = HeadlineBody::body(str_repeat('word ', 300), HeadlineBody::EXCERPT_LENGTH);

        self::assertSame(rtrim(str_repeat('word ', 200)), $excerpt);
        self::assertLessThanOrEqual(1_000, mb_strlen($excerpt));
        self::assertSame('Short text', HeadlineBody::body('<p>Short text</p>', HeadlineBody::EXCERPT_LENGTH));
    }

    public function testAnExcerptAfterTitleDropsTheRepeatedHeadline(): void
    {
        self::assertSame(
            'Ten dead.',
            HeadlineBody::bodyAfterTitle(
                'Storm hits coast.',
                '<p>Storm hits coast. Ten dead.</p>',
                HeadlineBody::EXCERPT_LENGTH,
            ),
        );
        self::assertSame(
            rtrim(str_repeat('ab ', 333)),
            HeadlineBody::bodyAfterTitle('Title', 'Title ' . str_repeat('ab ', 400), HeadlineBody::EXCERPT_LENGTH),
        );
    }

    private static function body(?string $summary): string
    {
        return HeadlineBody::body($summary, HeadlineBody::BODY_LENGTH);
    }

    private static function bodyAfter(string $title, ?string $summary): string
    {
        return HeadlineBody::bodyAfterTitle($title, $summary, HeadlineBody::BODY_LENGTH);
    }
}
