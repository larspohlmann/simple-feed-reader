<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedTargetModel;

/**
 * Recognises one embed host and reduces any of its URL spellings to one durable embed URL that keeps only what
 * identifies the media, so share tokens, autoplay and player chrome never survive.
 */
interface EmbedProviderInterface
{
    /** The canonical embed with its poster and link text, or null when the URL is not this host's or is malformed. */
    public function target(string $url): ?EmbedTargetModel;

    /**
     * The player URL families this host's `target()` URLs fall into, each an anchored regex — delimiter-free,
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
