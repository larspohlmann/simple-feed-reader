<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** What the provider said about one call besides its answer; RecommendationCallRepository writes it in SQL. */
#[ORM\Embeddable]
final class CallReceipt
{
    #[ORM\Column(length: 255, nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?string $requestId = null;

    /** The model version that answered, which an alias like `jev-latest` stands for; null when the reply named none. */
    #[ORM\Column(length: 255, nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?string $answeringModel = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?int $costNanoCredits = null;

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
