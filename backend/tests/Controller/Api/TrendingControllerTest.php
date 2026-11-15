<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\LinkCardContent;
use App\Entity\TrendingSnapshot;
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
        $fixtures = new TrendingFixtures($this->entityManager());
        $alpha = $fixtures->feed('alpha');
        $beta = $fixtures->feed('beta');
        $gamma = $fixtures->feed('gamma');
        $fixtures->subscribe($user, $alpha, $beta, $gamma);
        $lead = $fixtures->entry($alpha, 'Lead', '2031-05-14 10:00:00');
        $second = $fixtures->entry($beta, 'Second', '2031-05-14 09:00:00');
        $third = $fixtures->entry($gamma, 'Third', '2031-05-14 08:00:00');
        $fixtures->story(TrendingWindow::Day, '2031-05-14 10:00:00', $lead, $second, $third);

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
        self::assertSame(['outletCount' => 3, 'viewed' => false], $story['story']);
    }

    public function testAStoryTwoPostsCarryThroughTwoSitesIsLedByThePostWithAPicturedCard(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-card-led@example.com');
        $fixtures = new TrendingFixtures($this->entityManager());
        $ann = $fixtures->feed('ann', SocialNetwork::Mastodon);
        $ben = $fixtures->feed('ben', SocialNetwork::Bluesky);
        $fixtures->subscribe($user, $ann, $ben);
        $pictured = $fixtures->linkShare($ann, '2031-05-14 09:00:00', new LinkCardContent(
            'https://outlet.example/lighthouse',
            'Lighthouse keepers strike',
            'The keepers walked out at dawn.',
            'https://outlet.example/lighthouse.jpg',
            'Outlet',
        ));
        $plain = $fixtures->linkShare($ben, '2031-05-14 10:00:00', new LinkCardContent(
            'https://other.example/keepers',
            'Keepers on strike',
            null,
            null,
            'Other',
        ));
        $fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$pictured, 'outlet.example'],
            [$plain, 'other.example'],
        ]);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $story = self::onlyStoryOf($this->payload($this->client));
        self::assertSame($pictured->requireId(), $story['id']);
        self::assertIsArray($story['linkCards']);
        self::assertIsArray($story['linkCards'][0]);
        self::assertSame('Lighthouse keepers strike', $story['linkCards'][0]['title']);
        self::assertSame([$plain->requireId()], self::idsOf($story['duplicates']));
        self::assertSame(['outletCount' => 2, 'viewed' => false], $story['story']);
    }

    public function testAnArticleLeadsItsStoryAndAPostSharingItIsASource(): void
    {
        [$headers, $user] = $this->bearerAndUser('trending-article-led@example.com');
        $fixtures = new TrendingFixtures($this->entityManager());
        $alpha = $fixtures->feed('alpha');
        $beta = $fixtures->feed('beta');
        $ann = $fixtures->feed('ann', SocialNetwork::Mastodon);
        $fixtures->subscribe($user, $alpha, $beta, $ann);
        $lead = $fixtures->entry($alpha, 'Lead', '2031-05-14 09:00:00');
        $second = $fixtures->entry($beta, 'Second', '2031-05-14 08:00:00');
        $post = $fixtures->linkShare($ann, '2031-05-14 10:00:00', new LinkCardContent(
            'https://alpha.example.com/lead',
            'Lead',
            null,
            'https://alpha.example.com/lead.jpg',
            'Alpha',
        ));
        $fixtures->storyOfSites(TrendingWindow::Day, '2031-05-14 10:00:00', [
            [$lead, 'alpha.example.com'],
            [$second, 'beta.example.com'],
            [$post, 'alpha.example.com'],
        ]);

        $this->client->request('GET', '/api/trending?window=day', server: $headers);

        self::assertResponseIsSuccessful();
        $story = self::onlyStoryOf($this->payload($this->client));
        self::assertSame($lead->requireId(), $story['id']);
        self::assertSame([$post->requireId(), $second->requireId()], self::idsOf($story['duplicates']));
    }

    public function testBuiltAtIsSerialisedAsAtomInUtc(): void
    {
        [$headers] = $this->bearerAndUser('trending-built@example.com');
        $this->entityManager()->persist(new TrendingSnapshot(
            TrendingWindow::Week,
            new \DateTimeImmutable('2031-05-14 12:30:00'),
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
