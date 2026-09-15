<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\PageFetchException;
use App\Service\Reader\Media\BodyMediaResolver;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\Teaser\TeaserPlayer;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\Paywall\PaywallSignals;
use App\Service\Reader\Slideshow\ContainerSignature;
use App\Service\Reader\Slideshow\Slideshow;
use App\Service\Reader\Slideshow\SlideshowScanner;
use App\Service\Sanitize\EntrySanitizer;
use Dom\HTMLDocument;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * Turns an article URL into clean, sanitized, distraction-free HTML: fetch
 * (SSRF-guarded) → normalize → page-media scan → readability extraction →
 * body cleaning (duplicate-title removal, edge-boilerplate trim, lead-image
 * restore, media insertion, slideshow recreation) → EntrySanitizer (feed
 * HTML's own XSS barrier).
 * Never throws for an ordinary failure — returns a `failed` ExtractionResult
 * with a machine reason so the endpoint stays 200 and the client falls back
 * to feed content.
 *
 * Readability strips a page-header image as chrome, reporting it apart as
 * og:image; ReaderBodyCleaner restores it via ReaderLeadImage when the page
 * draws it and the body doesn't (#681), using a PageImageInventory built once
 * from the normalised document before readability consumes it (#684).
 *
 * PageMediaScanner also runs on the raw page before readability, so recovered
 * media can satisfy the length gate below and still be inserted when
 * readability's own extraction is thin (#748). PaywallSignals reads the same
 * normalised document and raw source, trusting the publisher's declaration and
 * falling back to a gated-block presence check (#908).
 *
 * SiblingMediaExtender derives from the declared scan but appends onto the
 * stream-resolved media only when consumed, so a failed extraction never pays
 * for network verification (#800).
 */
final class ArticleExtractor implements ArticleExtractorInterface
{
    /** Below this many characters of extracted text, treat as not an article. */
    private const int MIN_CONTENT_LENGTH = 200;

    public function __construct(
        private readonly HtmlPageFetcher $fetcher,
        private readonly FetchedPageNormalizer $normalizer,
        private readonly ReaderBodyCleaner $bodyCleaner,
        private readonly EntrySanitizer $sanitizer,
        private readonly PageMediaScanner $mediaScanner,
        private readonly BodyMediaResolver $bodyMedia,
        private readonly SlideshowScanner $slideshowScanner,
        private readonly TeaserPlayerScanner $teaserScanner,
        private readonly ArticleReadability $readability,
    ) {
    }

    #[WithSpan]
    public function extract(
        string $url,
        ?string $entryTitle = null,
        ?string $entryAuthor = null,
        ?FeedMedia $feedMedia = null,
    ): ExtractionResult {
        $feedMedia ??= FeedMedia::none();
        try {
            $page = $this->fetcher->fetch($url);
        } catch (PageFetchException $failure) {
            return ExtractionResult::failed($url, 'fetch', $failure->getMessage());
        }

        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventory::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptions::fromDocument($normalized);
        $paywalled = PaywallSignals::isPreview($page->html, $normalized);
        $media = $this->mediaScanner->scan($page->html, $page->finalUrl, $feedMedia);
        $slideshows = $this->slideshowsIn($normalized);
        $teasers = $this->teasersIn($normalized, $page->finalUrl);

        $article = $this->readability->richest($normalized, $page, $this->slideshowContainers($slideshows));
        if ($article === null) {
            return ExtractionResult::failed($url, 'unextractable');
        }

        if ($article->content === null || !$article->hasContent()) {
            return ExtractionResult::failed($url, 'empty');
        }
        // A page whose media IS the article carries little prose. Recovered media
        // is itself evidence that this is an article worth showing.
        if ($media->isEmpty() && mb_strlen(trim((string) $article->textContent)) < self::MIN_CONTENT_LENGTH) {
            return ExtractionResult::failed($url, 'empty');
        }

        $leadImage = new LeadImageCandidate($article->image, $pageImages, $leadCaptions->captionFor($article->image));
        $body = $this->bodyCleaner->clean(
            $article->content,
            [$article->title, $entryTitle],
            $leadImage,
            $this->bodyMedia->resolveForBody($media, $page->html),
            $entryAuthor,
            $feedMedia,
            $slideshows,
            $teasers,
            $article->excerpt,
        );
        $clean = $this->sanitizer->sanitize($body);
        if ($clean === null) {
            return ExtractionResult::failed($url, 'empty');
        }

        return ExtractionResult::ok(
            url: $page->finalUrl,
            title: $article->title,
            byline: $article->byline,
            siteName: $article->siteName,
            contentHtml: $clean,
            excerpt: $article->excerpt,
            paywalled: $paywalled,
        );
    }

    /** @return list<Slideshow> */
    private function slideshowsIn(?HTMLDocument $normalized): array
    {
        return $normalized === null ? [] : $this->slideshowScanner->scan($normalized);
    }

    /** @return list<TeaserPlayer> */
    private function teasersIn(?HTMLDocument $normalized, string $finalUrl): array
    {
        return $normalized === null ? [] : $this->teaserScanner->scan($normalized, $finalUrl);
    }

    /**
     * @param list<Slideshow> $slideshows
     * @return list<ContainerSignature>
     */
    private function slideshowContainers(array $slideshows): array
    {
        return array_values(array_filter(
            array_map(static fn (Slideshow $slideshow): ?ContainerSignature => $slideshow->container, $slideshows),
        ));
    }
}
