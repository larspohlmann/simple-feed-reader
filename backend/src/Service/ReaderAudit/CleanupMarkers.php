<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\ExtractionResult;

/**
 * Every marker one audited article earns — and only for an article the pipeline
 * actually extracted.
 *
 * A failed extraction earns nothing. Whatever the reason — the page never
 * arrived, readability found no article, the coverage gate rejected what it did
 * find — the reader falls back to the feed body and shows the user the original.
 * That is a real outcome and no cleaner changes it, so listing it fills the
 * report with work nobody can do (#744).
 */
final readonly class CleanupMarkers
{
    public function __construct(
        private LeadingChromeMarkers $leadingChrome,
        private SocialWidgetMarkers $socialWidgets,
        private BodyShapeMarkers $bodyShape,
        private PhraseMarkers $phrases,
    ) {
    }

    /**
     * The body is measured by the caller, which also reports its numbers, so the
     * cleaned HTML is parsed once per article rather than once per reader of it.
     *
     * @return list<CleanupMarker>
     */
    public function detect(ExtractionResult $result, SampledEntry $entry, ?ExtractedBody $body): array
    {
        if (!$result->ok || $body === null) {
            return [];
        }

        return [
            ...$this->leadingChrome->detect($body),
            ...$this->socialWidgets->detect($body),
            ...$this->bodyShape->detect($body, $entry, $result->title),
            ...$this->phrases->detect($body),
        ];
    }
}
