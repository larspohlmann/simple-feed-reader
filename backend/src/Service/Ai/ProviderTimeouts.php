<?php

declare(strict_types=1);

namespace App\Service\Ai;

/**
 * How long one chat completion may take, as one value.
 *
 * The two bounds belong together and are chosen together: the wall clock caps
 * the whole call, the first-byte bound caps the silence before it starts, and
 * a first-byte bound at or above the wall clock would never fire. They used to
 * be constants on the client, which meant one pair of numbers for every
 * endpoint an account had configured (#433).
 *
 * Hosted and local endpoints want different numbers, and the same numbers
 * cannot serve both. A hosted provider that goes quiet for three minutes is
 * dead, and waiting an hour on it only delays the failure report; a local
 * model on modest hardware is merely thinking, and every earlier raise of the
 * shared constants — 120 s to 300 s (#320), 300 s to 600 s, 30 s to 180 s —
 * was that conflict answered by moving the bound for everybody. So the account
 * marks the connection instead, and the connection carries its own profile.
 */
final readonly class ProviderTimeouts
{
    /**
     * A ranking over a large batch can legitimately generate for minutes, and
     * a reasoning model spends most of that thinking before it answers at all:
     * 120 s failed real batches three times running and killed the run (#320),
     * and 300 s still failed a slow local model on a large batch with the
     * generic "did not answer" once the whole generation overran the wall
     * clock.
     */
    private const float STANDARD_WALL_CLOCK_SECONDS = 600.0;

    /**
     * The answer arrives as an SSE stream (#312), so silence — no delta for
     * this long — means a dead connection, not a thinking model. The binding
     * constraint is time-to-first-token: the provider sends nothing while it
     * evaluates the prompt, and a local model on a large #308 batch needs the
     * headroom. Raise it before inventing a second "first token" timeout.
     *
     * Note what this bound really covers: Symfony's idle timeout also bounds
     * the wait for the response headers, so the clock runs from the request
     * going out, not from the first body chunk. It is time-to-first-*byte*.
     * A provider that ignores `stream: true` sends nothing at all — headers
     * included — until the whole answer is ready, so it has this window rather
     * than the wall clock to answer end to end. That is the accepted price of
     * failing a dead connection in 180 s instead of 600 s.
     */
    private const float STANDARD_FIRST_BYTE_SECONDS = 180.0;

    /**
     * What a connection the account marked as slow gets instead. An hour is
     * long enough for a local model to generate a full batch on modest
     * hardware, where 600 s is not.
     *
     * It stays finite. An unbounded call would hold the run's per-user lock
     * until the process died, and a hung local server is indistinguishable
     * from a thinking one except by giving up eventually.
     */
    private const float SLOW_WALL_CLOCK_SECONDS = 3600.0;

    /**
     * A local model evaluating a large batch legitimately spends minutes
     * before the first token. Kept well below the wall clock so a dead
     * connection is still reported in a quarter of an hour rather than an
     * hour.
     */
    private const float SLOW_FIRST_BYTE_SECONDS = 900.0;

    private function __construct(
        public float $wallClockSeconds,
        public float $firstByteSeconds,
    ) {
    }

    public static function standard(): self
    {
        return new self(self::STANDARD_WALL_CLOCK_SECONDS, self::STANDARD_FIRST_BYTE_SECONDS);
    }

    public static function forSlowModel(): self
    {
        return new self(self::SLOW_WALL_CLOCK_SECONDS, self::SLOW_FIRST_BYTE_SECONDS);
    }
}
