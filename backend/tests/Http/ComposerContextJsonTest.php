<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Http\ComposerContextJson;
use App\Service\Social\Social\Model\CommunityModel;
use App\Service\Social\Social\Model\ComposerContextModel;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\ComposerKind;
use App\Service\Social\Social\Model\LinkPostChoicesModel;
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
        $linkPost = new LinkPostChoicesModel([new CommunityModel(7, '!rss@lemmy.world')], 'The article', 4, 150);

        $row = ComposerContextJson::from(self::linkContext(new ComposerDefaultsModel(10000, linkPost: $linkPost)));

        self::assertSame(
            [
                'communities' => [['id' => 7, 'handle' => '!rss@lemmy.world']],
                'title' => 'The article',
                'minTitleCharacters' => 4,
                'maxTitleCharacters' => 150,
            ],
            $row['lemmy'],
        );
    }

    public function testAComposerOnAnyOtherNetworkHasNoLemmyRow(): void
    {
        $row = ComposerContextJson::from(self::linkContext(new ComposerDefaultsModel(300)));

        self::assertNull($row['lemmy']);
    }
}
