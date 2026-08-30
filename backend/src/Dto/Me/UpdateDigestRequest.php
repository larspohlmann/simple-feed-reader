<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestFormat;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The whole digest configuration in one write. Every field is required, with no
 * default, for the same reason UpdatePreferencesRequest is: a value that
 * degrades quietly to a default is indistinguishable from one the user set.
 * Kept separate from UpdatePreferencesRequest so a scrape-fallback toggle need
 * not resend the digest and vice versa (#180's reasoning, #636).
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
}
