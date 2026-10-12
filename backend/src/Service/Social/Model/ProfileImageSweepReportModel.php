<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ProfileImageSweepReportModel
{
    public function __construct(
        public int $checked,
        public int $changed,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    public function adding(self $other): self
    {
        return new self($this->checked + $other->checked, $this->changed + $other->changed);
    }

    /** @return array{checked: int, changed: int} */
    public function toLogContext(): array
    {
        return ['checked' => $this->checked, 'changed' => $this->changed];
    }
}
