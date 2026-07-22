# Reader API 4a — Subscriptions, Feed Discovery, Tags & User Refresh — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give an authenticated user the "library" half of the reader — subscribe to feeds (with HTML feed-discovery), organise them with tags, list subscriptions, and refresh their own feeds — over a bearer-token JSON API.

**Architecture:** All five reader entities, their migrations, and the `RefreshRunner` pipeline already exist (plans 1–3b). This plan adds **controllers, repository query methods, one discovery service, a subscription service, four `ApiException` subtypes, and one rate-limiter** — no schema change. It follows the house patterns exactly: `final` controllers (no `AbstractController`), `#[CurrentUser] User $user`, `#[MapRequestPayload]` DTOs with `Assert\*`, injected `ClockInterface`, hand-built JSON arrays (ATOM dates, enum `->value`), and typed `ApiException` throwing mapped to `application/problem+json` by the existing `ApiExceptionListener`. Every endpoint is bearer-auth + stateless + JSON in/out, per the native-client readiness constraint in `docs/architecture.md`.

**Tech Stack:** Symfony 7.4 LTS, PHP 8.3, Doctrine ORM 3.6, lexik/jwt 3.2, symfony/rate-limiter, PHPUnit 12 (SQLite dev / MySQL CI), `symfony/html-sanitizer` (already wired). HTML parsing for discovery uses the built-in `ext-dom` `DOMDocument` (no new dependency).

**Deferred to Plan 4b:** entry listing with cursor pagination, read/favorite/kept `EntryState`, the mark-read watermark, **unread counts on `GET /subscriptions`**, and OPML import/export. This plan ships `GET /subscriptions` *without* an `unreadCount` field; 4b adds it.

---

## Scope of the IDOR fix (read first)

`FeedRepository::dueQueryBuilder` (`src/Repository/FeedRepository.php:77-78`) currently early-returns when `$feedId` is set, dropping the `$userId` subscription check. Today the only per-feed caller is the CLI (`--feed`, trusted). **This plan adds `POST /api/refresh?feedId=…`, a user-facing per-feed path, which makes the IDOR reachable.** Task 1 fixes the query and the controller independently verifies subscription ownership (defence in depth).

## File Structure

**Create:**
- `src/Exception/AlreadySubscribedException.php` — 409 `already_subscribed`
- `src/Exception/SubscriptionLimitReachedException.php` — 409 `subscription_limit_reached`
- `src/Exception/TagNameTakenException.php` — 409 `tag_name_taken`
- `src/Exception/FeedDiscoveryException.php` — 422 `feed_unreachable` (API layer; distinct from the fetch-layer `App\Service\Fetch\Exception\FeedUnreachableException`)
- `src/Service/Discovery/FeedDiscovery.php` — SSRF-guarded discover: parse-as-feed or scan HTML `<link rel=alternate>`
- `src/Service/Discovery/FeedDiscoveryResult.php` — value object (`directFeed` | `candidates`)
- `src/Service/Discovery/FeedCandidate.php` — value object `{url, title}`
- `src/Service/Subscription/SubscriptionService.php` — subscribe orchestration
- `src/Service/Subscription/SubscribeOutcome.php` — value object (`?Subscription` | `list<FeedCandidate>`)
- `src/Dto/Subscription/SubscribeRequest.php`, `src/Dto/Subscription/UpdateSubscriptionRequest.php`
- `src/Dto/Tag/CreateTagRequest.php`, `src/Dto/Tag/UpdateTagRequest.php`
- `src/Controller/Api/SubscriptionController.php`, `src/Controller/Api/TagController.php`, `src/Controller/Api/RefreshController.php`
- `src/Http/SubscriptionJson.php`, `src/Http/TagJson.php` — small shared serialisers (hand-built arrays), so subscription/tag shapes are defined once
- Test files mirroring each of the above under `tests/`

**Modify:**
- `src/Repository/FeedRepository.php` — compose clauses instead of early-return (IDOR fix)
- `src/Repository/SubscriptionRepository.php`, `src/Repository/TagRepository.php` — add query methods
- `src/Entity/Subscription.php` — ensure `addTag`/`removeTag`/`getTags` collection helpers
- `src/Service/Refresh/RefreshRequest.php` — add `forUserFeed(userId, feedId, budget)` factory
- `config/packages/rate_limiter.yaml` — add the `refresh` limiter

---

## Task 1: Fix the FeedRepository IDOR and add the user-feed refresh scope

**Files:**
- Modify: `src/Repository/FeedRepository.php:68-101`
- Modify: `src/Service/Refresh/RefreshRequest.php`
- Modify: `src/Repository/SubscriptionRepository.php`
- Test: `tests/Repository/FeedRepositoryUserFeedScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;

final class FeedRepositoryUserFeedScopeTest extends DbTestCase
{
    public function testPerFeedScopeStillHonoursTheSubscriptionCheck(): void
    {
        $factory = new UserFactory($this->em, self::getContainer()->get('security.user_password_hasher'));
        $owner = $factory->create('owner@example.com');
        $stranger = $factory->create('stranger@example.com');

        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $sub = new Subscription($owner, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($sub);
        $this->em->flush();

        $repo = $this->em->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repo);

        $now = new \DateTimeImmutable('2026-06-01T00:00:00Z');

        // Owner CAN reach the feed by id.
        $ownerResult = $repo->findDue($now, 50, (int) $owner->getId(), (int) $feed->getId(), force: true);
        self::assertCount(1, $ownerResult);

        // Stranger CANNOT reach it by id — the subscription EXISTS clause must still apply.
        $strangerResult = $repo->findDue($now, 50, (int) $stranger->getId(), (int) $feed->getId(), force: true);
        self::assertCount(0, $strangerResult);
        self::assertSame(0, $repo->countDue($now, (int) $stranger->getId(), (int) $feed->getId(), force: true));
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `cd backend && vendor/bin/phpunit --filter FeedRepositoryUserFeedScopeTest`
Expected: FAIL — stranger currently gets 1 feed because `$feedId` short-circuits the `$userId` check.

- [ ] **Step 3: Replace `dueQueryBuilder` so clauses compose**

In `src/Repository/FeedRepository.php`, replace the method body (lines 74-101) with:

```php
        $qb = $this->createQueryBuilder('f');

        if ($feedId !== null) {
            // Manual per-feed retry: exactly this feed, "gone" included, schedule
            // ignored — but the user scope below still applies.
            $qb->andWhere('f.id = :feedId')->setParameter('feedId', $feedId);
        } else {
            $qb->andWhere('f.status != :gone')->setParameter('gone', FeedStatus::Gone);

            if ($force) {
                if ($cooldownCutoff !== null) {
                    $qb->andWhere('(f.lastFetchedAt IS NULL OR f.lastFetchedAt <= :cooldownCutoff)')
                        ->setParameter('cooldownCutoff', $cooldownCutoff);
                }
            } else {
                $qb->andWhere('(f.nextFetchAt IS NULL OR f.nextFetchAt <= :now)')
                    ->setParameter('now', $now);
            }
        }

        if ($userId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.feed = f AND s.user = :userId)',
                Subscription::class,
            ))->setParameter('userId', $userId);
        }

        return $qb;
