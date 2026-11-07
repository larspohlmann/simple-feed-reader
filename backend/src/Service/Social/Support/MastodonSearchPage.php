<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelinePageModel;

/** A search answer as entries: every status but a reply. */
final class MastodonSearchPage
{
    /** @throws SocialNetworkUnreachableException */
    public static function of(ApiResponseModel $accepted): TimelinePageModel
    {
        $statuses = JsonNodeModel::of($accepted->json())->listAt('statuses')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance answered without search results.');

        return MastodonTimelinePage::of(array_values(array_filter(
            $statuses,
            static fn (JsonNodeModel $status): bool => null === $status->string('in_reply_to_id'),
        )));
    }

    private function __construct()
    {
    }
}
