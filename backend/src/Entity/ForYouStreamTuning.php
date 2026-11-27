<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ForYouStreamPrecision;
use Doctrine\ORM\Mapping as ORM;

/** Whether the For you stream runs and how selective it is, embedded in the settings row. */
#[ORM\Embeddable]
final class ForYouStreamTuning
{
    #[ORM\Column(name: 'for_you_stream_enabled', options: ['default' => true])]
    private bool $enabled;

    #[ORM\Column(
        name: 'for_you_stream_precision',
        length: 10,
        enumType: ForYouStreamPrecision::class,
        options: ['default' => 'wide'],
    )]
    private ForYouStreamPrecision $precision;

    public function __construct(bool $enabled, ForYouStreamPrecision $precision)
    {
        $this->enabled = $enabled;
        $this->precision = $precision;
    }

    public static function defaults(): self
    {
        return new self(true, ForYouStreamPrecision::DEFAULT);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPrecision(): ForYouStreamPrecision
    {
        return $this->precision;
    }
}
