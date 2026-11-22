<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\Model;

use App\Enum\SocialNetwork;

/** Where a post of text alone goes: a network, by the chosen account or the network's default one. */
final readonly class TextPostTargetModel
{
    public function __construct(
        public SocialNetwork $network,
        public ?int $accountId,
    ) {
    }
}
