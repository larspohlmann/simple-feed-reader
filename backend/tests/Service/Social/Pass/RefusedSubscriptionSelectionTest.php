<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Social\Model\RefusedProfileSubscriptionModel;
use App\Service\Social\Pass\RefusedSubscriptionSelection;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class RefusedSubscriptionSelectionTest extends TestCase
{
    use AssignsEntityIds;

    public function testTheActorsToAskAboutAreListedOnceEach(): void
    {
        $selection = new RefusedSubscriptionSelection(25, 20, null);
        $selection->add(self::candidate(1, 'did:plc:bob'));
        $selection->add(self::candidate(2, 'did:plc:carol'));
        $selection->add(self::candidate(3, 'did:plc:bob'));

        self::assertSame(['did:plc:bob', 'did:plc:carol'], $selection->unknownActors());
    }

    private static function candidate(int $id, string $actor): RefusedProfileSubscriptionModel
    {
        $feed = self::withId(new Feed('https://bsky.app/profile/' . $actor . '/rss'), $id);
        $subscription = new Subscription(SocialAccounts::owner(), $feed, new \DateTimeImmutable('2026-10-17 12:00:00'));

        return new RefusedProfileSubscriptionModel(self::withId($subscription, $id), $actor, null);
    }
}
