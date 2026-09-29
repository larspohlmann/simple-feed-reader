<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use Dom\HTMLDocument;

/**
 * Whether the article body links to a membership provider's checkout: the signal for a member gate whose container
 * class is too generic to trust. Keyed on the provider endpoint, not one site's theme.
 */
final readonly class MembershipCheckout
{
    /** Provider checkout endpoints; a bare sign-in or membership link is not a gate. */
    private const array CHECKOUT_ENDPOINTS = ['memberful.com/checkout'];
    private const string LOWER_HREF = 'translate(@href, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    public function __construct(private OutsideFurniture $outsideFurniture)
    {
    }

    public function foundOutsideFurnitureIn(HTMLDocument $document): bool
    {
        return $this->outsideFurniture->holdsMatchFor($document, self::checkoutLinkQuery());
    }

    private static function checkoutLinkQuery(): string
    {
        $endpoints = array_map(
            static fn (string $endpoint): string => \sprintf('contains(%s, "%s")', self::LOWER_HREF, $endpoint),
            self::CHECKOUT_ENDPOINTS,
        );

        return '//*[local-name()="a" and (' . implode(' or ', $endpoints) . ')]';
    }
}
