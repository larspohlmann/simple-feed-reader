<?php

declare(strict_types=1);

namespace App\Enum;

enum CallPhase: string
{
    case Distill = 'distill';
    case Batch = 'batch';
    case Consolidate = 'consolidate';
    case StoryTitle = 'story_title';
    case InterestTerms = 'interest_terms';
}
