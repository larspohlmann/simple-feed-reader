<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\PageRepair;

use App\Service\Reader\PageRepair\OrphanIconGlyphRemover;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class OrphanIconGlyphRemoverTest extends TestCase
{
    use ParsesHtml;

    private OrphanIconGlyphRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new OrphanIconGlyphRemover();
    }

    public function testRemovesAnOrphanIconGlyphAndPrunesTheHoldersItEmpties(): void
    {
        // U+E80F, alone in a <span> in a <p> in a <div>: all three go, from the inside out. A glyph-free paragraph
        // comes first, so the scan must skip past it, and whitespace around the span must not keep the blank <p>.
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
        $document = $this->document(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>'
        );
        $this->remover->repairIn($document);

        return $document->saveHtml();
    }
}
