<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * Scans the article's short blocks for the wording SuspiciousPhrases lists, each
 * family over the region it is allowed to match. Reports each family at most
 * once, with the offending line as the detail, so a page with eight share
 * buttons produces one reviewable finding instead of eight.
 */
final readonly class PhraseMarkers
{
    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body): array
    {
        $markers = [];
        foreach (SuspiciousPhrases::families() as $family) {
            $marker = $this->firstMatch($family, $this->scopeFor($family, $body));
            if ($marker !== null) {
                $markers[] = $marker;
            }
        }

        return $markers;
    }

    /** @return list<BodyBlock> */
    private function scopeFor(PhraseFamily $family, ExtractedBody $body): array
    {
        return $family->leadingOnly ? $body->leadingBlocks() : $body->blocks;
    }

    /** @param list<BodyBlock> $blocks */
    private function firstMatch(PhraseFamily $family, array $blocks): ?CleanupMarker
    {
        foreach ($blocks as $block) {
            $phrase = $family->matchIn(mb_strtolower($block->text));
            if ($phrase === null) {
                continue;
            }

            return new CleanupMarker(
                $family->code,
                $family->weight,
                $family->suspect,
                \sprintf('"%s" in: %s', $phrase, self::shortened($block->text)),
            );
        }

        return null;
    }

    private static function shortened(string $text): string
    {
        return mb_strlen($text) <= 120 ? $text : mb_substr($text, 0, 120) . '…';
    }
}
