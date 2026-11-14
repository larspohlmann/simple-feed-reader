<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/**
 * The sweep's JSONL files read back, with the report's three views: worst articles, worst feeds, busiest stages.
 * Feeds rank by the share of their articles flagged, not by marker count, so a prolific feed does not top the list.
 */
final readonly class AuditFindingsModel
{
    /** @param list<AuditFindingModel> $findings */
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
                $finding = AuditFindingModel::fromFindingsFileRecord($row);
                $byEntry[$finding->entryId] = $finding;
            }
        }

        return new self(array_values($byEntry));
    }

    /**
     * Flagged articles, worst first.
     *
     * @return list<AuditFindingModel>
     */
    public function ranked(): array
    {
        $flagged = array_values(array_filter(
            $this->findings,
            static fn (AuditFindingModel $finding): bool => $finding->markers !== [],
        ));
        usort(
            $flagged,
            static fn (AuditFindingModel $left, AuditFindingModel $right): int => $right->score() <=> $left->score(),
        );

        return $flagged;
    }

    /**
     * Per feed, worst share first, and only feeds flagged at least once: a sweep covers every subscribed feed, and the
     * clean rows would bury the few worth reading.
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
        $worstFirst = static fn (array $left, array $right): int
            => [$right['share'], $right['flagged']] <=> [$left['share'], $left['flagged']];
        usort($rows, $worstFirst);

        return $rows;
    }

    /**
     * How many articles each marker code and each named suspect appears on.
     *
     * @param callable(CleanupMarkerModel): string $keyOf
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
        return \count(array_filter(
            $this->findings,
            static fn (AuditFindingModel $finding): bool => $finding->extracted,
        ));
    }

    public function feedCount(): int
    {
        return \count(array_unique(array_map(
            static fn (AuditFindingModel $finding): int => $finding->feedId,
            $this->findings,
        )));
    }
}
