<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/**
 * The gated region or its call to action, as a publisher names it in the DOM
 * (`paywall-cta`, `duv-paywall-preview`, `subscription-only-block`), by class
 * fragment. Read from the shared normalised document before readability
 * consumes it — the block is exactly what the body cleaners remove.
 */
final readonly class PaywallBlocks
{
    /** The words publishers use for a gated region; `subscribe` alone is a newsletter form, not a wall. */
    private const array CLASS_FRAGMENTS = ['paywall', 'subscription-only', 'subscriber-only', 'subscribers-only'];
    private const string LOWER_CLASS = 'translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")';

    /** @return list<string> the squeezed text of every paywall block outside page furniture, in document order */
    public static function textsIn(HTMLDocument $document): array
    {
        $texts = [];
        foreach ((new XPath($document))->query(self::paywallClassQuery()) as $element) {
            if (!$element instanceof Element || PageFurniture::holds($element)) {
                continue;
            }
            $text = SqueezedText::of((string) $element->textContent);
            if ($text !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }

    private static function paywallClassQuery(): string
    {
        $fragments = array_map(
            static fn (string $fragment): string => \sprintf('contains(%s, "%s")', self::LOWER_CLASS, $fragment),
            self::CLASS_FRAGMENTS,
        );

        return '//*[' . implode(' or ', $fragments) . ']';
    }
}
