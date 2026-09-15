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

    /**
     * The anchored regular expression, delimiter-free and valid in both PCRE and
     * JavaScript, that matches exactly this host's `normalize()` output. It is the
     * one source of truth for the frame the reader upgrades a link to: the
     * frontend allow-list is generated from every provider's pattern (see
     * `App\Command\DumpEmbedFrameAllowlistCommand`), so a new provider needs no
     * second edit on the client and the two can never drift (#1048).
     */
    public function framePattern(): string;
}
