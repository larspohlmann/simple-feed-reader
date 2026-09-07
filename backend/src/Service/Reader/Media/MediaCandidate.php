<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * One piece of media the source page offers for this article. `posterUrl` is the
 * still a video shows before playback; `label` is the link text an embed falls
 * back to when the provider has no cheap poster; `precedingText` is the prose
 * block the media followed on the page, the trace by which it finds its place
 * again in a body that lost the player itself (see PageTextBlocks). `narrated`
 * flags machine-generated narration (#903), so the client renders it small.
 */
final readonly class MediaCandidate
{
    public function __construct(
        public MediaKind $kind,
        public string $url,
        public ?string $posterUrl = null,
        public ?string $label = null,
        public ?string $precedingText = null,
        public bool $narrated = false,
    ) {
    }

    /**
     * The candidate a video needs a still to be, or none. A video plays in a
     * <video> element and rots into a dead frame in the reader's TTL-less cache
     * without a poster; once every source has had its say and none supplied one,
     * the feed-declared still rescues it (#913), and a video the feed cannot
     * back either is dropped. A non-video passes through untouched — audio and
     * an embed carry no poster of their own.
     */
    public function resolvePoster(?string $fallbackPoster): ?self
    {
        if (!$this->kind->isVideo() || ($this->posterUrl !== null && $this->posterUrl !== '')) {
            return $this;
        }

        return $fallbackPoster === null || $fallbackPoster === ''
            ? null
            : new self($this->kind, $this->url, $fallbackPoster, $this->label, $this->precedingText, $this->narrated);
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
        );
    }

    /** The same media served from where its URL finally lands. */
    public function at(string $url): self
    {
        return new self($this->kind, $url, $this->posterUrl, $this->label, $this->precedingText, $this->narrated);
    }
}
