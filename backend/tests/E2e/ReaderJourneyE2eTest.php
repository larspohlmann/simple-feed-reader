<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The reader API against real news sites (heise, tagesschau, spiegel): an unreachable site skips, and nothing asserts
 * content. Group external-site: the weekly rot check excludes it, since a datacenter IP gets other answers. It logs in
 * as the seeded admin, so it adds no registrations to the per-IP budget.
 */
#[Group('external-site')]
final class ReaderJourneyE2eTest extends E2eTestCase
{
    private const ADMIN_EMAIL = 'e2e-admin@example.com';
    private const ADMIN_PASSWORD = 'e2e-admin-password-123';

    /** The three homepages the reader API is exercised against. */
    private const DOMAINS = [
        'heise' => 'https://www.heise.de',
        'tagesschau' => 'https://www.tagesschau.de',
        'spiegel' => 'https://www.spiegel.de',
    ];

    /** Every reason FeedDiscoveryResultModel can name, and the dialog can word. */
    private const array SCRAPE_FAILURE_REASONS = ['blocked', 'throttled', 'unreachable', 'not_scrapable'];

    /** One admin login for the whole class — the JWT is cached here. */
    private static ?string $adminJwt = null;

    /**
     * Posting a homepage answers 200 with well-formed candidates: its advertised feeds, or the 'scraped' fallback the
     * seeded admin is offered. An empty list is the site's doing and skips; a scrapeFailureReason is checked if given.
     */
    #[DataProvider('provideDomains')]
    public function testHomepageFeedDiscovery(string $label, string $homepage): void
    {
        $this->skipUnlessReachable($label, $homepage);

        $response = $this->postJson('/api/subscriptions', ['url' => $homepage], $this->adminJwt());
        $status = $response->getStatusCode();

        self::assertSame(200, $status, $label . ' homepage discovery should return 200 candidates');

        if ([] === ($response->toArray()['candidates'] ?? [])) {
            $reason = $response->toArray()['scrapeFailureReason'] ?? null;
            if (null !== $reason) {
                self::assertContains(
                    $reason,
                    self::SCRAPE_FAILURE_REASONS,
                    $label . ' a scrapeFailureReason must be one the dialog can render',
                );
            }

            self::markTestSkipped(sprintf(
                '%s homepage offered no candidates (%s)',
                $label,
                \is_string($reason) ? $reason : 'no reason given',
            ));
        }

        $candidates = $response->toArray()['candidates'] ?? null;
        self::assertIsArray($candidates, $label . ' response must carry a candidates array');
        self::assertNotEmpty($candidates, $label . ' homepage should advertise at least one feed');

        foreach ($candidates as $candidate) {
            self::assertIsArray($candidate);
            self::assertArrayHasKey('url', $candidate);
            self::assertArrayHasKey('title', $candidate);
            self::assertIsString($candidate['url'], 'every candidate needs a string url');
            self::assertNotSame('', trim($candidate['url']), 'every candidate needs a non-empty url');
        }
    }

