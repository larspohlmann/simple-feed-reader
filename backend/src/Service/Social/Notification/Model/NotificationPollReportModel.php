<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Model;

final readonly class NotificationPollReportModel
{
    public function __construct(
        public int $accounts,
        public int $failed,
        public int $newRows,
        public int $pruned,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    #[\NoDiscard]
    public function addingPolled(int $newRows): self
    {
        return clone($this, ['accounts' => $this->accounts + 1, 'newRows' => $this->newRows + $newRows]);
    }

    #[\NoDiscard]
    public function addingFailure(): self
    {
        return clone($this, ['accounts' => $this->accounts + 1, 'failed' => $this->failed + 1]);
    }

    #[\NoDiscard]
    public function withPruned(int $pruned): self
    {
        return clone($this, ['pruned' => $pruned]);
    }

    /** @return array{accounts: int, failed: int, newRows: int, pruned: int} */
    public function toLogContext(): array
    {
        return [
            'accounts' => $this->accounts,
            'failed' => $this->failed,
            'newRows' => $this->newRows,
            'pruned' => $this->pruned,
        ];
    }
}
