<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;

/**
 * Recognises one embed host and reduces any of its URL spellings to one durable embed URL that keeps only what
 * identifies the media, so share tokens, autoplay and player chrome never survive.
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
     * The player URL families this host's `normalize()` output falls into, each an anchored regex — delimiter-free,
     * valid in both PCRE and JavaScript — with what it plays and the box it needs. The patterns are disjoint, and the
     * reader client's allow-list is generated from them, so the two never drift.
     *
     * @return list<EmbedFrameModel>
     */
    public function frames(): array;

    /**
     * The embed-source hosts this provider claims. Readability's in-body keep-list is built from them, so a supported
     * embed survives extraction instead of being stripped as a non-video frame.
     *
     * @return list<string>
     */
    public function sourceHosts(): array;
}
