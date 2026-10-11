<?php

declare(strict_types=1);

namespace App\Tests\Service\Share\Support;

use App\Service\Share\Support\SharedLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SharedLinksTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function bodies(): iterable
    {
        yield 'hashtag' => [
            '<a href="https://mastodon.social/tags/Mastodon" rel="noopener noreferrer" target="_blank">'
            . '#<span>Mastodon</span></a>',
            [],
        ];
        yield 'mention' => [
            '<span translate="no"><a href="https://mastodon.iftas.org/&#64;iftas" rel="noopener noreferrer"'
            . ' target="_blank">&#64;<span>iftas</span></a></span>',
            [],
        ];
        yield 'text link' => ['<p><a href="https://example.com/a">read more</a></p>', []];
        yield 'link split across spans' => [
            '<p><a href="https://joinmastodon.org/sponsors#donate" target="_blank" rel="noopener noreferrer"'
            . ' translate="no"><span>https://</span><span>joinmastodon.org/sponsors#dona</span>'
            . '<span>te</span></a></p>',
            ['https://joinmastodon.org/sponsors#donate'],
        ];
        yield 'quoted post' => [
            '<p>RE: <a href="https://social.souverain.ovh/&#64;souverain/116830136337222206" target="_blank"'
            . ' rel="noopener noreferrer" translate="no"><span>https://</span>'
            . '<span>social.souverain.ovh/&#64;souverai</span><span>n/116830136337222206</span></a></p>'
            . '<p>Nous formons un mouvement ✊🏾</p>',
            ['https://social.souverain.ovh/@souverain/116830136337222206'],
        ];
        yield 'host-only text with an ellipsis' => [
            '<p><a href="https://example.com/long/path">example.com/long/pa…</a></p>',
            ['https://example.com/long/path'],
        ];
        yield 'quote with a non-breaking space' => [
            '<p>RE:&nbsp;<a href="https://q.test/@a/1">https://q.test/@a/1</a></p>',
            ['https://q.test/@a/1'],
        ];
        yield 'a quote then a shared link' => [
            '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a></p>'
            . '<p>Read <a href="https://example.com/a">https://example.com/a</a></p>',
            ['https://q.test/@a/1', 'https://example.com/a'],
        ];
        yield 'text drops www' => [
            '<a href="https://www.example.com/a">example.com/a</a>',
            ['https://www.example.com/a'],
        ];
        yield 'host cut to an ellipsis' => [
            '<a href="https://example.com/a">example.com…</a>',
            ['https://example.com/a'],
        ];
        yield 'text adds www' => ['<a href="https://example.com/a">www.example.com/a</a>', ['https://example.com/a']];
        yield 'host that only starts like the link host' => ['<a href="https://ex.com/a">ex.com.evil/a</a>', []];
        yield 'a host whose dot is another character' => ['<a href="https://example.com/a">examplexcom/a</a>', []];
        yield 'uppercase scheme' => ['<p>HTTPS://EXAMPLE.COM/A</p>', ['HTTPS://EXAMPLE.COM/A']];
        yield 'url inside code' => ['<p><code>curl https://example.com/api</code></p>', []];
        yield 'url in curly quotes' => ['<p>“https://example.com/a”</p>', ['https://example.com/a']];
        yield 'url before an ellipsis' => ['<p>https://example.com/a… more</p>', ['https://example.com/a']];
        yield 'bare url after a break' => [
            '<p>I went on Michael Cohen\'s podcast. It was... bizarre.<br />'
            . 'https://www.motherjones.com/politics/2026/10/x/</p>',
            ['https://www.motherjones.com/politics/2026/10/x/'],
        ];
        yield 'trailing full stop stays outside' => [
            '<p>See https://example.com/a.</p>',
            ['https://example.com/a'],
        ];
        yield 'closing parenthesis stays outside' => [
            '<p>(see https://example.com/a)</p>',
            ['https://example.com/a'],
        ];
        yield 'a duplicate counts once' => [
            '<p>https://example.com/a and again https://example.com/a</p>',
            ['https://example.com/a'],
        ];
        yield 'an href too long to store' => [
            '<p>https://example.com/' . str_repeat('a', 2029)
            . ' https://example.com/' . str_repeat('b', 2028) . '</p>',
            ['https://example.com/' . str_repeat('b', 2028)],
        ];
        yield 'six links give five' => [
            '<p>' . implode(' ', self::urls(6)) . '</p>',
            self::urls(5),
        ];
    }

    /** @return list<string> */
    private static function urls(int $count): array
    {
        return array_map(static fn (int $number): string => 'https://example.com/' . $number, range(1, $count));
    }

    /** @param list<string> $expected */
    #[DataProvider('bodies')]
    public function testFindsTheSharedUrls(string $body, array $expected): void
    {
        self::assertSame($expected, SharedLinks::of($body)->urls);
    }

    public function testLinksABareUrlAndKeepsPunctuationOutside(): void
    {
        self::assertSame(
            '<p>See <a href="https://example.com/a">https://example.com/a</a>.</p>',
            SharedLinks::of('<p>See https://example.com/a.</p>')->linkedHtml,
        );
    }

    public function testLinksAnUppercaseSchemeToo(): void
    {
        self::assertStringContainsString(
            '<a href="HTTPS://EXAMPLE.COM/A">',
            SharedLinks::of('<p>HTTPS://EXAMPLE.COM/A</p>')->linkedHtml,
        );
    }

    public function testLeavesAnAlreadyLinkedUrlAlone(): void
    {
        $body = '<p><a href="https://example.com/a">https://example.com/a</a></p>';

        self::assertSame($body, SharedLinks::of($body)->linkedHtml);
    }

    public function testLeavesABodyWithoutUrlsByteForByte(): void
    {
        self::assertSame('<p>a &#64; b</p>', SharedLinks::of('<p>a &#64; b</p>')->linkedHtml);
    }
}
