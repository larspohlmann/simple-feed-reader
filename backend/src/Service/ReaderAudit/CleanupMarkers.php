<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\ExtractionResult;

/**
 * Every marker one audited article earns. A failed extraction earns exactly one
 * — the reason it failed, which is already the whole finding — while a
 * successful one is measured by shape and by wording.
 */
final readonly class CleanupMarkers
{
    /**
     * Reason from ExtractionResult => [weight, the stage to look at first]. Only
     * the reasons a change to this codebase could fix are listed. A page that
     * never arrived (`fetch`) is a publisher blocking the fetcher or an outage,
     * and an entry with no URL (`no_url`) has nothing to fetch; no cleaner fixes
     * either. 59 of the first sweep's 159 findings were unreachable pages, and
     * they buried the ones worth acting on (#744).
     */
    private const array FAILURE_SUSPECTS = [
        'unextractable' => [4, 'readability found no article on the page'],
        'empty' => [4, 'extraction or cleaning left nothing'],
        'mismatch' => [5, 'ExtractionCoverageGate rejected the extraction (#654)'],
    ];

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
            return $this->failure((string) $result->reason);
        }

        return [
            ...$this->leadingChrome->detect($body),
            ...$this->socialWidgets->detect($body),
            ...$this->bodyShape->detect($body, $entry, $result->title),
            ...$this->phrases->detect($body),
        ];
    }

    /** @return list<CleanupMarker> */
    private function failure(string $reason): array
    {
        $reportable = self::FAILURE_SUSPECTS[$reason] ?? null;
        if ($reportable === null) {
            return [];
        }

        return [new CleanupMarker(
            'extraction_failed_' . $reason,
            $reportable[0],
            $reportable[1],
            'the reader fell back to the feed body',
        )];
    }
}
