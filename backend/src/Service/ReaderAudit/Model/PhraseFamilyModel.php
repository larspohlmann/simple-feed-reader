<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Model;

/**
 * One family of leftover-furniture wording, with the two limits that keep it off prose: the block length above which
 * a match means nothing, and the region of the body it may look at.
 */
final readonly class PhraseFamilyModel
{
    /** @param list<string> $phrases lower-case, matched as substrings */
    public function __construct(
        public string $code,
        public string $suspect,
        public int $weight,
        public int $maxBlockChars,
        public PhraseScope $scope,
        public array $phrases,
    ) {
    }

    /** The first phrase this block contains, or null when it holds none. */
    public function matchIn(string $lowerCasedBlock): ?string
    {
        if (mb_strlen($lowerCasedBlock) > $this->maxBlockChars) {
            return null;
        }

        foreach ($this->phrases as $phrase) {
            if (str_contains($lowerCasedBlock, $phrase)) {
                return $phrase;
            }
        }

        return null;
    }
}
