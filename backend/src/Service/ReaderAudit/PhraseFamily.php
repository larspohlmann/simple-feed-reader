<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * One family of leftover-furniture wording, with the two limits that keep it
 * from firing on prose: the block length above which a match means nothing, and
 * whether the family may match anywhere or only above the first paragraph.
 *
 * "Newsletter" in a 40-character line before the article starts is a signup box
 * in the reader's way; the same word in a 900-character paragraph is the article
 * talking about newsletters, and under the last paragraph it is the site's own
 * tail, which this audit tolerates (#744).
 */
final readonly class PhraseFamily
{
    /** @param list<string> $phrases lower-case, matched as substrings */
    public function __construct(
        public string $code,
        public string $suspect,
        public int $weight,
        public int $maxBlockChars,
        public bool $leadingOnly,
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
