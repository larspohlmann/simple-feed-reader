<?php

declare(strict_types=1);

namespace App\Service\Library\EntryList\Model;

use App\Repository\EntryListRowLemmy;
use App\Repository\EntryListRowSocial;

final readonly class ConnectedAccountStatesModel
{
    /**
     * @param array<int, EntryListRowSocial> $social by entry id, for every post
     * @param array<int, EntryListRowLemmy> $lemmy by entry id, for every entry that has a Lemmy block
     */
    public function __construct(public array $social, public array $lemmy)
    {
    }
}
