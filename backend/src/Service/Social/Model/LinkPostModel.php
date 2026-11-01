<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialNetwork;

/** An entry's article posted on its own, by the chosen account or the network's default one. */
final readonly class LinkPostModel
{
    public function __construct(
        public SocialNetwork $network,
        public ?int $accountId,
        public PostDraftModel $draft,
    ) {
    }
}
