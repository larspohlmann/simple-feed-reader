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
    /** Reason from ExtractionResult => [weight, the stage to look at first]. */
    private const array FAILURE_SUSPECTS = [
        'fetch' => [3, 'HtmlPageFetcher — the page never arrived'],
        'unextractable' => [4, 'readability found no article on the page'],
        'empty' => [4, 'extraction or cleaning left nothing'],
        'mismatch' => [5, 'ExtractionCoverageGate rejected the extraction (#654)'],
        'no_url' => [0, 'the entry has no source URL'],
    ];

    public function __construct(
        private StructureMarkers $structure,
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
            return [$this->failure((string) $result->reason)];
        }

        return [
            ...$this->structure->detect($body, $entry, $result->title),
            ...$this->phrases->detect($body),
        ];
    }

    private function failure(string $reason): CleanupMarker
    {
        [$weight, $suspect] = self::FAILURE_SUSPECTS[$reason] ?? [3, 'unknown failure reason'];

        return new CleanupMarker(
            'extraction_failed_' . $reason,
            $weight,
            $suspect,
            'the reader fell back to the feed body'
        );
    }
}
