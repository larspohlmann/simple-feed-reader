<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\RetentionWindows;
use App\Http\FullReplacePayload;
use Symfony\Component\Validator\Constraints as Assert;

/** Both windows are required: its controller maps it with {@see FullReplacePayload::CONTEXT}. */
final readonly class RetentionSettingsRequest
{
    private const int MAX_DAYS = 3650;

    public function __construct(
        #[Assert\Range(min: 1, max: self::MAX_DAYS)]
        public int $entryRetentionDays,
        #[Assert\Range(min: 1, max: self::MAX_DAYS)]
        public int $socialRetentionDays,
    ) {
    }

    public function toWindows(): RetentionWindows
    {
        return new RetentionWindows(entryDays: $this->entryRetentionDays, socialDays: $this->socialRetentionDays);
    }
}
