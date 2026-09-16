<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A run's provider-call retry bookkeeping: unusable-reply attempts, transport
 * failures, and the last unusable reply seen — three fields written together
 * by recordInvalidReply()/recordTransportFailure() and reset together by every
 * checkpoint. Embedded, unprefixed columns, like RunBatchProgress and
 * RunProfile: this was the seam PHPMD's field-count ceiling on
 * RecommendationRun was pointing at once RunThrottle (#947) pushed it over.
 */
#[ORM\Embeddable]
class RunCallAttempts
{
    #[ORM\Column(options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $transportFailures = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastInvalidReply = null;

    public function recordInvalidReply(string $reply): void
    {
        $this->attempts++;
        $this->lastInvalidReply = $reply;
    }

    public function recordTransportFailure(): int
    {
        return ++$this->transportFailures;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function transportFailures(): int
    {
        return $this->transportFailures;
    }

    public function lastInvalidReply(): ?string
    {
        return $this->lastInvalidReply;
    }

    public function reset(): void
    {
        $this->attempts = 0;
        $this->transportFailures = 0;
        $this->lastInvalidReply = null;
    }

    public function resetTransportFailures(): void
    {
        $this->transportFailures = 0;
    }
}
