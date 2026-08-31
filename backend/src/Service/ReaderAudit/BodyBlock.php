<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * One paragraph-level line of the cleaned article, with enough about its links
 * to say whether it is prose or chrome. Where a block sits matters more than
 * what it holds: a related-articles list under the last paragraph is furniture
 * the reader tolerates, and the same list above the first paragraph is the
 * article failing to start (#744).
 */
final readonly class BodyBlock
{
    /** Below this many characters a block is a caption or a label, not a paragraph. */
    private const int PROSE_CHARS = 200;

    /** A block whose text is this share links is a menu entry, not a sentence. */
    private const float LINK_DOMINATED = 0.8;

    public function __construct(
        public string $tag,
        public string $text,
        public int $linkCount,
        public int $linkedChars,
    ) {
    }

    public function length(): int
    {
        return mb_strlen($this->text);
    }

    public function isLinkDominated(): bool
    {
        return $this->length() > 0 && $this->linkedChars / $this->length() >= self::LINK_DOMINATED;
    }

    /** The first block that answers true is where the article begins. */
    public function isProse(): bool
    {
        return $this->length() >= self::PROSE_CHARS && !$this->isLinkDominated();
    }
}
