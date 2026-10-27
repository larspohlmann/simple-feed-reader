<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RequestAdjustment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the provider said about one call besides its answer, and what a fallback changed after the provider rejected
 * it (set on the newest rejected row of its run only); RecommendationCallRepository writes it in SQL.
 */
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

    #[ORM\Column(length: 24, nullable: true, enumType: RequestAdjustment::class)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?RequestAdjustment $adjustedSetting = null;

    #[ORM\Column(length: 64, nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?string $adjustedValue = null;

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

    public function getAdjustedSetting(): ?RequestAdjustment
    {
        return $this->adjustedSetting;
    }

    public function getAdjustedValue(): ?string
    {
        return $this->adjustedValue;
    }
}
