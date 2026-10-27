<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RequestAdjustment;
use Doctrine\ORM\Mapping as ORM;

/** What a fallback changed after the provider rejected this call; RecommendationCallRepository writes it in SQL. */
#[ORM\Embeddable]
final class CallAdjustment
{
    #[ORM\Column(name: 'adjusted_setting', length: 24, nullable: true, enumType: RequestAdjustment::class)]
    // @phpstan-ignore property.unusedType, property.onlyWritten (the repository's SQL assigns it, DQL reads it)
    private ?RequestAdjustment $setting = null;

    #[ORM\Column(name: 'adjusted_value', length: 64, nullable: true)]
    // @phpstan-ignore property.unusedType, property.onlyWritten (the repository's SQL assigns it, DQL reads it)
    private ?string $value = null;
}
