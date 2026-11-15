<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\GermanLexicon;

interface GermanLexiconInterface
{
    public function isNoun(string $form): bool;

    public function isKnown(string $form): bool;

    /** An unknown form gets the rarest class plus one. */
    public function frequencyClassOf(string $form): int;
}
