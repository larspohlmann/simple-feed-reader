<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NetworkFeedSubscriptions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class EntryFeedGroupScopeTest extends ApiTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testTheTagsGroupListsEveryTaggedFeedOnceAndNothingUntagged(): void
    {
        [$headers, $user] = $this->userAndBearer('group-tags@example.com');
        $this->seedGroups($user);

        $this->client->request('GET', '/api/entries?group=tags', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['tagged-social-post', 'hidden-tagged-post', 'twice-tagged-post'], $this->guidsOfResponse());
    }

    public function testTheFeedsGroupListsOnlyUntaggedFeedsOnNoNetwork(): void
    {
        [$headers, $user] = $this->userAndBearer('group-feeds@example.com');
        $this->seedGroups($user);

        $this->client->request('GET', '/api/entries?group=feeds', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['plain-post'], $this->guidsOfResponse());
    }

    public function testAGroupNeverListsAnotherUsersFeeds(): void
    {
        [$headers, $user] = $this->userAndBearer('group-own@example.com');
        $this->seedGroups($user);
        [, $stranger] = $this->userAndBearer('group-stranger@example.com');
        $foreign = $this->fixtures()->subscribe($stranger, 'https://example.org/foreign.xml');
        $this->fixtures()->post($foreign, 'foreign-post', '2026-10-09T00:00:00Z');

        $this->client->request('GET', '/api/entries?group=feeds', server: $headers);

        self::assertSame(['plain-post'], $this->guidsOfResponse());
    }

    public function testAnUnknownGroupIsAValidationError(): void
    {
        [$headers] = $this->userAndBearer('group-unknown@example.com');

        $this->client->request('GET', '/api/entries?group=folders', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            ['group' => ['Unknown group. Use one of: tags, feeds.']],
            $this->payload($this->client)['errors'],
        );
    }

    public function testMarkingTheFeedsGroupReadLeavesTaggedFeedsUnread(): void
    {
        [$headers, $user] = $this->userAndBearer('group-mark@example.com');
        $this->seedGroups($user);

        $this->markRead($headers, ['scope' => 'group', 'group' => 'feeds']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/entries?group=feeds&view=unread', server: $headers);
        self::assertSame([], $this->guidsOfResponse());
        $this->client->request('GET', '/api/entries?group=tags&view=unread', server: $headers);
        self::assertSame(['tagged-social-post', 'hidden-tagged-post', 'twice-tagged-post'], $this->guidsOfResponse());
    }

    public function testMarkingTheTagsGroupReadReachesFeedsHiddenFromAllItems(): void
    {
        [$headers, $user] = $this->userAndBearer('group-mark-tags@example.com');
        $this->seedGroups($user);

        $this->markRead($headers, ['scope' => 'group', 'group' => 'tags']);

        $this->client->request('GET', '/api/entries?group=tags&view=unread', server: $headers);
        self::assertSame([], $this->guidsOfResponse());
        $this->client->request('GET', '/api/entries?group=feeds&view=unread', server: $headers);
        self::assertSame(['plain-post'], $this->guidsOfResponse());
    }

    public function testMarkingAGroupReadWithoutAGroupIsAValidationError(): void
    {
        [$headers] = $this->userAndBearer('group-mark-missing@example.com');

        $this->markRead($headers, ['scope' => 'group']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            ['group' => ['A group is required when scope is "group".']],
            $this->payload($this->client)['errors'],
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $scope
     */
    private function markRead(array $headers, array $scope): void
    {
        $this->client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($scope + ['until' => '2026-10-12T00:00:00Z'], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Tagged: a feed with two tags (listed once), one hidden from All items (still listed), a tagged Bluesky profile.
     * Untagged: a plain feed (the Feeds group) and a Bluesky home timeline (social, so in neither group).
     */
    private function seedGroups(User $user): void
    {
        $fixtures = $this->fixtures();
        $news = $this->tag($user, 'news');
        $tech = $this->tag($user, 'tech');

        $twiceTagged = $fixtures->subscribe($user, 'https://example.com/twice.xml');
        $twiceTagged->addTag($news);
        $twiceTagged->addTag($tech);
        $fixtures->post($twiceTagged, 'twice-tagged-post', '2026-10-04T00:00:00Z');

        $hiddenTagged = $fixtures->subscribe($user, 'https://example.com/hidden.xml');
        $hiddenTagged->addTag($news);
        $hiddenTagged->setIncludeInAllItems(false);
        $fixtures->post($hiddenTagged, 'hidden-tagged-post', '2026-10-05T00:00:00Z');

        $taggedSocial = $fixtures->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $taggedSocial->addTag($tech);
        $fixtures->post($taggedSocial, 'tagged-social-post', '2026-10-06T00:00:00Z');

        $plain = $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN);
        $fixtures->post($plain, 'plain-post', '2026-10-07T00:00:00Z');

        $home = $fixtures->subscribe($user, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $fixtures->post($home, 'home-post', '2026-10-08T00:00:00Z');
        $this->entityManager()->flush();
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->entityManager()->persist($tag);
        $this->entityManager()->flush();

        return $tag;
    }

    /** @return list<string> */
    private function guidsOfResponse(): array
    {
        $entries = $this->payload($this->client)['entries'];
        self::assertIsArray($entries);

        return array_map(static function (mixed $entry): string {
            self::assertIsArray($entry);
            self::assertIsString($entry['title']);

            return $entry['title'];
        }, array_values($entries));
    }

    private function fixtures(): NetworkFeedSubscriptions
    {
        return new NetworkFeedSubscriptions($this->entityManager());
    }
}
