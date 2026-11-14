<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

use App\Service\Reader\Model\LeadingBlockModel;
use App\Service\Reader\Support\LeadingEngagementBlocks;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;

/**
 * A page's prose blocks, so media is found again in the extracted body by the block it followed: readability drops a
 * link-only player block but keeps the paragraph before it. Short blocks are skipped, as body cleaners remove them.
 */
final readonly class PageTextBlocksModel
{
    private const int MIN_LENGTH = 40;

    /** @param list<LeadingBlockModel> $blocks in document order */
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
            static fn (LeadingBlockModel $block): bool => mb_strlen($block->text) >= self::MIN_LENGTH,
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
