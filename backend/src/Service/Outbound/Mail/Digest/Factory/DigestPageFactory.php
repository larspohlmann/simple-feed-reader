<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Factory;

use App\Service\Mail\Digest\Model\DigestModel;
use App\Service\Mail\Digest\Model\DigestPageGroupModel;
use App\Service\Mail\Digest\Model\DigestPageModel;

/**
 * Projects a DigestModel to the capped page an HTML mail renders: it places
 * cards newest-group-first until the overall budget is spent, then leaves later
 * groups as heading-only so the HTML body stays under Gmail's ~102 KB clip.
 */
final readonly class DigestPageFactory
{
    public const int DEFAULT_MAX_CARDS = 30;

    public function build(DigestModel $model, int $maxCards): DigestPageModel
    {
        $budget = $maxCards;
        $groups = [];

        foreach ($model->groups as $group) {
            $take = max(0, min($budget, \count($group->entries)));
            $cards = \array_slice($group->entries, 0, $take);
            $budget -= $take;

            $groups[] = new DigestPageGroupModel(
                $group->term,
                $group->totalCount,
                $cards,
                $group->totalCount - \count($cards),
                $group->moreUrl,
            );
        }

        return new DigestPageModel($groups, $model->totalCount);
    }
}
