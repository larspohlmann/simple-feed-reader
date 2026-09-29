<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model;

/**
 * How far a refresh run has got across its slices. After any slice, `handled + remaining` is the number of feeds due
 * when that slice began, so the first slice seeds the total without a query. `done` is monotonic; the fraction is
 * not when the due set grows mid-run, which takes a user refresh outliving the 5-minute cooldown.
 */
final readonly class RefreshRunProgressModel
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

        // Nothing left: the run is over. The high-water total below would otherwise strand the bar short of full when
        // another sweep fetched our due feeds between two of our slices (the lock serialises slices, not runs).
        if (0 === $remaining) {
            return new self($done, $done);
        }

        // A high-water mark: feeds falling due mid-run would push `done` past a fixed total, and a shrinking total is
        // a bar that lurches forward for no reason the user can see.
        return new self($done, max($this->total, $done + $remaining));
    }
}
