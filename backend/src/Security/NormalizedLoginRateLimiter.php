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
 * `_security.last_username` request attribute, which LoginThrottlingListener
 * fills with the RAW submitted identifier, and then applies mb_strtolower()
 * and nothing else. Our user provider resolves accounts through
 * User::normalizeEmail(), which also trims. The two disagree on whitespace,
 * and the disagreement is exploitable rather than cosmetic:
 *
 *   " bob@example.com" and "bob@example.com" authenticate as the SAME account
 *   but occupy DIFFERENT throttle buckets.
 *
 * trim() strips six bytes (space, tab, LF, CR, NUL, VT) in any combination and
 * any length, so an attacker has an unbounded supply of spellings for one
 * address, each with a fresh budget of `max_attempts`. The per-identifier
 * throttle simply stops existing; only the per-IP global limiter remains, and
 * across distributed source addresses not even that.
 *
 * Rewriting the attribute in place — rather than reimplementing the key
 * derivation — is what keeps the fix honest. The hashing, the secret and the
 * two-limiter global/local structure stay with Symfony; this class only makes
 * sure the identifier handed to them is the one the user provider will
 * actually look up. There is still exactly one definition of "normalised",
 * User::normalizeEmail(), and adding a second here is precisely the drift this
 * bug was.
 *
 * MUTATING THE REQUEST IS DELIBERATE. The normalised value is what every other
 * layer already uses to identify the account, so leaving it in place is more
 * consistent, not less. The only other consumer of the attribute in Symfony is
 * AuthenticationUtils::getLastUsername(), which re-displays it on a form login
 * — this firewall is a stateless JSON endpoint that never re-displays anything.
 *
 * PEEKABLE IS LOAD-BEARING. LoginThrottlingListener behaves differently for a
 * peekable limiter: it peeks at CheckPassportEvent and consumes on failure,
 * instead of consuming up front. Decorating a peekable limiter with a
 * non-peekable one would silently shift the boundary by one attempt, so the
 * interface is preserved and the constructor demands a peekable inner — a
 * container error at build time beats an off-by-one in a brute-force defence.
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
