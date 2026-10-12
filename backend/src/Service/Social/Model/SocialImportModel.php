<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialImportModel
{
    /**
     * @param list<SocialImportFeedModel> $subscribe
     * @param list<int>                   $unsubscribe subscription ids
     * @param list<PrivateFeedModel>      $collections Home, lists and custom feeds to add as private feeds
     */
    public function __construct(
        public array $subscribe,
        public array $unsubscribe,
        public array $collections = [],
    ) {
    }
}
