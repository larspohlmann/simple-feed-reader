<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialFeedKind;
use App\Enum\SocialNetwork;

final readonly class SocialFeedAddressModel
{
    public function __construct(
        public SocialNetwork $network,
        public int $accountId,
        public SocialFeedKind $kind,
    ) {
    }
}