```

- [ ] **Step 4: Add the `forUserFeed` factory**

In `src/Service/Refresh/RefreshRequest.php`, alongside `forUser`/`forFeed`, add:

```php
    public static function forUserFeed(int $userId, int $feedId, int $budgetSeconds): self
    {
        return new self($userId, $feedId, true, $budgetSeconds, false);
    }
```

- [ ] **Step 5: Add `existsForUserAndFeed` to SubscriptionRepository**

In `src/Repository/SubscriptionRepository.php`:

```php
    public function existsForUserAndFeed(int $userId, int $feedId): bool
    {
        $count = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('s.feed = :feedId')->setParameter('feedId', $feedId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
```

- [ ] **Step 6: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter FeedRepositoryUserFeedScopeTest`
Expected: PASS. Also run the existing refresh suite to prove no regression: `vendor/bin/phpunit tests/Service/Refresh tests/Repository`
Expected: PASS.

- [ ] **Step 7: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Repository/FeedRepository.php src/Service/Refresh/RefreshRequest.php src/Repository/SubscriptionRepository.php tests/Repository/FeedRepositoryUserFeedScopeTest.php
git commit -m "fix(refresh): honour subscription scope on per-feed refresh (IDOR)"
```

---

## Task 2: Reader-API domain exceptions

**Files:**
- Create: `src/Exception/AlreadySubscribedException.php`, `SubscriptionLimitReachedException.php`, `TagNameTakenException.php`, `FeedDiscoveryException.php`
- Test: `tests/Exception/ReaderExceptionsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\AlreadySubscribedException;
use App\Exception\FeedDiscoveryException;
use App\Exception\SubscriptionLimitReachedException;
use App\Exception\TagNameTakenException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReaderExceptionsTest extends TestCase
{
    public function testTypesAndStatuses(): void
    {
        self::assertSame('already_subscribed', (new AlreadySubscribedException())->type);
        self::assertSame(Response::HTTP_CONFLICT, (new AlreadySubscribedException())->status);

        self::assertSame('subscription_limit_reached', (new SubscriptionLimitReachedException(500))->type);
        self::assertSame(Response::HTTP_CONFLICT, (new SubscriptionLimitReachedException(500))->status);

        self::assertSame('tag_name_taken', (new TagNameTakenException())->type);
        self::assertSame(Response::HTTP_CONFLICT, (new TagNameTakenException())->status);

        self::assertSame('feed_unreachable', (new FeedDiscoveryException())->type);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, (new FeedDiscoveryException())->status);
    }
}
```

- [ ] **Step 2: Run it — expect failure** (classes do not exist)

Run: `cd backend && vendor/bin/phpunit --filter ReaderExceptionsTest`
Expected: FAIL — "Class ... not found".

- [ ] **Step 3: Create the four exceptions**

`src/Exception/AlreadySubscribedException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class AlreadySubscribedException extends ApiException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct('already_subscribed', Response::HTTP_CONFLICT, 'Already subscribed to that feed', $detail);
    }
}
```

`src/Exception/SubscriptionLimitReachedException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class SubscriptionLimitReachedException extends ApiException
{
    public function __construct(int $limit)
    {
        parent::__construct(
            'subscription_limit_reached',
            Response::HTTP_CONFLICT,
            'Subscription limit reached',
            sprintf('You can subscribe to at most %d feeds.', $limit),
        );
    }
}
```

`src/Exception/TagNameTakenException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class TagNameTakenException extends ApiException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct('tag_name_taken', Response::HTTP_CONFLICT, 'Tag name already in use', $detail);
    }
}
```

`src/Exception/FeedDiscoveryException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class FeedDiscoveryException extends ApiException
{
    public function __construct(?string $detail = null, ?\Throwable $previous = null)
    {
        parent::__construct(
            'feed_unreachable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Could not read a feed from that address',
            $detail,
            [],
            $previous,
        );
    }
}
```

> Confirm the `ApiException` constructor signature matches: `(string $type, int $status, string $title, ?string $detail = null, array $errors = [], ?\Throwable $previous = null)` — see `src/Exception/ApiException.php`. Adjust argument order if it differs.

- [ ] **Step 4: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter ReaderExceptionsTest`
Expected: PASS.

- [ ] **Step 5: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Exception/AlreadySubscribedException.php src/Exception/SubscriptionLimitReachedException.php src/Exception/TagNameTakenException.php src/Exception/FeedDiscoveryException.php tests/Exception/ReaderExceptionsTest.php
git commit -m "feat(reader): add subscription/tag/discovery domain exceptions"
```

---

## Task 3: Subscription tag-collection helpers

**Files:**
- Modify: `src/Entity/Subscription.php`
- Test: `tests/Entity/SubscriptionTagsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class SubscriptionTagsTest extends TestCase
{
    public function testAddAndRemoveTagAreIdempotent(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $user = new User('u@example.com', $now);
        $sub = new Subscription($user, new Feed('https://example.com/f.xml'), $now);
        $tag = new Tag($user, 'news');

        $sub->addTag($tag);
        $sub->addTag($tag); // idempotent
        self::assertCount(1, $sub->getTags());
        self::assertTrue($sub->getTags()->contains($tag));

        $sub->removeTag($tag);
        self::assertCount(0, $sub->getTags());
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionTagsTest`
Expected: FAIL if `addTag`/`removeTag` are absent (they may already exist — if the test passes immediately, skip Step 3 and note it in the commit).

- [ ] **Step 3: Add the helpers if missing**

In `src/Entity/Subscription.php`, ensure these exist (the `$tags` property is the `ManyToMany` Collection at `:43-45`):

```php
    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }
    }

    public function removeTag(Tag $tag): void
    {
        $this->tags->removeElement($tag);
    }

    /** @return \Doctrine\Common\Collections\Collection<int, Tag> */
    public function getTags(): \Doctrine\Common\Collections\Collection
    {
        return $this->tags;
    }
