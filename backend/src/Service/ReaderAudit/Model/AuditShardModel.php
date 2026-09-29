<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/** Every shard draws the same sample; each keeps every count-th entry, so parallel shards cover it once. */
final readonly class AuditShardModel
{
    public function __construct(
        private int $index,
        private int $count,
    ) {
    }

    /**
     * @param list<SampledEntryModel> $sample
     *
     * @return list<SampledEntryModel>
     */
    public function pick(array $sample): array
    {
        if ($this->count <= 1) {
            return $sample;
        }

        $mine = [];
        foreach ($sample as $position => $entry) {
            if ($position % $this->count === $this->index) {
                $mine[] = $entry;
            }
        }

        return $mine;
    }
}
