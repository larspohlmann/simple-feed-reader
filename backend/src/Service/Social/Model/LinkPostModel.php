<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** A URL posted on its own, or as a quote when it names a post on the network it goes to. */
final readonly class LinkPostModel
{
    public function __construct(
        public LinkTargetModel $target,
        public PostDraftModel $draft,
    ) {
    }
}