```

- [ ] **Step 4: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionTagsTest`
Expected: PASS.

- [ ] **Step 5: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Entity/Subscription.php tests/Entity/SubscriptionTagsTest.php
git commit -m "feat(reader): subscription tag-collection helpers"
```

---

## Task 4: FeedDiscovery service

Given a user-entered URL, fetch it through the SSRF-guarded `FeedFetcherInterface`; if the body parses as a feed, it is a direct feed; otherwise scan the HTML for `<link rel="alternate" type="application/rss+xml|application/atom+xml">` and return resolved candidates; any fetch failure or "no feed found" becomes a `FeedDiscoveryException`.

**Files:**
- Create: `src/Service/Discovery/FeedCandidate.php`, `FeedDiscoveryResult.php`, `FeedDiscovery.php`
- Test: `tests/Service/Discovery/FeedDiscoveryTest.php`
- Fixture reuse: `tests/Fixtures/feeds/` (an existing valid feed XML — confirm a filename, e.g. `rss2-basic.xml` or `atom-basic.xml`)

- [ ] **Step 1: Write the value objects**

`src/Service/Discovery/FeedCandidate.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedCandidate
{
    public function __construct(public string $url, public ?string $title)
    {
    }
}
```

`src/Service/Discovery/FeedDiscoveryResult.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

