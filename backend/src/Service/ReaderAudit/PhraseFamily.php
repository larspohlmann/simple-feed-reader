<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * One family of leftover-furniture wording, with the block length above which a
 * match stops meaning anything. "Newsletter" in a 40-character line is a signup
 * box the trimmer missed; the same word inside a 900-character paragraph is the
 * article talking about newsletters.
 *
 * @phpstan-type PhraseList list<string>
 */
final readonly class PhraseFamily
{
    /** @param list<string> $phrases lower-case, matched as substrings */
    public function __construct(
        public string $code,
        public string $suspect,
        public int $weight,
        public int $maxBlockChars,
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
