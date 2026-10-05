<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Support\ProviderErrorReason;

/**
 * A refused request's failure, naming what the provider objected to: TypeSafe's `detail` or OpenRouter's
 * `error.message`. Never the raw body, which on OpenRouter carries the account's `user_id`.
 */
final class RefusalMessage
{
    public static function of(int $status, string $body): string
    {
        $reason = ProviderErrorReason::in($body);

        return null === $reason
            ? sprintf('That provider refused the request (status %d).', $status)
            : sprintf('That provider refused the request (status %d): %s', $status, $reason);
    }

    private function __construct()
    {
    }
}
