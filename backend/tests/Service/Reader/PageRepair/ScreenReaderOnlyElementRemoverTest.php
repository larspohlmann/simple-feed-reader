<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\PageRepair;

use App\Service\Reader\PageRepair\ScreenReaderOnlyElementRemover;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class ScreenReaderOnlyElementRemoverTest extends TestCase
{
    use ParsesHtml;

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
        $document = $this->document(
            '<html lang="en"><body>' . $bodyHtml . '</body></html>'
        );
        $this->remover->repairIn($document);

        return $document->saveHtml();
    }
}
