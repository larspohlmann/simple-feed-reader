<?php

declare(strict_types=1);

namespace App\Service\Tag\Factory;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Tag\TagDetails;

final readonly class TagFactory
{
    public function create(User $user, TagDetails $details, int $position): Tag
    {
        $tag = new Tag($user, $details->name);
        $tag->setColor($details->color);
        $tag->setIcon($details->icon);
        $tag->setPosition($position);

        return $tag;
    }
}
