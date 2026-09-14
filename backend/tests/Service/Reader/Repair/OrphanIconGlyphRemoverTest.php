<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Repair;

use App\Service\Reader\Repair\OrphanIconGlyphRemover;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class OrphanIconGlyphRemoverTest extends TestCase
{
    private OrphanIconGlyphRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new OrphanIconGlyphRemover();
    }

    public function testRemovesAnOrphanIconGlyphAndPrunesTheHoldersItEmpties(): void
    {
        // U+E80F is an icon-font glyph the sanitizer's class strip would orphan.
        // It sits in a <span>, in a <p>, in a <div> that holds nothing else:
        // stripping it empties all three, which are pruned from the inside out.
        // A plain paragraph precedes the glyph, so a scan that stopped at the
        // first glyph-free node would leave the glyph behind. The <p> keeps
        // whitespace around the glyph span, so an untrimmed emptiness check would
        // leave the blank <p> in place. The surrounding paragraphs must stay.
        $html = $this->repaired(
            '<section><p>Intro paragraph.</p>'
            . "<div><p> <span>\u{E80F}</span> </p></div>"
            . '<p>Quote body</p></section>'
        );

        self::assertStringNotContainsString("\u{E80F}", $html);
        self::assertStringNotContainsString('<span>', $html);
        self::assertStringNotContainsString('<div>', $html);
        self::assertDoesNotMatchRegularExpression('/<p>\s*<\/p>/', $html);
        self::assertStringContainsString('Intro paragraph.', $html);
        self::assertStringContainsString('Quote body', $html);
    }

    public function testKeepsAnEmptiedHolderThatStillCarriesAnImage(): void
    {
        // Stripping the glyph empties the <span> of text, but its <img> makes it
        // meaningful, so the holder must survive.
        $html = $this->repaired(
            "<span>\u{E80F}<img src=\"https://images.example.com/a.png\" alt=\"\"></span>"
        );

        self::assertStringNotContainsString("\u{E80F}", $html);
        self::assertStringContainsString('images.example.com/a.png', $html);
        self::assertStringContainsString('<img', $html);
    }

    public function testStripsAGlyphButKeepsTheTextAroundIt(): void
    {
        $html = $this->repaired("<p>Before\u{E80F}After</p>");

        self::assertStringNotContainsString("\u{E80F}", $html);
        self::assertStringContainsString('BeforeAfter', html_entity_decode($html));
    }

    private function repaired(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->remover->repairIn($document);

        return $document->saveHtml();
    }
}
