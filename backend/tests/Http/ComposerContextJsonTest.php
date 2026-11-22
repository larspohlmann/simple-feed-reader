<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\LinkCardContent;
use App\Entity\SocialAccount;
use App\Http\ComposerContextJson;
use App\Service\Social\Publishing\Model\CommunityModel;
use App\Service\Social\Publishing\Model\ComposerContextModel;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\ComposerKind;
use App\Service\Social\Publishing\Model\LemmyPostFieldsModel;
use App\Service\Social\Social\Model\PostPreviewModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class ComposerContextJsonTest extends TestCase
{
    private static function context(
        ComposerDefaultsModel $defaults,
        PostPreviewModel|LinkCardContent|null $subject,
    ): ComposerContextModel {
        $account = SocialAccounts::lemmyOf(SocialAccounts::owner());
        (new \ReflectionProperty(SocialAccount::class, 'id'))->setValue($account, 5);

        return new ComposerContextModel([], $account, true, $defaults, $subject, ComposerKind::Link, '');
    }

    private static function card(): LinkCardContent
    {
        return new LinkCardContent('https://news.example/article', 'Title', null, null, 'news.example');
    }

    public function testALemmyLinkListsItsCommunitiesItsTitleAndTheTitleLimit(): void
    {
        $lemmyPost = new LemmyPostFieldsModel([new CommunityModel(7, '!rss@lemmy.world')], 'The article', 4, 150);

        $row = ComposerContextJson::from(
            self::context(new ComposerDefaultsModel(10000, lemmyPost: $lemmyPost), self::card()),
        );

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
        $row = ComposerContextJson::from(self::context(new ComposerDefaultsModel(300), self::card()));

        self::assertNull($row['lemmy']);
    }

    public function testALinkContextSendsItsCardAsTheSubject(): void
    {
        $row = ComposerContextJson::from(self::context(new ComposerDefaultsModel(300), self::card()));

        self::assertSame('link', $row['subject']['kind'] ?? null);
    }

    public function testAContextWithoutSubjectSendsNull(): void
    {
        $row = ComposerContextJson::from(self::context(new ComposerDefaultsModel(300), null));

        self::assertArrayHasKey('subject', $row);
        self::assertNull($row['subject']);
    }
}
