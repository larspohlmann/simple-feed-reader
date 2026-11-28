<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\YourStreamPrecision;
use Doctrine\ORM\Mapping as ORM;

/** Whether Your stream runs and how selective it is, embedded in the settings row. */
#[ORM\Embeddable]
final readonly class YourStreamTuning
{
    public function __construct(
        #[ORM\Column(name: 'enabled', options: ['default' => true])]
        private bool $enabled,
        #[ORM\Column(
            name: 'match_precision',
            length: 10,
            enumType: YourStreamPrecision::class,
            options: ['default' => 'wide'],
        )]
        private YourStreamPrecision $precision,
    ) {
    }

    public static function defaults(): self
    {
        return new self(true, YourStreamPrecision::DEFAULT);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPrecision(): YourStreamPrecision
    {
        return $this->precision;
    }
}
