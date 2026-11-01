<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

/** What deleting a post found on its network: the post, or that it was gone already. */
enum PostDeletion: string
{
    case Deleted = 'deleted';
    case AlreadyGone = 'alreadyGone';
}
