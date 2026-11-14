<?php

declare(strict_types=1);

namespace App\Tests\Service\Trending\Support;

use App\Service\Trending\Support\EntryText;
use PHPUnit\Framework\TestCase;

final class EntryTextTest extends TestCase
{
    public function testTheBodyIsTheSummaryWithItsMarkupStripped(): void
    {
        self::assertSame(
            'Storm hits coast. More text & detail',
            EntryText::body('<p>Storm hits coast.</p><p>More text &amp; detail</p>'),
        );
    }

    public function testTheBodyIsCutAtTheLastSpaceWithinThreeHundredCharacters(): void
    {
        self::assertSame(rtrim(str_repeat('ab ', 100)), EntryText::body(str_repeat('ab ', 120) . 'tail'));
    }

    public function testAWordCrossingTheThreeHundredthCharacterIsDroppedWhole(): void
    {
        self::assertSame(
            str_repeat('a', 295),
            EntryText::body(str_repeat('a', 295) . ' ' . str_repeat('b', 20)),
        );
    }

    public function testAJunkSummaryIsAnEmptyBody(): void
    {
        self::assertSame('', EntryText::body('<p>none</p>'));
    }

    public function testAnInlineImageSeparatesTheWordsAroundIt(): void
    {
        self::assertSame(
            'Storm hits',
            EntryText::body('<p>Storm<img src="https://example.org/storm.jpg" alt="">hits</p>'),
        );
    }

    public function testNoSummaryIsAnEmptyBody(): void
    {
        self::assertSame('', EntryText::body(null));
    }

    public function testADerivedTitleIsNotRepeatedAtTheStartOfTheBody(): void
    {
        self::assertSame(
            'Ten dead.',
            EntryText::bodyAfterTitle('Storm hits coast.', '<p>Storm hits coast. Ten dead.</p>'),
        );
    }

    public function testAMultibyteTitleIsCutByCharactersNotBytes(): void
    {
        self::assertSame(
            'Dziesięć ofiar.',
            EntryText::bodyAfterTitle('Łódź: pożar.', '<p>Łódź: pożar. Dziesięć ofiar.</p>'),
        );
    }

    public function testAClippedDerivedTitleLosesItsEllipsisBeforeTheComparison(): void
    {
        self::assertSame(
            'that goes on',
            EntryText::bodyAfterTitle(
                'A very long first sentence…',
                '<p>A very long first sentence that goes on</p>',
            ),
        );
    }

    public function testATitleTheBodyDoesNotStartWithLeavesTheBodyWhole(): void
    {
        self::assertSame(
            'Other words here',
            EntryText::bodyAfterTitle('Storm hits coast.', '<p>Other words here</p>'),
        );
    }

    public function testTheBodyAfterTheTitleIsCutAtTheLastSpaceWithinThreeHundredCharacters(): void
    {
        self::assertSame(
            rtrim(str_repeat('ab ', 100)),
            EntryText::bodyAfterTitle('Title', 'Title ' . str_repeat('ab ', 120) . 'tail'),
        );
    }

    public function testAJunkSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', EntryText::bodyAfterTitle('Title', '<p>null</p>'));
    }

    public function testNoSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', EntryText::bodyAfterTitle('Title', null));
    }
}
