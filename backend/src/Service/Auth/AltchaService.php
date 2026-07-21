<?php

declare(strict_types=1);

namespace App\Service\Auth;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Self-hosted ALTCHA proof-of-work. The server never stores issued challenges:
 * the HMAC signature is what proves a challenge came from us, so verification
 * is stateless apart from the replay guard.
 *
 * Protocol: challenge = sha256(salt . number), signature = hmac_sha256(challenge, key).
 * The client finds `number` by brute force, which costs it measurable CPU time
 * and costs us one hash to check.
 */
final readonly class AltchaService
{
    private const ALGORITHM = 'SHA-256';
    /**
     * Difficulty window, in iterations of sha256. Both bounds are measured, not
     * guessed (PHP 8.3 / Node 22, Apple silicon):
     *
     *   attacker, native sha256      0.41 us/hash  ->  40 ms min, 63 ms avg
     *   widget, await subtle.digest  16.5 us/hash  ->  2.5 s avg, 3.3 s worst
     *
     * That is a ~25-40x asymmetry *against* the honest user, depending on the
     * attacker's runtime (25x vs optimised sync JS, 40x vs native). The widget
     * awaits one promise per candidate and cannot close that gap, so the window
     * is sized by what the browser can afford, not by what we would like the
     * attacker to pay. A wider window is defensible **if** the widget ever moves
     * to auto="onload", where the solve overlaps form filling instead of
     * blocking submit — that is a frontend decision, so do not widen this
     * without confirming the widget mode first.
     *
     * The floor is the load-bearing half. Challenges are free and unlimited to
     * request, and nothing binds a client to one it was issued, so with a floor
     * of zero an attacker batch-requests, discards the expensive challenges and
     * solves only the cheapest — making effective cost the minimum of the batch
     * rather than its mean. Pinning the minimum makes the cost floor a property
     * of the protocol instead of the attacker's luck.
     *
     * Sizing note: this buys resistance to bulk *email* abuse, not to account
     * creation. Registration lands in pending_verification, needs a clicked
     * email link, then a human admin — so a solved challenge only ever yields a
     * row that the purge command reaps after 48 hours. A PoW sizes the cost of
     * abuse; it does not cap it. The rate limiter on the guarded endpoints is
     * what caps it.
     *
     * Re-derive with: hash 2e5 candidates and divide.
     */
    private const MIN_NUMBER = 100_000;
    private const MAX_NUMBER = 200_000;
    private const TTL_SECONDS = 3600;
    /**
     * The replay entry must outlive the challenge itself. If it expired at the
     * same moment, a solution issued at T and first spent just before T+TTL
     * would find its own replay entry already evicted on a second use while the
     * challenge was still inside its validity window. The margin closes that.
     *
     * Deliberately untested: the cache adapter derives `expiresAfter` from the
     * wall clock, which MockClock cannot move, so the boundary this guards is
     * unreachable from a unit test. Verified by reading, not by assertion.
     */
    private const REPLAY_TTL_SECONDS = self::TTL_SECONDS + 600;

    public function __construct(
        #[Autowire('%env(ALTCHA_HMAC_KEY)%')]
        private string $hmacKey,
        private ClockInterface $clock,
        private CacheItemPoolInterface $altchaReplayCache,
    ) {
    }

    public function createChallenge(): AltchaChallenge
    {
        $expires = $this->clock->now()->getTimestamp() + self::TTL_SECONDS;
        $salt = bin2hex(random_bytes(12)) . '?expires=' . $expires;
        $number = random_int(self::MIN_NUMBER, self::MAX_NUMBER);

        $challenge = hash('sha256', $salt . $number);

        return new AltchaChallenge(
            self::ALGORITHM,
            $challenge,
            $salt,
            hash_hmac('sha256', $challenge, $this->hmacKey),
            self::MAX_NUMBER,
        );
    }

    /** @param string $payload base64-encoded JSON produced by the widget */
    public function verify(string $payload): bool
    {
        $solution = $this->decode($payload);
        if (null === $solution) {
            return false;
        }

        ['algorithm' => $algorithm, 'challenge' => $challenge, 'number' => $number,
            'salt' => $salt, 'signature' => $signature] = $solution;

        if (self::ALGORITHM !== $algorithm) {
            return false;
        }

        // Order matters: check our signature before doing anything with the
        // salt, so a forged challenge never reaches the rest of the routine.
        if (!hash_equals(hash_hmac('sha256', $challenge, $this->hmacKey), $signature)) {
            return false;
        }

        // `number` is client-supplied. Only values inside the difficulty window
        // could have come from solving a challenge we issued, so anything below
        // the floor is refused here rather than hashed — otherwise the floor
        // would bind only honest clients.
        if ($number < self::MIN_NUMBER || $number > self::MAX_NUMBER) {
            return false;
        }

        if (!hash_equals(hash('sha256', $salt . $number), $challenge)) {
            return false;
        }

        if ($this->isExpired($salt)) {
            return false;
        }

        return $this->claimOnce($signature);
    }

    /**
     * @return array{algorithm: string, challenge: string, number: int, salt: string, signature: string}|null
     */
    private function decode(string $payload): ?array
    {
        $json = base64_decode($payload, true);
        if (false === $json) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return null;
        }

        foreach (['algorithm', 'challenge', 'number', 'salt', 'signature'] as $key) {
            if (!isset($decoded[$key])) {
                return null;
            }
        }

        if (
            !\is_string($decoded['algorithm'])
            || !\is_string($decoded['challenge'])
            || !\is_string($decoded['salt'])
            || !\is_string($decoded['signature'])
            || !\is_int($decoded['number'])
        ) {
            return null;
        }

        return [
            'algorithm' => $decoded['algorithm'],
            'challenge' => $decoded['challenge'],
            'number' => $decoded['number'],
            'salt' => $decoded['salt'],
            'signature' => $decoded['signature'],
        ];
    }

    private function isExpired(string $salt): bool
    {
        $query = parse_url('?' . (parse_url($salt, \PHP_URL_QUERY) ?? ''), \PHP_URL_QUERY);
        parse_str(\is_string($query) ? $query : '', $params);

        $expires = $params['expires'] ?? null;
        if (!\is_string($expires) || !ctype_digit($expires)) {
            return true;
        }

        return $this->clock->now()->getTimestamp() > (int) $expires;
    }

    /**
     * A valid solution is worth exactly one use. The signature identifies the
     * challenge uniquely, so remembering it until expiry blocks replay.
     */
    private function claimOnce(string $signature): bool
    {
        $item = $this->altchaReplayCache->getItem('altcha_' . $signature);
        if ($item->isHit()) {
            return false;
        }

        $item->set(true);
        $item->expiresAfter(self::REPLAY_TTL_SECONDS);
        $this->altchaReplayCache->save($item);

        return true;
    }
}
