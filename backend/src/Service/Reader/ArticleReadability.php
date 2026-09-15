<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Slideshow\ContainerSignature;
use Dom\HTMLDocument;
use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Runs readability over the normalised page and returns the richer of two
 * extractions.
 *
 * Keep the richer of the score-neutral document (repairs only) and the
 * wrapper-chain-collapsed variant (#235), which rescues block-component pages
 * but breaks some well-structured ones (#476) — the longer body wins either
 * way. collapseWrapperChains() returns null when there is no chain to collapse,
 * skipping the second extraction.
 *
 * readability's built-in keep-list only covers video hosts (YouTube, Vimeo…),
 * so it strips a Spotify, SoundCloud or Brightcove frame before the body cleaner
 * can recover it in place; the allowed-frame regex is generated from the embed
 * providers instead, so every host the reader renders survives extraction where
 * the publisher put it (#1053).
 */
final readonly class ArticleReadability
{
    public function __construct(
        private FetchedPageNormalizer $normalizer,
        private RelatedTeaserGridRemover $teaserGridRemover,
        private EmbedProviders $embedProviders,
    ) {
    }

    /**
     * The conservative document arrives already normalised because the caller
     * reads its image inventory before readability consumes (mutates) it (#684).
     *
     * @param list<ContainerSignature> $slideshowContainers
     */
    #[WithSpan]
    public function richest(?HTMLDocument $normalized, PageResponse $page, array $slideshowContainers): ?Article
    {
        $collapsed = $this->normalizer->collapseWrapperChains($page->html);
        $this->removeTeaserGrids($normalized, $slideshowContainers);
        $this->removeTeaserGrids($collapsed, $slideshowContainers);

        return $this->richer($this->parse($normalized, $page->finalUrl), $this->parse($collapsed, $page->finalUrl));
    }

    /**
     * @param list<ContainerSignature> $slideshowContainers
     */
    private function removeTeaserGrids(?HTMLDocument $document, array $slideshowContainers): void
    {
        if ($document !== null) {
            $this->teaserGridRemover->removeFrom($document, $slideshowContainers);
        }
    }

    private function parse(?HTMLDocument $document, string $finalUrl): ?Article
    {
        if ($document === null) {
            return null;
        }

        $readability = new Readability(new Configuration(
            // EdgeBoilerplateTrimmer reads class/id fingerprints on this output
            // (#582); readability strips classes by default, which would make
            // that signal a permanent no-op.
            keepClasses: true,
            allowedVideoRegex: $this->embedProviders->videoEmbedRegex(),
            fixRelativeURLs: true,
            originalURL: $finalUrl,
        ));

        try {
            return $readability->parse($document);
        } catch (ParseException) {
            return null;
        }
    }

    /** Keep the extraction with more readable text; a tie keeps the conservative one. */
    private function richer(?Article $conservative, ?Article $collapsed): ?Article
    {
        if ($conservative === null) {
            return $collapsed;
        }
        if ($collapsed === null) {
            return $conservative;
        }

        return $this->textLength($collapsed) > $this->textLength($conservative)
            ? $collapsed
            : $conservative;
    }

    private function textLength(Article $article): int
    {
        return mb_strlen(trim((string) $article->textContent));
    }
}
