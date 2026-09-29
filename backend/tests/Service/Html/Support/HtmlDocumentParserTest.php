<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Support;

use App\Service\Html\Exception\UnparseableHtmlException;
use App\Service\Html\Support\HtmlDocumentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlDocumentParserTest extends TestCase
{
    public function testParsesHtmlIntoADocument(): void
    {
        /** @noinspection HtmlRequiredLangAttribute */
        $document = HtmlDocumentParser::parse('<html><body><p>Body</p></body></html>');

        self::assertStringContainsString('Body', (string) $document->querySelector('p')?->textContent);
    }

    public function testKeepsNonAsciiAsUtf8(): void
    {
        /** @noinspection HtmlRequiredLangAttribute */
        $document = HtmlDocumentParser::parse('<html><body><p>Grüße</p></body></html>');

        self::assertStringContainsString('Grüße', $document->saveHtml());
    }

    public function testBlankInputYieldsAnEmptyDocument(): void
    {
        self::assertNull(HtmlDocumentParser::parseOrEmpty('')->documentElement);
        self::assertNull(HtmlDocumentParser::parseOrEmpty('   ')->documentElement);
    }

    public function testParseOrEmptyHandsBackAParsedDocument(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty('<html lang="en"><body><p>Body</p></body></html>');

        self::assertSame('Body', $document->querySelector('p')?->textContent);
    }

    public function testParseHandsBackTheDocument(): void
    {
        $document = HtmlDocumentParser::parse('<html lang="en"><body><p>Body</p></body></html>');

        self::assertSame('Body', $document->querySelector('p')?->textContent);
    }

    #[DataProvider('blankHtml')]
    public function testParseRefusesBlankInput(string $html): void
    {
        $this->expectException(UnparseableHtmlException::class);
        $this->expectExceptionMessage('The HTML is blank or could not be parsed.');

        HtmlDocumentParser::parse($html);
    }

    /** @return iterable<string, array{string}> */
    public static function blankHtml(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => [" \n\t "];
    }
}
