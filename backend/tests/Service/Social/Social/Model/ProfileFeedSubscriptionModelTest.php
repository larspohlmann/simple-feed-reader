<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Model;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\ProfileFeedSubscriptionModel;
use PHPUnit\Framework\TestCase;

final class ProfileFeedSubscriptionModelTest extends TestCase
{
    public function testALemmyUserKeepsItsLeadingAt(): void
    {
        self::assertSame('@ada@feddit.example', $this->lemmy('@ada@feddit.example')->handleOn(SocialNetwork::Lemmy));
    }

    public function testALemmyCommunityGetsAnExclamationMark(): void
    {
        self::assertSame('!rss@feddit.example', $this->lemmy('rss@feddit.example')->handleOn(SocialNetwork::Lemmy));
    }

    private function lemmy(string $identity): ProfileFeedSubscriptionModel
    {
        return new ProfileFeedSubscriptionModel(1, $identity, 'https://feddit.example/feed.xml', 'Feed', null);
    }
}
