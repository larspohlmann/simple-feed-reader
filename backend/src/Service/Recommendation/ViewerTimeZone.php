<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The timezone a client wants its run history bucketed by (#409).
 *
 * Runs are stored as naive UTC, but the card prints each row in the reader's
 * own zone, so months must be cut in that same zone — otherwise a late-evening
 * run files under the wrong month and the section header contradicts its row.
 *
 * Fails soft on purpose: a display preference, not a security boundary. A
 * client with a stale timezone database should see its history in the wrong
 * zone, not lose access. A plain IANA identifier, so a native client sends it
 * as readily as a browser.
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
