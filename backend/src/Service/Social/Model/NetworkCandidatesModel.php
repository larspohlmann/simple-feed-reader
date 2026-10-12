<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class NetworkCandidatesModel
{
    /**
     * @param list<FollowedAccountModel>  $follows
     * @param list<SocialCollectionModel> $lists
     * @param list<SocialCollectionModel> $customFeeds
     */
    public function __construct(
        public array $follows,
        public array $lists,
        public array $customFeeds,
        public bool $truncated,
    ) {
    }
}
