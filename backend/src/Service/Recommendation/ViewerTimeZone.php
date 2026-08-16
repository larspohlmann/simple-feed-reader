<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The timezone a client wants its run history bucketed by (#409).
 *
 * Runs are stored as naive UTC, but the card prints each row in the reader's
 * own zone, so the months have to be cut in that same zone — otherwise a run
 * at 23:30 UTC on 31 August prints as 1 September and files under August, and
 * the section header contradicts the row beneath it.
 *
 * Fails soft on purpose. This is a display preference, not a security
 * boundary: a client shipping a stale timezone database should see its history
 * bucketed in the wrong zone, not lose access to it. A plain IANA identifier,
 * so a native client sends it as readily as a browser does.
 */
final readonly class ViewerTimeZone
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
