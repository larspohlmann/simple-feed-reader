<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon;

use App\Service\Social\Mastodon\MastodonComposerDefaults;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\PostDraftModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PostEmbed\QuotedPostEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Social\Model\PostContextModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonComposerDefaultsTest extends TestCase
{
    private const string INSTANCE = 'GET https://mastodon.example/api/v2/instance';
    private const string OWN_ACCOUNT = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';

    public function testAReplyMentionsTheAuthorAndTheThreadStarter(): void
    {
        $http = $this->answering(1000, 'private');
        $target = self::post(new PostContextModel('ann@hachyderm.io', visibility: 'unlisted'));
        $starter = self::post(new PostContextModel('Gargron@mastodon.social', visibility: 'public'));

        $defaults = $this->defaults($http)->forPublication(
            $target->account,
            self::replyTo($target),
            static fn (): SocialPostModel => $starter,
        );

        self::assertEquals(
            new ComposerDefaultsModel(1000, 'private', ['@ann@hachyderm.io', '@gargron@mastodon.social']),
            $defaults,
        );
    }

    public function testTheAccountItselfAndARepeatedAuthorAreMentionedNoMoreThanOnce(): void
    {
        $http = $this->answering(500, 'public');
        $target = self::post(new PostContextModel('Alice@mastodon.example', visibility: 'direct'));
        $starter = self::post(new PostContextModel('ann@hachyderm.io'));
        $again = self::post(new PostContextModel('ann@hachyderm.io'));
        $account = $target->account;

        $first = $this->defaults($http)
            ->forPublication($account, self::replyTo($target), static fn (): SocialPostModel => $starter);
        $second = $this->defaults($http)
            ->forPublication($account, self::replyTo($again), static fn (): SocialPostModel => $again);

        self::assertSame(['@ann@hachyderm.io'], $first->mentions);
        self::assertSame('direct', $first->visibility);
        self::assertSame(['@ann@hachyderm.io'], $second->mentions);
    }

    public function testAnInstanceThatDoesNotAnswerFallsBackToMastodonsDefaults(): void
    {
        $http = new RoutedHttpClient([
            self::INSTANCE => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
            self::OWN_ACCOUNT => new MockResponse('{}'),
        ]);
        $target = self::post(new PostContextModel());

        $defaults = $this->defaults($http)
            ->forPublication($target->account, self::replyTo($target), static fn (): SocialPostModel => $target);

        self::assertEquals(new ComposerDefaultsModel(500, 'public', []), $defaults);
    }

    public function testAStandalonePostStartsFromTheAccountsPrivacyAndTheInstanceLimit(): void
    {
        $defaults = $this->defaults($this->answering(500, 'unlisted'))
            ->forPublication(SocialAccounts::mastodon(), self::standalone(), self::noThreadStarter());

        self::assertEquals(new ComposerDefaultsModel(500, 'unlisted', []), $defaults);
    }

    public function testAStandalonePostFallsBackToMastodonsDefaultsWhenNeitherCallAnswers(): void
    {
        $http = new RoutedHttpClient([
            self::INSTANCE => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
            self::OWN_ACCOUNT => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ]);

        $defaults = $this->defaults($http)
            ->forPublication(SocialAccounts::mastodon(), self::standalone(), self::noThreadStarter());

        self::assertEquals(new ComposerDefaultsModel(500, 'public', []), $defaults);
    }

    public function testAQuoteTakesTheStricterVisibilityAndMentionsNoOne(): void
    {
        $quoted = self::post(new PostContextModel('ann@hachyderm.io', visibility: 'private'));
        $quote = self::standalone()->withEmbed(new QuotedPostEmbedModel($quoted));

        $defaults = $this->defaults($this->answering(500, 'public'))
            ->forPublication($quoted->account, $quote, static fn (): never => self::fail('A quote names no one.'));

        self::assertEquals(new ComposerDefaultsModel(500, 'private', []), $defaults);
    }

    public function testALinkPostLeavesRoomForTheLink(): void
    {
        $link = self::standalone()->withEmbed(new LinkEmbedModel('https://example.com', 'A title'));

        $defaults = $this->defaults($this->answering(500, 'unlisted'))
            ->forPublication(SocialAccounts::mastodon(), $link, self::noThreadStarter());

        self::assertEquals(new ComposerDefaultsModel(475, 'unlisted', []), $defaults);
    }

    private function answering(int $maxCharacters, string $privacy): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::INSTANCE => new MockResponse(json_encode(
                ['configuration' => ['statuses' => ['max_characters' => $maxCharacters]]],
                \JSON_THROW_ON_ERROR,
            )),
            self::OWN_ACCOUNT => new MockResponse(json_encode(
                ['id' => '1', 'source' => ['privacy' => $privacy]],
                \JSON_THROW_ON_ERROR,
            )),
        ]);
    }

    private function defaults(RoutedHttpClient $http): MastodonComposerDefaults
    {
        return new MastodonComposerDefaults(
            SocialApiWiring::mastodonAccountApi($http->client()),
            SocialApiWiring::mastodonApiClient($http->client()),
            SocialApiWiring::mastodonStatusLimit($http->client()),
        );
    }

    private static function post(PostContextModel $postContext): SocialPostModel
    {
        return SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110')->withPostContext($postContext);
    }

    private static function standalone(): PublicationModel
    {
        return PublicationModel::fromDraft(new PostDraftModel(''));
    }

    private static function replyTo(SocialPostModel $post): PublicationModel
    {
        return self::standalone()->withReplyTo($post);
    }

    /** @return \Closure(): ?SocialPostModel */
    private static function noThreadStarter(): \Closure
    {
        return static fn (): ?SocialPostModel => null;
    }
}
