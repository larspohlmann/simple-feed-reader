<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\GermanLexicon;

use App\Service\Curation\Trending\Exception\UnknownVerbStemException;

interface GermanLexiconInterface
{
    public function isNoun(string $form): bool;

    public function isKnown(string $form): bool;

    /** An unknown form gets the rarest class plus one. */
    public function frequencyClassOf(string $form): int;

    public function medianNounClassOfLength(int $length): int;

    public function isVerbStem(string $presentStem): bool;

    /** @throws UnknownVerbStemException */
    public function infinitiveStemOf(string $presentStem): string;
}
