<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Reader\Support\LeadingEngagementBlocks;
use App\Service\Text\Support\Whitespace;
use Dom\Element;

/**
 * The measurements every marker rule reads, taken once from the cleaned article HTML so no rule re-parses it.
 * Blocks stay in document order, because the audit's sharpest question is positional (LeadingRegion).
 */
final readonly class ExtractedBodyModel
{
    /** Below this much text the page produced a notice, not an article. */
    private const int ARTICLE_TEXT_CHARS = 1200;

    /**
     * @param list<BodyBlockModel> $blocks
     * @param list<BodyLinkModel>  $links
     * @param list<string>         $imageSources
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
        $document = HtmlDocumentParser::parseOrEmpty($html);
        if ($document->body === null) {
            return new self('', [], [], [], 0, 0);
        }

        $body = $document->body;

        return new self(
            text: Whitespace::collapse($body->textContent),
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
     * Whether the page yielded an article, by total text: a per-block test would take a cookie wall's legalese
     * paragraph for an article.
     */
    public function hasArticleText(): bool
    {
        return $this->textLength() >= self::ARTICLE_TEXT_CHARS;
    }

    /** @return list<BodyBlockModel> */
    private static function blocks(Element $body): array
    {
        $blocks = [];
        foreach (LeadingEngagementBlocks::in($body) as $block) {
            $blocks[] = self::blockOf($block->element, $block->text);
        }

        return $blocks;
    }

    private static function blockOf(Element $element, string $text): BodyBlockModel
    {
        $links = [];
        foreach ($element->getElementsByTagName('a') as $link) {
            $links[] = self::linkOf($link);
        }

        return new BodyBlockModel($element->localName, $text, $links, LeadingEngagementBlocks::isTimeOnly($element));
    }

    /** @return list<BodyLinkModel> */
    private static function links(Element $body): array
    {
        $links = [];
        foreach ($body->getElementsByTagName('a') as $link) {
            $links[] = self::linkOf($link);
        }

        return $links;
    }

    private static function linkOf(Element $link): BodyLinkModel
    {
        return new BodyLinkModel((string) $link->getAttribute('href'), Whitespace::collapse($link->textContent));
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
}
