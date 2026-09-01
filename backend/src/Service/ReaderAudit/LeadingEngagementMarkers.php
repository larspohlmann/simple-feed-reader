<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\LeadingEngagementRules;

final readonly class LeadingEngagementMarkers
{
    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body, ?string $entryAuthor): array
    {
        $blocks = array_values(array_filter(
            $body->leadingBlocks(),
            fn (BodyBlock $block): bool => $this->isEngagement($block, $entryAuthor),
        ));

        if ($blocks === []) {
            return [];
        }

        return [new CleanupMarker(
            'leading_engagement_chrome',
            3,
            'LeadingEngagementCleaner',
            sprintf('%d engagement blocks before the article: %s', count($blocks), $this->quoted($blocks)),
        )];
    }

    private function isEngagement(BodyBlock $block, ?string $entryAuthor): bool
    {
        return LeadingEngagementRules::isEmojiOnly($block->text)
            || LeadingEngagementRules::isCounter($block->text)
            || $block->isTimeOnly
            || (LeadingEngagementRules::hasAuthor($entryAuthor) && LeadingEngagementRules::isByline($block->text));
    }

    /** @param list<BodyBlock> $blocks */
    private function quoted(array $blocks): string
    {
        $shown = array_slice($blocks, 0, 3);
        $lines = array_map(static fn (BodyBlock $block): string => '"' . $block->text . '"', $shown);

        return implode(' | ', $lines) . (count($blocks) > 3 ? ' | …' : '');
    }
}
