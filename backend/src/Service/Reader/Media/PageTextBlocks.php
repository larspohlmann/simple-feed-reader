<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use App\Service\Reader\LeadingBlock;
use App\Service\Reader\LeadingEngagementBlocks;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * The prose blocks of a page, so a media element can be described by the block
 * it follows on the source page and found again by that block in the extracted
 * body. Readability removes a player block outright when its only text is a
 * link, but it keeps the paragraph before it nearly verbatim — that paragraph's
 * text is the one durable trace of where the player stood.
 *
 * Short blocks are skipped: a dateline or a kicker is exactly what the body
 * cleaners remove, so anchoring to one would lose the position anyway.
 */
final readonly class PageTextBlocks
{
    private const int MIN_LENGTH = 40;

    /** @param list<LeadingBlock> $blocks in document order */
    private function __construct(private array $blocks)
    {
    }

    public static function fromDocument(HTMLDocument $document): self
    {
        $body = $document->body;
        if ($body === null) {
            return new self([]);
        }

        $prose = array_filter(
            LeadingEngagementBlocks::in($body),
            static fn (LeadingBlock $block): bool => mb_strlen($block->text) >= self::MIN_LENGTH,
        );

        return new self(array_values($prose));
    }

    /** The text of the nearest prose block before the element, never one that contains it. */
    public function before(Element $element): ?string
    {
        $preceding = null;
        foreach ($this->blocks as $block) {
            if ($this->precedes($block->element, $element)) {
                $preceding = $block->text;
            }
        }

        return $preceding;
    }

    public function withText(string $text): ?Element
    {
        foreach ($this->blocks as $block) {
            if ($block->text === $text) {
                return $block->element;
            }
        }

        return null;
    }

    private function precedes(Element $block, Element $element): bool
    {
        $position = $element->compareDocumentPosition($block);

        return ($position & Node::DOCUMENT_POSITION_PRECEDING) !== 0
            && ($position & Node::DOCUMENT_POSITION_CONTAINS) === 0;
    }
}
