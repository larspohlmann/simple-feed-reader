<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Model\PageResponseModel;
use App\Service\Reader\Slideshow\Model\ContainerSignatureModel;
use Dom\HTMLDocument;
use fivefilters\Readability\Article;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Runs readability over the normalised page and over its wrapper-collapsed variant and keeps the richer extraction,
 * since the collapse rescues block-component pages but breaks some well-structured ones (#476). The frame keep-list
 * comes from the embed providers, so every host the reader renders survives extraction.
 */
final readonly class ArticleReadability
{
    public function __construct(
        private FetchedPageNormalizer $normalizer,
        private RelatedTeaserGridRemover $teaserGridRemover,
        private EmbedProviders $embedProviders,
        private ArticleContentGate $contentGate,
    ) {
    }

    /**
     * The conservative document arrives already normalised: the caller reads it before readability consumes it.
     *
     * @param list<ContainerSignatureModel> $slideshowContainers
     */
    #[WithSpan]
    public function richest(HTMLDocument $normalized, PageResponseModel $page, array $slideshowContainers): ?Article
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
            // EdgeBoilerplateTrimmer's verdict reads class fingerprints here; readability strips classes by default.
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

        return $this->contentGate->textLength($collapsed) > $this->contentGate->textLength($conservative)
            ? $collapsed
            : $conservative;
    }
}
