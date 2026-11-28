<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

final readonly class YourStreamWordSweepReportModel
{
    public function __construct(public int $due, public int $checked, public int $built, public int $failed)
    {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    public static function forDue(int $due): self
    {
        return new self($due, 0, 0, 0);
    }

    #[\NoDiscard]
    public function addingChecked(): self
    {
        return clone($this, ['checked' => $this->checked + 1]);
    }

    #[\NoDiscard]
    public function addingBuilt(): self
    {
        return clone($this, ['built' => $this->built + 1]);
    }

    #[\NoDiscard]
    public function addingFailed(): self
    {
        return clone($this, ['failed' => $this->failed + 1]);
    }

    /** @return array{due: int, checked: int, built: int, failed: int} */
    public function toLogContext(): array
    {
        return ['due' => $this->due, 'checked' => $this->checked, 'built' => $this->built, 'failed' => $this->failed];
    }
}
