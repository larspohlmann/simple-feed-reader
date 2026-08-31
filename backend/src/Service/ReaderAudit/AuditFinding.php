<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * What the audit learned about one article, and the row the report prints. It
 * round-trips through JSON so a sweep can be split across parallel shards and
 * the report assembled from their files afterwards.
 */
final readonly class AuditFinding
{
    /**
     * @param list<CleanupMarker>  $markers
     * @param array<string, int|float> $metrics
     */
    public function __construct(
        public int $entryId,
        public int $feedId,
        public string $feedTitle,
        public string $title,
        public string $sourceUrl,
        public string $readerLink,
        public bool $extracted,
        public array $markers,
        public array $metrics,
    ) {
    }

    public function score(): int
    {
        $score = 0;
        foreach ($this->markers as $marker) {
            $score += $marker->weight;
        }

        return $score;
    }

    /** @return list<string> */
    public function markerCodes(): array
    {
        return array_map(static fn (CleanupMarker $marker): string => $marker->code, $this->markers);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entryId' => $this->entryId,
            'feedId' => $this->feedId,
            'feedTitle' => $this->feedTitle,
            'title' => $this->title,
            'sourceUrl' => $this->sourceUrl,
            'readerLink' => $this->readerLink,
            'extracted' => $this->extracted,
            'markers' => array_map(static fn (CleanupMarker $m): array => $m->toArray(), $this->markers),
            'metrics' => $this->metrics,
            'score' => $this->score(),
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        /** @var list<array{code: string, weight: int, suspect: string, detail: string}> $markers */
        $markers = $row['markers'];
        /** @var array<string, int|float> $metrics */
        $metrics = $row['metrics'];

        return new self(
            entryId: DatabaseValue::int($row['entryId']),
            feedId: DatabaseValue::int($row['feedId']),
            feedTitle: DatabaseValue::string($row['feedTitle']),
            title: DatabaseValue::string($row['title']),
            sourceUrl: DatabaseValue::string($row['sourceUrl']),
            readerLink: DatabaseValue::string($row['readerLink']),
            extracted: (bool) $row['extracted'],
            markers: array_map(CleanupMarker::fromArray(...), $markers),
            metrics: $metrics,
        );
    }
}
