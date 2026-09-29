<?php

declare(strict_types=1);

namespace App\Service\Reader\PageRepair;

use Dom\HTMLDocument;

/**
 * Strips class and id from every heading before scoring: readability drops a heading whose class matches its
 * unlikely-candidate regex (a `header-anchor-post` class does), and a heading's meaning is its tag.
 */
final readonly class HeadingClassRemover implements PageRepairInterface
{
    public function repairIn(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('h1, h2, h3, h4, h5, h6') as $heading) {
            $heading->removeAttribute('class');
            $heading->removeAttribute('id');
        }
    }
}
