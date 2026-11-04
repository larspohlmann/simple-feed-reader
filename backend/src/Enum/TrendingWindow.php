<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum TrendingWindow: string
{
    use RequestValueParsing;

    case Day = 'day';
    case Week = 'week';

    private const int HOUR = 3_600;

    /**
     * @throws ValidationException when the value names neither window
     */
    public static function fromRequestValue(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Day;
        }

        return self::caseOrRefusal($value, 'window');
    }

    public function spanSeconds(): int
    {
        return match ($this) {
            self::Day => 24 * self::HOUR,
            self::Week => 7 * 24 * self::HOUR,
        };
    }

    public function rebuildIntervalSeconds(): int
    {
        return match ($this) {
            self::Day => 10 * 60,
            self::Week => self::HOUR,
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
}
