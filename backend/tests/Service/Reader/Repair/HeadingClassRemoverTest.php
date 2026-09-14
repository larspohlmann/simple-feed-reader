<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Repair;

use App\Service\Reader\Repair\HeadingClassRemover;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class HeadingClassRemoverTest extends TestCase
{
    private HeadingClassRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new HeadingClassRemover();
    }

    /** Substack marks every subheading `header-anchor-post`; the "header" token drops it from readability. */
    public function testStripsClassAndIdFromHeadings(): void
    {
        $html = $this->repaired(
            '<h2 class="header-anchor-post" id="anchor"><strong>Section</strong></h2>'
            . '<h3 class="header-anchor-post">Subsection</h3>'
        );

        self::assertStringContainsString('<h2><strong>Section</strong></h2>', $html);
        self::assertStringContainsString('<h3>Subsection</h3>', $html);
        self::assertStringNotContainsString('header-anchor-post', $html);
    }

    public function testTouchesEveryHeadingLevel(): void
    {
        $html = $this->repaired(
            '<h1 class="a">A</h1><h2 class="b">B</h2><h3 class="c">C</h3>'
            . '<h4 class="d">D</h4><h5 class="e">E</h5><h6 class="f">F</h6>'
        );

        self::assertStringNotContainsString('class=', $html);
    }

    public function testLeavesNonHeadingClassesAlone(): void
    {
        $html = $this->repaired('<p class="lead">Text</p><div class="wrap"><h2 class="x">H</h2></div>');

        self::assertStringContainsString('<p class="lead">', $html);
        self::assertStringContainsString('<div class="wrap">', $html);
        self::assertStringContainsString('<h2>H</h2>', $html);
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
