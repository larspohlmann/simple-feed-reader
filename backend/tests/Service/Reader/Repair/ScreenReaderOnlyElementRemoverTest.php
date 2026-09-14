<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Repair;

use App\Service\Reader\Repair\ScreenReaderOnlyElementRemover;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class ScreenReaderOnlyElementRemoverTest extends TestCase
{
    private ScreenReaderOnlyElementRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new ScreenReaderOnlyElementRemover();
    }

    public function testRemovesScreenReaderOnlyElements(): void
    {
        $html = $this->repaired(
            '<span class="visually-hidden">Image source,</span>'
            . '<span class="ssrcss-1f39n02-VisuallyHidden e16en2lz0">Image caption,</span>'
            . '<span class="sr-only">skip</span>'
            . '<p class="visible">Body</p>'
        );

        self::assertStringNotContainsString('Image source,', $html);
        self::assertStringNotContainsString('Image caption,', $html);
        self::assertStringNotContainsString('skip', $html);
        self::assertStringContainsString('Body', $html);
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
