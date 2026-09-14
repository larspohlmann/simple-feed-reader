<?php

declare(strict_types=1);

namespace App\Service\Reader\Repair;

use Dom\HTMLDocument;

/**
 * Readability strips a heading whose class or id matches its unlikely-candidate
 * regex — Substack marks every subheading `class="header-anchor-post"`, and the
 * "header" token drops each one, so the section headings vanish from the body. A
 * heading's meaning is its tag, not its class, so both attributes go before
 * scoring.
 */
final readonly class HeadingClassRemover implements PageRepair
{
    public function repairIn(HTMLDocument $document): void
    {
        foreach ($document->querySelectorAll('h1, h2, h3, h4, h5, h6') as $heading) {
            $heading->removeAttribute('class');
            $heading->removeAttribute('id');
        }
    }
}
