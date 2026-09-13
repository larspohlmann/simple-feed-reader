<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use Dom\HTMLDocument;

/**
 * Whether the page carries a gated call to action, matched by class fragment on
 * the shared document before readability consumes it — exactly what body
 * cleaners remove. The presence of such a block, outside page furniture and the
 * document root, is the fallback signal for a page that declares nothing (#908).
 */
final readonly class PaywallBlocks
{
    /** A gated call to action; `subscribe` alone is a newsletter form, not a wall. `regwall` is Ghost's gate. */
    private const array GATE_FRAGMENTS = [
        'paywall', 'regwall', 'subscription-only', 'subscriber-only', 'subscribers-only',
    ];
    private const string LOWER_CLASS = 'translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    public static function foundOutsideFurnitureIn(HTMLDocument $document): bool
    {
        return OutsideFurniture::holdsMatchFor($document, self::paywallClassQuery());
    }

    private static function paywallClassQuery(): string
    {
        $fragments = array_map(
            static fn (string $fragment): string => \sprintf('contains(%s, "%s")', self::LOWER_CLASS, $fragment),
            self::GATE_FRAGMENTS,
        );

        return '//*[' . implode(' or ', $fragments) . ']';
    }
}
