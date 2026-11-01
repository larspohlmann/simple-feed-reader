<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialNetwork;

/** A URL to share on a network, by the chosen account or the network's default one. */
final readonly class LinkTargetModel
{
    public function __construct(
        public SocialNetwork $network,
        public ?int $accountId,
        public string $url,
    ) {
    }
}
