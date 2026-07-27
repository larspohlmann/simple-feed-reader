<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EntrySnippet;
use PHPUnit\Framework\TestCase;

final class EntrySnippetTest extends TestCase
{
    public function testReturnsPlainTextWithoutMarkup(): void
    {
        self::assertSame(
            'Hello there',
            EntrySnippet::from('<p>Hello <strong>there</strong></p>'),
        );
    }

    public function testDropsALeadingImageLink(): void
    {
        self::assertSame(
            'The real summary.',
            EntrySnippet::from('<a href="https://x"><img src="https://i/a.jpg" alt=""/></a> The real summary.'),
        );
    }

    public function testReturnsNullForAnImageOnlyBody(): void
    {
        self::assertNull(EntrySnippet::from('<a href="https://x"><img src="https://i/a.jpg" alt=""/></a>'));
    }

    public function testReturnsNullForTheLiteralNoneArtifact(): void
    {
        self::assertNull(EntrySnippet::from('<a href="https://x"><img src="https://i/a.jpg" alt=""/></a> None'));
    }

    public function testKeepsNoneWhenItIsPartOfARealSentence(): void
    {
        self::assertSame(
            'None of the proposals survived the vote.',
            EntrySnippet::from('None of the proposals survived the vote.'),
        );
    }

    public function testReturnsNullForNull(): void
    {
        self::assertNull(EntrySnippet::from(null));
    }

    public function testCollapsesWhitespace(): void
    {
        self::assertSame('a b c', EntrySnippet::from("a\n  b\t\tc"));
    }

    /**
     * strip_tags() concatenates across element boundaries with no separator, so
     * without this a two-paragraph body would read as one smashed-together word.
     * Block-level boundaries must become whitespace before tags are stripped.
     */
    public function testInsertsASpaceAtBlockLevelBoundaries(): void
    {
        self::assertSame(
            'one two',
            EntrySnippet::from('<p>one</p><p>two</p>'),
        );
    }
}
