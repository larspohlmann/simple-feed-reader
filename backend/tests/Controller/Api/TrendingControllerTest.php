<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\InclusionChange;
use App\Entity\LinkCardContent;
use App\Entity\Subscription;
use App\Entity\TrendingSnapshot;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\TrendingWindow;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\TrendingFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class TrendingControllerTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testListsTheReadersStoriesOfTheWindow(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-api@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($user);
        $alpha = $fixtures->feed('alpha');
        $beta = $fixtures->feed('beta');
        $gamma = $fixtures->feed('gamma');
        $fixtures->subscribe($user, $alpha, $beta, $gamma);
        $lead = $fixtures->entry($alpha, 'Lead', '2031-05-14 10:00:00');
        $second = $fixtures->entry($beta, 'Second', '2031-05-14 09:00:00');
        $third = $fixtures->entry($gamma, 'Third', '2031-05-14 08:00:00');
        $stored = $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $lead, $second, $third);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($this->client);
        self::assertSame('day', $payload['window']);
        self::assertNull($payload['builtAt']);
        self::assertIsArray($payload['stories']);
        self::assertCount(1, $payload['stories']);
        $story = $payload['stories'][0];
        self::assertIsArray($story);
        self::assertSame($lead->requireId(), $story['id']);
        self::assertSame('Lead', $story['title']);
        self::assertIsArray($story['duplicates']);
        self::assertSame(
            [$second->requireId(), $third->requireId()],
            array_map(
                static fn (mixed $outlet): mixed => \is_array($outlet) ? $outlet['id'] : null,
                $story['duplicates'],
            ),
        );
        self::assertSame(
            [
                'id' => $stored->requireId(),
                'outletCount' => 3,
                'viewed' => false,
                'shareCount' => 0,
                'followed' => false,
            ],
            $story['story'],
        );
        self::assertSame([], $story['shares']);
    }

    public function testEachStoryCarriesItsId(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-story-id@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($user);
        $feeds = [$fixtures->feed('alpha'), $fixtures->feed('beta'), $fixtures->feed('gamma')];
        $fixtures->subscribe($user, ...$feeds);
        $fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', ...array_map(
            static fn (Feed $feed): Entry => $fixtures->entry($feed, 'Older', '2031-05-14 09:00:00'),
            $feeds,
        ));
        $newer = $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...array_map(
            static fn (Feed $feed): Entry => $fixtures->entry($feed, 'Newer', '2031-05-14 10:00:00'),
            $feeds,
        ));

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $stories = $this->payload($this->client)['stories'];
        self::assertIsArray($stories);
        self::assertIsArray($stories[0]);
        self::assertIsArray($stories[0]['story']);
        self::assertSame($newer->requireId(), $stories[0]['story']['id']);
    }

    public function testAStoryNoFollowedOutletCarriesIsLedByItsMostSharedArticleWithItsSharesUnderIt(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-card-led@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($user);
        $ann = $fixtures->feed('ann', SocialNetwork::Mastodon);
        $ben = $fixtures->feed('ben', SocialNetwork::Bluesky);
        $cat = $fixtures->feed('cat', SocialNetwork::Bluesky);
        $fixtures->subscribe($user, $ann, $ben, $cat);
        $lighthouse = new LinkCardContent(
            'https://outlet.example/lighthouse',
            'Lighthouse keepers strike',
            'The keepers walked out at dawn.',
            'https://outlet.example/lighthouse.jpg',
            'Outlet',
        );
        $pictured = $fixtures->linkShare($ann, '2031-05-14 08:00:00', $lighthouse);
        $alsoSharing = $fixtures->linkShare($cat, '2031-05-14 09:00:00', $lighthouse);
        $other = $fixtures->linkShare($ben, '2031-05-14 10:00:00', new LinkCardContent(
            'https://other.example/keepers',
            'Keepers on strike',
            null,
            null,
            'Other',
        ));
        $stored = $fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$pictured, 'outlet.example'],
            [$alsoSharing, 'outlet.example'],
            [$other, 'other.example'],
        ]);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $story = self::onlyStoryOf($this->payload($this->client));
        self::assertSame($alsoSharing->requireId(), $story['id'], 'the newest share with a pictured card');
        self::assertIsArray($story['linkCards']);
        self::assertIsArray($story['linkCards'][0]);
        self::assertSame('Lighthouse keepers strike', $story['linkCards'][0]['title']);
        self::assertSame([$alsoSharing->requireId(), $pictured->requireId()], self::idsOf($story['shares']));
        self::assertSame([$other->requireId()], self::idsOf($story['duplicates']));
        self::assertIsArray($story['duplicates']);
        self::assertIsArray($story['duplicates'][0]);
        self::assertSame([$other->requireId()], self::idsOf($story['duplicates'][0]['shares']));
        self::assertSame(
            [
                'id' => $stored->requireId(),
                'outletCount' => 3,
                'viewed' => false,
                'shareCount' => 3,
                'followed' => false,
            ],
            $story['story'],
        );
    }

    public function testAPostSharingAFollowedOutletsArticleIsAShareOfItsRowNotASourceOrARow(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-article-led@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($user);
        $alpha = $fixtures->feed('alpha');
        $beta = $fixtures->feed('beta');
        $ann = $fixtures->feed('ann', SocialNetwork::Mastodon);
        $fixtures->subscribe($user, $alpha, $beta, $ann);
        $lead = $fixtures->article($alpha, 'Lead', '2031-05-14 09:00:00', 'https://alpha.example.com/lead');
        $second = $fixtures->entry($beta, 'Second', '2031-05-14 08:00:00');
        $post = $fixtures->linkShare($ann, '2031-05-14 10:00:00', new LinkCardContent(
            'https://alpha.example.com/lead?utm_source=social',
            'Lead',
            null,
            'https://alpha.example.com/lead.jpg',
            'Alpha',
        ));
        $stored = $fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$lead, 'alpha.example.com'],
            [$second, 'beta.example.com'],
            [$post, 'alpha.example.com'],
        ]);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $story = self::onlyStoryOf($this->payload($this->client));
        self::assertSame($lead->requireId(), $story['id']);
        self::assertSame([$post->requireId()], self::idsOf($story['shares']));
        self::assertSame([$second->requireId()], self::idsOf($story['duplicates']));
        self::assertSame(
            [
                'id' => $stored->requireId(),
                'outletCount' => 3,
                'viewed' => false,
                'shareCount' => 1,
                'followed' => false,
            ],
            $story['story'],
        );
    }

    public function testAStorysSourceCountIsTheOutletsItsCardShowsTheReader(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-shown-count@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($user);
        $alpha = $fixtures->feed('alpha');
        $beta = $fixtures->feed('beta');
        $switchedOut = $fixtures->feed('gamma');
        $ann = $fixtures->feed('ann', SocialNetwork::Mastodon);
        $fixtures->subscribe($user, $alpha, $beta, $switchedOut, $ann);
        $lead = $fixtures->entry($alpha, 'Lead', '2031-05-14 10:00:00');
        $stored = $fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$lead, 'alpha.example.com'],
            [$fixtures->entry($beta, 'Second', '2031-05-14 09:00:00'), 'beta.example.com'],
            [$fixtures->entry($switchedOut, 'Switched out', '2031-05-14 08:00:00'), 'gamma.example.com'],
            [$fixtures->entry($ann, 'A post without a story card', '2031-05-14 06:00:00'), null],
        ]);
        $this->switchOutOfTrending($user, $switchedOut);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $story = self::onlyStoryOf($this->payload($this->client));
        self::assertSame($lead->requireId(), $story['id']);
        self::assertCount(2, self::idsOf($story['duplicates']), 'a feed switched out after the build still shows');
        self::assertSame(
            [
                'id' => $stored->requireId(),
                'outletCount' => 3,
                'viewed' => false,
                'shareCount' => 0,
                'followed' => false,
            ],
            $story['story'],
        );
    }

    public function testAFollowedStoryCardSaysSo(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-followed-card@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($user);
        $feeds = [$fixtures->feed('alpha'), $fixtures->feed('beta'), $fixtures->feed('gamma')];
        $members = static fn (string $title): array => array_map(
            static fn (Feed $feed): Entry => $fixtures->entry($feed, $title, '2031-05-14 10:00:00'),
            $feeds,
        );
        $followed = $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...$members('Followed'));
        $fixtures->story(TrendingWindow::Day, '2031-05-14 09:00:00', ...$members('Not followed'));
        $fixtures->follow($user, $followed, '2031-05-14 10:00:00');

        self::assertSame([true, false], $this->followedFlagsOfTheDay($headers));
    }

    public function testBuiltAtIsSerialisedAsAtomInUtc(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-built@example.com');
        $this->entityManager()->persist(new TrendingSnapshot(
            $user,
            TrendingWindow::Week,
            new \DateTimeImmutable('2031-05-14 12:30:00'),
            10,
            10,
            0,
            5,
            1_000,
        ));
        $this->entityManager()->flush();

        $this->client->request('GET', '/api/trending?window=week', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('2031-05-14T12:30:00+00:00', $this->payload($this->client)['builtAt']);
    }

    public function testAMissingWindowMeansTheDay(): void
    {
        [$headers] = $this->bearerAndUser('trending-default@example.com');

        $this->client->request('GET', '/api/trending', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['window' => 'day', 'builtAt' => null, 'stories' => []], $this->payload($this->client));
    }

    public function testAReaderWithoutASnapshotSeesNoStoriesAndNoBuildDue(): void
    {
        [$headers, $reader] = $this->bearerAndUser('trending-unbuilt@example.com');
        [, $builtFor] = $this->bearerAndUser('trending-unbuilt-other@example.com');
        $fixtures = (new TrendingFixtures($this->entityManager()))->ownedBy($builtFor);
        $feeds = [$fixtures->feed('alpha'), $fixtures->feed('beta'), $fixtures->feed('gamma')];
        $fixtures->subscribe($reader, ...$feeds);
        $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', ...array_map(
            static fn (Feed $feed): Entry => $fixtures->entry($feed, 'Built for another', '2031-05-14 10:00:00'),
            $feeds,
        ));
        foreach (TrendingWindow::cases() as $window) {
            $this->entityManager()->persist(new TrendingSnapshot(
                $builtFor,
                $window,
                new \DateTimeImmutable('2031-05-14 10:00:00'),
                3,
                3,
                1,
                5,
                1_000,
            ));
        }
        $this->entityManager()->flush();

        $this->client->request('GET', '/api/trending?window=day', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertSame(['window' => 'day', 'builtAt' => null, 'stories' => []], $this->payload($this->client));

        $this->client->request('GET', '/api/trending/followed', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload($this->client)['nextBuildDueAt']);
    }

    public function testAnUnknownWindowIsAValidationProblem(): void
    {
        [$headers] = $this->bearerAndUser('trending-unknown@example.com');

        $this->client->request('GET', '/api/trending?window=month', server: $headers);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $errors = $this->payload($this->client)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertArrayHasKey('window', $errors);
    }

    public function testRejectsAnonymous(): void
    {
        $this->client->request('GET', '/api/trending?window=day');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<mixed> each card's `story.followed`, in the list's order
     */
    private function followedFlagsOfTheDay(array $headers): array
    {
        $this->client->request('GET', '/api/trending?window=day', server: $headers);
        self::assertResponseIsSuccessful();
        $stories = $this->payload($this->client)['stories'];
        self::assertIsArray($stories);

        return array_values(array_map(static function (mixed $story): mixed {
            self::assertIsArray($story);
            self::assertIsArray($story['story']);

            return $story['story']['followed'] ?? null;
        }, $stories));
    }

    private function switchOutOfTrending(User $user, Feed $feed): void
    {
        $subscription = $this->entityManager()->getRepository(Subscription::class)
            ->findOneBy(['user' => $user, 'feed' => $feed]);
        self::assertInstanceOf(Subscription::class, $subscription);
        $subscription->changeInclusions(new InclusionChange(trending: false));
        $this->entityManager()->flush();
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    private static function onlyStoryOf(array $payload): array
    {
        self::assertIsArray($payload['stories']);
        self::assertCount(1, $payload['stories']);
        self::assertIsArray($payload['stories'][0]);

        return $payload['stories'][0];
    }

    /** @return list<mixed> */
    private static function idsOf(mixed $rows): array
    {
        self::assertIsArray($rows);

        return array_values(array_map(static fn (mixed $row): mixed => \is_array($row) ? $row['id'] : null, $rows));
    }
}
