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
        public ?int $durationSeconds = null,
    ) {
    }

    /** The same media with the feed-declared MIME type the reader trusts over its own guess. */
    #[\NoDiscard]
    public function withMimeType(?string $mimeType): self
    {
        if ($mimeType === null || $mimeType === $this->mimeType) {
            return $this;
        }

        return clone($this, ['mimeType' => $mimeType]);
    }

    /**
     * A poster-less video takes $fallbackPoster, or is dropped (null) without one: it would rot into a dead frame in
     * the TTL-less cache. Audio and embeds pass through untouched.
     */
    #[\NoDiscard]
    public function resolvePoster(?string $fallbackPoster): ?self
    {
        if (!$this->kind->isVideo() || ($this->posterUrl !== null && $this->posterUrl !== '')) {
            return $this;
        }

        return $fallbackPoster === null || $fallbackPoster === ''
            ? null
            : clone($this, ['posterUrl' => $fallbackPoster]);
    }

    /** The same media with the gaps a later, weaker source can fill: poster, label, the prose anchor, the narration tell, and the duration. */
    #[\NoDiscard]
    public function completedBy(self $later): self
    {
        return clone($this, [
            'posterUrl' => $this->posterUrl ?? $later->posterUrl,
            'label' => $this->label ?? $later->label,
            'precedingText' => $this->precedingText ?? $later->precedingText,
            'narrated' => $this->narrated || $later->narrated,
            'mimeType' => $this->mimeType ?? $later->mimeType,
            'durationSeconds' => $this->durationSeconds ?? $later->durationSeconds,
        ]);
    }

    /** The same media served from where its URL finally lands. */
    #[\NoDiscard]
    public function at(string $url): self
    {
        return clone($this, ['url' => $url]);
    }

    public function toAudioClip(): AudioClipModel
    {
        return new AudioClipModel($this->url, $this->label, $this->durationSeconds, $this->narrated);
    }
}
