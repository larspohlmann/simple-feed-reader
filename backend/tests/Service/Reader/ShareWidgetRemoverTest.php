<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ShareWidgetRemover;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ShareWidgetRemoverTest extends TestCase
{
    private ShareWidgetRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new ShareWidgetRemover();
    }

    public function testRemovesTheShariffBarButKeepsTheProse(): void
    {
        // The hanfjournal case: a Shariff bar is the first child of the article
        // body, before any prose, and renders as "teilen … merken".
        $html = '<article><div class="shariff"><ul class="shariff-buttons">'
            . '<li>Facebook teilen</li><li>Pinterest merken</li></ul></div>'
            . '<p>Die Cannafair 2026 laeuft dieses Wochenende.</p></article>';

        $result = $this->htmlAfterRemoval($html);

        self::assertStringNotContainsString('shariff', $result);
        self::assertStringNotContainsString('teilen', $result);
        self::assertStringContainsString('Cannafair', $result);
    }

    public function testRemovesTheWholeShareWidgetFamily(): void
    {
        $html = '<div class="sharedaddy sd-sharing">jetpack</div>'
            . '<div class="addtoany_share_save_container">addtoany</div>'
            . '<div class="sharethis-inline-share-buttons">sharethis</div>'
            . '<p>Kept.</p>';

        $result = $this->htmlAfterRemoval($html);

        self::assertStringNotContainsString('jetpack', $result);
        self::assertStringNotContainsString('addtoany', $result);
        self::assertStringNotContainsString('sharethis', $result);
        self::assertStringContainsString('Kept.', $result);
    }

    public function testMatchesAWholeClassTokenOnly(): void
    {
        // "sharing-hint" is not a whole match for any share-widget token (the
        // closest is "sd-sharing"), and "myshariff" is not "shariff": a
        // substring must never trigger a removal.
        $html = '<p class="sharing-hint">Kept one.</p><p class="myshariff">Kept two.</p>';

        $result = $this->htmlAfterRemoval($html);

        self::assertStringContainsString('Kept one.', $result);
        self::assertStringContainsString('Kept two.', $result);
    }

    public function testLeavesAPageWithoutShareWidgetsUnchanged(): void
    {
        $html = '<p>Just words.</p>';

        self::assertStringContainsString('Just words.', $this->htmlAfterRemoval($html));
    }

    private function htmlAfterRemoval(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<!doctype html><html lang="de"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->remover->removeFrom($document);

        return $document->saveHtml();
    }
}
