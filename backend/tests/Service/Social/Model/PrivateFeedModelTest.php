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

    public function testAFollowedTagIsKeyedByItsLowercasedNameAndKeepsTheCasingItWasShownIn(): void
    {
        $tag = PrivateFeedModel::followedTag('Caturday', '#Caturday');

        self::assertSame(SocialFeedKind::Tag, $tag->kind);
        self::assertSame('caturday', $tag->remoteId);
        self::assertSame('#Caturday', $tag->name);
        self::assertSame('tag:caturday', $tag->key());
        self::assertEquals($tag, PrivateFeedModel::of(SocialFeedKind::Tag, 'CATURDAY', '#Caturday'));
    }
}
