<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class PrivateFeedSyncReportModel
{
    public function __construct(
        public int $checked,
        public int $skipped,
        public int $added,
        public int $removed,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    public function adding(PrivateFeedSyncOutcomeModel $outcome): self
    {
        return new self(
            $this->checked + 1,
            $this->skipped + (null === $outcome->skip ? 0 : 1),
            $this->added + $outcome->added,
            $this->removed + $outcome->removed,
        );
    }

    /** @return array{checked: int, skipped: int, added: int, removed: int} */
    public function toLogContext(): array
    {
        return [
            'checked' => $this->checked,
            'skipped' => $this->skipped,
            'added' => $this->added,
            'removed' => $this->removed,
        ];
    }
}
