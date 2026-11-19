<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Lemmy\LemmyPostAddressRule;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class LemmyPostAddressRuleTest extends TestCase
{
    public function testALemmyPostIsRefused(): void
    {
        $account = SocialAccounts::lemmyOf(SocialAccounts::owner());
        $post = new SocialPostAddressModel(SocialNetwork::Lemmy, 'https://lemmy.example/post/7');

        $this->expectException(SocialPostRefusedException::class);
        $this->expectExceptionMessage('Lemmy posts are not deleted from this reader.');

        new LemmyPostAddressRule()->requireOwnPostId($post, $account);
    }

    public function testALemmyReplyUrlIsRefused(): void
    {
        $this->expectException(SocialPostRefusedException::class);
        $this->expectExceptionMessage('Lemmy posts are not deleted from this reader.');

        new LemmyPostAddressRule()->requireOwnReplyId(
            'https://lemmy.example/comment/5',
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
        );
    }

    public function testALemmyReplyIsRefused(): void
    {
        $this->expectException(SocialPostRefusedException::class);
        $this->expectExceptionMessage('Lemmy posts are not deleted from this reader.');

        new LemmyPostAddressRule()->requireOwnReplyGuids(
            'https://lemmy.example/comment/5',
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
        );
    }
}