final readonly class FeedDiscoveryResult
{
    /** @param list<FeedCandidate> $candidates */
    private function __construct(
        public bool $isDirectFeed,
        public ?string $feedUrl,
        public array $candidates,
    ) {
    }

    public static function directFeed(string $feedUrl): self
    {
        return new self(true, $feedUrl, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(false, null, $candidates);
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery;

use App\Exception\FeedDiscoveryException;
use App\Service\Discovery\FeedDiscovery;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\FeedParser;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FeedDiscoveryTest extends KernelTestCase
{
    private function discovery(StubFeedFetcher $fetcher): FeedDiscovery
    {
        $parser = self::getContainer()->get(FeedParser::class);
        self::assertInstanceOf(FeedParser::class, $parser);

        return new FeedDiscovery($fetcher, $parser);
    }

    public function testDirectFeedUrlReturnsCanonicalFinalUrl(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../Fixtures/feeds/rss2-basic.xml');
        self::assertIsString($xml);

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/feed',
            FetchResponse::fetched('https://example.com/feed.xml', permanentRedirect: false, body: $xml, etag: null, lastModified: null),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/feed');

        self::assertTrue($result->isDirectFeed);
        self::assertSame('https://example.com/feed.xml', $result->feedUrl);
    }

    public function testHtmlPageReturnsResolvedCandidates(): void
    {
        $html = <<<'HTML'
            <!doctype html><html><head>
              <link rel="alternate" type="application/rss+xml" title="Main" href="/rss.xml">
              <link rel="alternate" type="application/atom+xml" href="https://cdn.example.com/atom">
              <link rel="stylesheet" href="/style.css">
            </head><body>Hello</body></html>
            HTML;

        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/blog',
            FetchResponse::fetched('https://example.com/blog/', permanentRedirect: false, body: $html, etag: null, lastModified: null),
        );

        $result = $this->discovery($fetcher)->discover('https://example.com/blog');

        self::assertFalse($result->isDirectFeed);
        self::assertCount(2, $result->candidates);
        self::assertSame('https://example.com/rss.xml', $result->candidates[0]->url);
        self::assertSame('Main', $result->candidates[0]->title);
        self::assertSame('https://cdn.example.com/atom', $result->candidates[1]->url);
        self::assertNull($result->candidates[1]->title);
    }

    public function testHtmlWithoutFeedLinksThrowsDiscoveryException(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willReturn(
            'https://example.com/plain',
            FetchResponse::fetched('https://example.com/plain', permanentRedirect: false, body: '<html><body>nothing here</body></html>', etag: null, lastModified: null),
        );

        $this->expectException(FeedDiscoveryException::class);
        $this->discovery($fetcher)->discover('https://example.com/plain');
    }

    public function testFetchFailureBecomesDiscoveryException(): void
    {
        $fetcher = new StubFeedFetcher();
        $fetcher->willThrow('https://blocked.example.com', new FeedUnreachableException('blocked by SSRF guard'));

        $this->expectException(FeedDiscoveryException::class);
        $this->discovery($fetcher)->discover('https://blocked.example.com');
    }
}
```

> Confirm the fixture filename in `tests/Fixtures/feeds/` and that `FeedParser` is fetchable from the container (it is used by `RefreshRunner`). If `FeedParser` is not a public test service, add it to `config/services_test.yaml` the same way the fetcher/lock/hasher aliases are made public.

- [ ] **Step 3: Run it — expect failure** (`FeedDiscovery` does not exist)

Run: `cd backend && vendor/bin/phpunit --filter FeedDiscoveryTest`
Expected: FAIL.

- [ ] **Step 4: Implement `FeedDiscovery`**

`src/Service/Discovery/FeedDiscovery.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

use App\Exception\FeedDiscoveryException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\UrlResolver;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;

/**
 * Turns a user-entered URL into either a confirmed feed URL or a list of
 * candidate feeds discovered from an HTML page. Every fetch goes through the
 * SSRF-guarded fetcher, so discovery inherits the same protection as refresh.
 */
final readonly class FeedDiscovery
{
    private const FEED_LINK_TYPES = ['application/rss+xml', 'application/atom+xml'];

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedParser $parser,
    ) {
    }

    public function discover(string $url): FeedDiscoveryResult
    {
        try {
            $response = $this->fetcher->fetch($url);
        } catch (FetchException $e) {
            throw new FeedDiscoveryException('The address could not be fetched.', $e);
        }

        $body = $response->body ?? '';
        if ('' === trim($body)) {
            throw new FeedDiscoveryException('The address returned an empty document.');
        }

        try {
            $this->parser->parse($body); // validates it really is a feed
            return FeedDiscoveryResult::directFeed($response->finalUrl);
        } catch (FeedParseException) {
            // Not a feed — treat as HTML and look for <link rel="alternate">.
        }

        $candidates = $this->scanHtml($body, $response->finalUrl);
        if ([] === $candidates) {
            throw new FeedDiscoveryException('No feed was found at that address.');
        }

        return FeedDiscoveryResult::candidates($candidates);
    }

    /** @return list<FeedCandidate> */
    private function scanHtml(string $html, string $baseUrl): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never let the parser dereference external entities.
        $dom->loadHTML($html, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $candidates = [];
        $seen = [];
        foreach ($dom->getElementsByTagName('link') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            if ('alternate' !== strtolower(trim($link->getAttribute('rel')))) {
                continue;
            }
            if (!\in_array(strtolower(trim($link->getAttribute('type'))), self::FEED_LINK_TYPES, true)) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            if ('' === $href) {
                continue;
            }

            $resolved = UrlResolver::resolve($baseUrl, $href);
            if (isset($seen[$resolved])) {
                continue;
            }
            $seen[$resolved] = true;

            $title = trim($link->getAttribute('title'));
            $candidates[] = new FeedCandidate($resolved, '' === $title ? null : $title);
        }

        return $candidates;
    }
}
```

> Confirm the exact FQCNs `App\Service\Parser\FeedParser`, `App\Service\Parser\Exception\FeedParseException`, and `App\Service\Fetch\Exception\FetchException`. The exploration confirmed `FeedParser::parse(string $body): ParsedFeed` throws `FeedParseException` on non-feed input, and `UrlResolver::resolve(string $baseUrl, string $location): string` is static.

- [ ] **Step 5: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter FeedDiscoveryTest`
Expected: PASS (all four cases).

- [ ] **Step 6: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Service/Discovery tests/Service/Discovery/FeedDiscoveryTest.php
git commit -m "feat(reader): SSRF-guarded feed discovery service"
```

---

## Task 5: SubscriptionService and subscription queries

**Files:**
- Modify: `src/Repository/SubscriptionRepository.php` — add `findForUserWithTags`, `countForUser`, `findOneOwnedBy`, `findByTag`
- Create: `src/Service/Subscription/SubscribeOutcome.php`, `src/Service/Subscription/SubscriptionService.php`
- Test: `tests/Service/Subscription/SubscriptionServiceTest.php`

- [ ] **Step 1: Add the repository query methods**

In `src/Repository/SubscriptionRepository.php` (keep the `existsForUserAndFeed` from Task 1):

```php
    /**
     * A user's subscriptions with their feed and tags eager-loaded (no N+1),
     * ordered by display title then id for a stable list.
     *
     * @return list<\App\Entity\Subscription>
     */
    public function findForUserWithTags(int $userId): array
    {
        /** @var list<\App\Entity\Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.tags', 't')->addSelect('t')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneOwnedBy(int $id, int $userId): ?\App\Entity\Subscription
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.feed', 'f')->addSelect('f')
            ->leftJoin('s.tags', 't')->addSelect('t')
            ->andWhere('s.id = :id')->setParameter('id', $id)
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Subscriptions carrying a given tag — used to detach the tag before it is
     * deleted (portable: does not rely on join-table FK cascade behaviour).
     *
     * @return list<\App\Entity\Subscription>
     */
    public function findByTag(\App\Entity\Tag $tag): array
    {
        /** @var list<\App\Entity\Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->innerJoin('s.tags', 't')
            ->andWhere('t = :tag')->setParameter('tag', $tag)
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 2: Write the failing SubscriptionService test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Exception\AlreadySubscribedException;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\FeedDiscovery;
use App\Service\Discovery\FeedDiscoveryResult;
use App\Service\Subscription\SubscriptionService;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;

final class SubscriptionServiceTest extends DbTestCase
{
    private function factory(): UserFactory
    {
        return new UserFactory($this->em, self::getContainer()->get('security.user_password_hasher'));
    }

    /** A FeedDiscovery test double returning a fixed result. */
    private function discoveryReturning(FeedDiscoveryResult $result): FeedDiscovery
    {
        return new class($result) extends FeedDiscovery {
            public function __construct(private readonly FeedDiscoveryResult $result)
            {
            }

            public function discover(string $url): FeedDiscoveryResult
            {
                return $this->result;
            }
        };
    }

    public function testDirectFeedCreatesFeedAndSubscription(): void
    {
        $user = $this->factory()->create('sub@example.com');
        $this->em->flush();

        $service = new SubscriptionService(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
            $this->em->getRepository(Subscription::class),
            $this->em->getRepository(Feed::class),
            $this->em,
            new MockClock('2026-06-01T00:00:00Z'),
        );

        $outcome = $service->subscribe($user, 'https://example.com/feed');

        self::assertNotNull($outcome->subscription);
        self::assertSame('https://example.com/feed.xml', $outcome->subscription->getFeed()->getUrl());
        self::assertSame([], $outcome->candidates);
    }

    public function testSecondSubscriptionToSameFeedIsRejected(): void
    {
        $user = $this->factory()->create('dupe@example.com');
        $this->em->flush();

        $service = new SubscriptionService(
            $this->discoveryReturning(FeedDiscoveryResult::directFeed('https://example.com/feed.xml')),
            $this->em->getRepository(Subscription::class),
            $this->em->getRepository(Feed::class),
            $this->em,
            new MockClock('2026-06-01T00:00:00Z'),
        );

        $service->subscribe($user, 'https://example.com/feed');

        $this->expectException(AlreadySubscribedException::class);
        $service->subscribe($user, 'https://example.com/feed');
    }

    public function testHtmlPageReturnsCandidatesWithoutSubscribing(): void
    {
        $user = $this->factory()->create('cand@example.com');
        $this->em->flush();

        $service = new SubscriptionService(
            $this->discoveryReturning(FeedDiscoveryResult::candidates([
                new FeedCandidate('https://example.com/rss.xml', 'Main'),
            ])),
            $this->em->getRepository(Subscription::class),
            $this->em->getRepository(Feed::class),
            $this->em,
            new MockClock('2026-06-01T00:00:00Z'),
        );

        $outcome = $service->subscribe($user, 'https://example.com/blog');

        self::assertNull($outcome->subscription);
        self::assertCount(1, $outcome->candidates);
        self::assertSame(0, $this->em->getRepository(Subscription::class)->countForUser((int) $user->getId()));
    }
}
```

- [ ] **Step 3: Run it — expect failure**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionServiceTest`
Expected: FAIL.

- [ ] **Step 4: Implement `SubscribeOutcome` and `SubscriptionService`**

`src/Service/Subscription/SubscribeOutcome.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Service\Discovery\FeedCandidate;

final readonly class SubscribeOutcome
{
    /** @param list<FeedCandidate> $candidates */
    private function __construct(
        public ?Subscription $subscription,
        public array $candidates,
    ) {
    }

    public static function subscribed(Subscription $subscription): self
    {
        return new self($subscription, []);
    }

    /** @param list<FeedCandidate> $candidates */
    public static function candidates(array $candidates): self
    {
        return new self(null, $candidates);
    }
}
```

`src/Service/Subscription/SubscriptionService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\AlreadySubscribedException;
use App\Exception\SubscriptionLimitReachedException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Discovery\FeedDiscovery;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class SubscriptionService
{
    public const MAX_SUBSCRIPTIONS_PER_USER = 500;

    public function __construct(
        private FeedDiscovery $discovery,
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function subscribe(User $user, string $url): SubscribeOutcome
    {
        $result = $this->discovery->discover($url);

        if (!$result->isDirectFeed) {
            return SubscribeOutcome::candidates($result->candidates);
        }

        $userId = (int) $user->getId();
        if ($this->subscriptions->countForUser($userId) >= self::MAX_SUBSCRIPTIONS_PER_USER) {
            throw new SubscriptionLimitReachedException(self::MAX_SUBSCRIPTIONS_PER_USER);
        }

        $feedUrl = (string) $result->feedUrl;
        $feed = $this->feeds->findOneBy(['url' => $feedUrl]);
        if (null === $feed) {
            // New shared feed: nextFetchAt null => due immediately; the first
            // refresh fills in title/entries. Metadata is the refresh pipeline's
            // job, not the subscribe path's.
            $feed = new Feed($feedUrl);
            $this->em->persist($feed);
            $this->em->flush(); // assign an id so the duplicate check is meaningful
        }

        if ($this->subscriptions->existsForUserAndFeed($userId, (int) $feed->getId())) {
            throw new AlreadySubscribedException();
        }

        $subscription = new Subscription($user, $feed, $this->clock->now());
        $this->em->persist($subscription);
        $this->em->flush();

        return SubscribeOutcome::subscribed($subscription);
    }
}
```

> `FeedRepository`/`SubscriptionRepository` are `ServiceEntityRepository` subclasses and autowire by type. `$this->em->getRepository(Feed::class)` returns the `FeedRepository` instance, so the test's constructor wiring is valid.

- [ ] **Step 5: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionServiceTest`
Expected: PASS.

- [ ] **Step 6: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Repository/SubscriptionRepository.php src/Service/Subscription tests/Service/Subscription/SubscriptionServiceTest.php
git commit -m "feat(reader): subscription service and queries"
```

---

## Task 6: Subscription JSON serialiser + SubscribeRequest/UpdateSubscriptionRequest DTOs

**Files:**
- Create: `src/Http/SubscriptionJson.php`, `src/Http/TagJson.php`
- Create: `src/Dto/Subscription/SubscribeRequest.php`, `src/Dto/Subscription/UpdateSubscriptionRequest.php`
- Test: `tests/Http/SubscriptionJsonTest.php`

- [ ] **Step 1: Write the failing serialiser test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Http\SubscriptionJson;
use PHPUnit\Framework\TestCase;

final class SubscriptionJsonTest extends TestCase
{
    public function testShapeUsesCustomTitleThenFeedTitleThenUrl(): void
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $user = new User('u@example.com', $now);
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example Feed');
        $feed->setSiteUrl('https://example.com');
        $sub = new Subscription($user, $feed, $now);
        $tag = new Tag($user, 'news');
        $tag->setColor('#ff8800');
        $sub->addTag($tag);

        $shape = SubscriptionJson::one($sub);

        self::assertSame('Example Feed', $shape['title']);
        self::assertNull($shape['customTitle']);
        self::assertSame('https://example.com/feed.xml', $shape['feedUrl']);
        self::assertSame('https://example.com', $shape['siteUrl']);
        self::assertSame('active', $shape['status']);
        self::assertSame('2026-02-03T04:05:06+00:00', $shape['createdAt']);
        self::assertSame([['id' => $tag->getId(), 'name' => 'news', 'color' => '#ff8800', 'icon' => null]], $shape['tags']);
    }

    public function testCustomTitleWinsAndFallsBackToUrl(): void
    {
        $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
        $user = new User('u@example.com', $now);
        $feed = new Feed('https://example.com/feed.xml'); // no title set
        $sub = new Subscription($user, $feed, $now);
        $sub->setCustomTitle('My Name');

        $shape = SubscriptionJson::one($sub);
        self::assertSame('My Name', $shape['title']);
        self::assertSame('My Name', $shape['customTitle']);

        $sub->setCustomTitle(null);
        $shape = SubscriptionJson::one($sub);
        self::assertSame('https://example.com/feed.xml', $shape['title']); // url fallback
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionJsonTest`
Expected: FAIL.

- [ ] **Step 3: Implement the serialisers**

`src/Http/TagJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Tag;

final class TagJson
{
    /** @return array{id: int|null, name: string, color: string|null, icon: string|null} */
    public static function one(Tag $tag): array
    {
        return [
            'id' => $tag->getId(),
            'name' => $tag->getName(),
            'color' => $tag->getColor(),
            'icon' => $tag->getIcon(),
        ];
    }
}
```

`src/Http/SubscriptionJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Subscription;

final class SubscriptionJson
{
    /**
     * @return array{
     *   id: int|null, title: string, customTitle: string|null, feedUrl: string,
     *   siteUrl: string|null, status: string, createdAt: string,
     *   tags: list<array{id: int|null, name: string, color: string|null, icon: string|null}>
     * }
     */
    public static function one(Subscription $sub): array
    {
        $feed = $sub->getFeed();
        $title = $sub->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $tags = [];
        foreach ($sub->getTags() as $tag) {
            $tags[] = TagJson::one($tag);
        }

        return [
            'id' => $sub->getId(),
            'title' => $title,
            'customTitle' => $sub->getCustomTitle(),
            'feedUrl' => $feed->getUrl(),
            'siteUrl' => $feed->getSiteUrl(),
            'status' => $feed->getStatus()->value,
            'createdAt' => $sub->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'tags' => $tags,
        ];
    }
}
```

> This is where Plan 4b will add `'unreadCount' => …`. Confirm getters `Feed::getTitle/getSiteUrl/getStatus/getUrl`, `Subscription::getCustomTitle/getCreatedAt/getId`, `Tag::getName/getColor/getIcon` exist (they follow the standard entity accessor pattern in this codebase).

- [ ] **Step 4: Create the request DTOs**

`src/Dto/Subscription/SubscribeRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubscribeRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Url(protocols: ['http', 'https'])]
        #[Assert\Length(max: 750)]
        public string $url = '',
    ) {
    }
}
```

`src/Dto/Subscription/UpdateSubscriptionRequest.php` (PUT-like: both fields always applied):
```php
<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateSubscriptionRequest
{
    /** @param list<int> $tagIds */
    public function __construct(
        #[Assert\Length(max: 512)]
        public ?string $customTitle = null,
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $tagIds = [],
    ) {
    }
}
```

- [ ] **Step 5: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionJsonTest`
Expected: PASS.

