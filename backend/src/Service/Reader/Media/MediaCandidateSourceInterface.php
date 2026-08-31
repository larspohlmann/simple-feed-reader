<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Finds the media one publisher's pages offer. Reads the RAW page, not the
 * normalized document: FetchedPageNormalizer strips every <script> before it
 * parses, and some hosts carry their media only in player JSON.
 */
interface MediaCandidateSourceInterface
{
    /** @return list<MediaCandidate> */
    public function find(string $pageHtml, string $pageUrl): array;
}
