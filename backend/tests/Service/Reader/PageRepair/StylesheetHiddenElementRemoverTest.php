<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\PageRepair;

use App\Service\Reader\PageRepair\StylesheetHiddenElementRemover;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class StylesheetHiddenElementRemoverTest extends TestCase
{
    use ParsesHtml;

    private StylesheetHiddenElementRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new StylesheetHiddenElementRemover();
    }

    public function testRemovesTheBrowserNoticeThePageHidesById(): void
    {
        $result = $this->htmlAfterRemoval(
            '<style>#updateBrowserMessage{text-align:center;display:none}</style>'
            . '<p>New tune with my good mate Pogo.</p>'
            . '<div id="updateBrowserMessage"><p>Your current browser isn\'t compatible with SoundCloud.</p></div>'
        );

        self::assertStringNotContainsString('current browser', $result);
        self::assertStringContainsString('New tune with my good mate Pogo.', $result);
    }

    public function testRemovesTheStyleBlocks(): void
    {
        $result = $this->htmlAfterRemoval('<style>.ad{color:red}</style><p>Body</p>');

        self::assertStringNotContainsString('<style', $result);
        self::assertStringContainsString('Body', $result);
    }

    public function testKeepsAnElementTheStylesheetLeavesVisible(): void
    {
        $result = $this->htmlAfterRemoval('<style>#lead{display:block}</style><p id="lead">Kept.</p>');

        self::assertStringContainsString('Kept.', $result);
    }

    private function htmlAfterRemoval(string $bodyHtml): string
    {
        $document = $this->document('<!doctype html><html lang="en"><body>' . $bodyHtml . '</body></html>');
        $this->remover->repairIn($document);

        return $document->saveHtml();
    }
}
