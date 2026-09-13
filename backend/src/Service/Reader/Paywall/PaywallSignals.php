<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use Dom\HTMLDocument;

/**
 * The reader paywall verdict. Trust the publisher's schema.org
 * `isAccessibleForFree` declaration: premium marks a preview, free ends it. A
 * page that declares nothing falls back to the presence, outside page furniture,
 * of a gated block (#908) or a membership-provider checkout link (#998). Judged
 * before readability consumes the shared document, so the normalized document
 * must still be intact.
 */
final readonly class PaywallSignals
{
    public static function isPreview(string $html, ?HTMLDocument $normalized): bool
    {
        return SchemaOrgAccess::paywalledIn($html) ?? ($normalized !== null && self::gatedInBody($normalized));
    }

    private static function gatedInBody(HTMLDocument $normalized): bool
    {
        return PaywallBlocks::foundOutsideFurnitureIn($normalized)
            || MembershipCheckout::foundOutsideFurnitureIn($normalized);
    }
}
