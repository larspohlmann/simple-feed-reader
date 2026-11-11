<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Model;

final readonly class SubscribedFeedsModel
{
    /**
     * @param list<string> $urls
     * @param list<string> $hosts
     */
    public function __construct(
        public array $urls,
        public array $hosts,
    ) {
    }
}
