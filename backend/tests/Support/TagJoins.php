<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Subscription;
use App\Entity\Tag;
use PHPUnit\Framework\Assert;

final class TagJoins
{
    public static function positionOf(Subscription $subscription, Tag $tag): int
    {
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag() === $tag) {
                return $join->getPosition();
            }
        }
        Assert::fail('Subscription is not tagged with ' . $tag->getName());
    }
}
