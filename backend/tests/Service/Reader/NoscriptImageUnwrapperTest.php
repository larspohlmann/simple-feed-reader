<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\NoscriptImageUnwrapper;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class NoscriptImageUnwrapperTest extends TestCase
{
    private NoscriptImageUnwrapper $unwrapper;

    protected function setUp(): void
    {
        $this->unwrapper = new NoscriptImageUnwrapper();
    }

    /** heise ships the real photo only inside <noscript>; the sanitizer drops the tag, so it must be promoted first. */
    public function testPromotesAnImageOutOfNoscript(): void
    {
        $html = $this->unwrapped('<figure><noscript><img src="https://x.test/real.jpg" alt="A"></noscript></figure>');

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringContainsString('<img src="https://x.test/real.jpg" alt="A">', $html);
    }

    public function testReplacesTheAdjacentPlaceholderWithTheNoscriptImage(): void
    {
        $html = $this->unwrapped(
            '<figure><img src="data:image/svg+xml,placeholder" alt="A">'
            . '<noscript><img src="https://x.test/real.jpg" alt="A"></noscript></figure>'
        );

        self::assertStringNotContainsString('<noscript', $html);
        self::assertStringNotContainsString('data:image', $html);
        self::assertSame(1, substr_count($html, '<img'));
        self::assertStringContainsString('src="https://x.test/real.jpg"', $html);
    }

    public function testLeavesANoscriptWithoutAnImageUntouched(): void
    {
        $html = $this->unwrapped('<noscript><p>Bitte aktivieren Sie JavaScript.</p></noscript>');

        self::assertStringContainsString('<noscript><p>Bitte aktivieren Sie JavaScript.</p></noscript>', $html);
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