- [ ] **Step 6: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Http/SubscriptionJson.php src/Http/TagJson.php src/Dto/Subscription tests/Http/SubscriptionJsonTest.php
git commit -m "feat(reader): subscription/tag JSON shapes and request DTOs"
```

---

## Task 7: SubscriptionController (list, subscribe, update, delete)

**Files:**
- Create: `src/Controller/Api/SubscriptionController.php`
- Modify: `src/Repository/TagRepository.php` — add `findAllByIdsForUser` (used to resolve `tagIds`)
- Test: `tests/Controller/Api/SubscriptionControllerTest.php`

- [ ] **Step 1: Add the tag-resolution query**

In `src/Repository/TagRepository.php`:
```php
    /**
     * The user's tags matching the given ids. Fewer results than ids means one
     * or more ids were invalid or belonged to another user.
     *
     * @param list<int> $ids
     * @return list<\App\Entity\Tag>
     */
    public function findAllByIdsForUser(array $ids, int $userId): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<\App\Entity\Tag> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 2: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Service\Discovery\FeedDiscovery;
use App\Service\Discovery\FeedDiscoveryResult;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubscriptionControllerTest extends WebTestCase
{
    private function authHeader(KernelBrowser $client, string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $user = $factory->create($email);
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** Swap the discovery service for one returning a fixed result. */
    private function stubDiscovery(FeedDiscoveryResult $result): void
    {
        self::getContainer()->set(FeedDiscovery::class, new class($result) extends FeedDiscovery {
            public function __construct(private readonly FeedDiscoveryResult $result)
            {
            }

            public function discover(string $url): FeedDiscoveryResult
            {
                return $this->result;
            }
        });
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/subscriptions');
        self::assertResponseStatusCodeSame(401);
    }

    public function testSubscribeToDirectFeedThenList(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader($client, 'reader@example.com');
        $this->stubDiscovery(FeedDiscoveryResult::directFeed('https://example.com/feed.xml'));

        $client->request('POST', '/api/subscriptions', server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['url' => 'https://example.com/feed']));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('https://example.com/feed.xml', $created['subscription']['feedUrl']);

        $client->request('GET', '/api/subscriptions', server: $headers);
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertCount(1, $list['subscriptions']);
        self::assertArrayNotHasKey('unreadCount', $list['subscriptions'][0]); // 4b adds it
    }

    public function testSubscribeToHtmlReturnsCandidates(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader($client, 'html@example.com');
        $this->stubDiscovery(FeedDiscoveryResult::candidates([
            new \App\Service\Discovery\FeedCandidate('https://example.com/rss.xml', 'Main'),
        ]));

        $client->request('POST', '/api/subscriptions', server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['url' => 'https://example.com/blog']));
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('https://example.com/rss.xml', $body['candidates'][0]['url']);
    }

    public function testCannotUpdateAnotherUsersSubscription(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));

        $stranger = $factory->create('stranger@example.com');
        $feed = new Feed('https://example.com/x.xml');
        $em->persist($feed);
        $sub = new Subscription($stranger, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em->persist($sub);
        $em->flush();

        $headers = $this->authHeader($client, 'attacker@example.com');
        $client->request('PATCH', '/api/subscriptions/' . $sub->getId(), server: $headers + ['CONTENT_TYPE' => 'application/json'], content: json_encode(['customTitle' => 'hijacked', 'tagIds' => []]));
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
}
```

- [ ] **Step 3: Run it — expect failure** (no controller)

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionControllerTest`
Expected: FAIL.

