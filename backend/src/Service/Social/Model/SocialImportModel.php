<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialImportModel
{
    /**
     * @param list<SocialImportFeedModel> $subscribe
     * @param list<int>                   $unsubscribe subscription ids
     */
    public function __construct(
        public array $subscribe,
        public array $unsubscribe,
    ) {
    }
}
