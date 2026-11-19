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
            HeadlineBody::body('<p>Storm hits coast.</p><p>More text &amp; detail</p>'),
        );
    }

    public function testTheBodyIsCutAtTheLastSpaceWithinThreeHundredCharacters(): void
    {
        self::assertSame(rtrim(str_repeat('ab ', 100)), HeadlineBody::body(str_repeat('ab ', 120) . 'tail'));
    }

    public function testAWordCrossingTheThreeHundredthCharacterIsDroppedWhole(): void
    {
        self::assertSame(
            str_repeat('a', 295),
            HeadlineBody::body(str_repeat('a', 295) . ' ' . str_repeat('b', 20)),
        );
    }

    public function testAJunkSummaryIsAnEmptyBody(): void
    {
        self::assertSame('', HeadlineBody::body('<p>none</p>'));
    }

    public function testAnInlineImageSeparatesTheWordsAroundIt(): void
    {
        self::assertSame(
            'Storm hits',
            HeadlineBody::body('<p>Storm<img src="https://example.org/storm.jpg" alt="">hits</p>'),
        );
    }

    public function testNoSummaryIsAnEmptyBody(): void
    {
        self::assertSame('', HeadlineBody::body(null));
    }

    public function testADerivedTitleIsNotRepeatedAtTheStartOfTheBody(): void
    {
        self::assertSame(
            'Ten dead.',
            HeadlineBody::bodyAfterTitle('Storm hits coast.', '<p>Storm hits coast. Ten dead.</p>'),
        );
    }

    public function testAMultibyteTitleIsCutByCharactersNotBytes(): void
    {
        self::assertSame(
            'Dziesięć ofiar.',
            HeadlineBody::bodyAfterTitle('Łódź: pożar.', '<p>Łódź: pożar. Dziesięć ofiar.</p>'),
        );
    }

    public function testAClippedDerivedTitleLosesItsEllipsisBeforeTheComparison(): void
    {
        self::assertSame(
            'that goes on',
            HeadlineBody::bodyAfterTitle(
                'A very long first sentence…',
                '<p>A very long first sentence that goes on</p>',
            ),
        );
    }

    public function testATitleTheBodyDoesNotStartWithLeavesTheBodyWhole(): void
    {
        self::assertSame(
            'Other words here',
            HeadlineBody::bodyAfterTitle('Storm hits coast.', '<p>Other words here</p>'),
        );
    }

    public function testTheBodyAfterTheTitleIsCutAtTheLastSpaceWithinThreeHundredCharacters(): void
    {
        self::assertSame(
            rtrim(str_repeat('ab ', 100)),
            HeadlineBody::bodyAfterTitle('Title', 'Title ' . str_repeat('ab ', 120) . 'tail'),
        );
    }

    public function testAJunkSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', HeadlineBody::bodyAfterTitle('Title', '<p>null</p>'));
    }

    public function testNoSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', HeadlineBody::bodyAfterTitle('Title', null));
    }

    public function testAnExcerptKeepsUpToAThousandCharactersOnAWordBoundary(): void
    {
        $excerpt = HeadlineBody::excerpt(str_repeat('word ', 300));

        self::assertSame(rtrim(str_repeat('word ', 200)), $excerpt);
        self::assertLessThanOrEqual(1_000, mb_strlen($excerpt));
        self::assertSame('Short text', HeadlineBody::excerpt('<p>Short text</p>'));
    }

    public function testAnExcerptAfterTitleDropsTheRepeatedHeadline(): void
    {
        self::assertSame(
            'Ten dead.',
            HeadlineBody::excerptAfterTitle('Storm hits coast.', '<p>Storm hits coast. Ten dead.</p>'),
        );
        self::assertSame(
            rtrim(str_repeat('ab ', 333)),
            HeadlineBody::excerptAfterTitle('Title', 'Title ' . str_repeat('ab ', 400)),
        );
    }
}
