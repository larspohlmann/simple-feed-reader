<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Support;

/** The sentence a run or profile run carries when its AI provider failed it. */
final readonly class ProviderFailedMessage
{
    public static function of(string $baseUrl, string $failureDetail): string
    {
        return \sprintf('The AI provider at %s failed: %s', $baseUrl, $failureDetail);
    }

    private function __construct()
    {
    }
}
