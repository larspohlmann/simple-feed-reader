<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Enum\SocialFeedKind;
use App\Service\Social\Model\PrivateFeedModel;
use PHPUnit\Framework\TestCase;

final class PrivateFeedModelTest extends TestCase
{
    public function testAKeyNamesTheKindThenTheRemoteId(): void
    {
        self::assertSame('list:at://list/a', PrivateFeedModel::keyOf(SocialFeedKind::UserList, 'at://list/a'));
        self::assertSame('home:', PrivateFeedModel::home()->key());
    }

    public function testAKeyReadsBackAsItsKindAndRemoteIdWithoutAName(): void
    {
        self::assertEquals(
            PrivateFeedModel::userList('at://did:plc:a/app.bsky.graph.list/x:y', ''),
            PrivateFeedModel::fromKey('list:at://did:plc:a/app.bsky.graph.list/x:y'),
        );
        self::assertEquals(PrivateFeedModel::customFeed('at://f', ''), PrivateFeedModel::fromKey('custom_feed:at://f'));
        self::assertEquals(PrivateFeedModel::home(), PrivateFeedModel::fromKey('home:'));
    }
}
