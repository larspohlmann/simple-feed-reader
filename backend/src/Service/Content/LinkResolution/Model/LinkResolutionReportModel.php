<?php

declare(strict_types=1);

namespace App\Service\Content\LinkResolution\Model;

use App\Enum\LinkResolution;

final readonly class LinkResolutionReportModel
{
    public function __construct(
        public int $considered,
        public int $resolved,
        public int $unchanged,
        public int $unresolved,
        public int $deferred,
        public float $seconds,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0, 0, 0.0);
    }

    #[\NoDiscard]
    public function counted(LinkLandingModel $landing, float $seconds): self
    {
        return new self(
            $this->considered + 1,
            $this->resolved + (int) ($landing->resolution === LinkResolution::Resolved),
            $this->unchanged + (int) ($landing->resolution === LinkResolution::Unchanged),
            $this->unresolved + (int) ($landing->resolution === LinkResolution::Unresolved),
            $this->deferred + (int) $landing->isRetryable(),
            $this->seconds + $seconds,
        );
    }

    #[\NoDiscard]
    public function plus(self $other): self
    {
        return new self(
            $this->considered + $other->considered,
            $this->resolved + $other->resolved,
            $this->unchanged + $other->unchanged,
            $this->unresolved + $other->unresolved,
            $this->deferred + $other->deferred,
            $this->seconds + $other->seconds,
        );
    }

    /**
     * @return array{considered: int, resolved: int, unchanged: int, unresolved: int, deferred: int, seconds: float}
     */
    public function toLogContext(): array
    {
        return [
            'considered' => $this->considered,
            'resolved' => $this->resolved,
            'unchanged' => $this->unchanged,
            'unresolved' => $this->unresolved,
            'deferred' => $this->deferred,
            'seconds' => round($this->seconds, 3),
        ];
    }
}
