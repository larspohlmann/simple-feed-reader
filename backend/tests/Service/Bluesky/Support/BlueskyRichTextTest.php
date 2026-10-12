<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\BlueskyRichText;
use PHPUnit\Framework\TestCase;

final class BlueskyRichTextTest extends TestCase
{
    // "Grüße" is 7 bytes: the facets below index bytes, so a character count would land every link two bytes early.
    private const string TEXT = 'Grüße an @bob.bsky.social: https://example.com #tag';

    public function testFacetsLinkTheirUtf8ByteRanges(): void
    {
        $html = BlueskyRichText::asHtml(self::TEXT, [
            self::facet(11, 27, ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:bob']),
            self::facet(29, 48, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
            self::facet(49, 53, ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'tag']),
        ]);

        self::assertSame(
            '<p>Grüße an <a href="https://bsky.app/profile/did:plc:bob">@bob.bsky.social</a>: '
            . '<a href="https://example.com">https://example.com</a> '
            . '<a href="https://bsky.app/hashtag/tag">#tag</a></p>',
            $html,
        );
    }

    public function testLineBreaksBecomeParagraphsAndBreaksAndEverythingIsEscaped(): void
    {
        self::assertSame(
            '<p>a &lt; b &amp; c<br>two</p><p>three</p>',
            BlueskyRichText::asHtml("a < b & c\ntwo\n\nthree", []),
        );
    }

    public function testAFacetOutsideTheTextOrInsideACharacterIsIgnored(): void
    {
        self::assertSame('<p>ü x</p>', BlueskyRichText::asHtml('ü x', [
            self::facet(1, 3, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
            self::facet(2, 40, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
        ]));
    }

    public function testAnOverlappingOrUnsafeFacetIsIgnored(): void
    {
        self::assertSame('<p><a href="https://example.com">abc</a>def</p>', BlueskyRichText::asHtml('abcdef', [
            self::facet(0, 3, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com']),
            self::facet(2, 5, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://other.example']),
            self::facet(3, 6, ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']),
        ]));
    }

    public function testNoTextIsNoBody(): void
    {
        self::assertNull(BlueskyRichText::asHtml("  \n", []));
    }

    public function testOnlyAWebLinkFacetLinksAWebPage(): void
    {
        $mention = ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:alice'];
        $unsafe = ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)'];
        $web = ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com'];

        self::assertFalse(BlueskyRichText::linksAWebPage([self::facet(0, 3, $mention), self::facet(3, 6, $unsafe)]));
        self::assertTrue(BlueskyRichText::linksAWebPage([self::facet(0, 3, $mention), self::facet(3, 6, $web)]));
    }

    public function testFacetsGivenOutOfOrderAreLinkedInTextOrder(): void
    {
        self::assertSame(
            '<p><a href="https://a.example">ab</a>c<a href="https://d.example">de</a></p>',
            BlueskyRichText::asHtml('abcde', [
                self::facet(3, 5, self::link('https://d.example')),
                self::facet(0, 2, self::link('https://a.example')),
            ]),
        );
    }

    public function testAFacetWithANegativeStartOrNoLengthIsIgnored(): void
    {
        self::assertSame('<p>abc</p>', BlueskyRichText::asHtml('abc', [
            self::facet(-1, 2, self::link('https://example.com')),
            self::facet(1, 1, self::link('https://example.com')),
        ]));
    }

    public function testAFacetAfterAMultiByteCharacterIsMeasuredFromItsOwnStart(): void
    {
        self::assertSame(
            '<p>a<a href="https://example.com">b</a>€</p>',
            BlueskyRichText::asHtml('ab€', [self::facet(1, 2, self::link('https://example.com'))]),
        );
    }

    public function testAFacetOfAnUnknownFeatureIsIgnored(): void
    {
        self::assertSame('<p>abc</p>', BlueskyRichText::asHtml('abc', [
            self::facet(0, 2, ['$type' => 'app.bsky.richtext.facet#unknown', 'uri' => 'https://example.com']),
        ]));
    }

    public function testALinkSpanningALineBreakStaysOnOneLine(): void
    {
        self::assertSame(
            '<p><a href="https://example.com">a b</a></p>',
            BlueskyRichText::asHtml("a\r\nb", [self::facet(0, 4, self::link('https://example.com'))]),
        );
    }

    /** @return array<string, string> */
    private static function link(string $uri): array
    {
        return ['$type' => 'app.bsky.richtext.facet#link', 'uri' => $uri];
    }

    /** @param array<string, string> $feature */
    private static function facet(int $byteStart, int $byteEnd, array $feature): JsonNodeModel
    {
        return JsonNodeModel::of([
            'index' => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
            'features' => [$feature],
        ]);
    }
}
