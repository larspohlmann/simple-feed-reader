<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use Dom\HTMLDocument;

/**
 * Whether the page carries a gated call to action outside page furniture, matched by class fragment before
 * readability and the body cleaners remove it: the fallback signal for a page that declares nothing.
 */
final readonly class PaywallBlocks
{
    /** A gated call to action; `subscribe` alone is a newsletter form, not a wall. `regwall` is Ghost's gate. */
    private const array GATE_FRAGMENTS = [
        'paywall', 'regwall', 'subscription-only', 'subscriber-only', 'subscribers-only',
    ];
    private const string LOWER_CLASS = 'translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    public function __construct(private OutsideFurniture $outsideFurniture)
    {
    }

    public function foundOutsideFurnitureIn(HTMLDocument $document): bool
    {
        return $this->outsideFurniture->holdsMatchFor($document, self::paywallClassQuery());
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
