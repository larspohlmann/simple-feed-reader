<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Http\ComposerContextJson;
use App\Service\Social\Social\Model\ComposerContextModel;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\ComposerKind;
use App\Service\Social\Social\Model\LemmyCommunityModel;
use App\Service\Social\Social\Model\LemmyLinkModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class ComposerContextJsonTest extends TestCase
{
    private static function linkContext(ComposerDefaultsModel $defaults): ComposerContextModel
    {
        $account = SocialAccounts::lemmyOf(SocialAccounts::owner());
        (new \ReflectionProperty(SocialAccount::class, 'id'))->setValue($account, 5);
        $card = new LinkCardContent('https://news.example/article', 'Title', null, null, 'news.example');

        return new ComposerContextModel([], $account, true, $defaults, $card, ComposerKind::Link, '');
    }

    public function testALemmyLinkListsItsCommunitiesItsTitleAndTheTitleLimit(): void
    {
        $lemmyLink = new LemmyLinkModel([new LemmyCommunityModel(7, '!rss@lemmy.world')], 'The article');

        $row = ComposerContextJson::of(self::linkContext(new ComposerDefaultsModel(10000, lemmyLink: $lemmyLink)));

        self::assertSame(
            [
                'communities' => [['id' => 7, 'handle' => '!rss@lemmy.world']],
                'title' => 'The article',
                'maxTitleCharacters' => 200,
            ],
            $row['lemmy'],
        );
    }

    public function testAComposerOnAnyOtherNetworkHasNoLemmyRow(): void
    {
        $row = ComposerContextJson::of(self::linkContext(new ComposerDefaultsModel(300)));

        self::assertNull($row['lemmy']);
    }
}