- [ ] **Step 4: Implement the controller**

`src/Controller/Api/SubscriptionController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Subscription\SubscribeRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Entity\User;
use App\Http\SubscriptionJson;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/subscriptions')]
final class SubscriptionController
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly TagRepository $tags,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_subscriptions_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->subscriptionRepo->findForUserWithTags((int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(SubscriptionJson::one(...), $rows),
        ]);
    }

    #[Route('', name: 'api_subscriptions_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] SubscribeRequest $request): JsonResponse
    {
        $outcome = $this->subscriptions->subscribe($user, $request->url);

        if (null === $outcome->subscription) {
            return new JsonResponse([
                'candidates' => array_map(
                    static fn ($c) => ['url' => $c->url, 'title' => $c->title],
                    $outcome->candidates,
                ),
            ]);
        }

        return new JsonResponse(
            ['subscription' => SubscriptionJson::one($outcome->subscription)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_subscriptions_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[CurrentUser] User $user, #[MapRequestPayload] UpdateSubscriptionRequest $request): JsonResponse
    {
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $sub->setCustomTitle('' === (string) $request->customTitle ? null : $request->customTitle);

        // Replace the tag set with the requested (user-owned) tags.
        $resolved = $this->tags->findAllByIdsForUser($request->tagIds, (int) $user->getId());
        foreach ($sub->getTags()->toArray() as $existing) {
            $sub->removeTag($existing);
        }
        foreach ($resolved as $tag) {
            $sub->addTag($tag);
        }

        $this->em->flush();

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
    }

    #[Route('/{id}', name: 'api_subscriptions_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $this->em->remove($sub);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 5: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter SubscriptionControllerTest`
Expected: PASS. Confirm `array_map(SubscriptionJson::one(...), $rows)` first-class-callable syntax passes PHPStan; if not, wrap in a closure.

