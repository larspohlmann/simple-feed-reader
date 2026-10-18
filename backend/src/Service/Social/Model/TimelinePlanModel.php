<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Parser\Model\ParsedFeedModel;

final readonly class TimelinePlanModel
{
    /** @param ParsedFeedModel $parsed the page's entries plus the self-replies that stay entries */
    public function __construct(public ParsedFeedModel $parsed, public TimelineThreadsModel $threads)
    {
    }
}
