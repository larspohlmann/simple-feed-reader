<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Enum\DigestCadence;
use App\Enum\DigestFormat;
use App\Service\Mail\Digest\Model\DigestConfigurationModel;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The whole digest configuration in one write. No field has a default: a value that falls back quietly cannot be
 * told from one the user set. Separate from UpdatePreferencesRequest so neither write resends the other.
 */
final readonly class UpdateDigestRequest
{
    public function __construct(
        public bool $enabled,
        public DigestCadence $cadence,
        #[Assert\Range(min: 0, max: 23)]
        public int $sendHour,
        #[Assert\Range(min: 1, max: 7)]
        public int $weekday,
        public DigestFormat $format,
    ) {
    }

    public function toConfiguration(): DigestConfigurationModel
    {
        return new DigestConfigurationModel(
            enabled: $this->enabled,
            cadence: $this->cadence,
            sendHour: $this->sendHour,
            weekday: $this->weekday,
            format: $this->format,
        );
    }
}
