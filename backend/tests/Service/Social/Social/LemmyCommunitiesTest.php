<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Service\Social\Social\LemmyCommunities;
use App\Service\Social\Social\Model\CommunityModel;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LemmyCommunitiesTest extends TestCase
{
    private const string PAGE = 'GET https://lemmy.example/api/v3/community/list'
        . '?type_=Subscribed&sort=Old&limit=50&page=';

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function view(int $id, string $name, array $fields = []): array
    {
        return ['community' => array_merge([
            'id' => $id,
            'name' => $name,
            'actor_id' => 'https://lemmy.world/c/' . $name,
        ], $fields)];
    }

    /** @param list<array<string, mixed>> $views */
    private static function page(array $views): MockResponse
    {
        return new MockResponse(json_encode(['communities' => $views], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, MockResponse> $routes
     *
     * @return list<CommunityModel>
     */
    private static function postable(array $routes): array
    {
        $http = new RoutedHttpClient($routes);
        $communities = new LemmyCommunities(SocialApiWiring::lemmyAccountApi($http->client()));

        return $communities->postable(SocialAccounts::lemmyOf(SocialAccounts::owner()));
    }

    public function testPostableListsSubscribedCommunitiesByHandle(): void
    {
        $postable = self::postable([self::PAGE . '1' => self::page([
            self::view(3, 'SelfHosted'),
            self::view(7, 'rss'),
        ])]);

        self::assertEquals(
            [new CommunityModel(7, '!rss@lemmy.world'), new CommunityModel(3, '!selfhosted@lemmy.world')],
            $postable,
        );
    }

    public function testPostableLeavesOutRemovedDeletedAndModOnlyCommunities(): void
    {
        $postable = self::postable([self::PAGE . '1' => self::page([
            self::view(1, 'gone', ['removed' => true]),
            self::view(2, 'deleted', ['deleted' => true]),
            self::view(3, 'modsonly', ['posting_restricted_to_mods' => true]),
            self::view(4, 'open'),
            self::view(5, 'plain', ['actor_id' => 'http://lemmy.world/c/plain']),
        ])]);

        self::assertEquals([new CommunityModel(4, '!open@lemmy.world')], $postable);
    }

    public function testPostableReadsEveryPage(): void
    {
        $full = array_map(static fn (int $id): array => self::view($id, 'c' . $id), range(1, 50));

        $postable = self::postable([
            self::PAGE . '1' => self::page($full),
            self::PAGE . '2' => self::page([self::view(51, 'c51')]),
        ]);

        self::assertCount(51, $postable);
    }
}
