<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Service\Social\Lemmy\LemmyCommunities;
use App\Service\Social\Lemmy\LemmyComposerDefaults;
use App\Service\Social\Lemmy\LemmyPostWriter;
use App\Service\Social\Social\Model\CommunityModel;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\LinkPostChoicesModel;
use App\Service\Social\Social\Model\SharedLinkModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyComposerDefaultsTest extends TestCase
{
    private const string COMMUNITIES = 'GET https://lemmy.example/api/v3/community/list'
        . '?type_=Subscribed&sort=Old&limit=50&page=1';
    private const string ARTICLE = 'https://news.example/article';

    private static function defaults(): LemmyComposerDefaults
    {
        $answer = json_encode(['communities' => [
            ['community' => ['id' => 7, 'name' => 'rss', 'actor_id' => 'https://lemmy.world/c/rss']],
        ]], \JSON_THROW_ON_ERROR);
        $http = new RoutedHttpClient([self::COMMUNITIES => new MockResponse($answer)]);

        return new LemmyComposerDefaults(new LemmyCommunities(SocialApiWiring::lemmyAccountApi($http->client())));
    }

    public function testAPlainPostHoldsTheSameTenThousandCharactersAndOffersNoLinkChoices(): void
    {
        self::assertEquals(
            new ComposerDefaultsModel(LemmyComposerDefaults::MAX_CHARACTERS),
            (new LemmyComposerDefaults(new LemmyCommunities(SocialApiWiring::lemmyAccountApi(
                (new RoutedHttpClient([]))->client(),
            ))))->forAccount(SocialAccounts::lemmyOf(SocialAccounts::owner())),
        );
    }

    public function testALinkCarriesThePostableCommunitiesAndThePageTitle(): void
    {
        $defaults = self::defaults()->forLinkFrom(
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
            new SharedLinkModel(self::ARTICLE, 'The article'),
        );

        self::assertEquals(
            new ComposerDefaultsModel(
                LemmyComposerDefaults::MAX_CHARACTERS,
                linkPost: new LinkPostChoicesModel([new CommunityModel(7, '!rss@lemmy.world')], 'The article', 3, 200),
            ),
            $defaults,
        );
    }

    public function testALongPageTitleIsCutAtAWordToTwoHundred(): void
    {
        $title = trim(str_repeat('wordy ', 40));

        $link = self::defaults()->forLinkFrom(
            SocialAccounts::lemmyOf(SocialAccounts::owner()),
            new SharedLinkModel(self::ARTICLE, $title),
        )->linkPost;

        self::assertNotNull($link);
        self::assertLessThanOrEqual(LemmyPostWriter::MAX_TITLE_CHARACTERS, mb_strlen($link->title));
        self::assertMatchesRegularExpression('/^(wordy )+wordy…$/u', $link->title);
    }
}
