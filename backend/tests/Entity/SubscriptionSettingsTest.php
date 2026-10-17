<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SubscriptionSettingsTest extends TestCase
{
    public function testANewSubscriptionTakesOverThePreviousOnesSettings(): void
    {
        $now = new \DateTimeImmutable('2026-10-17 12:00:00');
        $user = new User('settings@example.test', $now);
        $previous = new Subscription($user, new Feed('https://bsky.app/profile/did:plc:rusty/rss'), $now);
        $previous->setCustomTitle('Rusty, old');
        $previous->setPosition(7);
        $previous->setIncludeInAllItems(false);
        $previous->setIncludeInForYou(false);
        $previous->setMarkedReadUntil(new \DateTimeImmutable('2026-10-10 00:00:00'));
        $previous->addTag(new Tag($user, 'People'), 3);
        $next = new Subscription($user, new Feed('social:bluesky:1:author:did:plc:rusty'), $now);

        $next->takeSettingsFrom($previous);

        self::assertSame('Rusty, old', $next->getCustomTitle());
        self::assertSame(7, $next->getPosition());
        self::assertFalse($next->isIncludeInAllItems());
        self::assertFalse($next->isIncludeInForYou());
        self::assertSame('2026-10-10', $next->getMarkedReadUntil()?->format('Y-m-d'));
        self::assertSame(
            [['People', 3]],
            array_map(
                static fn (SubscriptionTag $subscriptionTag): array => [
                    $subscriptionTag->getTag()->getName(),
                    $subscriptionTag->getPosition(),
                ],
                $next->getSubscriptionTags(),
            ),
        );
    }
}
