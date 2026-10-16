<?php

declare(strict_types=1);

namespace App\Entity;

/** How many days the age pass keeps an entry: any feed's, and a social network's posts. */
final readonly class RetentionWindows
{
    public const int DEFAULT_ENTRY_DAYS = 90;
    public const int DEFAULT_SOCIAL_DAYS = 7;

    public function __construct(public int $entryDays, public int $socialDays)
    {
    }
}
