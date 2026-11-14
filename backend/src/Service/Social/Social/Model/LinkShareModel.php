<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;

/** A link about to be shared: who shares it, and the post it names on that account's network, if one is quotable. */
final readonly class LinkShareModel
{
    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(
        public SocialAccount $account,
        public SharedLinkModel $link,
        public ?SocialPostModel $quoted,
    ) {
    }
}
