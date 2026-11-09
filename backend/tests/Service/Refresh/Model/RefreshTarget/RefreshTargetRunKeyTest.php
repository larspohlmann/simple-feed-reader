<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Model\RefreshTarget;

use App\Enum\FeedGroup;
use App\Enum\SocialNetwork;
use App\Repository\NetworkScope;
use App\Service\Refresh\Model\RefreshTarget\EveryFeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedGroupTargetModel;
use App\Service\Refresh\Model\RefreshTarget\FeedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NetworkTargetModel;
use App\Service\Refresh\Model\RefreshTarget\NeverFetchedTargetModel;
use App\Service\Refresh\Model\RefreshTarget\RefreshTargetModelInterface;
use App\Service\Refresh\Model\RefreshTarget\TagTargetModel;
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
