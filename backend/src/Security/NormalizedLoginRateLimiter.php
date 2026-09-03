<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Makes the login throttle bucket agree with the account it is protecting.
 *
 * Symfony's DefaultLoginRateLimiter derives its per-identifier key from the
 * `_security.last_username` request attribute — the RAW submitted identifier,
 * with mb_strtolower() and nothing else. Our user provider resolves accounts
 * through User::normalizeEmail(), which also trims. The two disagree on
 * whitespace, and the disagreement is exploitable:
 *
 *   " bob@example.com" and "bob@example.com" authenticate as the SAME account
 *   but occupy DIFFERENT throttle buckets.
 *
 * trim() strips six bytes in any combination and length, so an attacker has an
 * unbounded supply of spellings for one address, each with a fresh
 * `max_attempts` budget. The per-identifier throttle stops existing; only the
 * per-IP limiter remains, and across distributed sources not even that.
 *
 * Rewriting the attribute in place — rather than reimplementing the key
 * derivation — keeps the fix honest: the hashing, secret and two-limiter
 * structure stay with Symfony, and there is still exactly one definition of
 * "normalised" (User::normalizeEmail()). A second one here is precisely the drift
 * this bug was. MUTATING THE REQUEST IS DELIBERATE: the normalised value is what
 * every other layer uses, and the only other consumer,
 * AuthenticationUtils::getLastUsername(), re-displays it on a form login — this
 * firewall is a stateless JSON endpoint that never re-displays anything.
 *
 * PEEKABLE IS LOAD-BEARING. LoginThrottlingListener peeks a peekable limiter at
 * CheckPassportEvent and consumes on failure, instead of consuming up front.
 * Decorating a peekable limiter with a non-peekable one would silently shift the
 * boundary by one attempt, so the interface is preserved and the constructor
 * demands a peekable inner — a container error at build time beats an off-by-one
 * in a brute-force defence.
 */
final readonly class NormalizedLoginRateLimiter implements PeekableRequestRateLimiterInterface
{
    public function __construct(
        private PeekableRequestRateLimiterInterface $inner,
    ) {
    }

    public function consume(Request $request): RateLimit
    {
        return $this->inner->consume($this->normalize($request));
    }

    public function peek(Request $request): RateLimit
    {
        return $this->inner->peek($this->normalize($request));
    }

    public function reset(Request $request): void
    {
        $this->inner->reset($this->normalize($request));
    }

    private function normalize(Request $request): Request
    {
        $identifier = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);

        if (\is_string($identifier)) {
            $request->attributes->set(
                SecurityRequestAttributes::LAST_USERNAME,
                User::normalizeEmail($identifier),
            );
        }

        return $request;
    }
}
