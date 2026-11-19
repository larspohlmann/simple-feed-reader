<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Model;

use App\Service\Social\Social\Model\SocialCollectionModel;

final readonly class ResolvedCollectionsModel
{
    /**
     * @param list<SocialCollectionModel> $found
     * @param list<string>                $gone
     */
    public function __construct(
        public array $found = [],
        public array $gone = [],
    ) {
    }
}
