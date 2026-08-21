<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\HtmlDocumentParser;
use PHPUnit\Framework\TestCase;

final class HtmlDocumentParserTest extends TestCase
{
    public function testParsesHtmlIntoADocument(): void
    {
        /** @noinspection HtmlRequiredLangAttribute */
        $document = HtmlDocumentParser::parseOrNull('<html><body><p>Body</p></body></html>');

        self::assertNotNull($document);
        self::assertStringContainsString('Body', (string) $document->querySelector('p')?->textContent);
    }

    public function testKeepsNonAsciiAsUtf8(): void
    {
        /** @noinspection HtmlRequiredLangAttribute */
        $document = HtmlDocumentParser::parseOrNull('<html><body><p>Grüße</p></body></html>');

        self::assertNotNull($document);
        self::assertStringContainsString('Grüße', $document->saveHtml());
    }

    public function testBlankInputYieldsNull(): void
    {
        self::assertNull(HtmlDocumentParser::parseOrNull(''));
        self::assertNull(HtmlDocumentParser::parseOrNull('   '));
    }
}
