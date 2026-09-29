<?php

declare(strict_types=1);

namespace App\Service\Clock\Model;

/**
 * The IANA zone a client wants its day and month buckets cut in; cut in any other zone, a late-evening row files
 * under the wrong bucket. Fails soft to UTC: a display preference, not a security boundary.
 */
final readonly class ViewerTimeZoneModel
{
    private function __construct(public \DateTimeZone $zone)
    {
    }

    public static function of(?string $identifier): self
    {
        if (null === $identifier || '' === $identifier) {
            return new self(new \DateTimeZone('UTC'));
        }

        try {
            return new self(new \DateTimeZone($identifier));
        } catch (\Exception) {
            return new self(new \DateTimeZone('UTC'));
        }
    }
}
