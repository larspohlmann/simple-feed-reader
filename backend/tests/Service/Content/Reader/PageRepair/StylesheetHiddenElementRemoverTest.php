<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\PageRepair;

use App\Service\Reader\PageRepair\StylesheetHiddenElementRemover;
use App\Tests\Support\RepairsPages;
use PHPUnit\Framework\TestCase;

final class StylesheetHiddenElementRemoverTest extends TestCase
{
    use RepairsPages;

    private StylesheetHiddenElementRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new StylesheetHiddenElementRemover();
    }

    public function testRemovesTheBrowserNoticeThePageHidesById(): void
    {
        $result = $this->htmlAfterRepair(
            $this->remover,
            '<style>#updateBrowserMessage{text-align:center;display:none}</style>'
            . '<p>New tune with my good mate Pogo.</p>'
            . '<div id="updateBrowserMessage"><p>Your current browser isn\'t compatible with SoundCloud.</p></div>'
        );

        self::assertStringNotContainsString('current browser', $result);
        self::assertStringContainsString('New tune with my good mate Pogo.', $result);
    }

    public function testKeepsAnElementTheStylesheetLeavesVisible(): void
    {
        $result = $this->htmlAfterRepair($this->remover, '<style>#lead{display:block}</style><p id="lead">Kept.</p>');

        self::assertStringContainsString('Kept.', $result);
    }
}
