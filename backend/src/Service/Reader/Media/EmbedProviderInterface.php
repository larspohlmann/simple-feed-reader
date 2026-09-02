<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Recognises one embed host and reduces any of its URL spellings to a single
 * durable embed URL. Implementations keep only what identifies the media —
 * for most hosts nothing of the query, for Brightcove the video id alone — so
 * share tokens, autoplay and player chrome never survive.
 */
interface EmbedProviderInterface
{
    public function matches(string $url): bool;

    /** The canonical embed URL, or null when the URL is malformed for this host. */
    public function normalize(string $url): ?string;

    /** A still to show before playback, or null when the host offers none cheaply. */
    public function poster(string $url): ?string;

    /** Link text used when there is no poster. */
    public function label(): string;
}
