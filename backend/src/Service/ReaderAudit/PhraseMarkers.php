<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * Scans the article's short blocks for the wording SuspiciousPhrases lists.
 * Reports each family at most once, with the offending line as the detail, so a
 * page with eight share buttons produces one reviewable finding instead of eight.
 */
final readonly class PhraseMarkers
{
    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body): array
    {
        $markers = [];
        foreach (SuspiciousPhrases::families() as $family) {
            $marker = $this->firstMatch($family, $body);
            if ($marker !== null) {
                $markers[] = $marker;
            }
        }

        return $markers;
    }

    private function firstMatch(PhraseFamily $family, ExtractedBody $body): ?CleanupMarker
    {
        foreach ($body->blockTexts as $blockText) {
            $phrase = $family->matchIn(mb_strtolower($blockText));
            if ($phrase === null) {
                continue;
            }

            return new CleanupMarker(
                $family->code,
                $family->weight,
                $family->suspect,
                \sprintf('"%s" in: %s', $phrase, self::shortened($blockText)),
            );
        }

        return null;
    }

    private static function shortened(string $text): string
    {
        return mb_strlen($text) <= 120 ? $text : mb_substr($text, 0, 120) . '…';
    }
}
