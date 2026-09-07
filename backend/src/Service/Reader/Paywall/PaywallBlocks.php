<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/**
 * Gated regions and CTAs matched by class fragment, read from the shared
 * document before readability consumes it — exactly what body cleaners remove.
 */
final readonly class PaywallBlocks
{
    /** A gated call to action; `subscribe` alone is a newsletter form, not a wall. */
    private const array GATE_FRAGMENTS = ['paywall', 'subscription-only', 'subscriber-only', 'subscribers-only'];
    /** The soft gate that fades or truncates the last visible region and drops the rest server-side, e.g. ZEIT `paragraph--faded`; `fade` and `fade-in` are animation, `truncate` is an ellipsis (#898). */
    private const array FADE_FRAGMENTS = ['faded', 'fade-out', 'fadeout', 'truncated'];
    private const string LOWER_CLASS = 'translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';
    /** State markers like `has-paywall` sit here; the document root is the page, never a region within it. */
    private const array DOCUMENT_ROOTS = ['html', 'body'];

    /** @return list<string> the squeezed text of every paywall block outside page furniture, in document order */
    public static function textsIn(HTMLDocument $document): array
    {
        $texts = [];
        foreach ((new XPath($document))->query(self::paywallClassQuery()) as $element) {
            if (!$element instanceof Element || self::isDocumentRoot($element) || PageFurniture::holds($element)) {
                continue;
            }
            $text = SqueezedText::of((string) $element->textContent);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private static function isDocumentRoot(Element $element): bool
    {
        return \in_array(strtolower($element->localName), self::DOCUMENT_ROOTS, true);
    }

    private static function paywallClassQuery(): string
    {
        $fragments = array_map(
            static fn (string $fragment): string => \sprintf('contains(%s, "%s")', self::LOWER_CLASS, $fragment),
            [...self::GATE_FRAGMENTS, ...self::FADE_FRAGMENTS],
        );

        return '//*[' . implode(' or ', $fragments) . ']';
    }
}
