<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use App\Service\Reader\Support\StylesheetHiddenIds;
use Dom\HTMLDocument;

/**
 * Removes the elements the page's own stylesheet hides by id (a no-JS fallback's "browser not supported" notice), which
 * readability cannot see as hidden.
 */
final readonly class StylesheetHiddenElementRemover implements PageRepairInterface
{
    public function repairIn(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('style') as $style) {
            $this->removeElementsHiddenBy($document, (string) $style->textContent);
        }
    }

    private function removeElementsHiddenBy(HTMLDocument $document, string $stylesheet): void
    {
        foreach (StylesheetHiddenIds::in($stylesheet) as $id) {
            $document->getElementById($id)?->remove();
        }
    }
}
