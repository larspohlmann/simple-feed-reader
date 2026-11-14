<?php

declare(strict_types=1);

namespace App\Service\LinkCard\Model;

use App\Entity\QuotedPostContent;

/** A quoted post and the address the post's "RE:" line names it by, which may be its uri rather than its url. */
final readonly class QuotedTargetModel
{
    public function __construct(
        public string $address,
        public QuotedPostContent $post,
    ) {
    }
}
