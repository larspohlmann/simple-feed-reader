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
 * The body is kept in document order, because the audit's sharpest question is
 * positional: what stands between the top of the reader view and the first real
 * paragraph. Everything above that paragraph is the leading region, and chrome
 * there is what the reader's user actually runs into (#744).
 */
final readonly class ExtractedBody
{
    private const array BLOCK_TAGS = ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'figcaption', 'div'];

    /**
     * @param list<BodyBlock> $blocks
     * @param list<BodyLink>  $links
     * @param list<string>    $imageSources
     */
    private function __construct(
        public string $text,
        public array $blocks,
        public array $links,
        public array $imageSources,
        public int $paragraphCount,
        public int $headingCount,
    ) {
    }

    public static function fromHtml(string $html): self
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        if ($document === null || $document->body === null) {
            return new self('', [], [], [], 0, 0);
        }

        $body = $document->body;

        return new self(
            text: self::collapsed($body->textContent),
            blocks: self::blocks($body),
            links: self::links($body),
            imageSources: self::imageSources($body),
            paragraphCount: $body->getElementsByTagName('p')->length,
            headingCount: self::headingCount($body),
        );
    }

    public function textLength(): int
    {
        return mb_strlen($this->text);
    }

    /**
     * Whether the body reaches a paragraph at all. False means the reader showed
     * the user no article — a wall, an index page, a stub — which is what makes
     * a wall's wording believable rather than the article's own fine print.
     */
    public function hasArticleText(): bool
    {
        foreach ($this->blocks as $block) {
            if ($block->isProse()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything above the article's first real paragraph. A body that never
     * reaches one is leading region throughout — it is all chrome, which is
     * exactly what the rules should then see.
     *
     * @return list<BodyBlock>
     */
    public function leadingBlocks(): array
    {
        $leading = [];
        foreach ($this->blocks as $block) {
            if ($block->isProse()) {
                return $leading;
            }
            $leading[] = $block;
        }

        return $leading;
    }

    /** @return list<BodyBlock> */
    private static function blocks(Element $body): array
    {
        $blocks = [];
        foreach ($body->getElementsByTagName('*') as $element) {
            if (!\in_array($element->localName, self::BLOCK_TAGS, true) || self::hasBlockChild($element)) {
                continue;
            }
            $text = self::collapsed($element->textContent);
            if ($text !== '') {
                $blocks[] = self::blockOf($element, $text);
            }
        }

        return $blocks;
    }

    private static function blockOf(Element $element, string $text): BodyBlock
    {
        $linkCount = 0;
        $linkedChars = 0;
        foreach ($element->getElementsByTagName('a') as $link) {
            ++$linkCount;
            $linkedChars += mb_strlen(self::collapsed($link->textContent));
        }

        return new BodyBlock($element->localName, $text, $linkCount, $linkedChars);
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

    /** @return list<BodyLink> */
    private static function links(Element $body): array
    {
        $links = [];
        foreach ($body->getElementsByTagName('a') as $link) {
            $links[] = new BodyLink((string) $link->getAttribute('href'), self::collapsed($link->textContent));
        }

        return $links;
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

    private static function collapsed(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
