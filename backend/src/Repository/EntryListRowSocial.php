<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SocialNetwork;

/** A row's post on its network: whether the reader can like it there, did, and wrote it. */
final readonly class EntryListRowSocial
{
    public function __construct(
        public SocialNetwork $network,
        public bool $isLiked,
        public bool $canLike,
        public bool $isOwn = false,
    ) {
    }
}
