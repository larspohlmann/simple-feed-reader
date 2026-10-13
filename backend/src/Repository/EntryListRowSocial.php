<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\SocialNetwork;

/** A row's post on a network the reader has an account on, and whether they liked it there. */
final readonly class EntryListRowSocial
{
    public function __construct(
        public SocialNetwork $network,
        public bool $isLiked,
    ) {
    }
}
