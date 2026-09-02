<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * The sweep's JSONL files read back as one collection, with the three views the
 * report is built from: the worst articles, the worst feeds, and the stages that
 * account for the most markers.
 *
 * Feeds are ranked by the share of their audited articles that carry a marker,
 * not by how many markers they produced: a feed that publishes ten times as
 * often would otherwise always top the list, and the question the report answers
 * is "which site does the reader handle badly", not "which site is large".
 */
final readonly class AuditFindings
{
    /** @param list<AuditFinding> $findings */
    private function __construct(public array $findings)
    {
    }

    /**
     * An entry measured in more than one file keeps its last measurement: the
     * shards fetch under their own concurrency, and a host that rate-limited them
     * is re-measured alone afterwards (#783).
     *
     * @param list<string> $paths
     */
    public static function fromJsonlFiles(array $paths): self
    {
        $byEntry = [];
        foreach ($paths as $path) {
            foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                /** @var array<string, mixed> $row */
                $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                $finding = AuditFinding::fromArray($row);
                $byEntry[$finding->entryId] = $finding;
            }
        }

        return new self(array_values($byEntry));
    }

    /**
     * Flagged articles, worst first.
     *
     * @return list<AuditFinding>
     */
    public function ranked(): array
    {
        $flagged = array_values(array_filter($this->findings, static fn (AuditFinding $f): bool => $f->markers !== []));
        usort($flagged, static fn (AuditFinding $a, AuditFinding $b): int => $b->score() <=> $a->score());

        return $flagged;
    }

    /**
     * Per feed: [feedTitle, audited, flagged, share, worstScore], worst share
     * first. Only feeds that failed at least once — a sweep covers every
     * subscribed feed, so listing the clean ones buried nine rows worth reading
     * under a hundred and sixty reading "0%".
     *
     * @return list<array{feed: string, audited: int, flagged: int, share: float, worst: int}>
     */
    public function byFeed(): array
    {
        $feeds = [];
        foreach ($this->findings as $finding) {
            $blank = ['feed' => $finding->feedTitle, 'audited' => 0, 'flagged' => 0, 'share' => 0.0, 'worst' => 0];
            $row = $feeds[$finding->feedId] ?? $blank;
            ++$row['audited'];
            $row['flagged'] += $finding->markers === [] ? 0 : 1;
            $row['worst'] = max($row['worst'], $finding->score());
            $feeds[$finding->feedId] = $row;
        }

        $rows = [];
        foreach ($feeds as $row) {
            if ($row['flagged'] === 0) {
                continue;
            }
            $row['share'] = (float) $row['flagged'] / $row['audited'];
            $rows[] = $row;
        }
        $worstFirst = static fn (array $a, array $b): int
            => [$b['share'], $b['flagged']] <=> [$a['share'], $a['flagged']];
        usort($rows, $worstFirst);

        return $rows;
    }

    /**
     * How many articles each marker code and each named suspect appears on.
     *
     * @param callable(CleanupMarker): string $keyOf
     *
     * @return array<string, int>
     */
    public function tally(callable $keyOf): array
    {
        $counts = [];
        foreach ($this->findings as $finding) {
            foreach ($finding->markers as $marker) {
                $key = $keyOf($marker);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    public function audited(): int
    {
        return \count($this->findings);
    }

    public function extracted(): int
    {
        return \count(array_filter($this->findings, static fn (AuditFinding $f): bool => $f->extracted));
    }

    public function feedCount(): int
    {
        return \count(array_unique(array_map(static fn (AuditFinding $f): int => $f->feedId, $this->findings)));
    }
}