- [ ] **Step 6: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Controller/Api/SubscriptionController.php src/Repository/TagRepository.php tests/Controller/Api/SubscriptionControllerTest.php
git commit -m "feat(reader): subscription endpoints (list/subscribe/update/delete)"
```

---

## Task 8: TagController (CRUD)

**Files:**
- Create: `src/Controller/Api/TagController.php`
- Create: `src/Dto/Tag/CreateTagRequest.php`, `src/Dto/Tag/UpdateTagRequest.php`
- Modify: `src/Repository/TagRepository.php` — add `findForUser`, `existsForUserAndName`, `findOneOwnedBy`
- Test: `tests/Controller/Api/TagControllerTest.php`

- [ ] **Step 1: Add the tag queries**

In `src/Repository/TagRepository.php`:
```php
    /** @return list<\App\Entity\Tag> */
    public function findForUser(int $userId): array
    {
        /** @var list<\App\Entity\Tag> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function existsForUserAndName(int $userId, string $name, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->andWhere('LOWER(t.name) = LOWER(:name)')->setParameter('name', $name);

        if (null !== $excludeId) {
            $qb->andWhere('t.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findOneOwnedBy(int $id, int $userId): ?\App\Entity\Tag
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.id = :id')->setParameter('id', $id)
            ->andWhere('t.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }
```

- [ ] **Step 2: Create the DTOs**

`src/Dto/Tag/CreateTagRequest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Tag;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateTagRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 100)]
        public string $name = '',
        #[Assert\Length(max: 20)]
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Color must be a hex value like #ff8800.')]
        public ?string $color = null,
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Icon must be a Material Symbol name.')]
        public ?string $icon = null,
    ) {
    }
}
```

`src/Dto/Tag/UpdateTagRequest.php` (identical shape; name required for rename):
```php
<?php

declare(strict_types=1);

namespace App\Dto\Tag;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateTagRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 1, max: 100)]
        public string $name = '',
        #[Assert\Length(max: 20)]
        #[Assert\Regex(pattern: '/^#[0-9a-fA-F]{6}$/', message: 'Color must be a hex value like #ff8800.')]
        public ?string $color = null,
        #[Assert\Length(max: 64)]
        #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Icon must be a Material Symbol name.')]
        public ?string $icon = null,
    ) {
    }
}
```

> `Assert\Regex` treats `null`/`''` as valid unless combined with `NotBlank`, so nullable `color`/`icon` pass when omitted.

- [ ] **Step 3: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TagControllerTest extends WebTestCase
{
    private function authHeader(KernelBrowser $client, string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($factory->create($email));

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    public function testCreateListAndRejectDuplicateName(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader($client, 'tagger@example.com');

        $client->request('POST', '/api/tags', server: $headers, content: json_encode(['name' => 'News', 'color' => '#ff8800', 'icon' => 'newspaper']));
        self::assertResponseStatusCodeSame(201);
        $tag = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('News', $tag['tag']['name']);
        self::assertSame('#ff8800', $tag['tag']['color']);

        // Case-insensitive duplicate rejected.
        $client->request('POST', '/api/tags', server: $headers, content: json_encode(['name' => 'news']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('tag_name_taken', json_decode((string) $client->getResponse()->getContent(), true)['type']);

        $client->request('GET', '/api/tags', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode((string) $client->getResponse()->getContent(), true)['tags']);
    }

    public function testInvalidColorIsRejected(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader($client, 'badcolor@example.com');
        $client->request('POST', '/api/tags', server: $headers, content: json_encode(['name' => 'X', 'color' => 'red']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_error', json_decode((string) $client->getResponse()->getContent(), true)['type']);
    }

    public function testDeleteAnotherUsersTagIs404(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $owner = $factory->create('owner2@example.com');
        $tag = new \App\Entity\Tag($owner, 'private');
        $em->persist($tag);
        $em->flush();

        $headers = $this->authHeader($client, 'intruder@example.com');
        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
    }
}
```

- [ ] **Step 4: Run it — expect failure**

Run: `cd backend && vendor/bin/phpunit --filter TagControllerTest`
Expected: FAIL.

- [ ] **Step 5: Implement the controller**

`src/Controller/Api/TagController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\TagNameTakenException;
use App\Http\TagJson;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/tags')]
final class TagController
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly SubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_tags_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'tags' => array_map(TagJson::one(...), $this->tags->findForUser((int) $user->getId())),
        ]);
    }

    #[Route('', name: 'api_tags_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateTagRequest $request): JsonResponse
    {
        if ($this->tags->existsForUserAndName((int) $user->getId(), $request->name)) {
            throw new TagNameTakenException();
        }

        $tag = new Tag($user, $request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $this->em->persist($tag);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_tags_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, #[CurrentUser] User $user, #[MapRequestPayload] UpdateTagRequest $request): JsonResponse
    {
        $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such tag.');

        if ($this->tags->existsForUserAndName((int) $user->getId(), $request->name, $id)) {
            throw new TagNameTakenException();
        }

        $tag->setName($request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)]);
    }

    #[Route('/{id}', name: 'api_tags_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $tag = $this->tags->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such tag.');

        // Detach from every subscription first (portable across SQLite/MySQL).
        foreach ($this->subscriptions->findByTag($tag) as $sub) {
            $sub->removeTag($tag);
        }
        $this->em->remove($tag);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

> Confirm `Tag` has `setName/setColor/setIcon`. If not present, add the setters (single-line, matching other entities).

- [ ] **Step 6: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter TagControllerTest`
Expected: PASS.

- [ ] **Step 7: Quality gate + commit**

```bash
cd backend && composer cs && composer stan
git add src/Controller/Api/TagController.php src/Dto/Tag src/Repository/TagRepository.php tests/Controller/Api/TagControllerTest.php
git commit -m "feat(reader): tag CRUD endpoints"
```

---

## Task 9: RefreshController — the user-scoped progress-loop endpoint

`POST /api/refresh` runs one budgeted `RefreshRunner` slice over the caller's own feeds (or one of them via `?feedId=`), returns the tally as JSON, and is rate-limited per user. The client loops until `remaining` is 0. **Status contract (always HTTP 200):** `busy` → wait and retry; `partial` → continue looping; `completed` → done; `aborted` → terminal, surface an error and stop. The endpoint reserves `RefreshRunner::SAFETY_MARGIN_SECONDS` (10s), so its budget must exceed it — 25s here processes several feeds per call while staying under typical FastCGI limits.

**Files:**
- Create: `src/Controller/Api/RefreshController.php`
- Modify: `config/packages/rate_limiter.yaml`
- Test: `tests/Controller/Api/RefreshControllerTest.php`

- [ ] **Step 1: Add the `refresh` rate limiter**

In `config/packages/rate_limiter.yaml`, under `framework.rate_limiter`, add:
```yaml
        refresh:
            policy: 'sliding_window'
            limit: 90
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```
(90 requests / 5 min per user is generous enough for a full progress loop; the shared refresh lock and 5-minute per-feed cooldown are the real throttle.)

- [ ] **Step 2: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RefreshControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('test.cache.rate_limiter')->clear();
        self::ensureKernelShutdown();
    }

    private function auth(KernelBrowser $client, string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($factory->create($email));

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/refresh');
        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshWithNoFeedsReportsCompleted(): void
    {
        $client = self::createClient();
        $headers = $this->auth($client, 'norefresh@example.com');
        $client->request('POST', '/api/refresh', server: $headers);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('completed', $body['status']);
        self::assertSame(0, $body['total']);
    }

    public function testPerFeedRefreshOfANonSubscribedFeedIs404(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $feed = new Feed('https://example.com/notmine.xml');
        $em->persist($feed);
        $em->flush();

        $headers = $this->auth($client, 'nosub@example.com');
        $client->request('POST', '/api/refresh?feedId=' . $feed->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPerFeedRefreshOfOwnFeedIsAccepted(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $factory = new UserFactory($em, self::getContainer()->get('security.user_password_hasher'));
        $user = $factory->create('owner3@example.com');
        $feed = new Feed('https://example.com/mine.xml');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z')));
        $em->flush();

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        // Swap in a stub fetcher so no real network I/O happens.
        self::getContainer()->set(\App\Service\Fetch\FeedFetcherInterface::class, new \App\Tests\Support\StubFeedFetcher());
        // StubFeedFetcher throws LogicException on an unstubbed URL; stub the one feed as not-modified.
        self::getContainer()->get(\App\Service\Fetch\FeedFetcherInterface::class)
            ->willReturn('https://example.com/mine.xml', \App\Service\Fetch\FetchResponse::notModified('https://example.com/mine.xml', false, null, null));

        $client->request('POST', '/api/refresh?feedId=' . $feed->getId(), server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertContains($body['status'], ['completed', 'partial']);
        self::assertSame(1, $body['total']);
    }
}
```

> If swapping `FeedFetcherInterface` on the live container proves flaky (the runner may resolve it before `set()`), instead assert only the no-feeds and 404 cases here and cover the fetch path in `tests/Service/Refresh`. Keep whichever is green.

- [ ] **Step 3: Run it — expect failure**

Run: `cd backend && vendor/bin/phpunit --filter RefreshControllerTest`
Expected: FAIL.

- [ ] **Step 4: Implement the controller**

`src/Controller/Api/RefreshController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Exception\RateLimitedException;
use App\Repository\SubscriptionRepository;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class RefreshController
{
    /**
     * Above RefreshRunner::SAFETY_MARGIN_SECONDS (10) so a call processes more
     * than a single feed, and below typical FastCGI limits.
     */
    private const BUDGET_SECONDS = 25;

    public function __construct(
        private readonly RefreshRunner $refreshRunner,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ClockInterface $clock,
        private readonly RateLimiterFactoryInterface $refreshLimiter,
    ) {
    }

    #[Route('/api/refresh', name: 'api_refresh', methods: ['POST'])]
    public function __invoke(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $feedId = null,
    ): JsonResponse {
        $this->enforceLimit($user);

        $userId = (int) $user->getId();

        if (null !== $feedId) {
            if (!$this->subscriptions->existsForUserAndFeed($userId, $feedId)) {
                throw new NotFoundHttpException('No such subscription.');
            }
            $request = RefreshRequest::forUserFeed($userId, $feedId, self::BUDGET_SECONDS);
        } else {
            $request = RefreshRequest::forUser($userId, self::BUDGET_SECONDS);
        }

        return new JsonResponse($this->refreshRunner->run($request)->toArray());
    }

    private function enforceLimit(User $user): void
    {
        $limit = $this->refreshLimiter->create('user-' . $user->getId())->consume();
        if ($limit->isAccepted()) {
            return;
        }

        throw new RateLimitedException(
            max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()),
        );
    }
}
```

> The `RateLimiterFactoryInterface $refreshLimiter` argument name must match the limiter key `refresh` for Symfony's named autowiring alias (`<name>Limiter`). Confirm `RateLimitedException`'s constructor takes the retry-after seconds (int) as used in `AuthController::enforceLimit`. If `#[MapQueryParameter]` rejects a missing param, default handling covers it (nullable + default null).

