<?php

declare(strict_types=1);

namespace App\Tests\Repository\Model\RefreshTarget;

use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Repository\Model\RefreshTarget\EveryFeedTargetModel;
use App\Repository\Model\RefreshTarget\FeedGroupTargetModel;
use App\Repository\Model\RefreshTarget\FeedTargetModel;
use App\Repository\Model\RefreshTarget\NetworkTargetModel;
use App\Repository\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Repository\Model\RefreshTarget\RefreshTargetModelInterface;
use App\Repository\Model\RefreshTarget\TagTargetModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefreshTargetRunKeyTest extends TestCase
{
    /** @return iterable<string, array{RefreshTargetModelInterface, string}> */
    public static function targets(): iterable
    {
        $networkScope = NetworkScope::whole(SocialNetwork::Mastodon);

        yield 'every feed' => [new EveryFeedTargetModel(), 'all'];
        yield 'one feed' => [new FeedTargetModel(7), 'feed-7'];
        yield 'one tag' => [new TagTargetModel(7), 'tag-7'];
        yield 'one network' => [new NetworkTargetModel($networkScope), 'set-network-' . $networkScope->toRunKey()];
        yield 'one group' => [new FeedGroupTargetModel(FeedGroup::Tags), 'group-tags'];
        yield 'never fetched' => [new NeverFetchedTargetModel(), 'never-fetched'];
    }

    #[DataProvider('targets')]
    public function testEachTargetKeepsItsRunKey(RefreshTargetModelInterface $target, string $runKey): void
    {
        self::assertSame($runKey, $target->runKey());
    }
}
