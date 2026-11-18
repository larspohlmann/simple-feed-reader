<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Support;

final class MostShared
{
    /**
     * The one with the most shares; a tie goes to the newest.
     *
     * @template T
     *
     * @param non-empty-list<T> $newestFirst
     * @param \Closure(T): int  $shareCount
     *
     * @return T
     */
    public static function pick(array $newestFirst, \Closure $shareCount): mixed
    {
        $mostShared = $newestFirst[0];
        $mostShares = $shareCount($mostShared);
        foreach ($newestFirst as $candidate) {
            $shares = $shareCount($candidate);
            if ($shares > $mostShares) {
                $mostShared = $candidate;
                $mostShares = $shares;
            }
        }

        return $mostShared;
    }

    private function __construct()
    {
    }
}
