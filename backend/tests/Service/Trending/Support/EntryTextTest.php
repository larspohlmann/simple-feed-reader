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

    public function testTheBodyKeepsTheTitleItStartsWith(): void
    {
        self::assertSame(
            'Storm hits coast. Ten dead.',
            EntryText::body('<p>Storm hits coast. Ten dead.</p>'),
        );
    }

    public function testTheBodyIsCutAtThreeHundredCharacters(): void
    {
        $body = EntryText::body(str_repeat('ab ', 120) . 'tail');

        self::assertSame(300, mb_strlen($body));
        self::assertSame(str_repeat('ab ', 100), $body);
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

    public function testTheBodyAfterTheTitleIsCutAtThreeHundredCharacters(): void
    {
        $body = EntryText::bodyAfterTitle('Title', 'Title ' . str_repeat('ab ', 120) . 'tail');

        self::assertSame(300, mb_strlen($body));
        self::assertSame(str_repeat('ab ', 100), $body);
    }

    public function testNoSummaryAfterTheTitleIsAnEmptyBody(): void
    {
        self::assertSame('', EntryText::bodyAfterTitle('Title', null));
    }
}
