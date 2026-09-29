<?php

declare(strict_types=1);

namespace App\Service\Ai\Model;

/**
 * How long one chat completion may take: a wall clock for the whole call and a first-byte bound on the silence
 * before it, which must stay below the wall clock or it never fires. A connection marked slow gets a larger pair.
 */
final readonly class ProviderTimeoutsModel
{
    /** A reasoning model ranking a big batch generates for minutes: 120 s, then 300 s failed real batches (#320). */
    private const float STANDARD_WALL_CLOCK_SECONDS = 600.0;

    /**
     * Silence on the stream means a dead connection. Symfony's idle timeout also covers the wait for the headers, so a
     * provider that ignores `stream: true` must deliver its whole answer within this bound, not the wall clock.
     */
    private const float STANDARD_FIRST_BYTE_SECONDS = 180.0;

    /**
     * An hour lets a local model on modest hardware finish a full batch. It stays finite, or a hung server would hold
     * the run's per-user lock until the process died.
     */
    private const float SLOW_WALL_CLOCK_SECONDS = 3600.0;

    /**
     * Minutes of prompt evaluation are normal on a local model; well below the wall clock, so a dead connection fails
     * in 15 minutes. WorkerPresence::FRESH_SECONDS is sized from this bound: raise both together.
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
