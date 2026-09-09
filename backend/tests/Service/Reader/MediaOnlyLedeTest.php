<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\MediaOnlyLede;
use PHPUnit\Framework\TestCase;

final class MediaOnlyLedeTest extends TestCase
{
    private function restore(string $body, ?string $excerpt): string
    {
        $document = HtmlDocumentParser::parseOrNull($body);
        self::assertNotNull($document);
        (new MediaOnlyLede())->restore($document, $excerpt);

        return $document->saveHtml();
    }

    public function testPrependsTheExcerptWhenTheBodyIsMediaOnly(): void
    {
        $lede = 'König Harald amtierte seit 1991 und starb im Alter von 89 Jahren.';
        $clean = $this->restore(
            '<figure class="reader-slideshow"><figcaption>Gallery</figcaption>'
            . '<ol><li><img src="https://img/a.jpg" alt="A"><p>Caption A</p></li></ol></figure>',
            $lede,
        );

        self::assertStringContainsString("<p>{$lede}</p>", $clean);
        self::assertLessThan(strpos($clean, 'reader-slideshow'), strpos($clean, 'König Harald'));
    }

    public function testLeavesABodyThatAlreadyHasProseUntouched(): void
    {
        $clean = $this->restore(
            '<p>The article already carries a full paragraph of its own reporting here.</p>'
            . '<figure><img src="https://img/a.jpg" alt="A"></figure>',
            'A short excerpt that must not be duplicated.',
        );

        self::assertStringNotContainsString('must not be duplicated', $clean);
    }

    public function testDoesNothingWithoutAnExcerpt(): void
    {
        $body = '<figure class="reader-slideshow"><ol><li><img src="https://img/a.jpg" alt="A"></li></ol></figure>';

        self::assertStringNotContainsString('<p>', $this->restore($body, null));
        self::assertStringNotContainsString('<p>', $this->restore($body, '   '));
    }

    public function testTreatsFigureCaptionsAsMediaNotProse(): void
    {
        $clean = $this->restore(
            '<figure><img src="https://img/a.jpg" alt="A">'
            . '<figcaption>A long descriptive caption that still is not article body prose.</figcaption></figure>',
            'The restored lede paragraph for this photo article.',
        );

        self::assertStringContainsString('<p>The restored lede paragraph for this photo article.</p>', $clean);
    }
}
