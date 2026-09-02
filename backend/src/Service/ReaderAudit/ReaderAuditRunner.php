<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\ExtractionResult;

/**
 * Runs the reader pipeline over sampled articles exactly as the reader endpoint
 * does — extract, then the coverage gate — and reports what the cleaners left
 * behind. It yields, so a thousand-article sweep streams to disk instead of
 * holding a thousand article bodies in memory.
 */
final readonly class ReaderAuditRunner
{
    public function __construct(
        private ArticleExtractorInterface $extractor,
        private ExtractionCoverageGate $coverageGate,
        private CleanupMarkers $markers,
    ) {
    }

    /**
     * @param iterable<SampledEntry> $entries
     *
     * @return \Generator<int, AuditFinding>
     */
    public function run(iterable $entries, ReaderLink $link): \Generator
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

    private function audit(SampledEntry $entry, ReaderLink $link): AuditFinding
    {
        $result = $this->coverageGate->verify(
            $this->extractor->extract($entry->url, $entry->title, $entry->author),
            $entry->feedContentHtml,
        );

        $body = $result->ok ? ExtractedBody::fromHtml((string) $result->contentHtml) : null;

        return new AuditFinding(
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

    private function crashed(SampledEntry $entry, ReaderLink $link, \Throwable $error): AuditFinding
    {
        return new AuditFinding(
            entryId: $entry->entryId,
            feedId: $entry->feedId,
            feedTitle: $entry->feedTitle,
            title: $entry->title,
            sourceUrl: $entry->url,
            readerLink: $link->to($entry),
            extracted: false,
            markers: [new CleanupMarker('audit_error', 4, 'the pipeline threw', $error->getMessage())],
            metrics: ['chars' => 0],
        );
    }

    /** @return array<string, int|float> */
    private function metrics(ExtractionResult $result, ?ExtractedBody $body): array
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
            'leadingBlocks' => \count($body->leadingBlocks()),
            'paywalled' => (int) $result->paywalled,
        ];
    }
}
