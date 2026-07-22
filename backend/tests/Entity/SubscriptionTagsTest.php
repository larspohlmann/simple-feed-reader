<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SubscriptionTagsTest extends TestCase
{
    public function testAddAndRemoveTagAreIdempotent(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $user = new User('u@example.com', $now);
        $sub = new Subscription($user, new Feed('https://example.com/f.xml'), $now);
        $tag = new Tag($user, 'news');

        $sub->addTag($tag);
        $sub->addTag($tag); // idempotent
        self::assertCount(1, $sub->getTags());
        self::assertTrue($sub->getTags()->contains($tag));

        $sub->removeTag($tag);
        self::assertCount(0, $sub->getTags());
    }
}
