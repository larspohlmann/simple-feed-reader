<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * The media a source page offers for one article, in source order.
 *
 * The cap is a runaway guard, not an editorial choice: the largest measured
 * article carries ten embeds, and truncating that one would recreate the bug
 * this work fixes.
 */
final readonly class ArticleMedia
{
    public const int MAX_ITEMS = 20;

    /** @param list<MediaCandidate> $candidates */
    public function __construct(public array $candidates)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }

    public function withoutEmbeds(): self
    {
        return new self(array_values(
            array_filter($this->candidates, static fn (MediaCandidate $c): bool => $c->kind !== MediaKind::Embed)
        ));
    }

    /** A file plays everywhere without a library; a stream is the fallback for a page that offers no file. */
    public function withoutRedundantStreams(): self
    {
        if (!$this->offers(MediaKind::Video)) {
            return $this;
        }

        return new self(array_values(
            array_filter($this->candidates, static fn (MediaCandidate $c): bool => $c->kind !== MediaKind::Stream)
        ));
    }

    /** @param list<MediaCandidate> $more */
    public function with(array $more): self
    {
        return new self(\array_slice(array_merge($this->candidates, $more), 0, self::MAX_ITEMS));
    }

    private function offers(MediaKind $kind): bool
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->kind === $kind) {
                return true;
            }
        }

        return false;
    }
}
