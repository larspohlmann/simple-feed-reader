<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Service\Social\Lemmy\LemmyCommunities;
use App\Service\Social\Lemmy\LemmyComposerDefaults;
use App\Service\Social\Lemmy\Support\LemmyPostLimits;
use App\Service\Social\Publishing\Model\CommunityModel;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\LemmyPostFieldsModel;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Social\Model\SocialPostModel;
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

    public function testAPlainPostHoldsTheSameTenThousandCharactersAndOffersNoPostFields(): void
    {
        self::assertEquals(
            new ComposerDefaultsModel(LemmyPostLimits::MAX_CHARACTERS),
            (new LemmyComposerDefaults(new LemmyCommunities(SocialApiWiring::lemmyAccountApi(
                (new RoutedHttpClient([]))->client(),
            ))))->forPublication(self::account(), self::standalone(), self::noThreadStarter()),
        );
    }

    public function testALinkPostOffersCommunitiesAndAClippedTitle(): void
    {
        $defaults = self::defaults()
            ->forPublication(self::account(), self::linkTo('The article'), self::noThreadStarter());

        self::assertEquals(
            new ComposerDefaultsModel(
                LemmyPostLimits::MAX_CHARACTERS,
                lemmyPost: new LemmyPostFieldsModel([new CommunityModel(7, '!rss@lemmy.world')], 'The article', 3, 200),
            ),
            $defaults,
        );
    }

    public function testALongPageTitleIsCutAtAWordToTwoHundred(): void
    {
        $title = trim(str_repeat('wordy ', 40));

        $fields = self::defaults()->forPublication(self::account(), self::linkTo($title), self::noThreadStarter())
            ->lemmyPost;

        self::assertNotNull($fields);
        self::assertLessThanOrEqual(LemmyPostLimits::MAX_TITLE_CHARACTERS, mb_strlen($fields->title));
        self::assertMatchesRegularExpression('/^(wordy )+wordy…$/u', $fields->title);
    }

    public function testAReplyOffersNoPostFields(): void
    {
        $post = SocialPostModel::onLemmy(self::account(), '42', null);
        $reply = self::linkTo('The article')->withReplyTo($post);

        $defaults = self::defaults()->forPublication(self::account(), $reply, self::noThreadStarter());

        self::assertNull($defaults->lemmyPost);
    }

    private static function account(): SocialAccount
    {
        return SocialAccounts::lemmyOf(SocialAccounts::owner());
    }

    private static function standalone(): PublicationModel
    {
        return PublicationModel::fromDraft(new PostDraftModel(''));
    }

    private static function linkTo(string $title): PublicationModel
    {
        return self::standalone()->withEmbed(new LinkEmbedModel(self::ARTICLE, $title));
    }

    /** @return \Closure(): ?SocialPostModel */
    private static function noThreadStarter(): \Closure
    {
        return static fn (): ?SocialPostModel => null;
    }
}
