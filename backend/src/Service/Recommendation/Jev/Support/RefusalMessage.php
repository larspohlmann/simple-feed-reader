<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

use App\Service\Ai\Model\ProviderCredentialsModel;
use App\Service\Recommendation\Support\ProviderErrorReason;

final class RefusalMessage
{
    public static function of(int $status, string $body, ProviderCredentialsModel $credentials): string
    {
        $reason = ProviderErrorReason::in($body, $credentials);

        return null === $reason
            ? sprintf('That provider refused the request (status %d).', $status)
            : sprintf('That provider refused the request (status %d): %s', $status, $reason);
    }

    private function __construct()
    {
    }
}
