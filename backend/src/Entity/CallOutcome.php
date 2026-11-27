<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallVerdict;
use Doctrine\ORM\Mapping as ORM;

/** How one call ended: built by RecordedCall when it settles, written to the run-log row by the repository's SQL. */
#[ORM\Embeddable]
final class CallOutcome
{
    public function __construct(
        /** Null while the call is still streaming. */
        #[ORM\Column(length: 24, nullable: true, enumType: CallVerdict::class)]
        private ?CallVerdict $verdict,
        /** Every byte the provider sent: a reasoning model can send megabytes while the answer stays empty. */
        #[ORM\Column(options: ['default' => 0])]
        private int $wireBytes,
        /** When the call settled (any verdict). Null while streaming. */
        #[ORM\Column(nullable: true)]
        private ?\DateTimeImmutable $finishedAt,
        /** Why the provider stopped: `length` when max_tokens truncated the answer, `stop` at a natural end. */
        #[ORM\Column(length: 32, nullable: true)]
        private ?string $finishReason,
    ) {
    }

    public static function pending(): self
    {
        return new self(null, 0, null, null);
    }

    public function getVerdict(): ?CallVerdict
    {
        return $this->verdict;
    }

    public function getWireBytes(): int
    {
        return $this->wireBytes;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getFinishReason(): ?string
    {
        return $this->finishReason;
    }
}
