<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Headline;
use App\Service\Bluesky\BlueskyEntryRule;
use App\Service\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyEntryRuleTest extends TestCase
{
    private const string POST = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mxhdhodv222n';
    private const string PLACEHOLDER = '[contains quote post or other embedded content]';

    /** @return iterable<string, array{string, bool}> */
    public static function guids(): iterable
    {
        yield 'a post' => [self::POST, true];
        yield 'a web URL' => ['https://bsky.app/profile/bsky.app/post/3mxhdhodv222n', false];
    }

    #[DataProvider('guids')]
    public function testSupportsAPostByItsGuid(string $guid, bool $supported): void
    {
        self::assertSame($supported, (new BlueskyEntryRule())->supports(self::post($guid, 'Title', '<p>x</p>')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function bodies(): iterable
    {
        yield 'its own paragraph' => [
            '<p>a masterclass in alt text</p><p>' . self::PLACEHOLDER . '</p>',
            '<p>a masterclass in alt text</p>',
        ];
        yield 'after a line break' => ['<p>line one<br>' . self::PLACEHOLDER . '</p>', '<p>line one</p>'];
        yield 'after a blank line of plain text' => ["text\n\n" . self::PLACEHOLDER, 'text'];
        yield 'no placeholder' => ['<p>Just text.</p>', '<p>Just text.</p>'];
    }

    #[DataProvider('bodies')]
    public function testThePlaceholderLeavesContentAndSummary(string $body, string $expected): void
    {
        $entry = (new BlueskyEntryRule())->apply(
            new ParsedEntryModel(self::POST, null, Headline::derived('Title'), null, $body, $body, null),
        );

        self::assertSame($expected, $entry->contentHtml);
        self::assertSame($expected, $entry->summary);
    }

    public function testAnImageOnlyPostStaysAnUntitledPost(): void
    {
        $entry = (new BlueskyEntryRule())->apply(self::post(self::POST, self::PLACEHOLDER, self::PLACEHOLDER));

        self::assertNull($entry->contentHtml);
        self::assertNull($entry->summary);
        self::assertSame('', $entry->headline->text);
        self::assertTrue($entry->headline->derived);
    }

    public function testADerivedTitleIsDerivedAgainFromTheRemainingText(): void
    {
        $entry = (new BlueskyEntryRule())->apply(
            self::post(self::POST, 'stale', '<p>Fresh words.</p><p>' . self::PLACEHOLDER . '</p>'),
        );

        self::assertSame('Fresh words.', $entry->headline->text);
        self::assertTrue($entry->headline->derived);
    }

    public function testADerivedTitleIsTakenFromTheBodyBeforeTheSummary(): void
    {
        $entry = (new BlueskyEntryRule())->apply(new ParsedEntryModel(
            self::POST,
            null,
            Headline::derived('stale'),
            null,
            '<p>Summary words.</p>',
            '<p>Body words.</p>',
            null,
        ));

        self::assertEquals(Headline::derived('Body words.'), $entry->headline);
    }

    public function testAFeedTitleIsKept(): void
    {
        $entry = (new BlueskyEntryRule())->apply(new ParsedEntryModel(
            self::POST,
            null,
            Headline::fromFeed('Feed headline'),
            null,
            null,
            '<p>Body text.</p><p>' . self::PLACEHOLDER . '</p>',
            null,
        ));

        self::assertSame('Feed headline', $entry->headline->text);
        self::assertFalse($entry->headline->derived);
        self::assertSame('<p>Body text.</p>', $entry->contentHtml);
    }

    private static function post(string $guid, string $derivedTitle, string $contentHtml): ParsedEntryModel
    {
        return new ParsedEntryModel($guid, null, Headline::derived($derivedTitle), null, null, $contentHtml, null);
    }
}
