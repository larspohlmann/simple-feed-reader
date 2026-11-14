<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Support;

use App\Service\LinkCard\Support\QuoteLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuoteLineTest extends TestCase
{
    private const string QUOTED = 'https://q.test/@a/1';

    /** @return iterable<string, array{string, string}> */
    public static function bodies(): iterable
    {
        yield 'a quote line of its own' => [
            '<p>RE: <a href="https://q.test/&#64;a/1"><span>https://</span><span>q.test/&#64;a/1</span></a></p>'
                . '<p>Reposting.</p>',
            '<p>Reposting.</p>',
        ];
        yield 'a non-breaking space' => [
            '<p>RE:&nbsp;<a href="https://q.test/@a/1">https://q.test/@a/1</a></p><p>Yes.</p>',
            '<p>Yes.</p>',
        ];
        yield 'text after the quote in the same paragraph' => [
            '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a><br>Agreed.</p>',
            '<p><br>Agreed.</p>',
        ];
        yield 'the marker split across text nodes' => [
            '<p>RE<!-- -->: <a href="https://q.test/@a/1">https://q.test/@a/1</a></p><p>x</p>',
            '<p>x</p>',
        ];
        yield 'another link first, then the quote' => [
            '<p>See <a href="https://o.test/">o</a></p>'
                . '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a></p>',
            '<p>See <a href="https://o.test/">o</a></p>',
        ];
        yield 'an emoji image left in the paragraph' => [
            '<p>RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a>'
                . ' <img alt=":bear:" src="https://e.test/b.png"></p>',
            '<p> <img alt=":bear:" src="https://e.test/b.png"></p>',
        ];
    }

    #[DataProvider('bodies')]
    public function testRemovesTheQuoteLineOfThatUrl(string $html, string $expected): void
    {
        self::assertSame($expected, QuoteLine::removedFrom($html, self::QUOTED));
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'another url quoted' => ['<p>RE: <a href="https://q.test/@b/2">https://q.test/@b/2</a></p><p>x</p>'];
        yield 'the url shared, not quoted' => [
            '<p>See <a href="https://q.test/@a/1">https://q.test/@a/1</a></p>',
        ];
        yield 'no paragraph' => ['RE: <a href="https://q.test/@a/1">https://q.test/@a/1</a>'];
    }

    #[DataProvider('untouched')]
    public function testLeavesABodyWithoutThatQuoteByteForByte(string $html): void
    {
        self::assertSame($html, QuoteLine::removedFrom($html, self::QUOTED));
    }

    public function testTheTargetIsTheLinkTheReParagraphQuotes(): void
    {
        $html = '<p>See <a href="https://a.example/x">this</a></p>'
            . '<p class="quote-inline">RE: <a href="https://m.example/@b/9">https://m.example/@b/9</a></p>';

        self::assertSame('https://m.example/@b/9', QuoteLine::targetIn($html));
    }

    public function testABodyWithoutAReParagraphQuotesNothing(): void
    {
        self::assertNull(QuoteLine::targetIn('<p>RE is not here: <a href="https://m.example/@b/9">x</a></p>'));
    }
}
