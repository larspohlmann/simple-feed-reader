<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Reader\Media\PageFurniture;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;

/**
 * Whether the shared document holds an element matching the query that is part of
 * the article, not its chrome: outside page furniture (aside/nav/footer) and not
 * the document root itself. The shared discipline behind every gate signal (#908).
 */
final readonly class OutsideFurniture
{
    private const array DOCUMENT_ROOTS = ['html', 'body'];

    public static function holdsMatchFor(HTMLDocument $document, string $xpath): bool
    {
        foreach ((new XPath($document))->query($xpath) as $element) {
            if ($element instanceof Element && !self::isDocumentRoot($element) && !PageFurniture::holds($element)) {
                return true;
            }
        }

        return false;
    }

    private static function isDocumentRoot(Element $element): bool
    {
        return \in_array($element->localName, self::DOCUMENT_ROOTS, true);
    }
}
