<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RequestAdjustment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Why the call failed and what a fallback changed in answer. The repository's SQL writes the detail when the call
 * settles and the adjustment later, onto the newest failure carrying the same detail.
 */
#[ORM\Embeddable]
final readonly class CallFailure
{
    public function __construct(
        /** The transport exception's message, for transport-failed calls only. */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $errorDetail,
        #[ORM\Column(length: 24, nullable: true, enumType: RequestAdjustment::class)]
        private ?RequestAdjustment $adjustedSetting = null,
        #[ORM\Column(length: 64, nullable: true)]
        private ?string $adjustedValue = null,
    ) {
    }

    /** The rejection a fallback answered: the detail names the failed call, the setting and value what changed. */
    public static function adjusted(string $errorDetail, RequestAdjustment $setting, ?string $value): self
    {
        return new self($errorDetail, $setting, $value);
    }

    public function getErrorDetail(): ?string
    {
        return $this->errorDetail;
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
