<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\Support;

use App\Service\Recommendation\Run\Model\WaveBatchModel;

final readonly class BatchWaveWinners
{
    private function __construct()
    {
    }

    /**
     * @param list<WaveBatchModel> $waveBatches
     *
     * @return array{0: array<int, list<array{id: int, score: int, reason: string}>>, 1: list<int>}
     */
    public static function splitByPruned(array $waveBatches): array
    {
        $winners = [];
        $pending = [];
        foreach ($waveBatches as $position => $waveBatch) {
            if ($waveBatch->isFullyPruned()) {
                $winners[$position] = [];

                continue;
            }
            $pending[] = $position;
        }

        return [$winners, $pending];
    }

    /**
     * @param array<int, list<array{id: int, score: int, reason: string}>> $winners
     * @param list<int>                                                    $stillUnresolved
     *
     * @return list<list<array{id: int, score: int, reason: string}>>
     */
    public static function degradeUnresolved(array $winners, array $stillUnresolved): array
    {
        foreach ($stillUnresolved as $position) {
            $winners[$position] = [];
        }
        ksort($winners);

        return array_values($winners);
    }
}
