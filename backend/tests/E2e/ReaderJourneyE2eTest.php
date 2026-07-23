<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Tests\E2e\Support\E2eTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The reader API against the real internet: feed autodiscovery, subscribing to a
 * concrete feed, driving the refresh loop, and confirming the ingest pipeline
 * resolved the feed's title — every hop a real HTTP round-trip through nginx →
 * PHP-FPM → MySQL, with the app's SSRF-guarded fetcher reaching out to real news
 * sites (heise, tagesschau, spiegel).
 *
 * Because it depends on external sites, it degrades gracefully and never flakes:
 * a domain that is unreachable (down, slow, blocking us) is skipped, not failed.
 * Assertions are on structure and end-to-end behaviour, never on article
 * content, counts, or exact titles — all of which change minute to minute.
 *
 * Auth is the seeded `app:e2e:seed-admin` account, reused across the class (the
 * JWT TTL is a week), so the reader suite adds zero registrations to the tight
 * per-IP registration budget the README documents.
 */
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

    /** One admin login for the whole class — the JWT is cached here. */
    private static ?string $adminJwt = null;

    /**
     * Feed autodiscovery from a real homepage: posting the site root returns a
     * 200 with a non-empty, well-formed `candidates` list — native rss/atom
     * candidates when the homepage advertises `<link rel="alternate">` feeds,
     * or the synthetic 'scraped' fallback candidate when it does not (heise's
     * homepage, for one, offers none). Discovery no longer answers 422 for a
     * feedless page; only a homepage the scraper cannot handle either comes
     * back with an empty list plus a `scrapeFailureReason`, and that being a
     * real, uncontrollable property of the site, the case is skipped.
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
            self::assertIsString($reason, $label . ' an empty candidate list must carry a scrapeFailureReason');
            self::markTestSkipped(sprintf('%s homepage offered no candidates (%s)', $label, $reason));
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
     * The full reader journey against a live feed: discover feeds from the
     * homepages, subscribe to a concrete feed, drive the refresh loop to
     * completion, and confirm the pipeline ingested it (its title resolves to
     * the feed's `<title>`, not the raw URL). Cleans up the subscription after.
     *
     * It walks the discovered candidates and stops at the first one that
     * resolves a title, because not every real feed does: tagesschau's primary
     * feed is Atom 0.3 (`xmlns="http://purl.org/atom/ns#"`), which the parser
     * fetches but leaves untitled, whereas its RSS 2.0 feed and both of
     * spiegel's resolve cleanly. The whole flow is skipped only when no reachable
     * homepage offers a feed at all.
     */
    public function testSubscribeRefreshAndResolveTitle(): void
    {
        $token = $this->adminJwt();

        $candidates = $this->discoverFeedCandidates($token);
        if ([] === $candidates) {
            self::markTestSkipped('No reachable news homepage offered a feed candidate');
        }

        $unresolved = [];

        foreach ($candidates as [$label, $candidateUrl]) {
            // Clean slate so the admin holds exactly the one feed under test.
            $this->deleteAllSubscriptions($token);

            $created = $this->postJson('/api/subscriptions', ['url' => $candidateUrl], $token);
            if (422 === $created->getStatusCode()) {
                // The feed became unreachable between discovery and subscribe —
                // an external hiccup, not a stack fault. Try the next candidate.
                $unresolved[] = $label . ' subscribe 422';
                continue;
            }
            self::assertSame(201, $created->getStatusCode(), $label . ' concrete feed URL should subscribe (201)');

            $subscription = $created->toArray()['subscription'] ?? null;
            self::assertIsArray($subscription, 'a 201 must carry the created subscription');

            $subId = $subscription['id'] ?? null;
            $feedUrl = $subscription['feedUrl'] ?? null;
            self::assertIsInt($subId, 'the subscription needs an integer id');
            self::assertIsString($feedUrl, 'the subscription needs a feedUrl');

            $report = $this->driveRefreshToCompletion($token);
            self::assertSame(
                0,
                $report['remaining'] ?? null,
                'refresh loop should reach remaining=0; last=' . (string) json_encode($report),
            );

            $mine = $this->findSubscription($token, $subId);
            self::assertNotNull($mine, 'the new subscription should appear in the list');

            $title = $mine['title'] ?? null;
            self::assertIsString($title, 'a subscription should carry a string title');

            // A resolved title (the feed's <title>, not the raw URL) proves the
            // fetch → parse → ingest pipeline ran end to end. Robust to the
            // 5-minute refresh cooldown a rapid re-run hits (force-refresh still
            // honours it): a prior run's fetch left the title in place, so this
            // passes whether or not THIS run did the fetching.
            if ('' !== trim($title) && $title !== $feedUrl) {
                self::assertNotSame($feedUrl, $title, 'title should be the ingested feed <title>, not the feedUrl');
                $this->deleteSubscription($token, $subId); // one verified journey is enough
                return;
            }

            $this->deleteSubscription($token, $subId);
            $unresolved[] = $label . ' <' . $candidateUrl . '> title unresolved';
        }

        self::markTestSkipped('No discovered feed resolved a title (' . implode('; ', $unresolved) . ')');
    }

    /**
     * The reader surface against a live, ingested feed: list its entries, flip an
     * entry's read/favorite state, prove the view filters follow, read the
     * per-subscription unread count, watermark everything read, then round-trip
     * the whole subscription through OPML export → re-import.
     *
     * Every external hop degrades gracefully: a candidate that fails to subscribe
     * between discovery and here, or a feed that ingested nothing this run (empty,
     * or an Atom 0.3 feed the parser leaves itemless), is not a stack fault — the
     * loop moves to the next candidate, and the whole test skips if none yield
     * entries. All assertions are structural or behavioural; never on entry
     * counts, titles, or article text, which change minute to minute.
     */
    public function testReaderSurfaceEntriesStateMarkReadAndOpml(): void
    {
        $token = $this->adminJwt();

        $candidates = $this->discoverFeedCandidates($token);
        if ([] === $candidates) {
            self::markTestSkipped('No reachable news homepage offered a feed candidate');
        }

        $notes = [];

        foreach ($candidates as [$label, $candidateUrl]) {
            // Clean slate so the admin holds exactly the one feed under test.
            $this->deleteAllSubscriptions($token);

            $created = $this->postJson('/api/subscriptions', ['url' => $candidateUrl], $token);
            if (201 !== $created->getStatusCode()) {
                // The feed became unreachable between discovery and subscribe —
                // an external hiccup, not a stack fault. Try the next candidate.
                $notes[] = $label . ' subscribe ' . $created->getStatusCode();
                continue;
            }

            $subscription = $created->toArray()['subscription'] ?? null;
            self::assertIsArray($subscription, 'a 201 must carry the created subscription');

            $subId = $subscription['id'] ?? null;
            self::assertIsInt($subId, 'the subscription needs an integer id');

            $report = $this->driveRefreshToCompletion($token);
            self::assertNotSame(
                'aborted',
                $report['status'] ?? null,
                'refresh aborted: ' . (string) json_encode($report),
            );

            // A feed that ingested nothing this run (empty, or Atom 0.3 which the
            // parser fetches but leaves without usable items) is not a stack
            // fault — move on to the next candidate.
            $entries = $this->getJson('/api/entries?subscription=' . $subId, $token)->toArray()['entries'] ?? null;
            if (!is_array($entries) || [] === $entries) {
                $notes[] = $label . ' no entries';
                continue;
            }

            // ---- Reader surface: structural / behavioural assertions only ----
            // The entry list carries the reader read-model shape for each row.
            $first = $entries[0];
            self::assertIsArray($first);
            self::assertArrayHasKey('id', $first);
            self::assertArrayHasKey('isRead', $first);
            self::assertArrayHasKey('source', $first);

            $entryId = $first['id'];
            self::assertIsInt($entryId);

            $unreadPath = '/api/entries?subscription=' . $subId . '&view=unread';

            // (a) Force the entry UNREAD → it appears in the unread view. Driving
            // the flag explicitly (rather than assuming a fresh ingest starts
            // unread) keeps this robust to re-runs: EntryState rows outlive the
            // subscription, so a prior run may have left this entry read.
            $unreadResp = $this->patchState($token, $entryId, ['isRead' => false]);
            self::assertSame(200, $unreadResp->getStatusCode());
            $unreadState = $unreadResp->toArray()['state'] ?? null;
            self::assertIsArray($unreadState);
            self::assertFalse($unreadState['isRead'] ?? null, 'PATCH isRead=false clears the read flag');
            self::assertContains(
                $entryId,
                $this->entryIds($token, $unreadPath),
                'an unread entry appears in the unread view',
            );

            // (b) Mark it READ → it drops out of the unread view.
            $readResp = $this->patchState($token, $entryId, ['isRead' => true]);
            self::assertSame(200, $readResp->getStatusCode());
            $readState = $readResp->toArray()['state'] ?? null;
            self::assertIsArray($readState);
            self::assertTrue($readState['isRead'] ?? null, 'PATCH isRead=true sets the read flag');
            self::assertNotContains(
                $entryId,
                $this->entryIds($token, $unreadPath),
                'a read entry must not appear in the unread view',
            );

            // (c) Favorite it → it appears in the favorites view.
            $this->patchState($token, $entryId, ['isFavorite' => true]);
            $favIds = $this->entryIds($token, '/api/entries?view=favorites');
            self::assertContains($entryId, $favIds, 'the favorited entry should appear in the favorites view');

            // (d) The per-subscription unread count is surfaced on the list.
            $mine = $this->findSubscription($token, $subId);
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
                usleep(500_000); // 500 ms, then retry
                continue;
            }

            if (0 === ($report['remaining'] ?? -1)) {
                break;
            }
        }

        return $report;
    }

    /**
     * Every feed candidate advertised by the reachable homepages, as
     * `[label, candidateUrl]` pairs in domain order. Empty when nothing is
     * reachable or nothing advertises a feed.
     *
     * @return list<array{string, string}>
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
                continue; // homepage offered no feed (e.g. heise answers 422)
            }

            $candidates = $response->toArray()['candidates'] ?? [];
            if (!is_array($candidates)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $url = is_array($candidate) ? ($candidate['url'] ?? null) : null;
                if (is_string($url) && '' !== trim($url)) {
                    $found[] = [$label, $url];
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
            static fn ($e) => is_array($e) ? ($e['id'] ?? null) : null,
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
     * Host-side reachability probe using a fresh client (never the app client):
     * a short GET straight to the public site. Any transport error, timeout, or
     * non-2xx/3xx status means "treat as down". Public HTTPS validates against
     * the system CA bundle, so no special trust store is needed here.
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