    /**
     * Discover, subscribe, refresh to completion, and see the feed's <title> resolve. It takes the first candidate that
     * resolves one, since not every real feed does (tagesschau's Atom 0.3 stays untitled), and skips if none is found.
     */
    public function testSubscribeRefreshAndResolveTitle(): void
    {
        $token = $this->adminJwt();

        $candidates = $this->discoverFeedCandidates($token);
        if ([] === $candidates) {
            self::markTestSkipped('No reachable news homepage offered a feed candidate');
        }

        $unresolved = [];

        foreach ($candidates as [$label, $candidateUrl, $candidateFormat]) {
            // Clean slate so the admin holds exactly the one feed under test.
            $this->deleteAllSubscriptions($token);

            $created = $this->postJson(
                '/api/subscriptions',
                $this->subscribeBody($candidateUrl, $candidateFormat),
                $token,
            );
            if (422 === $created->getStatusCode()) {
                // The feed became unreachable between discovery and subscribe —
                // an external hiccup, not a stack fault. Try the next candidate.
                $unresolved[] = $label . ' subscribe 422';
                continue;
            }
            self::assertSame(201, $created->getStatusCode(), $label . ' concrete feed URL should subscribe (201)');

            $subscription = $created->toArray()['subscription'] ?? null;
            self::assertIsArray($subscription, 'a 201 must carry the created subscription');

            $subscriptionId = $subscription['id'] ?? null;
            $feedUrl = $subscription['feedUrl'] ?? null;
            self::assertIsInt($subscriptionId, 'the subscription needs an integer id');
            self::assertIsString($feedUrl, 'the subscription needs a feedUrl');

            $report = $this->driveRefreshToCompletion($token);
            self::assertSame(
                0,
                $report['remaining'] ?? null,
                'refresh loop should reach remaining=0; last=' . (string) json_encode($report),
            );

            $mine = $this->findSubscription($token, $subscriptionId);
            self::assertNotNull($mine, 'the new subscription should appear in the list');

            $title = $mine['title'] ?? null;
            self::assertIsString($title, 'a subscription should carry a string title');

            // A title other than the URL proves fetch, parse and ingest ran. A rerun inside the 5-minute cooldown
            // fetches nothing, but the earlier run's title still counts.
            if ('' !== trim($title) && $title !== $feedUrl) {
                self::assertNotSame($feedUrl, $title, 'title should be the ingested feed <title>, not the feedUrl');
                $this->deleteSubscription($token, $subscriptionId); // one verified journey is enough
                return;
            }

            $this->deleteSubscription($token, $subscriptionId);
            $unresolved[] = $label . ' <' . $candidateUrl . '> title unresolved';
        }

        self::markTestSkipped('No discovered feed resolved a title (' . implode('; ', $unresolved) . ')');
    }

