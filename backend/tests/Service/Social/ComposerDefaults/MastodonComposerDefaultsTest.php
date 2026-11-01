<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\ComposerDefaults;

use App\Service\Social\ComposerDefaults\MastodonComposerDefaults;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\ResponseContextModel;
use App\Service\Social\Model\SocialPostModel;
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

    public function testTheInstanceLimitTheStricterVisibilityAndBothAuthorsOpenTheComposer(): void
    {
        $http = $this->answering(1000, 'private');
        $target = self::post(new ResponseContextModel('ann@hachyderm.io', visibility: 'unlisted'));
        $starter = self::post(new ResponseContextModel('Gargron@mastodon.social', visibility: 'public'));

        $defaults = $this->defaults($http)->defaultsFor($target, static fn (): SocialPostModel => $starter);

        self::assertEquals(
            new ComposerDefaultsModel(1000, 'private', ['@ann@hachyderm.io', '@gargron@mastodon.social']),
            $defaults,
        );
    }

    public function testTheAccountItselfAndARepeatedAuthorAreMentionedNoMoreThanOnce(): void
    {
        $http = $this->answering(500, 'public');
        $target = self::post(new ResponseContextModel('Alice@mastodon.example', visibility: 'direct'));
        $starter = self::post(new ResponseContextModel('ann@hachyderm.io'));
        $again = self::post(new ResponseContextModel('ann@hachyderm.io'));

        $first = $this->defaults($http)->defaultsFor($target, static fn (): SocialPostModel => $starter);
        $second = $this->defaults($http)->defaultsFor($again, static fn (): SocialPostModel => $again);

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
        $target = self::post(new ResponseContextModel());

        $defaults = $this->defaults($http)->defaultsFor($target, static fn (): SocialPostModel => $target);

        self::assertEquals(new ComposerDefaultsModel(500, 'public', []), $defaults);
    }

    public function testAnAccountsOwnPostStartsFromTheInstanceLimitAndTheAccountsPrivacyWithoutMentions(): void
    {
        $defaults = $this->defaults($this->answering(1000, 'unlisted'))->forAccount(SocialAccounts::mastodon());

        self::assertEquals(new ComposerDefaultsModel(1000, 'unlisted', []), $defaults);
    }

    public function testAnAccountsOwnPostFallsBackToMastodonsDefaultsWhenNeitherCallAnswers(): void
    {
        $http = new RoutedHttpClient([
            self::INSTANCE => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
            self::OWN_ACCOUNT => new MockResponse('', ['http_code' => Response::HTTP_BAD_GATEWAY]),
        ]);

        $defaults = $this->defaults($http)->forAccount(SocialAccounts::mastodon());

        self::assertEquals(new ComposerDefaultsModel(500, 'public', []), $defaults);
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
        );
    }

    private static function post(ResponseContextModel $responseContext): SocialPostModel
    {
        return SocialPostModel::onMastodon(SocialAccounts::mastodon(), '110')->inResponseContext($responseContext);
    }
}
