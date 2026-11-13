<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum TrendingWindow: string
{
    use RequestValueParsing;

    case Day = 'day';
    case Week = 'week';

    public const int STORIES_WORTH_SHOWING = 5;
    public const int MULTIPLE_SOURCES = 2;

    private const int HOUR = 3_600;
    private const int PER_MILLE = 1_000;

    /**
     * @param string $parameter the query parameter a refusal names
     *
     * @throws ValidationException when the value names neither window
     */
    public static function fromRequestValue(?string $value, string $parameter = 'window'): self
    {
        if ($value === null || $value === '') {
            return self::Day;
        }

        return self::caseOrRefusal($value, $parameter);
    }

    public function startBefore(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d seconds', $this->spanSeconds()));
    }

    private function spanSeconds(): int
    {
        return match ($this) {
            self::Day => 24 * self::HOUR,
            self::Week => 7 * 24 * self::HOUR,
        };
    }

    /**
     * A story sharing an entry with one the reader is shown in these windows is left out of this one (D41).
     *
     * @return list<self>
     */
    public function leavesOutTheStoriesOf(): array
    {
        return match ($this) {
            self::Day => [],
            self::Week => [self::Day],
        };
    }

    public function rebuildIntervalSeconds(): int
    {
        return match ($this) {
            self::Day => 10 * 60,
            self::Week => self::HOUR,
        };
    }

    /** What a tick assumes a build of a window never built takes: a week build measured about 25 s on Strato. */
    public function firstBuildEstimateMilliseconds(): int
    {
        return match ($this) {
            self::Day => 5_000,
            self::Week => 30_000,
        };
    }

    /** Two entries further apart than this never link, however alike their headlines. */
    public function maxLinkGapSeconds(): int
    {
        return match ($this) {
            self::Day => 36 * self::HOUR,
            self::Week => 48 * self::HOUR,
        };
    }

    public function minSubscribedFeeds(): int
    {
        return match ($this) {
            self::Day => 3,
            self::Week => 5,
        };
    }

    /** The preferred minimum: the floor, or this window's share of the reader's active feeds when that is more. */
    public function minimumOutletsFor(int $activeFeeds): int
    {
        $shareRoundedUp = intdiv($this->activeSharePerMille() * $activeFeeds + self::PER_MILLE - 1, self::PER_MILLE);

        return max($this->minSubscribedFeeds(), $shareRoundedUp);
    }

    private function activeSharePerMille(): int
    {
        return match ($this) {
            self::Day => 30,
            self::Week => 25,
        };
    }
}