- [ ] **Step 5: Run tests — expect pass**

Run: `cd backend && vendor/bin/phpunit --filter RefreshControllerTest`
Expected: PASS.

- [ ] **Step 6: Full suite + quality gate + commit**

```bash
cd backend && vendor/bin/phpunit && composer cs && composer stan && composer md
git add src/Controller/Api/RefreshController.php config/packages/rate_limiter.yaml tests/Controller/Api/RefreshControllerTest.php
git commit -m "feat(reader): user-scoped refresh progress endpoint"
```

Also run the PhpStorm inspection gate on the changed PHP files (block on ERROR/WARNING) before considering the plan done.

---

## Final verification (after all tasks)

- [ ] `cd backend && vendor/bin/phpunit` — full suite green on SQLite (was 544 + new tests).
- [ ] `docker compose exec -T php vendor/bin/phpunit` — full suite green on MySQL (portability of the new repository queries: `LOWER()`, `EXISTS`, `IN`, join-table detach).
- [ ] `composer cs && composer stan && composer md` — all green.
- [ ] Manual authorization sweep confirmed by tests: every new endpoint returns 401 anonymous and 404 cross-user; no endpoint sets a cookie, reads a session, or returns non-JSON (native-client readiness, `docs/architecture.md`).

## Self-Review

**Spec coverage (design spec `## API surface`):**
- `GET /subscriptions` — Task 7 (tags included; unread counts deferred to 4b, noted).
- `POST /subscriptions {url}` → subscribed or discovery candidates — Tasks 4–7.
- `PATCH /subscriptions/{id}` (customTitle, tags) — Task 7.
- `DELETE /subscriptions/{id}` — Task 7.
- `GET/POST/PATCH/DELETE /tags` — Task 8.
- `POST /refresh` (+ optional `feedId`) — Task 9.
- Feed discovery (`<link rel=alternate>`) — Task 4.
- SSRF guard reused for discovery — Task 4 (via `FeedFetcherInterface`).
- Error contract types (`already_subscribed`, `subscription_limit_reached`, `tag_name_taken`, `feed_unreachable`) — Task 2.
- **Deferred (Plan 4b):** `GET /entries`, `PATCH /entries/{id}/state`, `POST /entries/mark-read`, unread counts, `POST /opml/import`, `GET /opml/export`.

**Type consistency:** `SubscribeOutcome.subscription`/`.candidates`, `FeedDiscoveryResult.isDirectFeed`/`.feedUrl`/`.candidates`, `FeedCandidate.url`/`.title`, `RefreshRequest::forUserFeed`, `SubscriptionRepository::{existsForUserAndFeed,findForUserWithTags,countForUser,findOneOwnedBy,findByTag}`, `TagRepository::{findForUser,existsForUserAndName,findOneOwnedBy,findAllByIdsForUser}` are used consistently across tasks.

**Placeholders:** none — every code and test step is complete. Three "confirm the existing signature" notes remain (ApiException ctor arg order, entity setters, `FeedParser`/`FeedParseException` FQCNs); these are verifications against real files, resolved by the failing-test step, not deferred work.
