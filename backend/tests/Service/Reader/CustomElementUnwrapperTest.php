<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\CustomElementUnwrapper;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class CustomElementUnwrapperTest extends TestCase
{
    private CustomElementUnwrapper $unwrapper;

    protected function setUp(): void
    {
        $this->unwrapper = new CustomElementUnwrapper();
    }

    /** nature.com 495343: every section photo sits inside <sh-background-transition>, dropped by the sanitizer with its children. */
    public function testReplacesACustomElementWithItsChildrenInPlace(): void
    {
        $html = $this->unwrapped(
            '<div id="s"><sh-background-transition class="Layer--one">'
            . '<div id="v"><img src="https://x.test/a.jpg" alt=""></div>'
            . '</sh-background-transition><p>Caption.</p></div>'
        );

        self::assertStringNotContainsString('sh-background-transition', $html);
        self::assertStringContainsString(
            '<div id="s"><div id="v"><img src="https://x.test/a.jpg" alt=""></div><p>Caption.</p></div>',
            $html,
        );
    }

    public function testUnwrapsNestedCustomElementsAndKeepsTheirText(): void
    {
        $html = $this->unwrapped('<p>Before <my-outer><my-inner>inside</my-inner> tail</my-outer> after</p>');

        self::assertStringContainsString('<p>Before inside tail after</p>', $html);
    }

    public function testLeavesStandardElementsAlone(): void
    {
        $html = $this->unwrapped(
            '<figure class="a-b"><img src="https://x.test/a.jpg" alt=""><figcaption>C</figcaption></figure>',
        );

        self::assertStringContainsString('<figure class="a-b">', $html);
        self::assertStringContainsString('<figcaption>C</figcaption>', $html);
    }

    public function testAnEmptyCustomElementSimplyDisappears(): void
    {
        $html = $this->unwrapped('<p>A</p><lite-youtube videoid="x"></lite-youtube>');

        self::assertStringNotContainsString('lite-youtube', $html);
    }

    private function unwrapped(string $bodyHtml): string
    {
        $document = HTMLDocument::createFromString(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>',
            LIBXML_NOERROR,
        );
        $this->unwrapper->unwrapIn($document);

        return $document->saveHtml();
    }
}
