<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Enum\SocialNetwork;

/** A post as its network names it everywhere: a Bluesky AT URI or a Mastodon status URL. */
final readonly class SocialPostAddressModel
{
    public function __construct(
        public SocialNetwork $network,
        public string $address,
    ) {
    }
}
