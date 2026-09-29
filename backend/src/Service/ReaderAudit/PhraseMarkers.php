<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\CleanupMarkerModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Service\ReaderAudit\Model\PhraseFamilyModel;
use App\Service\ReaderAudit\Model\PhraseScope;

/**
 * Scans short blocks for SuspiciousPhrases' wording, each family over the region it may match, and reports each family
 * once with the offending line, so a share bar of eight buttons is one finding.
 */
final readonly class PhraseMarkers
{
    public function __construct(
        private LeadingRegion $leadingRegion,
        private SuspiciousPhrases $phrases,
    ) {
    }

    /** @return list<CleanupMarkerModel> */
    public function detect(ExtractedBodyModel $body): array
    {
        $markers = [];
        foreach ($this->phrases->families() as $family) {
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
            PhraseScope::AboveTheArticle => $this->leadingRegion->blocksOf($body),
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
