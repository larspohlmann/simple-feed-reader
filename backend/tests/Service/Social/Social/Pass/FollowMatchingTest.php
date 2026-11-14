<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Pass;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\ImportCandidateModel;
use App\Service\Social\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Social\Pass\FollowMatching;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{SocialNetwork, string, string}> */
    public static function noLongerFollowedHandles(): iterable
    {
        yield 'a Bluesky DID' => [SocialNetwork::Bluesky, 'did:plc:bob', 'did:plc:bob'];
        yield 'a Bluesky handle' => [SocialNetwork::Bluesky, 'carol.bsky.social', '@carol.bsky.social'];
        yield 'a Mastodon account' => [SocialNetwork::Mastodon, 'ada@mastodon.example', '@ada@mastodon.example'];
        yield 'a Lemmy community' => [SocialNetwork::Lemmy, 'memes@lemmy.example', '!memes@lemmy.example'];
    }

    #[DataProvider('noLongerFollowedHandles')]
    public function testANoLongerFollowedFeedIsHandledTheWayItsNetworkWritesIt(
        SocialNetwork $network,
        string $identity,
        string $handle,
    ): void {
        $matching = new FollowMatching($network, []);
        $matching->offer(self::profileFeed(1, $identity));

        self::assertSame([$handle], array_map(
            static fn (ImportCandidateModel $candidate): string => $candidate->account->handle,
            $matching->candidates(),
        ));
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
