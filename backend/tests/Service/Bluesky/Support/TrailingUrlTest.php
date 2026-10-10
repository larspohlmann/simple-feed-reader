<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Support;

use App\Service\Bluesky\Support\TrailingUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrailingUrlTest extends TestCase
{
    private const string URL = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string TRACKED = 'https://www.motherjones.com/politics/2026/10/youre-funding-trumps-new-ads/'
        . '?utm_source=dlvr.it&utm_medium=slack';

    /** @return iterable<string, array{string, string, string}> */
    public static function bodies(): iterable
    {
        yield 'after a space in bare text' => [
            'Step down if ICE doesn&#039;t leave the city. ' . self::URL,
            self::URL,
            "Step down if ICE doesn't leave the city.",
        ];
        yield 'after a line break' => [
            '<p>“Foreign policy.</p><p>Russians.”<br />' . self::URL . '</p>',
            self::URL,
            '<p>“Foreign policy.</p><p>Russians.”</p>',
        ];
        yield 'with entities in its query' => [
            '<p>But will he ever pay you back?<br />https://www.motherjones.com/politics/2026/10/'
                . 'youre-funding-trumps-new-ads/?utm_source&#61;dlvr.it&amp;utm_medium&#61;slack</p>',
            self::TRACKED,
            '<p>But will he ever pay you back?</p>',
        ];
        yield 'in a paragraph of its own' => [
            '<p>Read this.</p><p>' . self::URL . '</p>',
            self::URL,
            '<p>Read this.</p>',
        ];
        yield 'the whole body' => [self::URL, self::URL, ''];
        yield 'before trailing blanks' => [
            '<p>Read this.<br />' . self::URL . " \n</p>",
            self::URL,
            '<p>Read this.</p>',
        ];
        yield 'before a trailing comment' => [
            '<p>Read this. ' . self::URL . '</p><!-- dlvr.it -->',
            self::URL,
            '<p>Read this.</p><!-- dlvr.it -->',
        ];
    }

    #[DataProvider('bodies')]
    public function testRemovesTheUrlTheTextEndsWith(string $html, string $url, string $expected): void
    {
        self::assertSame($expected, TrailingUrl::removedFrom($html, $url));
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'the URL mid-text' => ['<p>See ' . self::URL . ' for more.</p>'];
        yield 'another URL at the end' => ['<p>See https://example.com/other</p>'];
        yield 'no text' => ['<p></p>'];
    }

    #[DataProvider('untouched')]
    public function testLeavesABodyThatDoesNotEndWithTheUrl(string $html): void
    {
        self::assertSame($html, TrailingUrl::removedFrom($html, self::URL));
    }
}
