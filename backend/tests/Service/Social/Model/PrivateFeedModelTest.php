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
}
