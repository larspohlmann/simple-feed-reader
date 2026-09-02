<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One host-agnostic way to find the media a page offers. Implementations are
 * collected in AsTaggedItem priority order, highest first. The first to name a
 * URL sets that candidate; later ones fill its gaps and add the URLs it never
 * named — a declaration leads, but hides nothing a scan finds (#788).
 *
 * Reads the RAW page, not FetchedPageNormalizer's document: that pass is tuned
 * for readability scoring, removes elements, and is free to change again.
 *
 * Mirrors Service/Scraper/Layer/ScrapeLayerInterface, which solves the same
 * problem shape for feedless pages.
 */
#[AutoconfigureTag('app.media_candidate_source')]
interface MediaCandidateSourceInterface
{
    /** @return list<MediaCandidate> */
    public function find(string $pageHtml, string $pageUrl): array;
}
