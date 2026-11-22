<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Model;

use App\Service\Social\Social\Model\PostContextModel;
use App\Service\Social\Social\Model\SocialCountsModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class SocialPostModelTest extends TestCase
{
    public function testALaterLikeReplacesAnEarlierOne(): void
    {
        $post = SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110')
            ->alreadyLikedAs('97')
            ->alreadyLikedAs('110');

        self::assertSame('110', $post->existingLikeId);
    }

    public function testALaterRepostReplacesAnEarlierOne(): void
    {
        $post = SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110')
            ->alreadyRepostedAs('97')
            ->alreadyRepostedAs('110');

        self::assertSame('110', $post->existingRepostId);
    }

    public function testLaterCountsReplaceEarlierOnes(): void
    {
        $later = new SocialCountsModel(4, 5, 6, null);

        $post = SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110')
            ->withCounts(new SocialCountsModel(1, 2, 3, null))
            ->withCounts($later);

        self::assertSame($later, $post->counts);
    }

    public function testEveryStepKeepsWhatTheOthersSet(): void
    {
        $counts = new SocialCountsModel(1, 2, 3, null);

        $post = SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110')
            ->withCounts($counts)
            ->alreadyLikedAs('110')
            ->alreadyRepostedAs('110')
            ->notRepostable()
            ->withPostContext(new PostContextModel('alice@mastodon.example', null, 'private', true));

        self::assertSame('110', $post->existingLikeId);
        self::assertSame('110', $post->existingRepostId);
        self::assertFalse($post->canRepost);
        self::assertSame($counts, $post->counts);
        self::assertSame('110', $post->withCounts(null)->existingRepostId);
        self::assertFalse($post->withCounts(null)->canRepost);
        self::assertSame('private', $post->withCounts(null)->postContext->visibility);
        self::assertSame('private', $post->alreadyLikedAs('111')->postContext->visibility);
        self::assertSame('110', $post->withPostContext(new PostContextModel())->existingLikeId);
        self::assertSame($counts, $post->withPostContext(new PostContextModel())->counts);
    }
}
