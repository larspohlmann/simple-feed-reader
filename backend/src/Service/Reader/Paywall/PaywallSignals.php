<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Reader\Paywall\Model\AccessDeclaration;
use App\Service\Reader\Paywall\Support\SchemaOrgAccess;
use Dom\HTMLDocument;

/**
 * The reader paywall verdict: the publisher's schema.org `isAccessibleForFree` declaration decides. A page that
 * declares nothing is a preview when a gated block (#908) or a membership checkout (#998) sits outside the page
 * furniture. Judged before readability consumes the normalised document.
 */
final readonly class PaywallSignals
{
    public static function isPreview(HTMLDocument $rawDocument, HTMLDocument $normalized): bool
    {
        return match (SchemaOrgAccess::declaredIn($rawDocument)) {
            AccessDeclaration::Paywalled => true,
            AccessDeclaration::Free => false,
            AccessDeclaration::Undeclared => self::gatedInBody($normalized),
        };
    }

    private static function gatedInBody(HTMLDocument $normalized): bool
    {
        return PaywallBlocks::foundOutsideFurnitureIn($normalized)
            || MembershipCheckout::foundOutsideFurnitureIn($normalized);
    }

    private function __construct()
    {
    }
}
