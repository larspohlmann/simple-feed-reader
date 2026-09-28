<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Tag;
use App\Service\Subscription\Model\BulkSubscribeResultModel;

final class OnboardingJson
{
    /** @return array<string, mixed> */
    public static function subscribed(BulkSubscribeResultModel $result): array
    {
        return [
            'subscribed' => $result->imported,
            'skipped' => $result->alreadySubscribed + $result->invalid + $result->skippedOverLimit,
            'skippedOverLimit' => $result->skippedOverLimit,
            'tagsCreated' => array_map(static fn (Tag $tag) => TagJson::one($tag), $result->tagsCreated),
        ];
    }
}
