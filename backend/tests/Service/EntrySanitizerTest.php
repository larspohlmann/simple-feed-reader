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
        // @lang TEXT: this markup is deliberately hostile and malformed — it is
        // what the sanitizer has to strip — so the injected-HTML complaints
        // about the missing `alt`, the unresolvable `src` and the obsolete
        // handler attribute are all pointing at the fixture's whole purpose.
        $clean = (string) $this->sanitizer->sanitize(
            /** @lang TEXT */
            '<p onclick="evil()">Hi</p><script>alert(1)</script><img src="x" onerror="evil()">',
        );

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
        // @lang TEXT: `evil.swf` must stay an unresolvable path — stripping the
        // elements that reference it is what the test asserts.
        $dirty = /** @lang TEXT */ '<iframe src="https://evil.example.com/"></iframe>'
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
        // @lang TEXT: the `alt`-less data-URI image is the input under test, so
        // it stays exactly as written.
        $clean = (string) $this->sanitizer->sanitize(
            /** @lang TEXT */
            '<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">',
        );

        self::assertStringNotContainsString('data:', $clean);
    }

    /**
     * Both bodies of article HTML cross this barrier — the feed's on ingest and
     * the extracted one on every reader request — so trimming the blank tail
     * here is what keeps it out of every client's article (#296).
     */
    public function testTrimsTheBlankTailAFeedLeftBehind(): void
    {
        $clean = (string) $this->sanitizer->sanitize(
            "<p>The last sentence.</p>\n<p>&nbsp;</p>\n<p></p>\n<br>\n\n  ",
        );

        self::assertSame('<p>The last sentence.</p>', $clean);
    }

    public function testEmptyInputBecomesNull(): void
    {
        self::assertNull($this->sanitizer->sanitize(null));
        self::assertNull($this->sanitizer->sanitize('   '));
        self::assertNull($this->sanitizer->sanitize('<script>only evil</script>'));
        // Nothing but a tail is not an article either.
        self::assertNull($this->sanitizer->sanitize('<p>&nbsp;</p><p></p><br>'));
    }
}
