<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RequestAdjustment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Why the call failed and what a fallback changed in answer; RecommendationCallRepository writes it in SQL. */
#[ORM\Embeddable]
final class CallFailure
{
    /** The transport exception's message, for transport-failed calls only. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    // @phpstan-ignore property.unusedType (only the repository's SQL and Doctrine's hydration assign it)
    private ?string $errorDetail = null;

    #[ORM\Column(name: 'adjusted_setting', length: 24, nullable: true, enumType: RequestAdjustment::class)]
    // @phpstan-ignore property.unusedType, property.onlyWritten (the repository's SQL assigns it, DQL reads it)
    private ?RequestAdjustment $adjustedSetting = null;

    #[ORM\Column(name: 'adjusted_value', length: 64, nullable: true)]
    // @phpstan-ignore property.unusedType, property.onlyWritten (the repository's SQL assigns it, DQL reads it)
    private ?string $adjustedValue = null;

    public function getErrorDetail(): ?string
    {
        return $this->errorDetail;
    }
}
