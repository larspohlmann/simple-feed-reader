<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

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
    /** State markers like `has-paywall` sit here; the document root is the page, never a region within it. */
    private const array DOCUMENT_ROOTS = ['html', 'body'];

    public static function foundOutsideFurnitureIn(HTMLDocument $document): bool
    {
        foreach ((new XPath($document))->query(self::paywallClassQuery()) as $element) {
            if ($element instanceof Element && !self::isDocumentRoot($element) && !PageFurniture::holds($element)) {
                return true;
            }
        }

        return false;
    }

    private static function isDocumentRoot(Element $element): bool
    {
        return \in_array(strtolower($element->localName), self::DOCUMENT_ROOTS, true);
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
