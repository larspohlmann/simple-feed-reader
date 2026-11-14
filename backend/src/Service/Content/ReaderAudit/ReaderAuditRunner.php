<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\ReaderAudit\Model\AuditFindingModel;
use App\Service\ReaderAudit\Model\CleanupMarkerModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\ReaderLinkModel;
use App\Service\ReaderAudit\Model\SampledEntryModel;

/**
 * Runs the reader pipeline over sampled articles as the reader endpoint does (extract, then the coverage gate) and
 * reports what the cleaners left. It yields, so a sweep streams to disk instead of holding every body in memory.
 */
final readonly class ReaderAuditRunner
{
    public function __construct(
        private ArticleExtractorInterface $extractor,
        private ExtractionCoverageGate $coverageGate,
        private CleanupMarkers $markers,
        private LeadingRegion $leadingRegion,
    ) {
    }

    /**
     * @param iterable<SampledEntryModel> $entries
     *
     * @return \Generator<int, AuditFindingModel>
     */
    public function run(iterable $entries, ReaderLinkModel $link): \Generator
    {
        foreach ($entries as $entry) {
            try {
                yield $this->audit($entry, $link);
            } catch (\Throwable $error) {
                // A sweep of a thousand publishers meets malformed markup no test
                // fixture holds; one page that kills the pipeline must not cost
                // the other 999 results.
                yield $this->crashed($entry, $link, $error);
            }
        }
    }

    private function audit(SampledEntryModel $entry, ReaderLinkModel $link): AuditFindingModel
    {
        $result = $this->coverageGate->verify(
            $this->extractor->extract($entry->url, new EntryHintsModel(title: $entry->title, author: $entry->author)),
            $entry->feedContentHtml,
        );

        $body = $result->ok ? ExtractedBodyModel::fromHtml((string) $result->contentHtml) : null;

        return new AuditFindingModel(
            entryId: $entry->entryId,
            feedId: $entry->feedId,
            feedTitle: $entry->feedTitle,
            title: $entry->title,
            sourceUrl: $entry->url,
            readerLink: $link->to($entry),
            extracted: $result->ok,
            markers: $this->markers->detect($result, $entry, $body),
            metrics: $this->metrics($result, $body),
        );
    }

    private function crashed(SampledEntryModel $entry, ReaderLinkModel $link, \Throwable $error): AuditFindingModel
    {
        return new AuditFindingModel(
            entryId: $entry->entryId,
            feedId: $entry->feedId,
            feedTitle: $entry->feedTitle,
            title: $entry->title,
            sourceUrl: $entry->url,
            readerLink: $link->to($entry),
            extracted: false,
            markers: [new CleanupMarkerModel('audit_error', 4, 'the pipeline threw', $error->getMessage())],
            metrics: ['chars' => 0],
        );
    }

    /** @return array<string, int|float> */
    private function metrics(ExtractionResultModel $result, ?ExtractedBodyModel $body): array
    {
        if ($body === null) {
            return [
                'chars' => 0,
                'paragraphs' => 0,
                'links' => 0,
                'images' => 0,
                'leadingBlocks' => 0,
                'paywalled' => 0,
            ];
        }

        return [
            'chars' => $body->textLength(),
            'paragraphs' => $body->paragraphCount,
            'links' => \count($body->links),
            'images' => \count($body->imageSources),
            'leadingBlocks' => \count($this->leadingRegion->blocksOf($body)),
            'paywalled' => (int) $result->paywalled,
        ];
    }
}
