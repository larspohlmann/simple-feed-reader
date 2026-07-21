<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EntrySanitizer;
use PHPUnit\Framework\TestCase;

final class EntrySanitizerTest extends TestCase
{
    private EntrySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new EntrySanitizer();
    }

    public function testStripsScriptsAndEventHandlers(): void
    {
        $dirty = '<p onclick="evil()">Hi</p><script>alert(1)</script><img src="x" onerror="evil()">';
        $clean = (string) $this->sanitizer->sanitize($dirty);

        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringContainsString('<p>Hi</p>', $clean);
    }

    public function testStripsJavascriptUrls(): void
    {
        $clean = (string) $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a>');

        self::assertStringNotContainsString('javascript:', $clean);
    }

    public function testKeepsFormattingImagesAndLinks(): void
    {
        $html = '<p>Some <strong>bold</strong> text with <img src="https://example.com/pic.jpg" alt="pic"> '
            . 'and a <a href="https://example.com/">link</a>.</p>';
        $clean = (string) $this->sanitizer->sanitize($html);

        self::assertStringContainsString('<strong>bold</strong>', $clean);
        self::assertStringContainsString('src="https://example.com/pic.jpg"', $clean);
        self::assertStringContainsString('href="https://example.com/"', $clean);
    }

    public function testForcesSafeLinkAttributes(): void
    {
        $clean = (string) $this->sanitizer->sanitize('<a href="https://example.com/">link</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $clean);
        self::assertStringContainsString('target="_blank"', $clean);
    }

    public function testStripsXmlNamespaceArtifactsFromAtomXhtmlContent(): void
    {
        $fromAtom = '<div xmlns="http://www.w3.org/1999/xhtml"><p>Inline <strong>xhtml</strong> body.</p></div>';
        $clean = (string) $this->sanitizer->sanitize($fromAtom);

        self::assertStringNotContainsString('xmlns', $clean);
        self::assertStringContainsString('<strong>xhtml</strong>', $clean);
    }

    public function testStripsDangerousEmbeddedContent(): void
    {
        $dirty = '<iframe src="https://evil.example.com/"></iframe>'
            . '<object data="evil.swf"></object>'
            . '<embed src="evil.swf">'
            . '<form action="https://evil.example.com/"><input name="pw" type="password"></form>'
            . '<style>body { display: none }</style>';
        $clean = (string) $this->sanitizer->sanitize($dirty);

        self::assertStringNotContainsString('iframe', $clean);
        self::assertStringNotContainsString('<object', $clean);
        self::assertStringNotContainsString('<embed', $clean);
        self::assertStringNotContainsString('<form', $clean);
        self::assertStringNotContainsString('<input', $clean);
        self::assertStringNotContainsString('display: none', $clean);
    }

    public function testStripsDataUriImages(): void
    {
        $clean = (string) $this->sanitizer->sanitize(
            '<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">',
        );

        self::assertStringNotContainsString('data:', $clean);
    }

    public function testEmptyInputBecomesNull(): void
    {
        self::assertNull($this->sanitizer->sanitize(null));
        self::assertNull($this->sanitizer->sanitize('   '));
        self::assertNull($this->sanitizer->sanitize('<script>only evil</script>'));
    }
}
