<?php

declare(strict_types=1);

namespace App\Tests\Service\Scraper;

use App\Service\Scraper\CardTitle;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class CardTitleTest extends TestCase
{
    private function anchor(string $html): Element
    {
        $doc = HTMLDocument::createFromString("<html lang=\"en\"><body>{$html}</body></html>", \LIBXML_NOERROR);
        $anchor = $doc->querySelector('a');
        \assert($anchor instanceof Element);

        return $anchor;
    }

    /**
     * \Dom\HTMLDocument (lexbor) parses arbitrarily deep nesting — verified
     * empirically at 60 000 wrapper elements, no parser-side depth cap — so
     * the title walk must not recurse per element: PHP 8.3+ turns stack
     * exhaustion into an \Error, which escapes the FeedParseException failure
     * channel the scrape pipeline reports through.
     */
    public function testAdversariallyDeepAnchorNestingDoesNotBlowTheStack(): void
    {
        $depth = 60000;
        $anchor = $this->anchor(
            '<a href="/deep">'
            . str_repeat('<span>', $depth) . 'Deep title text here' . str_repeat('</span>', $depth)
            . '</a>',
        );

        self::assertSame('Deep title text here', CardTitle::of($anchor, $anchor));
    }

    public function testShallowAnchorTitlesFromTheFirstTextNodeInDocumentOrder(): void
    {
        // Whitespace-only text and empty wrappers are skipped; the first
        // NON-EMPTY text node in document order wins — including one nested
        // inside a later element, never the anchor's trailing sibling text.
        $anchor = $this->anchor(
            '<a href="/shallow"> <span></span><span><i>Actual title</i></span> trailing text</a>',
        );

        self::assertSame('Actual title', CardTitle::of($anchor, $anchor));
    }
}
