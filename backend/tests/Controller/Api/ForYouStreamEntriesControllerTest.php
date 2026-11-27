<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\ForYouStreamEntry;
use App\Entity\InclusionChange;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class ForYouStreamEntriesControllerTest extends ApiTestCase
{
    private KernelBrowser $client;
    private User $user;
    private Feed $feed;
    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->user = $this->factory()->create('stream-reader@example.com');
        $this->feed = $this->feedFor($this->user);
        $this->subscription = $this->entityManager()->getRepository(Subscription::class)->findOneBy(
            ['user' => $this->user, 'feed' => $this->feed],
        ) ?? throw new \LogicException('The seeded subscription is missing.');
    }

    public function testTheListIsTheCallersMatchesNewestFirst(): void
    {
        $older = $this->member('2026-11-27T08:00:00');
        $newer = $this->member('2026-11-27T09:00:00');
        $this->nonMember('2026-11-27T10:00:00');
        $other = $this->factory()->create('stream-other@example.com');
        $this->member('2026-11-27T11:00:00', $other, $this->feedFor($other));

        $this->get('/api/entries/for-you-stream');

        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        self::assertSame([$newer->requireId(), $older->requireId()], $this->ids());
    }

    public function testTheUnreadSwitchAndTheOldestOrderApply(): void
    {
        $older = $this->member('2026-11-27T08:00:00');
        $newer = $this->member('2026-11-27T09:00:00');
        $read = $this->member('2026-11-27T07:00:00');
        $state = new EntryState($this->user, $read);
        $state->hide(new \DateTimeImmutable('2026-11-27T12:00:00'));
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();

        $this->get('/api/entries/for-you-stream?unread=true&order=asc');

        self::assertSame([$older->requireId(), $newer->requireId()], $this->ids());
    }

    public function testTheCursorPagesThroughTheMatches(): void
    {
        $oldest = $this->member('2026-11-27T08:00:00');
        $middle = $this->member('2026-11-27T09:00:00');
        $newest = $this->member('2026-11-27T10:00:00');

        $this->get('/api/entries/for-you-stream?limit=2');
        $first = $this->payload($this->client);
        self::assertSame([$newest->requireId(), $middle->requireId()], $this->ids());
        self::assertIsString($first['nextCursor']);

        $this->get('/api/entries/for-you-stream?limit=2&cursor=' . urlencode($first['nextCursor']));

        self::assertSame([$oldest->requireId()], $this->ids());
    }

    public function testAFeedSwitchedOutOfForYouHidesItsMatches(): void
    {
        $this->member('2026-11-27T08:00:00');
        $this->subscription->changeInclusions(new InclusionChange(forYou: false));
        $this->entityManager()->flush();

        $this->get('/api/entries/for-you-stream');

        self::assertSame([], $this->ids());
    }

    public function testAnUnsubscribedFeedsMatchesAreHidden(): void
    {
        $this->member('2026-11-27T08:00:00');
        $this->entityManager()->remove($this->subscription);
        $this->entityManager()->flush();

        $this->get('/api/entries/for-you-stream');

        self::assertSame([], $this->ids());
    }

    public function testWithoutABearerTokenTheListIsRefused(): void
    {
        $this->client->request('GET', '/api/entries/for-you-stream');

        $this->assertRejected($this->client, Response::HTTP_UNAUTHORIZED);
    }

    public function testMarkReadMarksMatchesUpToUntilAndNothingElse(): void
    {
        $before = $this->member('2026-11-27T08:00:00');
        $after = $this->member('2026-11-27T10:00:00');
        $nonMember = $this->nonMember('2026-11-27T07:00:00');

        $this->post('/api/entries/for-you-stream/mark-read', ['until' => '2026-11-27T09:00:00Z']);

        self::assertSame(Response::HTTP_NO_CONTENT, $this->client->getResponse()->getStatusCode());
        $this->entityManager()->clear();
        self::assertTrue($this->isRead($before));
        self::assertFalse($this->isRead($after));
        self::assertFalse($this->isRead($nonMember));
    }

    public function testMarkReadLeavesAMatchTheSweepStoredAfterUntilUnread(): void
    {
        $matchedBefore = $this->member('2026-11-27T08:00:00', matchedAt: '2026-11-27 08:55:00');
        $matchedAfter = $this->member('2026-11-27T07:00:00', matchedAt: '2026-11-27 09:05:00');

        $this->post('/api/entries/for-you-stream/mark-read', ['until' => '2026-11-27T09:00:00Z']);

        $this->entityManager()->clear();
        self::assertTrue($this->isRead($matchedBefore));
        self::assertFalse($this->isRead($matchedAfter));
    }

    public function testMarkReadWithoutUntilIsUnprocessable(): void
    {
        $this->post('/api/entries/for-you-stream/mark-read', []);

        $this->assertRejected($this->client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function get(string $uri): void
    {
        $this->client->request('GET', $uri, server: $this->jsonBearerFor($this->user));
    }

    /** @param array<string, string> $body */
    private function post(string $uri, array $body): void
    {
        $this->client->request(
            'POST',
            $uri,
            server: $this->jsonBearerFor($this->user),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<mixed> */
    private function ids(): array
    {
        $entries = $this->payload($this->client)['entries'] ?? null;
        self::assertIsArray($entries);

        return array_column($entries, 'id');
    }

    private function feedFor(User $user): Feed
    {
        $feed = new Feed('https://example.com/stream-' . uniqid('', true) . '.xml');
        $feed->setTitle('Stream feed');
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager()->flush();

        return $feed;
    }

    private function nonMember(string $publishedAt, ?Feed $feed = null): Entry
    {
        $published = new \DateTimeImmutable($publishedAt . 'Z');
        $entry = new Entry(
            $feed ?? $this->feed,
            'guid-' . uniqid('', true),
            'https://example.com/' . uniqid('', true),
            'Stream entry',
            $published,
            $published,
        );
        $entry->setPublishedAt($published);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    private function member(
        string $publishedAt,
        ?User $user = null,
        ?Feed $feed = null,
        string $matchedAt = '2026-11-27 08:30:00',
    ): Entry {
        $entry = $this->nonMember($publishedAt, $feed);
        $this->entityManager()->persist(new ForYouStreamEntry(
            $user ?? $this->user,
            $entry,
            new \DateTimeImmutable($matchedAt),
            1.0,
            'stem',
        ));
        $this->entityManager()->flush();

        return $entry;
    }

    private function isRead(Entry $entry): bool
    {
        $state = $this->entityManager()->getRepository(EntryState::class)
            ->findOneForUserEntry($this->user->requireId(), $entry->requireId());

        return $state?->isHidden() === true;
    }
}
