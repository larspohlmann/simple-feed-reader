<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

/**
 * One piece of media the page offers. `precedingText` is the prose block it followed, by which it finds its place in
 * a body that lost the player (see PageTextBlocksModel); `narrated` marks machine narration, rendered small.
 */
final readonly class MediaCandidateModel
{
    public function __construct(
        public MediaKind $kind,
        public string $url,
        public ?string $posterUrl = null,
        public ?string $label = null,
        public ?string $precedingText = null,
        public bool $narrated = false,
        public ?string $mimeType = null,
    ) {
    }

    /** The same media with the feed-declared MIME type the reader trusts over its own guess. */
    public function withMimeType(?string $mimeType): self
    {
        if ($mimeType === null || $mimeType === $this->mimeType) {
            return $this;
        }

        return new self(
            $this->kind,
            $this->url,
            $this->posterUrl,
            $this->label,
            $this->precedingText,
            $this->narrated,
            $mimeType,
        );
    }

    /**
     * A poster-less video takes $fallbackPoster, or is dropped (null) without one: it would rot into a dead frame in
     * the TTL-less cache. Audio and embeds pass through untouched.
     */
    public function resolvePoster(?string $fallbackPoster): ?self
    {
        if (!$this->kind->isVideo() || ($this->posterUrl !== null && $this->posterUrl !== '')) {
            return $this;
        }

        return $fallbackPoster === null || $fallbackPoster === ''
            ? null
            : new self(
                $this->kind,
                $this->url,
                $fallbackPoster,
                $this->label,
                $this->precedingText,
                $this->narrated,
                $this->mimeType,
            );
    }

    /** The same media with the gaps a later, weaker source can fill: poster, label, the prose anchor, and the narration tell. */
    public function completedBy(self $later): self
    {
        return new self(
            $this->kind,
            $this->url,
            $this->posterUrl ?? $later->posterUrl,
            $this->label ?? $later->label,
            $this->precedingText ?? $later->precedingText,
            $this->narrated || $later->narrated,
            $this->mimeType ?? $later->mimeType,
        );
    }

    /** The same media served from where its URL finally lands. */
    public function at(string $url): self
    {
        return new self(
            $this->kind,
            $url,
            $this->posterUrl,
            $this->label,
            $this->precedingText,
            $this->narrated,
            $this->mimeType,
        );
    }
}
