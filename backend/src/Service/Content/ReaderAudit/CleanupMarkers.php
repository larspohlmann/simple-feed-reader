<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\ReaderAudit\Model\CleanupMarkerModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;

/**
 * Every marker one extracted article earns. A failed extraction earns none: the reader falls back to the feed body
 * and no cleaner changes that, so reporting it is work nobody can do (#746).
 */
final readonly class CleanupMarkers
{
    public function __construct(
        private LeadingChromeMarkers $leadingChrome,
        private LeadingEngagementMarkers $leadingEngagement,
        private SocialWidgetMarkers $socialWidgets,
        private BodyShapeMarkers $bodyShape,
        private PhraseMarkers $phrases,
    ) {
    }

    /** @return list<CleanupMarkerModel> */
    public function detect(ExtractionResultModel $result, SampledEntryModel $entry, ?ExtractedBodyModel $body): array
    {
        if (!$result->ok || $body === null) {
            return [];
        }

        return [
            ...$this->leadingChrome->detect($body),
            ...$this->leadingEngagement->detect($body, $entry->author),
            ...$this->socialWidgets->detect($body),
            ...$this->bodyShape->detect($body, $entry, $result->title),
            ...$this->phrases->detect($body),
        ];
    }
}
