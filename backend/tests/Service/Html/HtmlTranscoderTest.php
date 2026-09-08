<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Html\HtmlTranscoder;
use PHPUnit\Framework\TestCase;

final class HtmlTranscoderTest extends TestCase
{
    private const string LATIN1_BODY = "<html lang=\"fr\"><body><p>Caf\xE9 cr\xE8me</p></body></html>";

    public function testDecodesABodyInTheDeclaredCharset(): void
    {
        self::assertStringContainsString('Café crème', HtmlTranscoder::toUtf8(self::LATIN1_BODY, 'windows-1252'));
    }

    public function testDecodesAMultiByteCharset(): void
    {
        $shiftJis = '<html lang="ja"><body><p>'
            . mb_convert_encoding('日本語', 'SJIS', 'UTF-8')
            . '</p></body></html>';

        self::assertStringContainsString('日本語', HtmlTranscoder::toUtf8($shiftJis, 'Shift_JIS'));
    }

    public function testDecodesIso88591AsWindows1252LikeABrowser(): void
    {
        // WHATWG maps the iso-8859-1 label to windows-1252, so 0x93 is a
        // curly quote, not the C1 control mbstring would make of it.
        $quoted = "<html lang=\"en\"><body><p>\x93quoted\x94</p></body></html>";

        self::assertStringContainsString('“quoted”', HtmlTranscoder::toUtf8($quoted, 'iso-8859-1'));
    }

    public function testRewritesAStaleMetaCharsetSoALaterParseDecodesTheResultAsUtf8(): void
    {
        $withMeta = '<meta charset="windows-1252">' . self::LATIN1_BODY;

        $reparsed = HtmlDocumentParser::parseOrNull(HtmlTranscoder::toUtf8($withMeta, 'windows-1252'));

        self::assertSame('Café crème', (string) $reparsed?->querySelector('p')?->textContent);
    }

    public function testRewritesAStaleHttpEquivCharsetSoALaterParseDecodesTheResultAsUtf8(): void
    {
        $withMeta = '<meta http-equiv="Content-Type" content="text/html; charset=windows-1252">' . self::LATIN1_BODY;

        $transcoded = HtmlTranscoder::toUtf8($withMeta, 'windows-1252');
        $reparsed = HtmlDocumentParser::parseOrNull($transcoded);

        self::assertStringContainsString('content="text/html; charset=utf-8"', $transcoded);
        self::assertSame('Café crème', (string) $reparsed?->querySelector('p')?->textContent);
    }

    public function testLeavesABodyAlreadyInUtf8Untouched(): void
    {
        $utf8 = '<html lang="fr"><body><p>Café crème</p></body></html>';

        self::assertSame($utf8, HtmlTranscoder::toUtf8($utf8, 'UTF-8'));
        self::assertSame($utf8, HtmlTranscoder::toUtf8($utf8, 'utf8'));
    }

    public function testLeavesTheBodyUntouchedForAnUnknownCharsetLabel(): void
    {
        self::assertSame(self::LATIN1_BODY, HtmlTranscoder::toUtf8(self::LATIN1_BODY, 'x-nonsense'));
        self::assertSame(self::LATIN1_BODY, HtmlTranscoder::toUtf8(self::LATIN1_BODY, ''));
    }
}
