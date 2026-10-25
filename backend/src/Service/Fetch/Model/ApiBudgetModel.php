<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

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
        return new self(self::STANDARD_TIMEOUT_SECONDS, self::STANDARD_MAX_DURATION_SECONDS, ResponseSizeLimit::Api);
    }
}
