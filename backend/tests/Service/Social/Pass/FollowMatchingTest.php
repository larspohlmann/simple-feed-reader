<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;
use PHPUnit\Framework\TestCase;

final class FollowMatchingTest extends TestCase
{
    public function testTheLeftoverFeedsKeyedByAHandleAreListedWithoutThoseKeyedByADid(): void
    {
        $byDid = self::profileFeed(1, 'did:plc:bob');
        $byHandle = self::profileFeed(2, 'carol.bsky.social');
        $matching = new FollowMatching(SocialNetwork::Bluesky, []);
        $matching->offer($byDid);
        $matching->offer($byHandle);

        self::assertSame([$byHandle], $matching->unmatchedHandles());
    }

    private static function profileFeed(int $subscriptionId, string $identity): ProfileFeedSubscriptionModel
    {
        return new ProfileFeedSubscriptionModel(
            $subscriptionId,
            $identity,
            'https://bsky.app/profile/' . $identity . '/rss',
            $identity,
            null,
        );
    }
}
