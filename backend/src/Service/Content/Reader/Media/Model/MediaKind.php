<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

enum MediaKind: string
{
    case Audio = 'audio';
    case Video = 'video';
    /** An HLS playlist: a <video> for Safari and the native client, hls.js elsewhere. */
    case Stream = 'stream';
    case Embed = 'embed';

    /** Plays in a <video> element, so it needs a poster against the TTL-less cache. */
    public function isVideo(): bool
    {
        return $this === self::Video || $this === self::Stream;
    }

    /**
     * Takes the article's lead position when placed at the top, so the page hero must not stack above it; audio does
     * not. A match, so a future kind must declare where it sits rather than default in.
     */
    public function readsAsLeadVisual(): bool
    {
        return match ($this) {
            self::Video, self::Stream, self::Embed => true,
            self::Audio => false,
        };
    }
}
