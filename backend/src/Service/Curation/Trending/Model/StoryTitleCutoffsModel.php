<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

/** An untitled story is retried once its last attempt is older than retryBefore; a titled one waits for retitleBefore. */
final readonly class StoryTitleCutoffsModel
{
    public function __construct(
        public \DateTimeImmutable $retryBefore,
        public \DateTimeImmutable $retitleBefore,
    ) {
    }
}
