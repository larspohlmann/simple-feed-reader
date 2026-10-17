<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

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
