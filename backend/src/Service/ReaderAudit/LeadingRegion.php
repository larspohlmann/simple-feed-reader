<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\LeadingEngagementRules;
use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;

/**
 * Everything above the article's first real paragraph, judged by the reader's own prose rule. A body that never
 * reaches one is leading region throughout: it is all chrome, which is exactly what the rules should then see.
 */
final readonly class LeadingRegion
{
    public function __construct(private LeadingEngagementRules $rules)
    {
    }

    /** @return list<BodyBlockModel> */
    public function blocksOf(ExtractedBodyModel $body): array
    {
        $leading = [];
        foreach ($body->blocks as $block) {
            if ($this->rules->isProse($block->text, $block->linkedTextLength())) {
                return $leading;
            }
            $leading[] = $block;
        }

        return $leading;
    }
}
