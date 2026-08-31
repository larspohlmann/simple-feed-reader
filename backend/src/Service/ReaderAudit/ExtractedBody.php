<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;

/**
 * The measurements every marker rule reads, taken once from the cleaned article
 * HTML. Parsing the body per rule would re-parse it a dozen times per article
 * and put the audit's cost in the DOM instead of in the network.
 *
 * "Block" means a paragraph-level element that carries text: the unit a reader
 * sees as a line of the article, and the unit leftover chrome arrives in.
 */
final readonly class ExtractedBody
{
    private const array BLOCK_TAGS = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div'];

    /**
     * @param list<string> $blockTexts
     * @param list<string> $linkTexts
     * @param list<string> $imageSources
     */
    private function __construct(
        public string $text,
        public array $blockTexts,
        public array $linkTexts,
        public array $imageSources,
        public int $paragraphCount,
        public int $headingCount,
        public int $linkDominatedListItems,
    ) {
    }

    public static function fromHtml(string $html): self
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        if ($document === null || $document->body === null) {
            return new self('', [], [], [], 0, 0, 0);
        }

        $body = $document->body;

        return new self(
            text: self::collapsed($body->textContent),
            blockTexts: self::blockTexts($body),
            linkTexts: self::linkTexts($body),
            imageSources: self::imageSources($body),
            paragraphCount: $body->getElementsByTagName('p')->length,
            headingCount: self::headingCount($body),
            linkDominatedListItems: self::linkDominatedListItems($body),
        );
    }

    public function textLength(): int
    {
        return mb_strlen($this->text);
    }

    public function wordCount(): int
    {
        return $this->text === '' ? 0 : \count(explode(' ', $this->text));
    }

    /** Share of the body's characters that sit inside a link. */
    public function linkTextRatio(): float
    {
        $total = $this->textLength();
        if ($total === 0) {
            return 0.0;
        }

        $linked = 0;
        foreach ($this->linkTexts as $linkText) {
            $linked += mb_strlen($linkText);
        }

        return min(1.0, $linked / $total);
    }

    /** @return list<string> */
    private static function blockTexts(Element $body): array
    {
        $texts = [];
        foreach ($body->getElementsByTagName('*') as $element) {
            if (!\in_array($element->localName, self::BLOCK_TAGS, true)) {
                continue;
            }
            $text = self::collapsed($element->textContent);
            if ($text !== '' && !self::hasBlockChild($element)) {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    /** A block that wraps other blocks reports its children's text, not its own. */
    private static function hasBlockChild(Element $element): bool
    {
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if (\in_array($child->localName, self::BLOCK_TAGS, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function linkTexts(Element $body): array
    {
        $texts = [];
        foreach ($body->getElementsByTagName('a') as $link) {
            $texts[] = self::collapsed($link->textContent);
        }

        return $texts;
    }

    /** @return list<string> */
    private static function imageSources(Element $body): array
    {
        $sources = [];
        foreach ($body->getElementsByTagName('img') as $image) {
            $sources[] = (string) $image->getAttribute('src');
        }

        return $sources;
    }

    private static function headingCount(Element $body): int
    {
        $count = 0;
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $tag) {
            $count += $body->getElementsByTagName($tag)->length;
        }

        return $count;
    }

    /** List items whose text is mostly link text — the shape a menu or a "related" list keeps. */
    private static function linkDominatedListItems(Element $body): int
    {
        $count = 0;
        foreach ($body->getElementsByTagName('li') as $item) {
            $total = mb_strlen(self::collapsed($item->textContent));
            if ($total === 0) {
                continue;
            }
            $linked = 0;
            foreach ($item->getElementsByTagName('a') as $link) {
                $linked += mb_strlen(self::collapsed($link->textContent));
            }
            if ($linked / $total >= 0.8) {
                ++$count;
            }
        }

        return $count;
    }

    private static function collapsed(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