    /**
     * Against a live ingested feed: entries, read and favourite state and the views that follow them, the unread count,
     * mark-all-read, and an OPML export re-imported as already subscribed. A candidate with no entries is skipped.
     */
    public function testReaderSurfaceEntriesStateMarkReadAndOpml(): void
    {
        $token = $this->adminJwt();

        $candidates = $this->discoverFeedCandidates($token);
        if ([] === $candidates) {
            self::markTestSkipped('No reachable news homepage offered a feed candidate');
        }

        $notes = [];

        foreach ($candidates as [$label, $candidateUrl, $candidateFormat]) {
            // Clean slate so the admin holds exactly the one feed under test.
            $this->deleteAllSubscriptions($token);

            $created = $this->postJson(
                '/api/subscriptions',
                $this->subscribeBody($candidateUrl, $candidateFormat),
                $token,
            );
            if (201 !== $created->getStatusCode()) {
                // The feed became unreachable between discovery and subscribe —
                // an external hiccup, not a stack fault. Try the next candidate.
                $notes[] = $label . ' subscribe ' . $created->getStatusCode();
                continue;
            }

            $subscription = $created->toArray()['subscription'] ?? null;
            self::assertIsArray($subscription, 'a 201 must carry the created subscription');

            $subscriptionId = $subscription['id'] ?? null;
            self::assertIsInt($subscriptionId, 'the subscription needs an integer id');

            $report = $this->driveRefreshToCompletion($token);
            self::assertNotSame(
                'aborted',
                $report['status'] ?? null,
                'refresh aborted: ' . (string) json_encode($report),
            );

            // A feed that ingested nothing this run (empty, or Atom 0.3 which the
            // parser fetches but leaves without usable items) is not a stack
            // fault — move on to the next candidate.
            $entries = $this->getJson(
                '/api/entries?subscription=' . $subscriptionId,
                $token,
            )->toArray()['entries'] ?? null;
            if (!is_array($entries) || [] === $entries) {
                $notes[] = $label . ' no entries';
                continue;
            }

            // The entry list carries the reader read-model shape for each row.
            $first = $entries[0];
            self::assertIsArray($first);
            self::assertArrayHasKey('id', $first);
            self::assertArrayHasKey('isHidden', $first);
            self::assertArrayHasKey('source', $first);

            $entryId = $first['id'];
            self::assertIsInt($entryId);

            $unreadPath = '/api/entries?subscription=' . $subscriptionId . '&view=unread';

            // (a) Force it unread: EntryState rows outlive the subscription, so an earlier run may have left it read.
            $unreadResponse = $this->patchState($token, $entryId, ['isHidden' => false]);
            self::assertSame(200, $unreadResponse->getStatusCode());
            $unreadState = $unreadResponse->toArray()['state'] ?? null;
            self::assertIsArray($unreadState);
            self::assertFalse($unreadState['isHidden'] ?? null, 'PATCH isHidden=false clears the read flag');
            self::assertContains(
                $entryId,
                $this->entryIds($token, $unreadPath),
                'an unread entry appears in the unread view',
            );

            // (b) Mark it READ → it drops out of the unread view.
            $readResponse = $this->patchState($token, $entryId, ['isHidden' => true]);
            self::assertSame(200, $readResponse->getStatusCode());
            $readState = $readResponse->toArray()['state'] ?? null;
            self::assertIsArray($readState);
            self::assertTrue($readState['isHidden'] ?? null, 'PATCH isHidden=true sets the read flag');
            self::assertNotContains(
                $entryId,
                $this->entryIds($token, $unreadPath),
                'a read entry must not appear in the unread view',
            );

            // (c) Favorite it → it appears in the favorites view.
            $this->patchState($token, $entryId, ['isFavorite' => true]);
            $favoriteIds = $this->entryIds($token, '/api/entries?view=favorites');
            self::assertContains($entryId, $favoriteIds, 'the favorited entry should appear in the favorites view');

            // (d) The per-subscription unread count is surfaced on the list.
            $mine = $this->findSubscription($token, $subscriptionId);
            self::assertNotNull($mine, 'the new subscription should appear in the list');
            self::assertArrayHasKey('unreadCount', $mine);

            // (e) mark-read all → the unread view empties.
            $markRead = $this->postJson('/api/entries/mark-read', [
                'scope' => 'all',
                'until' => (new \DateTimeImmutable('+1 day'))->format(DATE_ATOM),
            ], $token);
            self::assertSame(204, $markRead->getStatusCode());
            self::assertSame(
                [],
                $this->entryIds($token, '/api/entries?view=unread'),
                'after mark-read all, nothing is unread',
            );

            // (f) OPML export lists the feed, and re-importing that export is a
            // no-op the importer recognises as already subscribed.
            $export = $this->http->request('GET', '/api/opml/export', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            self::assertSame(200, $export->getStatusCode());

            $opml = $export->getContent();
            self::assertStringContainsString('xmlUrl=', $opml, 'export should list the subscribed feed');

            $reimport = $this->http->request('POST', '/api/opml/import', [
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'text/x-opml'],
                'body' => $opml,
            ]);
            self::assertSame(200, $reimport->getStatusCode());
            self::assertGreaterThanOrEqual(
                1,
                $reimport->toArray()['alreadySubscribed'] ?? 0,
                're-importing the export must recognise the feed as already subscribed',
            );

            $this->deleteAllSubscriptions($token); // one full journey is enough
            return;
        }

        self::markTestSkipped(
            'No reachable feed yielded entries to exercise the reader surface (' . implode('; ', $notes) . ')',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideDomains(): iterable
    {
        foreach (self::DOMAINS as $label => $homepage) {
            yield $label => [$label, $homepage];
        }
    }

    /**
     * POST /api/refresh in a loop until the run reports nothing left to do.
     * `busy` means the global refresh lock is held elsewhere — wait briefly and
     * retry; `aborted` is a terminal persistence failure and fails fast.
     *
     * @return array<array-key, mixed>
     */
    private function driveRefreshToCompletion(string $token): array
    {
        $report = [];

        for ($attempt = 0; $attempt < 6; ++$attempt) {
            $response = $this->postJson('/api/refresh', [], $token);
            self::assertSame(200, $response->getStatusCode(), 'refresh always answers 200');

            $report = $response->toArray();
            self::assertNotSame(
                'aborted',
                $report['status'] ?? null,
                'refresh aborted: ' . (string) json_encode($report),
            );

            if ('busy' === ($report['status'] ?? null)) {
                usleep(500_000);
                continue;
            }

            if (0 === ($report['remaining'] ?? -1)) {
                break;
            }
        }

        return $report;
    }

    /** @return array{url: string, format?: string} */
    private function subscribeBody(string $url, ?string $format): array
    {
        return null === $format ? ['url' => $url] : ['url' => $url, 'format' => $format];
    }

    /**
     * Every candidate the reachable homepages advertise, as [label, url, format] in domain order; format is null for a
     * direct feed URL.
     *
     * @return list<array{string, string, string|null}>
     */
    private function discoverFeedCandidates(string $token): array
    {
        $found = [];

        foreach (self::DOMAINS as $label => $homepage) {
            if (!$this->isReachable($homepage)) {
                continue;
            }

            $response = $this->postJson('/api/subscriptions', ['url' => $homepage], $token);
            if (200 !== $response->getStatusCode()) {
                continue;
            }

            $candidates = $response->toArray()['candidates'] ?? [];
            if (!is_array($candidates)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $url = is_array($candidate) ? ($candidate['url'] ?? null) : null;
                if (is_array($candidate) && is_string($url) && '' !== trim($url)) {
                    // Carry the format: a 'scraped' candidate's URL is the homepage
                    // itself, so re-posting it without the format just re-runs
                    // discovery (200 candidates) instead of subscribing (201).
                    $format = $candidate['format'] ?? null;
                    $found[] = [$label, $url, is_string($format) ? $format : null];
                }
            }
        }

        return $found;
    }

    /**
     * The caller's own subscription row with the given id, or null.
     *
     * @return array<array-key, mixed>|null
     */
    private function findSubscription(string $token, int $id): ?array
    {
        $subscriptions = $this->getJson('/api/subscriptions', $token)->toArray()['subscriptions'] ?? [];
        self::assertIsArray($subscriptions);

        foreach ($subscriptions as $subscription) {
            if (is_array($subscription) && ($subscription['id'] ?? null) === $id) {
                return $subscription;
            }
        }

        return null;
    }

    /** Remove every subscription the admin currently holds, for a clean slate. */
    private function deleteAllSubscriptions(string $token): void
    {
        $subscriptions = $this->getJson('/api/subscriptions', $token)->toArray()['subscriptions'] ?? [];
        self::assertIsArray($subscriptions);

        foreach ($subscriptions as $subscription) {
            $id = is_array($subscription) ? ($subscription['id'] ?? null) : null;
            if (is_int($id)) {
                $this->deleteSubscription($token, $id);
            }
        }
    }

    private function deleteSubscription(string $token, int $id): void
    {
        $response = $this->http->request('DELETE', '/api/subscriptions/' . $id, [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        self::assertSame(204, $response->getStatusCode(), 'deleting a subscription should return 204');
    }

    /**
     * PATCH an entry's reader state (there is no `patchJson` helper on the base
     * case), returning the raw response so callers can assert on it.
     *
     * @param array<string, bool> $changes
     */
    private function patchState(string $token, int $entryId, array $changes): ResponseInterface
    {
        return $this->http->request('PATCH', '/api/entries/' . $entryId . '/state', [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body' => json_encode($changes, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * The `id` of every entry an `/api/entries` list path returns, for order-
     * independent membership checks against a view.
     *
     * @return array<mixed>
     */
    private function entryIds(string $token, string $path): array
    {
        $entries = $this->getJson($path, $token)->toArray()['entries'] ?? null;
        self::assertIsArray($entries);

        return array_map(
            static fn ($entry) => is_array($entry) ? ($entry['id'] ?? null) : null,
            $entries,
        );
    }

    private function skipUnlessReachable(string $label, string $url): void
    {
        if (!$this->isReachable($url)) {
            self::markTestSkipped($label . ' (' . $url . ') unreachable');
        }
    }

    /**
     * A short GET to the public site with a fresh client, never the app's; a transport error, a timeout or a status
     * outside 2xx and 3xx counts as down.
     */
    private function isReachable(string $url): bool
    {
        try {
            $status = HttpClient::create()->request('GET', $url, [
                'timeout' => 8,
                'max_duration' => 12,
                'headers' => ['User-Agent' => 'SimpleFeedReader-E2E-Probe/1.0'],
            ])->getStatusCode();
        } catch (TransportExceptionInterface) {
            return false;
        }

        return $status >= 200 && $status < 400;
    }

    private function adminJwt(): string
    {
        return self::$adminJwt ??= $this->login(self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
    }
}
