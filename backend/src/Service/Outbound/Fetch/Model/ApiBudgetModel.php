<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Model;

final readonly class ApiBudgetModel
{
    public const float STANDARD_TIMEOUT_SECONDS = 10.0;

    public const float STANDARD_MAX_DURATION_SECONDS = self::STANDARD_TIMEOUT_SECONDS * 2;

    public function __construct(
        public float $timeoutSeconds,
        public float $maxDurationSeconds,
        public ResponseSizeLimit $sizeLimit,
    ) {
    }

    public static function standard(): self
    {
        return self::waitingUpTo(self::STANDARD_TIMEOUT_SECONDS);
    }

    public static function waitingUpTo(float $timeoutSeconds): self
    {
        return new self($timeoutSeconds, self::computeMaxDuration($timeoutSeconds), ResponseSizeLimit::Api);
    }

    public static function computeMaxDuration(float $timeoutSeconds): float
    {
        return $timeoutSeconds * 2;
    }
}
