<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\CleanupMarkerModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\PhraseFamilyModel;
use App\Service\ReaderAudit\Model\PhraseScope;

/**
 * Scans the article's short blocks for the wording SuspiciousPhrases lists, each
 * family over the region it is allowed to match. Reports each family at most
 * once, with the offending line as the detail, so a page with eight share
 * buttons produces one reviewable finding instead of eight.
 *
 * A block that is a link back into the same page is skipped whatever it says. A
 * "Skip to content" is the page's own accessibility affordance, not a menu, and
 * every Missy Magazine article carries one (#744).
 */
final readonly class PhraseMarkers
{
    /** @return list<CleanupMarkerModel> */
    public function detect(ExtractedBodyModel $body): array
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

    /** @return list<BodyBlockModel> */
    private function scopeFor(PhraseFamilyModel $family, ExtractedBodyModel $body): array
    {
        return match ($family->scope) {
            PhraseScope::AboveTheArticle => $body->leadingBlocks(),
            PhraseScope::OnlyWhenNoArticle => $body->hasArticleText() ? [] : $body->blocks,
        };
    }

    /** @param list<BodyBlockModel> $blocks */
    private function firstMatch(PhraseFamilyModel $family, array $blocks): ?CleanupMarkerModel
    {
        foreach ($blocks as $block) {
            if ($block->isInPageAffordance()) {
                continue;
            }
            $phrase = $family->matchIn(mb_strtolower($block->text));
            if ($phrase === null) {
                continue;
            }

            return new CleanupMarkerModel(
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
