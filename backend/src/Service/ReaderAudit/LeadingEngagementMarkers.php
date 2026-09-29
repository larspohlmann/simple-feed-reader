<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\LeadingEngagementRules;
use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\CleanupMarkerModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;

final readonly class LeadingEngagementMarkers
{
    /** @return list<CleanupMarkerModel> */
    public function detect(ExtractedBodyModel $body, ?string $entryAuthor): array
    {
        $blocks = array_values(array_filter(
            $body->leadingBlocks(),
            fn (BodyBlockModel $block): bool => $this->isEngagement($block, $entryAuthor),
        ));

        if ($blocks === []) {
            return [];
        }

        return [new CleanupMarkerModel(
            'leading_engagement_chrome',
            3,
            'LeadingEngagementCleaner',
            sprintf('%d engagement blocks before the article: %s', count($blocks), $this->quoted($blocks)),
        )];
    }

    private function isEngagement(BodyBlockModel $block, ?string $entryAuthor): bool
    {
        return LeadingEngagementRules::isEmojiOnly($block->text)
            || LeadingEngagementRules::isCounter($block->text)
            || $block->isTimeOnly
            || (LeadingEngagementRules::hasAuthor($entryAuthor) && LeadingEngagementRules::isByline($block->text));
    }

    /** @param list<BodyBlockModel> $blocks */
    private function quoted(array $blocks): string
    {
        $shown = array_slice($blocks, 0, 3);
        $lines = array_map(static fn (BodyBlockModel $block): string => '"' . $block->text . '"', $shown);

        return implode(' | ', $lines) . (count($blocks) > 3 ? ' | …' : '');
    }
}
