<?php

declare(strict_types=1);

namespace App\Entity;

/** The profile section's stored choices: the schedule, the connection and the two history caps only it reads. */
final readonly class ProfileSettingsValues
{
    public function __construct(
        public ?int $intervalHours,
        public ?AiProviderSettings $connection,
        public int $keptCap,
        public int $viewedCap,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            null,
            null,
            ProfileTuning::DEFAULT_KEPT_CAP,
            ProfileTuning::DEFAULT_VIEWED_CAP,
        );
    }
}
