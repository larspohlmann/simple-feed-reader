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
    private const array HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function repairIn(HTMLDocument $document): void
    {
        foreach (self::HEADING_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $heading) {
                $heading->removeAttribute('class');
                $heading->removeAttribute('id');
            }
        }
    }
}
