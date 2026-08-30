<?php

declare(strict_types=1);

namespace App\Service\Refresh;

/**
 * How far a refresh RUN has got — not the slice that just landed.
 *
 * A slice's report counts two different populations: its `total` is that slice's
 * batch, capped by RefreshRunner::BATCH_LIMIT, while its `remaining` is an uncapped
 * run-wide count of what is still due. Dividing one by the other is what left the
 * reader's bar at zero for minutes, then snapped it to full, and ran it backwards in
 * between (#721).
 *
 * No query is needed to seed the run. After any slice, `handled + remaining` IS the
 * number of feeds that were due when that slice began: every due feed either reached
 * an outcome or is still due. The denominator therefore falls out of the first slice.
 *
 * `done` is monotonic; the rendered fraction `done / total` is not, if `total` grows
 * faster than `done` between two slices (20/200, then 30/430). That needs the due set
 * to grow mid-run, which for a user-scoped force refresh means the run outliving the
 * 5-minute cooldown — a very large account, and one the client's #302 stall guard
 * would stop well before it got there.
 */
final readonly class RefreshRunProgress
{
    private function __construct(
        /** Feeds this run has taken to an outcome, summed over every slice. */
        public int $done,
        /** What the run has to do: everything finished plus everything still due. */
        public int $total,
    ) {
    }

    public static function start(): self
    {
        return new self(0, 0);
    }

    /** Rebuilds a run from what the store kept between two slices. */
    public static function resumed(int $done, int $total): self
    {
        return new self($done, $total);
    }

    /**
     * @param int $handled   feeds this slice took to an outcome
     * @param int $remaining feeds still due run-wide once this slice finished
     */
    public function advancedBy(int $handled, int $remaining): self
    {
        $done = $this->done + $handled;

        // Nothing left to do: the run is over, and whatever it turned out to be
        // worth, all of it is done. The high-water denominator below would
        // otherwise strand the bar short of full whenever work left the due set
        // without this run handling it — the global lock serialises SLICES, not
        // runs, so another sweep can fetch our due feeds between two of ours.
        if (0 === $remaining) {
            return new self($done, $done);
        }

        // A high-water mark, not a recomputation. Feeds fall due while a long
        // sweep runs, which would otherwise push `done` past a denominator fixed
        // by the first slice; and a slice that handles work without new arrivals
        // must not shrink it, because a shrinking total is a bar that lurches
        // forward for no reason the user can see.
        return new self($done, max($this->total, $done + $remaining));
    }

    /** @return array{done: int, total: int} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'total' => $this->total];
    }
}
