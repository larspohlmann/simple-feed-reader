<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;

/**
 * What a failed fetch attempt earns next: the one direct fallback for a proxied
 * attempt, or the next address family for a direct one.
 *
 * Separate from ConcurrentFeedFetcher because these are rules about a single
 * attempt, not about the concurrency engine that runs them — and because they
 * are the branch-heavy part, which is far easier to read and to test on its own.
 */
final readonly class FetchRetryPolicy
{
    public function __construct(private UrlGuard $urlGuard)
    {
    }

    /**
     * The next attempt this failure earns, or null when it is terminal.
     *
     * A still-proxied attempt (direct fallback off, so no fallback applies) must
     * not fall through to a cross-family retry: that would re-send the same
     * proxied request once per address family, when the spec requires the
     * failure to be terminal instead.
     */
    public function nextAttemptAfter(FetchAttempt $attempt, FetchException $failure): ?FetchAttempt
    {
        $fallback = $this->directFallbackFor($attempt);
        if (null !== $fallback) {
            return $fallback;
        }

        return $attempt->isProxied() ? null : $this->overNextFamily($attempt, $failure);
    }

    /** The one direct fallback for a proxied attempt, or null when none applies. */
    public function directFallbackFor(FetchAttempt $attempt): ?FetchAttempt
    {
        $proxy = $attempt->proxy;

        return null !== $proxy && $proxy->directFallback ? $attempt->withoutProxy() : null;
    }

    /**
     * The same attempt pinned to the next address family, or null when a
     * different family cannot help (see warrantsAnotherFamily). A single-family
     * host has nothing left to try, so the guard's attempt list bounds the retry.
     */
    private function overNextFamily(FetchAttempt $attempt, FetchException $failure): ?FetchAttempt
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
     * Whether this failure could clear on a different address family. An error
     * status (any non-2xx the classifier raised — a 4xx/5xx, or the 410/429 it
     * singles out) can be tied to the source address, so the other family is
     * worth a try. With no status it is a transport failure: a dead-route reset
     * qualifies, a timeout does not. An oversized body would repeat on any family.
     */
    private function warrantsAnotherFamily(FetchException $failure): bool
    {
        if ($failure instanceof ResponseTooLargeException) {
            return false;
        }

        if ($failure instanceof FeedUnreachableException && null === $failure->statusCode) {
            return CrossFamilyFailover::isWarranted($failure->getPrevious());
        }

        return true;
    }
}
