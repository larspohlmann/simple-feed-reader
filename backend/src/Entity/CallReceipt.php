<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** What the provider said about one call besides its answer; RecordedCall builds it, the repository's SQL writes it. */
#[ORM\Embeddable]
final class CallReceipt
{
    public function __construct(
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $requestId,
        /** The model version that answered, which an alias like `jev-latest` stands for; null when the reply named none. */
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $answeringModel,
        #[ORM\Column(type: Types::BIGINT, nullable: true)]
        private ?int $costNanoCredits,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getAnsweringModel(): ?string
    {
        return $this->answeringModel;
    }

    public function getCostNanoCredits(): ?int
    {
        return $this->costNanoCredits;
    }
}
