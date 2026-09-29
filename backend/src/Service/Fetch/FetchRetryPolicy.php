<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\FetchAttemptModel;

/**
 * What a failed fetch attempt earns next: the one direct fallback for a proxied attempt, or the next address family
 * for a direct one.
 */
final readonly class FetchRetryPolicy
{
    public function __construct(private UrlGuard $urlGuard, private CrossFamilyFailover $failover)
    {
    }

    /**
     * The next attempt this failure earns, or null when it is terminal. A still-proxied attempt (no direct fallback)
     * never retries over another family: that would re-send the same proxied request once per family.
     */
    public function nextAttemptAfter(FetchAttemptModel $attempt, FetchException $failure): ?FetchAttemptModel
    {
        $fallback = $this->directFallbackFor($attempt);
        if (null !== $fallback) {
            return $fallback;
        }

        return $attempt->isProxied() ? null : $this->overNextFamily($attempt, $failure);
    }

    public function directFallbackFor(FetchAttemptModel $attempt): ?FetchAttemptModel
    {
        $proxy = $attempt->proxy;

        return null !== $proxy && $proxy->directFallback ? $attempt->withoutProxy() : null;
    }

    private function overNextFamily(FetchAttemptModel $attempt, FetchException $failure): ?FetchAttemptModel
    {
        if (!$this->warrantsAnotherFamily($failure)) {
            return null;
        }

        try {
            $familyCount = \count($this->urlGuard->assertSafe($attempt->url)->pinnedAddressAttempts());
        } catch (FetchException) {
            return null;
        }

        return $attempt->pinnedAddressAttempt + 1 < $familyCount
            ? $attempt->overNextPinnedAddress()
            : null;
    }

    /**
     * An error status can be tied to the source address, so another family may answer; with no status, a dead-route
     * reset qualifies and a timeout does not. An oversized body would repeat on any family.
     */
    private function warrantsAnotherFamily(FetchException $failure): bool
    {
        if ($failure instanceof ResponseTooLargeException) {
            return false;
        }

        if ($failure instanceof FeedUnreachableException && null === $failure->statusCode) {
            return $this->failover->isWarranted($failure->getPrevious());
        }

        return true;
    }
}
