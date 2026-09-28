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
 * Runs readability over the normalised page and over its wrapper-collapsed variant (#235) and keeps the richer
 * extraction, since the collapse rescues block-component pages but breaks some well-structured ones (#476). The
 * frame keep-list comes from the embed providers, so every host the reader renders survives extraction (#1053).
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
     * The conservative document arrives already normalised: the caller reads it before readability consumes
     * it (#684).
     *
     * @param list<ContainerSignature> $slideshowContainers
     */
    #[WithSpan]
    public function richest(HTMLDocument $normalized, PageResponse $page, array $slideshowContainers): ?Article
    {
        $collapsed = $this->normalizer->collapseWrapperChains($page->html);
        $this->teaserGridRemover->removeFrom($normalized, $slideshowContainers);
        if ($collapsed === null) {
            return $this->parse($normalized, $page->finalUrl);
        }
        $this->teaserGridRemover->removeFrom($collapsed, $slideshowContainers);

        return $this->richer($this->parse($normalized, $page->finalUrl), $this->parse($collapsed, $page->finalUrl));
    }

    private function parse(HTMLDocument $document, string $finalUrl): ?Article
    {
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
