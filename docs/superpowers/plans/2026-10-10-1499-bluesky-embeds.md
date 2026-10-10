# Bluesky embeds from the public AppView (#1499) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Bluesky post never stores the `[contains quote post or other embedded content]` placeholder, and after ingest its image, video, link card or quote is filled from Bluesky's public AppView, retried on later refreshes for three days.

**Architecture:** `BlueskyEntryRule` (Ingest) strips the placeholder at ingest. New entries whose guid is an AT post URI get a `PendingPostEnrichment` row. A new service module, `Service/Bluesky`, owns the AppView: `PostEnricher::enrich()` runs after `FeedOutcomePersister`'s flush (fetched and 304 outcomes), calls `getPosts` in chunks of 25 through the SSRF-guarded `FeedFetcherInterface`, renders each post view's `embed` with `PostEmbedRenderer`, and `EntryEmbedWriter` appends the fragment to the stored body, sets summary, lead image and media. The SPA only gains article-body styles.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM 3.6 + DBAL 4.4 (backend), Angular 20 SCSS (frontend).

**Spec:** `docs/superpowers/specs/2026-10-10-1499-bluesky-embeds-design.md`

## Rulings (gaps the spec leaves, resolved here)

1. **No sanitizer config change.** Probed against the real `EntrySanitizer`: `allowSafeElements()` already admits `video` with `src`, `poster`, `controls`, `preload`, `playsinline`, plus `figure`, `blockquote`, `footer`, `strong`, `small`; the existing `allowAttribute('class', ['audio', 'figure'])` already lets every class value through on `figure`. Task 4 pins each allowance with a test over the renderer's real output instead.
2. **The first fetch of a new subscription is queued too.** `Subscription/FirstFetchRecorder` ingests a new feed's first entries outside `FeedOutcomePersister`, so following the spec literally would leave every first-fetch Bluesky post unfilled. It queues them through `PendingPostQueue` (no AppView call inside the subscribe request); the next refresh, fetched or 304, fills them. Dependency `Subscription → Bluesky`, no cycle.
3. **Search index sees the filled text.** `enrich()` returns the entries it filled; the persister indexes created ∪ filled (deduplicated by object) after the pass. `EntryIndexer::index` is an upsert.
4. **Pass order:** queue → flush (only when something was queued) → bulk-delete the feed's rows queued before now − 3 days (`<`, strict) → load the feed's oldest 100 (`queuedAt`, then entry id) → per chunk of 25: stop when `HostThrottle` reports a wait for the AppView host; `FetchException` or an unusable answer logs a warning, keeps that chunk's rows and goes on with the next chunk; a `FeedThrottledException` is recorded by `AppViewClient`, so the throttle check stops the following chunks → final flush.
5. **Catch-all:** `PostEnricher::enrich()` catches `\Exception`, logs `error` `Bluesky enrichment failed for {url}` and returns `[]`. A failed flush closes the EntityManager; the next feed's flush then fails, which `FeedOutcomePersister::persist()` already maps to `Aborted`.
6. **A post view without `embed`** changes nothing; its row is dequeued. An embed with no usable https URL (all images http, http playlist, http card URI) renders the "View embedded content on Bluesky" fallback link, like an unknown type.
7. **`PendingPostEnrichment` uses the entry as its identifier** (`#[ORM\Id]` on the OneToOne), which makes it unique by primary key; Doctrine drops the implicit unique index for it. No index on `queued_at` (a transient, tiny table).
8. **JSON access** goes through `Bluesky/Model/JsonNodeModel` (a field of the wrong type reads as absent), so PHPStan level max needs no casts.
9. **The AT URI pattern** lives once in `Ingest/Support/AtPostUri`, which also builds the bsky.app web URL (`https://bsky.app/profile/<did>/post/<rkey>`) from it.
10. **`ParsedEntryModel`** gains `parsedTitle()` and `withPostText(ParsedTitleModel, ?string $summary, ?string $contentHtml)`; `withContentHtml()` delegates to `withPostText()`, so the constructor call count does not grow.
11. **Video shape:** `.post-video video { aspect-ratio: auto; max-height: 80dvh }` overrides the generic 16:9 pin, so the box takes the poster's natural shape and a portrait clip cannot outgrow the viewport. The `<video>` carries no width/height. Images carry `width`/`height` from `aspectRatio`.
12. **Labels** are English, as the existing embed labels are ("Watch on YouTube"): "Watch on Bluesky", "View embedded content on Bluesky". The link-card host is shown without a leading `www.`.
13. **No frontend logic, no Jest.** `decorators/hls-streams.ts` already arms every `<video src="….m3u8">` with hls.js, so Chrome plays the playlist too. Angular's `[innerHTML]` sanitizer drops `playsinline` (not in its allow-list), so an iPhone plays the clip fullscreen, as it does every feed video today. Not fixed here; offer it as a follow-up.
14. **Image-only post stays a post (controller ruling, overrides the planner's draft):** when the feed gave no title and nothing derives from the remaining text, the title is `ParsedTitleModel::untitledPost()` — `(untitled)` with `derived` true — so the SPA keeps treating it as a post (no reader request for the bsky.app page, #1495). `ParsedTitleModel` gains that one named constructor.

## Global Constraints

- Generic: a Bluesky item is recognised by its guid's shape (`AtPostUri::matches`), never by a host list.
- `Ingest` never names `Bluesky` or `Refresh`. Module edges added: `Refresh → Bluesky`, `Subscription → Bluesky`, `Bluesky → {Fetch, Ingest, Sanitize, Text, Url, Parser, Image, Html}`. None of those depends back (checked).
- `Entry` gains no field. All queries on `pending_post_enrichment` live in `PendingPostEnrichmentRepository`.
- The RSS text stays the body; the AppView supplies only the embed. The title is never touched by enrichment.
- Every AppView URL passes `HttpsImageUrl::orNull`; every AppView text is HTML-escaped (`htmlspecialchars`, `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5`); the whole result goes through `EntrySanitizer`.
- New entries only; nothing stored is backfilled.
- House rules (CLAUDE.md): `final readonly` services in a module root, data in `Model/` (suffix `Model`), static helpers in `Support/` with a private constructor, exceptions in `Exception/`; guard clauses; no abbreviations; default to no comment; `declare(strict_types=1)`; lines ≤ 120; every touched `src` file PHPMD-clean.
- Commits: `type(#1499): lower-case summary`, no attribution lines. Do not merge or push.
- Gates per backend task (from `backend/`): the task's tests, then `composer check && composer md`. Frontend: `docker compose exec -T frontend npm run check`.

---

### Task 1: The placeholder never lands

**Files:**
- Create: `backend/src/Service/Ingest/Support/AtPostUri.php`
- Create: `backend/src/Service/Ingest/PlatformEntryRule/BlueskyEntryRule.php`
- Modify: `backend/src/Service/Parser/Model/ParsedEntryModel.php`
- Modify: `backend/src/Service/Parser/Model/ParsedTitleModel.php`
- Test: `backend/tests/Service/Ingest/Support/AtPostUriTest.php`
- Test: `backend/tests/Service/Ingest/PlatformEntryRule/BlueskyEntryRuleTest.php`
- Test: `backend/tests/Service/Parser/Model/ParsedEntryModelTest.php`
- Test: `backend/tests/Service/Ingest/PlatformEntryRulesWiringTest.php`

**Interfaces:**
- Produces: `AtPostUri::matches(string $uri): bool`, `AtPostUri::webUrl(string $uri): ?string`; `ParsedEntryModel::parsedTitle(): ParsedTitleModel`, `ParsedEntryModel::withPostText(ParsedTitleModel $title, ?string $summary, ?string $contentHtml): self`; `ParsedTitleModel::untitledPost(): self`; `BlueskyEntryRule` (tagged `app.platform_entry_rule` automatically through `_instanceof` in `config/services.yaml`).

- [ ] **Step 1: Write the failing tests**

`AtPostUriTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\Support;

use App\Service\Ingest\Support\AtPostUri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtPostUriTest extends TestCase
{
    private const string POST = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mxhdhodv222n';

    /** @return iterable<string, array{string, bool}> */
    public static function uris(): iterable
    {
        yield 'a post' => [self::POST, true];
        yield 'a repost record' => ['at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.repost/3mxhdhodv222n', false];
        yield 'a post with a trailing path' => [self::POST . '/extra', false];
        yield 'text before the scheme' => ['x' . self::POST, false];
        yield 'the web URL of a post' => ['https://bsky.app/profile/bsky.app/post/3mxhdhodv222n', false];
        yield 'a Mastodon guid' => ['https://mastodon.social/@Mastodon/115', false];
    }

    #[DataProvider('uris')]
    public function testMatchesAPostsAtUriOnly(string $uri, bool $matches): void
    {
        self::assertSame($matches, AtPostUri::matches($uri));
    }

    public function testThePostsWebUrlNamesItsDidAndRecordKey(): void
    {
        self::assertSame(
            'https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mxhdhodv222n',
            AtPostUri::webUrl(self::POST),
        );
    }

    public function testAnythingElseHasNoWebUrl(): void
    {
        self::assertNull(AtPostUri::webUrl('at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.graph.starterpack/3lgml'));
    }
}
```

`BlueskyEntryRuleTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\PlatformEntryRule;

use App\Service\Ingest\PlatformEntryRule\BlueskyEntryRule;
use App\Service\Parser\Model\ParsedEntryModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyEntryRuleTest extends TestCase
{
    private const string POST = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mxhdhodv222n';
    private const string PLACEHOLDER = '[contains quote post or other embedded content]';

    /** @return iterable<string, array{string, bool}> */
    public static function guids(): iterable
    {
        yield 'a post' => [self::POST, true];
        yield 'a web URL' => ['https://bsky.app/profile/bsky.app/post/3mxhdhodv222n', false];
    }

    #[DataProvider('guids')]
    public function testSupportsAPostByItsGuid(string $guid, bool $supported): void
    {
        self::assertSame($supported, (new BlueskyEntryRule())->supports(self::post($guid, 'Title', '<p>x</p>')));
    }

    /** @return iterable<string, array{string, string}> */
    public static function bodies(): iterable
    {
        yield 'its own paragraph' => [
            '<p>a masterclass in alt text</p><p>' . self::PLACEHOLDER . '</p>',
            '<p>a masterclass in alt text</p>',
        ];
        yield 'after a line break' => ['<p>line one<br>' . self::PLACEHOLDER . '</p>', '<p>line one</p>'];
        yield 'after a blank line of plain text' => ["text\n\n" . self::PLACEHOLDER, 'text'];
        yield 'no placeholder' => ['<p>Just text.</p>', '<p>Just text.</p>'];
    }

    #[DataProvider('bodies')]
    public function testThePlaceholderLeavesContentAndSummary(string $body, string $expected): void
    {
        $entry = (new BlueskyEntryRule())->apply(
            new ParsedEntryModel(self::POST, null, 'Title', null, $body, $body, null, titleDerived: true),
        );

        self::assertSame($expected, $entry->contentHtml);
        self::assertSame($expected, $entry->summary);
    }

    public function testAnImageOnlyPostStaysAnUntitledPost(): void
    {
        $entry = (new BlueskyEntryRule())->apply(self::post(self::POST, self::PLACEHOLDER, self::PLACEHOLDER));

        self::assertNull($entry->contentHtml);
        self::assertNull($entry->summary);
        self::assertSame('(untitled)', $entry->title);
        self::assertTrue($entry->titleDerived);
    }

    public function testADerivedTitleIsDerivedAgainFromTheRemainingText(): void
    {
        $entry = (new BlueskyEntryRule())->apply(
            self::post(self::POST, 'stale', '<p>Fresh words.</p><p>' . self::PLACEHOLDER . '</p>'),
        );

        self::assertSame('Fresh words.', $entry->title);
        self::assertTrue($entry->titleDerived);
    }

    public function testAFeedTitleIsKept(): void
    {
        $entry = (new BlueskyEntryRule())->apply(new ParsedEntryModel(
            self::POST,
            null,
            'Feed headline',
            null,
            null,
            '<p>Body text.</p><p>' . self::PLACEHOLDER . '</p>',
            null,
        ));

        self::assertSame('Feed headline', $entry->title);
        self::assertFalse($entry->titleDerived);
        self::assertSame('<p>Body text.</p>', $entry->contentHtml);
    }

    private static function post(string $guid, string $derivedTitle, string $contentHtml): ParsedEntryModel
    {
        return new ParsedEntryModel($guid, null, $derivedTitle, null, null, $contentHtml, null, titleDerived: true);
    }
}
```

The bodies are what `Rss2Parser` really produces for Bluesky items (probed: a `\n\n`-separated description becomes `<p>…</p><p>[contains …]</p>`; a placeholder-only description stays the bare placeholder).

In `ParsedEntryModelTest` add (imports: `App\Service\Parser\Model\ParsedTitleModel`):

```php
    public function testWithPostTextReplacesTitleSummaryAndContentAndKeepsTheRest(): void
    {
        $entry = new ParsedEntryModel(
            'guid',
            'https://example.com/1',
            'Old title',
            'Author',
            'Old summary',
            '<p>Old</p>',
            null,
            categories: [new ParsedCategoryModel('Politics', 'https://example.test/tax')],
            authorUrl: 'https://example.com/author',
        );

        $copy = $entry->withPostText(ParsedTitleModel::derived('New title'), null, '<p>New</p>');

        self::assertSame('New title', $copy->title);
        self::assertTrue($copy->titleDerived);
        self::assertNull($copy->summary);
        self::assertSame('<p>New</p>', $copy->contentHtml);
        self::assertSame('https://example.com/1', $copy->url);
        self::assertSame('Author', $copy->author);
        self::assertSame($entry->categories, $copy->categories);
        self::assertSame('https://example.com/author', $copy->authorUrl);
        self::assertSame('Old summary', $entry->withContentHtml('<p>x</p>')->summary);
    }

    public function testParsedTitleSaysWhetherTheTitleWasDerived(): void
    {
        $derived = (new ParsedEntryModel('guid', null, 'Post text', null, null, null, null, titleDerived: true))
            ->parsedTitle();
        $fromFeed = (new ParsedEntryModel('guid', null, 'Headline', null, null, null, null))->parsedTitle();

        self::assertSame(['Post text', true], [$derived->text, $derived->derived]);
        self::assertSame(['Headline', false], [$fromFeed->text, $fromFeed->derived]);
    }
```

In `PlatformEntryRulesWiringTest` add:

```php
    public function testTheContainersIngestorDropsABlueskyPlaceholder(): void
    {
        $ingestor = self::getContainer()->get(EntryIngestor::class);
        self::assertInstanceOf(EntryIngestor::class, $ingestor);
        $feed = new Feed('https://bsky.app/profile/bsky.app/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->flush();
        $post = new ParsedEntryModel(
            'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mxhdhodv222n',
            'https://bsky.app/profile/bsky.app/post/3mxhdhodv222n',
            'a masterclass in alt text',
            null,
            null,
            '<p>a masterclass in alt text</p><p>[contains quote post or other embedded content]</p>',
            null,
            titleDerived: true,
        );

        $ingestor->ingest(
            $feed,
            new ParsedFeedModel('Feed', null, null, null, [$post]),
            new FeedIngestContext(new \DateTimeImmutable('2026-10-10T12:00:00Z'), null),
        );
        $this->entityManager->flush();

        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['feed' => $feed]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('<p>a masterclass in alt text</p>', $entry->getContentHtml());
        self::assertSame('a masterclass in alt text', $entry->getSummary());
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Ingest tests/Service/Parser/Model`
Expected: FAIL — classes `AtPostUri` and `BlueskyEntryRule` not found, `withPostText`/`parsedTitle` undefined, the wiring test stores the placeholder.

- [ ] **Step 3: Implement**

`AtPostUri`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest\Support;

/** A Bluesky post's AT URI, `at://<did>/app.bsky.feed.post/<rkey>`: the guid of a Bluesky feed item. */
final class AtPostUri
{
    private const string PATTERN = '#^at://([^/]+)/app\.bsky\.feed\.post/([^/]+)$#';

    public static function matches(string $uri): bool
    {
        return preg_match(self::PATTERN, $uri) === 1;
    }

    public static function webUrl(string $uri): ?string
    {
        if (preg_match(self::PATTERN, $uri, $parts) !== 1) {
            return null;
        }

        return 'https://bsky.app/profile/' . $parts[1] . '/post/' . $parts[2];
    }

    private function __construct()
    {
    }
}
```

`ParsedEntryModel` — add the two methods and let `withContentHtml()` delegate (its explicit constructor call goes):

```php
    public function parsedTitle(): ParsedTitleModel
    {
        return $this->titleDerived ? ParsedTitleModel::derived($this->title) : ParsedTitleModel::fromFeed($this->title);
    }

    public function withPostText(ParsedTitleModel $title, ?string $summary, ?string $contentHtml): self
    {
        return new self(
            guid: $this->guid,
            url: $this->url,
            title: $title->text,
            author: $this->author,
            summary: $summary,
            contentHtml: $contentHtml,
            publishedAt: $this->publishedAt,
            media: $this->media,
            categories: $this->categories,
            discussion: $this->discussion,
            authorUrl: $this->authorUrl,
            titleDerived: $title->derived,
        );
    }

    public function withContentHtml(?string $contentHtml): self
    {
        return $this->withPostText($this->parsedTitle(), $this->summary, $contentHtml);
    }
```

`ParsedTitleModel` — one more named constructor, next to `untitled()`:

```php
    public static function untitledPost(): self
    {
        return new self(self::UNTITLED, true);
    }
```

`BlueskyEntryRule`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Ingest\Support\AtPostUri;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedTitleModel;
use App\Service\Parser\Support\EntryTitle;

/** Bluesky's RSS puts a placeholder line where a post embeds something; neither the body nor the title keeps it. */
final readonly class BlueskyEntryRule implements PlatformEntryRuleInterface
{
    private const string ESCAPED_PLACEHOLDER = '\[contains quote post or other embedded content\]';
    private const string PLACEHOLDER_PATTERN = '#<p>\s*' . self::ESCAPED_PLACEHOLDER . '\s*</p>'
        . '|(?:<br\s*/?>)?\s*' . self::ESCAPED_PLACEHOLDER . '#u';

    public function supports(ParsedEntryModel $entry): bool
    {
        return AtPostUri::matches($entry->guid);
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $summary = self::withoutPlaceholder($entry->summary);
        $contentHtml = self::withoutPlaceholder($entry->contentHtml);
        $title = $entry->titleDerived ? self::postTitle($contentHtml ?? $summary) : $entry->parsedTitle();

        return $entry->withPostText($title, $summary, $contentHtml);
    }

    private static function postTitle(?string $text): ParsedTitleModel
    {
        $title = EntryTitle::of(null, $text);

        return $title->derived ? $title : ParsedTitleModel::untitledPost();
    }

    private static function withoutPlaceholder(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $kept = trim(preg_replace(self::PLACEHOLDER_PATTERN, '', $text) ?? $text);

        return $kept === '' ? null : $kept;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Ingest tests/Service/Parser`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`. Then:

```bash
git add backend/src/Service/Ingest backend/src/Service/Parser/Model/ParsedEntryModel.php backend/src/Service/Parser/Model/ParsedTitleModel.php backend/tests/Service/Ingest backend/tests/Service/Parser/Model/ParsedEntryModelTest.php
git commit -m "feat(#1499): a bluesky post never stores the embed placeholder"
```

---

### Task 2: The pending-enrichment table

**Files:**
- Create: `backend/src/Entity/PendingPostEnrichment.php`
- Create: `backend/src/Repository/PendingPostEnrichmentRepository.php`
- Create: `backend/migrations/Version20261010180000.php`
- Test: `backend/tests/Repository/PendingPostEnrichmentRepositoryTest.php`

**Interfaces:**
- Produces: `new PendingPostEnrichment(Entry $entry, \DateTimeImmutable $queuedAt)`, `getEntry(): Entry`, `getQueuedAt(): \DateTimeImmutable`; `PendingPostEnrichmentRepository::deleteQueuedBefore(Feed $feed, \DateTimeImmutable $cutoff): void`, `PendingPostEnrichmentRepository::findOldestForFeed(Feed $feed, int $limit): list<PendingPostEnrichment>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
use App\Tests\DbTestCase;

final class PendingPostEnrichmentRepositoryTest extends DbTestCase
{
    public function testFindsAFeedsRowsOldestFirstUpToTheLimit(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $other = $this->feed('https://bsky.app/profile/b/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/new', '2026-10-10 11:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/old', '2026-10-10 09:00:00');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/mid', '2026-10-10 10:00:00');
        $this->queued($other, 'at://did:plc:b/app.bsky.feed.post/elsewhere', '2026-10-10 08:00:00');
        $this->entityManager->flush();

        $rows = $this->repository()->findOldestForFeed($feed, 2);

        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/old', 'at://did:plc:a/app.bsky.feed.post/mid'],
            self::guidsOf($rows),
        );
    }

    public function testDeletesOnlyTheFeedsRowsQueuedBeforeTheCutoff(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $other = $this->feed('https://bsky.app/profile/b/rss');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/old', '2026-10-10 09:59:59');
        $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/at-cutoff', '2026-10-10 10:00:00');
        $this->queued($other, 'at://did:plc:b/app.bsky.feed.post/elsewhere', '2026-10-10 08:00:00');
        $this->entityManager->flush();

        $this->repository()->deleteQueuedBefore($feed, new \DateTimeImmutable('2026-10-10 10:00:00'));

        self::assertSame(
            ['at://did:plc:a/app.bsky.feed.post/at-cutoff'],
            self::guidsOf($this->repository()->findOldestForFeed($feed, 10)),
        );
        self::assertCount(1, $this->repository()->findOldestForFeed($other, 10));
    }

    public function testARowGoesWithItsEntry(): void
    {
        $feed = $this->feed('https://bsky.app/profile/a/rss');
        $entry = $this->queued($feed, 'at://did:plc:a/app.bsky.feed.post/gone', '2026-10-10 09:00:00')->getEntry();
        $this->entityManager->flush();
        $connection = $this->entityManager->getConnection();

        $connection->executeStatement('DELETE FROM entry WHERE id = ?', [$entry->requireId()]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM pending_post_enrichment'));
    }

    /**
     * @param list<PendingPostEnrichment> $rows
     *
     * @return list<string>
     */
    private static function guidsOf(array $rows): array
    {
        return array_map(static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(), $rows);
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);

        return $feed;
    }

    private function queued(Feed $feed, string $guid, string $queuedAt): PendingPostEnrichment
    {
        $entry = new Entry(
            $feed,
            $guid,
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 08:00:00'),
            new \DateTimeImmutable('2026-10-10 08:00:00'),
        );
        $pending = new PendingPostEnrichment($entry, new \DateTimeImmutable($queuedAt));
        $this->entityManager->persist($entry);
        $this->entityManager->persist($pending);

        return $pending;
    }

    private function repository(): PendingPostEnrichmentRepository
    {
        $repository = self::getContainer()->get(PendingPostEnrichmentRepository::class);
        self::assertInstanceOf(PendingPostEnrichmentRepository::class, $repository);

        return $repository;
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Repository/PendingPostEnrichmentRepositoryTest.php`
Expected: FAIL — class `PendingPostEnrichment` not found.

- [ ] **Step 3: Implement**

`PendingPostEnrichment`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PendingPostEnrichmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A stored Bluesky post whose embed the AppView has not filled yet. Transient, so it stays out of the backup. */
#[ORM\Entity(repositoryClass: PendingPostEnrichmentRepository::class)]
#[ORM\Table(name: 'pending_post_enrichment')]
final class PendingPostEnrichment
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private Entry $entry;

    #[ORM\Column(name: 'queued_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $queuedAt;

    public function __construct(Entry $entry, \DateTimeImmutable $queuedAt)
    {
        $this->entry = $entry;
        $this->queuedAt = $queuedAt;
    }

    public function getEntry(): Entry
    {
        return $this->entry;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }
}
```

`PendingPostEnrichmentRepository`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PendingPostEnrichment> */
final class PendingPostEnrichmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingPostEnrichment::class);
    }

    public function deleteQueuedBefore(Feed $feed, \DateTimeImmutable $cutoff): void
    {
        $this->getEntityManager()->createQuery(sprintf(
            'DELETE FROM %s pending WHERE pending.queuedAt < :cutoff'
            . ' AND IDENTITY(pending.entry) IN (SELECT queuedEntry.id FROM %s queuedEntry WHERE queuedEntry.feed = :feed)',
            PendingPostEnrichment::class,
            Entry::class,
        ))
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->setParameter('feed', $feed)
            ->execute();
    }

    /** @return list<PendingPostEnrichment> */
    public function findOldestForFeed(Feed $feed, int $limit): array
    {
        /** @var list<PendingPostEnrichment> $rows */
        $rows = $this->createQueryBuilder('pending')
            ->innerJoin('pending.entry', 'queuedEntry')
            ->addSelect('queuedEntry')
            ->andWhere('queuedEntry.feed = :feed')
            ->setParameter('feed', $feed)
            ->orderBy('pending.queuedAt', 'ASC')
            ->addOrderBy('queuedEntry.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
```

`Version20261010180000` (the `mysql()` guard is the one `Version20261006120000` uses):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending_post_enrichment, the Bluesky posts whose embeds await the AppView (#1499).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('pending_post_enrichment'), 'pending_post_enrichment already exists.');

        if ($this->mysql()) {
            $this->addSql('CREATE TABLE pending_post_enrichment (entry_id INT NOT NULL, queued_at DATETIME NOT NULL,'
                . ' PRIMARY KEY (entry_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE pending_post_enrichment ADD CONSTRAINT FK_BA362B72BA364942'
                . ' FOREIGN KEY (entry_id) REFERENCES entry (id) ON DELETE CASCADE');

            return;
        }

        $this->addSql('CREATE TABLE pending_post_enrichment (entry_id INTEGER NOT NULL, queued_at DATETIME NOT NULL,'
            . ' PRIMARY KEY (entry_id), CONSTRAINT FK_BA362B72BA364942 FOREIGN KEY (entry_id) REFERENCES entry (id)'
            . ' ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('pending_post_enrichment'), 'pending_post_enrichment does not exist.');

        $this->addSql('DROP TABLE pending_post_enrichment');
    }

    public function isTransactional(): bool
    {
        return false;
    }

    /** Refuses any platform but the two supported ones: better a refusal than DDL nobody tested. */
    private function mysql(): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        $this->abortIf(
            !$platform instanceof AbstractMySQLPlatform && !$platform instanceof SQLitePlatform,
            \sprintf('No DDL defined for platform %s; only MySQL and SQLite are supported.', $platform::class),
        );

        return $platform instanceof AbstractMySQLPlatform;
    }
}
```

`FK_BA362B72BA364942` is DBAL's generated name (`FK_` + crc32 hex of `pending_post_enrichment` and `entry_id`); DBAL's comparator ignores FK names, so a mismatch would not break validation, but keep it.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Repository/PendingPostEnrichmentRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Verify the migration on both dialects**

MySQL (Docker, read-only first): `docker compose exec php bin/console doctrine:schema:update --dump-sql`
Expected: exactly the `CREATE TABLE pending_post_enrichment …` and `ALTER TABLE … ADD CONSTRAINT …` of the migration's MySQL branch (column order, types and options). If Doctrine prints anything different, copy its statements into the migration. Then apply to the live Docker DB (the php-fpm container already reads the new mapping):

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php bin/console doctrine:schema:update --dump-sql
```
Expected: migrate runs `Version20261010180000`; validate prints `[OK]` for mapping and database; the last command prints `[OK] Nothing to update`.

SQLite (from `backend/`, a scratch file, as CI's migration leg does):

```bash
rm -f var/migration-check.db
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:migrations:migrate --no-interaction
DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:schema:validate
rm -f var/migration-check.db
```
Expected: both `[OK]`.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`. Then:

```bash
git add backend/src/Entity/PendingPostEnrichment.php backend/src/Repository/PendingPostEnrichmentRepository.php backend/migrations/Version20261010180000.php backend/tests/Repository/PendingPostEnrichmentRepositoryTest.php
git commit -m "feat(#1499): a table of bluesky posts awaiting their embeds"
```

---

### Task 3: The AppView client

**Files:**
- Create: `backend/src/Service/Bluesky/Model/JsonNodeModel.php`
- Create: `backend/src/Service/Bluesky/Exception/AppViewAnswerException.php`
- Create: `backend/src/Service/Bluesky/AppViewClient.php`
- Create: `backend/tests/Fixtures/Bluesky/external.json` (content in Task 4, Step 1 — create all six fixtures now; this task uses `external.json`)
- Test: `backend/tests/Service/Bluesky/Model/JsonNodeModelTest.php`
- Test: `backend/tests/Service/Bluesky/AppViewClientTest.php`

**Interfaces:**
- Produces: `JsonNodeModel::of(mixed $value): self`, `->type(): ?string`, `->string(string $key): ?string` (trimmed, null when absent, not a string or blank), `->int(string $key): ?int`, `->node(string $key): self`, `->nodes(string $key): list<self>`; `AppViewClient::URIS_PER_CALL = 25`, `AppViewClient::isThrottled(): bool`, `AppViewClient::posts(list<string> $uris): array<string, JsonNodeModel>` (`@throws FetchException`, `@throws AppViewAnswerException`).

- [ ] **Step 1: Write the failing tests**

Create the six fixtures of Task 4, Step 1 first.

`JsonNodeModelTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Model;

use App\Service\Bluesky\Model\JsonNodeModel;
use PHPUnit\Framework\TestCase;

final class JsonNodeModelTest extends TestCase
{
    public function testReadsTypedFieldsAndTreatsTheWrongTypeAsAbsent(): void
    {
        $node = JsonNodeModel::of([
            '$type' => 'app.bsky.embed.images#view',
            'alt' => '  a cat  ',
            'blank' => '   ',
            'width' => 4000,
            'height' => '3000',
        ]);

        self::assertSame('app.bsky.embed.images#view', $node->type());
        self::assertSame('a cat', $node->string('alt'));
        self::assertNull($node->string('blank'));
        self::assertNull($node->string('width'));
        self::assertNull($node->string('missing'));
        self::assertSame(4000, $node->int('width'));
        self::assertNull($node->int('height'));
    }

    public function testNestedNodesAndListsDegradeToEmpty(): void
    {
        $node = JsonNodeModel::of([
            'author' => ['handle' => 'bsky.app'],
            'images' => [['alt' => 'one'], ['alt' => 'two']],
            'map' => ['first' => ['alt' => 'x']],
            'text' => 'not a node',
        ]);

        self::assertSame('bsky.app', $node->node('author')->string('handle'));
        self::assertNull($node->node('text')->string('handle'));
        self::assertSame(['one', 'two'], array_map(
            static fn (JsonNodeModel $image): ?string => $image->string('alt'),
            $node->nodes('images'),
        ));
        self::assertSame([], $node->nodes('map'));
        self::assertSame([], $node->nodes('missing'));
        self::assertNull(JsonNodeModel::of('scalar')->type());
    }
}
```

`AppViewClientTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Tests\Support\ReadsFixtures;
use App\Tests\Support\StubFeedFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class AppViewClientTest extends TestCase
{
    use ReadsFixtures;

    private const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts';
    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string GONE = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxgone';
    private const string TISCH_QUERY = 'uris=at%3A%2F%2Fdid%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi%2Fapp.bsky.feed.post'
        . '%2F3mxjuesq6v62t';
    private const string GONE_QUERY = 'uris=at%3A%2F%2Fdid%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi%2Fapp.bsky.feed.post'
        . '%2F3mxgone';

    private StubFeedFetcher $fetcher;
    private HostThrottle $throttle;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00', 'UTC');
        $this->fetcher = new StubFeedFetcher();
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $clock), $clock);
    }

    public function testAsksForEveryUriInOneRequestAndKeysTheAnswerByUri(): void
    {
        $url = self::GET_POSTS . '?' . self::TISCH_QUERY . '&' . self::GONE_QUERY;
        $this->fetcher->willReturnBody($url, $this->fixture('Bluesky/external.json'));

        $posts = $this->client()->posts([self::TISCH, self::GONE]);

        self::assertSame([$url], $this->fetcher->fetchedUrls);
        self::assertSame([self::TISCH], array_keys($posts));
        self::assertSame('Mother Jones', $posts[self::TISCH]->node('author')->string('displayName'));
    }

    public function testAPostWithoutAUriIsLeftOut(): void
    {
        $url = self::GET_POSTS . '?' . self::TISCH_QUERY;
        $this->fetcher->willReturnBody($url, '{"posts":[{"cid":"bafy"},{"uri":"' . self::TISCH . '"}]}');

        self::assertSame([self::TISCH], array_keys($this->client()->posts([self::TISCH])));
    }

    public function testAThrottledAnswerIsRecordedForTheHostAndRethrown(): void
    {
        $this->fetcher->willThrow(self::GET_POSTS . '?' . self::TISCH_QUERY, new FeedThrottledException('HTTP 429', 300));
        $client = $this->client();
        self::assertFalse($client->isThrottled());

        try {
            $client->posts([self::TISCH]);
            self::fail('A throttled answer must be rethrown.');
        } catch (FeedThrottledException) {
        }

        self::assertTrue($client->isThrottled());
        self::assertSame(300, $this->throttle->remainingSeconds(self::GET_POSTS));
    }

    public function testAnotherFetchFailureRecordsNoThrottle(): void
    {
        $this->fetcher->willThrow(self::GET_POSTS . '?' . self::TISCH_QUERY, new FeedUnreachableException('timeout'));
        $client = $this->client();

        try {
            $client->posts([self::TISCH]);
            self::fail('A fetch failure must be rethrown.');
        } catch (FeedUnreachableException) {
        }

        self::assertFalse($client->isThrottled());
    }

    /** @return iterable<string, array{string}> */
    public static function unusableAnswers(): iterable
    {
        yield 'not JSON' => ['<html>Bad gateway</html>'];
        yield 'an error object' => ['{"error":"InvalidRequest","message":"Missing required key \"uris\""}'];
        yield 'posts that are not a list' => ['{"posts":{"first":{"uri":"at://x"}}}'];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnAnswerWithoutAPostsListIsRejected(string $body): void
    {
        $this->fetcher->willReturnBody(self::GET_POSTS . '?' . self::TISCH_QUERY, $body);

        $this->expectException(AppViewAnswerException::class);

        $this->client()->posts([self::TISCH]);
    }

    private function client(): AppViewClient
    {
        return new AppViewClient($this->fetcher, $this->throttle);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Bluesky`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`JsonNodeModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Model;

/** One object of an AppView answer, read field by field: a field of the wrong type reads as absent. */
final readonly class JsonNodeModel
{
    /** @param array<mixed> $fields */
    private function __construct(private array $fields)
    {
    }

    public static function of(mixed $value): self
    {
        return new self(\is_array($value) ? $value : []);
    }

    public function type(): ?string
    {
        return $this->string('$type');
    }

    public function string(string $key): ?string
    {
        $value = $this->fields[$key] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function int(string $key): ?int
    {
        $value = $this->fields[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    public function node(string $key): self
    {
        return self::of($this->fields[$key] ?? null);
    }

    /** @return list<self> */
    public function nodes(string $key): array
    {
        $value = $this->fields[$key] ?? null;

        return \is_array($value) && array_is_list($value) ? array_map(self::of(...), $value) : [];
    }
}
```

`AppViewAnswerException`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Exception;

final class AppViewAnswerException extends \RuntimeException
{
}
```

`AppViewClient`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;

/** Bluesky's public AppView, asked anonymously through the SSRF-guarded fetcher. */
final readonly class AppViewClient
{
    public const int URIS_PER_CALL = 25;

    private const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts';

    public function __construct(
        private FeedFetcherInterface $fetcher,
        private HostThrottle $hostThrottle,
    ) {
    }

    public function isThrottled(): bool
    {
        return $this->hostThrottle->remainingSeconds(self::GET_POSTS) > 0;
    }

    /**
     * @param list<string> $uris at most URIS_PER_CALL post URIs
     *
     * @return array<string, JsonNodeModel> the post views by URI; a deleted or hidden post is absent
     *
     * @throws FetchException
     * @throws AppViewAnswerException
     */
    public function posts(array $uris): array
    {
        try {
            $body = $this->fetcher->fetch(self::postsUrl($uris))->modifiedBody();
        } catch (FeedThrottledException $exception) {
            $this->hostThrottle->record(self::GET_POSTS, $exception->retryAfterSeconds);

            throw $exception;
        }

        return self::byUri($body);
    }

    /** @param list<string> $uris */
    private static function postsUrl(array $uris): string
    {
        $query = array_map(static fn (string $uri): string => 'uris=' . rawurlencode($uri), $uris);

        return self::GET_POSTS . '?' . implode('&', $query);
    }

    /**
     * @return array<string, JsonNodeModel>
     *
     * @throws AppViewAnswerException
     */
    private static function byUri(string $body): array
    {
        $answer = json_decode($body, true);
        $views = \is_array($answer) ? $answer['posts'] ?? null : null;
        if (!\is_array($views) || !array_is_list($views)) {
            throw new AppViewAnswerException('The AppView answered without a list of posts.');
        }

        $posts = [];
        foreach ($views as $view) {
            $post = JsonNodeModel::of($view);
            $uri = $post->string('uri');
            if ($uri !== null) {
                $posts[$uri] = $post;
            }
        }

        return $posts;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Bluesky`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`. Then:

```bash
git add backend/src/Service/Bluesky backend/tests/Service/Bluesky backend/tests/Fixtures/Bluesky
git commit -m "feat(#1499): ask bluesky's public appview for posts by uri"
```

---

### Task 4: Render a post's embed

**Files:**
- Create: `backend/tests/Fixtures/Bluesky/{external,video,images,record,record-with-media,starter-pack}.json` (if Task 3 did not)
- Create: `backend/src/Service/Bluesky/Model/RenderedEmbedModel.php`
- Create: `backend/src/Service/Bluesky/PostEmbedRenderer.php`
- Test: `backend/tests/Service/Bluesky/PostEmbedRendererTest.php`

**Interfaces:**
- Consumes: `JsonNodeModel` (Task 3), `AtPostUri::webUrl` (Task 1), `HttpsImageUrl::orNull`, `ParagraphedText::asHtml(string, \Closure(string): string)`, `ParsedMediumModel`, `VisualMediaKind`, `DeclaredImageModel`.
- Produces: `PostEmbedRenderer::render(JsonNodeModel $post): ?RenderedEmbedModel` (null: no `embed`, or the post URI is not an AT post URI); `RenderedEmbedModel { string $html; ?DeclaredImageModel $leadImage; list<ParsedMediumModel> $media; ?string $linkCardUrl; followedBy(self): self }`.

- [ ] **Step 1: Fixtures**

Recorded on 2026-10-10 from `getAuthorFeed` (`.feed[].post` has the `getPosts` post-view shape), trimmed to the fields the code reads plus the quoted post's own `embeds` (to prove it is not rendered). Each file is a `getPosts` answer. Write them byte for byte:

`external.json`:

```json
{
  "posts": [
    {
      "uri": "at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t",
      "author": {"did": "did:plc:qobvnkudcv3zlaklxxjduqoi", "handle": "motherjones.com", "displayName": "Mother Jones"},
      "record": {
        "$type": "app.bsky.feed.post",
        "text": "Billionaire heiress Jessica Tisch is a holdover from Eric Adams’ mayoral administration who has pushed to expand surveillance infrastructure in New York City. Progressives are calling on her to step down if ICE doesn't leave the city. www.motherjones.com/politics/202..."
      },
      "embed": {
        "external": {
          "uri": "https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/",
          "title": "After ICE Shooting, Progressives Want New York's Police Commissioner to Step Down",
          "description": "28-year-old Oscar Belgal still has a bullet lodged in his body.",
          "thumb": "https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/bafkreie2nvxxbwowodsbtm3rksbshxxyrzr7jp6qkebllexxwjkmjs3a4y"
        },
        "$type": "app.bsky.embed.external#view"
      }
    }
  ]
}
```

`video.json`:

```json
{
  "posts": [
    {
      "uri": "at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhlfehxzi27",
      "author": {"did": "did:plc:qobvnkudcv3zlaklxxjduqoi", "handle": "motherjones.com", "displayName": "Mother Jones"},
      "record": {
        "$type": "app.bsky.feed.post",
        "text": "From battleground candidates to Elon Musk, Republicans can’t stop posting wistfully about Rhodesia, the defunct white supremacist state in southern Africa.\n\nHistory repeats. Sometimes as tragedy…sometimes as LARP."
      },
      "embed": {
        "cid": "bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde",
        "playlist": "https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/playlist.m3u8",
        "thumbnail": "https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/thumbnail.jpg",
        "aspectRatio": {"height": 1920, "width": 1080},
        "$type": "app.bsky.embed.video#view"
      }
    }
  ]
}
```

`images.json`:

```json
{
  "posts": [
    {
      "uri": "at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mv3shqdfuc2e",
      "author": {"did": "did:plc:z72i7hdynmk6r22z27h6tvur", "handle": "bsky.app", "displayName": "Bluesky"},
      "record": {
        "$type": "app.bsky.feed.post",
        "text": "Not to brag, but ... we snagged a couple of passes to today’s Apple event. Stay tuned for our take on all the latest and greatest Apple products."
      },
      "embed": {
        "images": [
          {
            "thumb": "https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:z72i7hdynmk6r22z27h6tvur/bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4",
            "fullsize": "https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:z72i7hdynmk6r22z27h6tvur/bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4",
            "alt": "An enormous pile of red apples and green apples—sweet, tart, delicious, and coming soon to an Apple Store near you! Keep your eye on this thread (and maybe put on a sturdy hat) to learn more about today's big drops.",
            "aspectRatio": {"height": 3000, "width": 4000}
          }
        ],
        "$type": "app.bsky.embed.images#view"
      }
    }
  ]
}
```

`record.json`:

```json
{
  "posts": [
    {
      "uri": "at://did:plc:mtr3pwmbnirvyp3robuicd57/app.bsky.feed.post/3mxhy7vmqec2f",
      "author": {"did": "did:plc:mtr3pwmbnirvyp3robuicd57", "handle": "davidcorn.bsky.social", "displayName": "David Corn"},
      "record": {
        "$type": "app.bsky.feed.post",
        "text": "Hey, it’s another episode of STRESS TEST. Check it out. And please subscribe at youtube.com/motherjones."
      },
      "embed": {
        "record": {
          "$type": "app.bsky.embed.record#viewRecord",
          "uri": "at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhvfsp7n32t",
          "author": {"did": "did:plc:qobvnkudcv3zlaklxxjduqoi", "handle": "motherjones.com", "displayName": "Mother Jones"},
          "value": {
            "$type": "app.bsky.feed.post",
            "text": "Big money has long dominated American politics, with wealthy donors, corporations, and special interests dumping tons of cash into presidential and congressional elections. \n\nBut this year, the quid pro quo of campaign money for preferential government treatment is more brazen than ever before."
          },
          "embeds": [
            {
              "$type": "app.bsky.embed.external#view",
              "external": {
                "uri": "https://www.motherjones.com/politics/2026/10/stress-test-campaign-finance-corruption-midterms-video-podcast/",
                "title": "The midterm spending bonanza is threatening democracy like never before."
              }
            }
          ]
        },
        "$type": "app.bsky.embed.record#view"
      }
    }
  ]
}
```

`record-with-media.json`:

```json
{
  "posts": [
    {
      "uri": "at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxh7eyc2bs2b",
      "author": {"did": "did:plc:qobvnkudcv3zlaklxxjduqoi", "handle": "motherjones.com", "displayName": "Mother Jones"},
      "record": {
        "$type": "app.bsky.feed.post",
        "text": "Come ask Amy anything about her coverage of Kristan Hawkins and the anti-abortion movement!"
      },
      "embed": {
        "media": {
          "external": {
            "uri": "https://sh.reddit.com/r/IAmA/comments/1x1n1i1/after_the_fall_of_roe_v_wade_the_antiabortion/",
            "title": "From the IAmA community on Reddit",
            "description": "Explore this post and more from the IAmA community",
            "thumb": "https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/bafkreihbcj6mbqkkoffjnzm4qlwje6vezlsydmbv4hljgllcqrkjs2kg7q"
          },
          "$type": "app.bsky.embed.external#view"
        },
        "record": {
          "record": {
            "$type": "app.bsky.embed.record#viewRecord",
            "uri": "at://did:plc:rhgbyqyye2vpydw7c75j4wnr/app.bsky.feed.post/3mxh76vhjfk2k",
            "author": {"did": "did:plc:rhgbyqyye2vpydw7c75j4wnr", "handle": "amylittlefield.bsky.social", "displayName": "Amy Littlefield"},
            "value": {
              "$type": "app.bsky.feed.post",
              "text": "I spent months following Kristan Hawkins, president of Students for Life and one of the country’s most influential anti-abortion leaders, for a profile and doc for @motherjones.com and @revealnews.org \n\nLots of fascinating details didn’t make it into the piece. \n\nHead to Reddit to ask me anything!"
            }
          }
        },
        "$type": "app.bsky.embed.recordWithMedia#view"
      }
    }
  ]
}
```

`starter-pack.json`:

```json
{
  "posts": [
    {
      "uri": "at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mwolmfws5k2r",
      "author": {"did": "did:plc:z72i7hdynmk6r22z27h6tvur", "handle": "bsky.app", "displayName": "Bluesky"},
      "record": {
        "$type": "app.bsky.feed.post",
        "text": "Happy opening day of hockey season, NHL fans! \n\nKeep up with all 1,344 regular season games with this starter pack of hockey writers."
      },
      "embed": {
        "record": {
          "$type": "app.bsky.graph.defs#starterPackViewBasic",
          "uri": "at://did:plc:54vdi5eoabhod2vumsbcpi7g/app.bsky.graph.starterpack/3lgml5ek7772a",
          "cid": "bafyreig5co3zqwje5vtj535kqfc3rvdofgeh25br43ovzs57ezspkha6dm",
          "record": {"$type": "app.bsky.graph.starterpack", "name": "NHL Writers"}
        },
        "$type": "app.bsky.embed.record#view"
      }
    }
  ]
}
```

- [ ] **Step 2: Write the failing test**

Every expected string below was produced by running the Step 3 code over these fixtures and through the real `EntrySanitizer`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PostEmbedRendererTest extends TestCase
{
    use ReadsFixtures;

    private const string MOTHER_JONES = 'https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/post/';
    private const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string CARD_THUMB = 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreie2nvxxbwowodsbtm3rksbshxxyrzr7jp6qkebllexxwjkmjs3a4y';
    private const string VIDEO = 'https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/';
    private const string FULLSIZE = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:z72i7hdynmk6r22z27h6tvur/'
        . 'bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4';
    private const string REDDIT_CARD = 'https://sh.reddit.com/r/IAmA/comments/1x1n1i1/'
        . 'after_the_fall_of_roe_v_wade_the_antiabortion/';
    private const string REDDIT_THUMB = 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreihbcj6mbqkkoffjnzm4qlwje6vezlsydmbv4hljgllcqrkjs2kg7q';
    private const string FALLBACK = '<p><a href="https://bsky.app/profile/did:plc:a/post/b">'
        . 'View embedded content on Bluesky</a></p>';

    public function testALinkCardLinksTheArticleWithItsTitleDescriptionAndHost(): void
    {
        $embed = $this->rendered('external');

        self::assertSame(
            '<figure class="link-card"><a href="' . self::CARD . '"><img src="' . self::CARD_THUMB . '" alt="">'
                . '<strong>After ICE Shooting, Progressives Want New York&apos;s Police Commissioner to Step Down'
                . '</strong><span>28-year-old Oscar Belgal still has a bullet lodged in his body.</span>'
                . '<small>motherjones.com</small></a></figure>',
            $embed->html,
        );
        self::assertSame(self::CARD, $embed->linkCardUrl);
        self::assertSame(self::CARD_THUMB, $embed->leadImage?->url);
        self::assertSame([], $embed->media);
    }

    public function testAVideoPlaysThePlaylistUnderItsPosterAndLinksThePost(): void
    {
        $embed = $this->rendered('video');

        self::assertSame(
            '<figure class="post-video"><video controls preload="none" playsinline poster="' . self::VIDEO
                . 'thumbnail.jpg" src="' . self::VIDEO . 'playlist.m3u8"></video></figure>'
                . '<p><a href="' . self::MOTHER_JONES . '3mxhlfehxzi27">Watch on Bluesky</a></p>',
            $embed->html,
        );
        self::assertSame(
            [self::VIDEO . 'thumbnail.jpg', 1080, 1920],
            [$embed->leadImage?->url, $embed->leadImage?->width, $embed->leadImage?->height],
        );
        self::assertEquals(
            [new ParsedMediumModel(self::VIDEO . 'playlist.m3u8', VisualMediaKind::Video, 1080, 1920, self::VIDEO . 'thumbnail.jpg')],
            $embed->media,
        );
        self::assertNull($embed->linkCardUrl);
    }

    public function testImagesShowFullSizeWithAltTextAndDimensions(): void
    {
        $embed = $this->rendered('images');

        self::assertSame(
            '<figure class="post-images"><img src="' . self::FULLSIZE . '" alt="An enormous pile of red apples and'
                . ' green apples—sweet, tart, delicious, and coming soon to an Apple Store near you! Keep your eye on'
                . ' this thread (and maybe put on a sturdy hat) to learn more about today&apos;s big drops."'
                . ' width="4000" height="3000"></figure>',
            $embed->html,
        );
        self::assertSame([self::FULLSIZE, 4000, 3000], [$embed->leadImage?->url, $embed->leadImage?->width, $embed->leadImage?->height]);
        self::assertEquals([new ParsedMediumModel(self::FULLSIZE, VisualMediaKind::Image, 4000, 3000)], $embed->media);
    }

    public function testAQuoteShowsTheQuotedTextAndAuthorButNotTheQuotedPostsOwnEmbed(): void
    {
        $embed = $this->rendered('record');

        self::assertSame(
            '<figure class="quote-post"><blockquote><p>Big money has long dominated American politics, with wealthy'
                . ' donors, corporations, and special interests dumping tons of cash into presidential and congressional'
                . ' elections.</p><p>But this year, the quid pro quo of campaign money for preferential government'
                . ' treatment is more brazen than ever before.</p><footer><a href="' . self::MOTHER_JONES
                . '3mxhvfsp7n32t">Mother Jones (@motherjones.com)</a></footer></blockquote></figure>',
            $embed->html,
        );
        self::assertStringNotContainsString('stress-test', $embed->html);
        self::assertNull($embed->leadImage);
        self::assertNull($embed->linkCardUrl);
    }

    public function testAQuoteWithMediaRendersTheQuoteThenTheMedia(): void
    {
        $embed = $this->rendered('record-with-media');

        self::assertStringStartsWith('<figure class="quote-post"><blockquote><p>I spent months', $embed->html);
        self::assertStringContainsString(
            '<footer><a href="https://bsky.app/profile/did:plc:rhgbyqyye2vpydw7c75j4wnr/post/3mxh76vhjfk2k">'
                . 'Amy Littlefield (@amylittlefield.bsky.social)</a></footer></blockquote></figure>'
                . '<figure class="link-card"><a href="' . self::REDDIT_CARD . '">',
            $embed->html,
        );
        self::assertStringEndsWith(
            '<strong>From the IAmA community on Reddit</strong><span>Explore this post and more from the IAmA'
                . ' community</span><small>sh.reddit.com</small></a></figure>',
            $embed->html,
        );
        self::assertSame(self::REDDIT_CARD, $embed->linkCardUrl);
        self::assertSame(self::REDDIT_THUMB, $embed->leadImage?->url);
    }

    public function testAStarterPackLinksThePostOnBluesky(): void
    {
        self::assertSame(
            '<p><a href="https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mwolmfws5k2r">'
                . 'View embedded content on Bluesky</a></p>',
            $this->rendered('starter-pack')->html,
        );
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function unrenderableEmbeds(): iterable
    {
        yield 'an unknown type' => [['$type' => 'app.bsky.embed.somethingNew#view']];
        yield 'a quoted post that is gone' => [[
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewNotFound',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'notFound' => true,
            ],
        ]];
        yield 'a video on http' => [['$type' => 'app.bsky.embed.video#view', 'playlist' => 'http://video.example/p.m3u8']];
        yield 'images on http only' => [[
            '$type' => 'app.bsky.embed.images#view',
            'images' => [['fullsize' => 'http://example.com/a.jpg', 'alt' => 'a']],
        ]];
        yield 'a link card to an http page' => [[
            '$type' => 'app.bsky.embed.external#view',
            'external' => ['uri' => 'http://example.com/a', 'title' => 'A'],
        ]];
    }

    /** @param array<mixed> $embed */
    #[DataProvider('unrenderableEmbeds')]
    public function testAnEmbedItCannotRenderLinksThePost(array $embed): void
    {
        $rendered = $this->renderer()->render(self::post($embed));

        self::assertSame(self::FALLBACK, $rendered?->html);
        self::assertNull($rendered?->leadImage);
    }

    public function testAPostWithoutAnEmbedRendersNothing(): void
    {
        self::assertNull($this->renderer()->render(JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b'])));
    }

    public function testAPostWhoseUriIsNoPostsRendersNothing(): void
    {
        self::assertNull($this->renderer()->render(JsonNodeModel::of([
            'uri' => 'at://did:plc:a/app.bsky.feed.repost/b',
            'embed' => ['$type' => 'app.bsky.embed.somethingNew#view'],
        ])));
    }

    public function testHostileLinkCardTextIsEscapedAndAnHttpThumbnailDropped(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.external#view',
            'external' => [
                'uri' => 'https://example.com/a?x=1&y=2',
                'title' => '<script>alert(1)</script>',
                'description' => 'Fish & "chips"',
                'thumb' => 'http://example.com/t.jpg',
            ],
        ]));

        self::assertSame(
            '<figure class="link-card"><a href="https://example.com/a?x=1&amp;y=2"><strong>&lt;script&gt;alert(1)'
                . '&lt;/script&gt;</strong><span>Fish &amp; &quot;chips&quot;</span><small>example.com</small></a>'
                . '</figure>',
            $rendered?->html,
        );
        self::assertNull($rendered?->leadImage);
    }

    public function testAnHttpImageIsDroppedAndAProtocolRelativeOneUpgraded(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.images#view',
            'images' => [
                ['fullsize' => 'http://example.com/a.jpg', 'alt' => 'a'],
                ['fullsize' => 'https://example.com/b.jpg', 'alt' => '<b>'],
                ['fullsize' => '//example.com/c.jpg'],
            ],
        ]));

        self::assertSame(
            '<figure class="post-images"><img src="https://example.com/b.jpg" alt="&lt;b&gt;">'
                . '<img src="https://example.com/c.jpg" alt=""></figure>',
            $rendered?->html,
        );
        self::assertSame('https://example.com/b.jpg', $rendered?->leadImage?->url);
        self::assertCount(2, $rendered?->media ?? []);
    }

    public function testAVideoWithoutAThumbnailHasNoPosterAndNoLeadImage(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.video#view',
            'playlist' => 'https://video.example/p.m3u8',
        ]));

        self::assertStringStartsWith(
            '<figure class="post-video"><video controls preload="none" playsinline src="https://video.example/p.m3u8">',
            (string) $rendered?->html,
        );
        self::assertNull($rendered?->leadImage);
        self::assertNull($rendered?->media[0]->previewImageUrl);
    }

    public function testAQuotedAuthorsHostileNameAndTextAreEscaped(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'evil.example', 'displayName' => '<img src=x onerror=alert(1)>'],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => "one <b>\ntwo"],
            ],
        ]));

        self::assertSame(
            '<figure class="quote-post"><blockquote><p>one &lt;b&gt;<br>two</p><footer>'
                . '<a href="https://bsky.app/profile/did:plc:c/post/d">&lt;img src=x onerror=alert(1)&gt;'
                . ' (@evil.example)</a></footer></blockquote></figure>',
            $rendered?->html,
        );
    }

    public function testAQuotedAuthorWithoutADisplayNameShowsTheHandle(): void
    {
        $rendered = $this->renderer()->render(self::post([
            '$type' => 'app.bsky.embed.record#view',
            'record' => [
                '$type' => 'app.bsky.embed.record#viewRecord',
                'uri' => 'at://did:plc:c/app.bsky.feed.post/d',
                'author' => ['handle' => 'pantspants.bsky.social', 'displayName' => ' '],
                'value' => ['$type' => 'app.bsky.feed.post', 'text' => ''],
            ],
        ]));

        self::assertSame(
            '<figure class="quote-post"><blockquote><footer><a href="https://bsky.app/profile/did:plc:c/post/d">'
                . '@pantspants.bsky.social</a></footer></blockquote></figure>',
            $rendered?->html,
        );
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function sanitizedParts(): iterable
    {
        yield 'images' => ['images', [
            '<figure class="post-images"><img src="' . self::FULLSIZE . '" alt="An enormous pile',
            ' width="4000" height="3000" /></figure>',
        ]];
        yield 'video' => ['video', [
            '<figure class="post-video"><video controls preload="none" playsinline poster="' . self::VIDEO
                . 'thumbnail.jpg" src="' . self::VIDEO . 'playlist.m3u8"></video></figure>',
        ]];
        yield 'link card' => ['external', [
            '<figure class="link-card"><a href="' . self::CARD . '" rel="noopener noreferrer" target="_blank">'
                . '<img src="' . self::CARD_THUMB . '" alt /><strong>',
            '<small>motherjones.com</small></a></figure>',
        ]];
        yield 'quote' => ['record', [
            '<figure class="quote-post"><blockquote><p>Big money',
            '<footer><a href="' . self::MOTHER_JONES . '3mxhvfsp7n32t" rel="noopener noreferrer" target="_blank">'
                . 'Mother Jones (&#64;motherjones.com)</a></footer></blockquote></figure>',
        ]];
        yield 'fallback' => ['starter-pack', [
            '<p><a href="https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3mwolmfws5k2r"'
                . ' rel="noopener noreferrer" target="_blank">View embedded content on Bluesky</a></p>',
        ]];
    }

    /** @param list<string> $parts */
    #[DataProvider('sanitizedParts')]
    public function testTheRenderedEmbedSurvivesTheSanitizer(string $fixture, array $parts): void
    {
        $sanitized = (string) (new EntrySanitizer(new TrailingBlankRemover()))->sanitize($this->rendered($fixture)->html);

        foreach ($parts as $part) {
            self::assertStringContainsString($part, $sanitized);
        }
    }

    private function rendered(string $fixture): RenderedEmbedModel
    {
        $answer = json_decode($this->fixture('Bluesky/' . $fixture . '.json'), true, flags: \JSON_THROW_ON_ERROR);
        $posts = JsonNodeModel::of($answer)->nodes('posts');
        self::assertCount(1, $posts);
        $rendered = $this->renderer()->render($posts[0]);
        self::assertNotNull($rendered);

        return $rendered;
    }

    /** @param array<mixed> $embed */
    private static function post(array $embed): JsonNodeModel
    {
        return JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b', 'embed' => $embed]);
    }

    private function renderer(): PostEmbedRenderer
    {
        return new PostEmbedRenderer();
    }
}
```

Keep lines ≤ 120 when transcribing (wrap the long `ParsedMediumModel` and `assertSame` calls).

- [ ] **Step 3: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Bluesky/PostEmbedRendererTest.php`
Expected: FAIL — class `PostEmbedRenderer` not found.

- [ ] **Step 4: Implement**

`RenderedEmbedModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Model;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedMediumModel;

/** A post's embed as HTML to follow its body, with the picture and media it brings and its link card's URL. */
final readonly class RenderedEmbedModel
{
    /** @param list<ParsedMediumModel> $media */
    public function __construct(
        public string $html,
        public ?DeclaredImageModel $leadImage = null,
        public array $media = [],
        public ?string $linkCardUrl = null,
    ) {
    }

    public function followedBy(self $next): self
    {
        return new self(
            $this->html . $next->html,
            $this->leadImage ?? $next->leadImage,
            [...$this->media, ...$next->media],
            $this->linkCardUrl ?? $next->linkCardUrl,
        );
    }
}
```

`PostEmbedRenderer`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Model\RenderedEmbedModel;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\Support\AtPostUri;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Text\Support\ParagraphedText;
use App\Service\Url\Support\HttpsImageUrl;

/** Renders the embed of one AppView post view as HTML; every text is escaped and every URL must be https. */
final readonly class PostEmbedRenderer
{
    private const string IMAGES = 'app.bsky.embed.images#view';
    private const string VIDEO = 'app.bsky.embed.video#view';
    private const string EXTERNAL = 'app.bsky.embed.external#view';
    private const string RECORD = 'app.bsky.embed.record#view';
    private const string RECORD_WITH_MEDIA = 'app.bsky.embed.recordWithMedia#view';
    private const string VIEW_RECORD = 'app.bsky.embed.record#viewRecord';
    private const string POST_RECORD = 'app.bsky.feed.post';
    private const string WATCH_LABEL = 'Watch on Bluesky';
    private const string FALLBACK_LABEL = 'View embedded content on Bluesky';

    /** Null when the post embeds nothing, or its URI is no post's. */
    public function render(JsonNodeModel $post): ?RenderedEmbedModel
    {
        $embed = $post->node('embed');
        $postUrl = AtPostUri::webUrl($post->string('uri') ?? '');
        if ($embed->type() === null || $postUrl === null) {
            return null;
        }

        return match ($embed->type()) {
            self::RECORD => $this->quoteOrFallback($embed->node('record'), $postUrl),
            self::RECORD_WITH_MEDIA => $this->quoteOrFallback($embed->node('record')->node('record'), $postUrl)
                ->followedBy($this->media($embed->node('media'), $postUrl)),
            default => $this->media($embed, $postUrl),
        };
    }

    private function media(JsonNodeModel $embed, string $postUrl): RenderedEmbedModel
    {
        $rendered = match ($embed->type()) {
            self::IMAGES => $this->images($embed),
            self::VIDEO => $this->video($embed, $postUrl),
            self::EXTERNAL => $this->linkCard($embed->node('external')),
            default => null,
        };

        return $rendered ?? $this->fallback($postUrl);
    }

    private function images(JsonNodeModel $embed): ?RenderedEmbedModel
    {
        $tags = '';
        $media = [];
        foreach ($embed->nodes('images') as $image) {
            $url = HttpsImageUrl::orNull($image->string('fullsize'));
            if ($url === null) {
                continue;
            }
            $ratio = $image->node('aspectRatio');
            $tags .= sprintf(
                '<img src="%s" alt="%s"%s>',
                self::escaped($url),
                self::escaped($image->string('alt') ?? ''),
                self::dimensions($ratio),
            );
            $media[] = new ParsedMediumModel($url, VisualMediaKind::Image, $ratio->int('width'), $ratio->int('height'));
        }
        if ($media === []) {
            return null;
        }
        $lead = new DeclaredImageModel($media[0]->url, $media[0]->width, $media[0]->height);

        return new RenderedEmbedModel('<figure class="post-images">' . $tags . '</figure>', $lead, $media);
    }

    private function video(JsonNodeModel $embed, string $postUrl): ?RenderedEmbedModel
    {
        $playlist = HttpsImageUrl::orNull($embed->string('playlist'));
        if ($playlist === null) {
            return null;
        }
        $thumbnail = HttpsImageUrl::orNull($embed->string('thumbnail'));
        $width = $embed->node('aspectRatio')->int('width');
        $height = $embed->node('aspectRatio')->int('height');
        $poster = $thumbnail === null ? '' : sprintf(' poster="%s"', self::escaped($thumbnail));

        return new RenderedEmbedModel(
            sprintf(
                '<figure class="post-video"><video controls preload="none" playsinline%s src="%s"></video></figure>',
                $poster,
                self::escaped($playlist),
            ) . self::linkParagraph($postUrl, self::WATCH_LABEL),
            $thumbnail === null ? null : new DeclaredImageModel($thumbnail, $width, $height),
            [new ParsedMediumModel($playlist, VisualMediaKind::Video, $width, $height, $thumbnail)],
        );
    }

    private function linkCard(JsonNodeModel $external): ?RenderedEmbedModel
    {
        $url = HttpsImageUrl::orNull($external->string('uri'));
        if ($url === null) {
            return null;
        }
        $thumb = HttpsImageUrl::orNull($external->string('thumb'));
        $card = ($thumb === null ? '' : sprintf('<img src="%s" alt="">', self::escaped($thumb)))
            . self::optionalTag('strong', $external->string('title'))
            . self::optionalTag('span', $external->string('description'))
            . self::optionalTag('small', self::host($url));

        return new RenderedEmbedModel(
            sprintf('<figure class="link-card"><a href="%s">%s</a></figure>', self::escaped($url), $card),
            $thumb === null ? null : new DeclaredImageModel($thumb),
            linkCardUrl: $url,
        );
    }

    private function quoteOrFallback(JsonNodeModel $record, string $postUrl): RenderedEmbedModel
    {
        $quotedUrl = AtPostUri::webUrl($record->string('uri') ?? '');
        $value = $record->node('value');
        if ($record->type() !== self::VIEW_RECORD || $value->type() !== self::POST_RECORD || $quotedUrl === null) {
            return $this->fallback($postUrl);
        }
        $text = $value->string('text');
        $paragraphs = $text === null ? '' : ParagraphedText::asHtml($text, self::escaped(...));

        return new RenderedEmbedModel(sprintf(
            '<figure class="quote-post"><blockquote>%s<footer><a href="%s">%s</a></footer></blockquote></figure>',
            $paragraphs,
            self::escaped($quotedUrl),
            self::escaped(self::authorName($record->node('author'))),
        ));
    }

    private function fallback(string $postUrl): RenderedEmbedModel
    {
        return new RenderedEmbedModel(self::linkParagraph($postUrl, self::FALLBACK_LABEL));
    }

    private static function authorName(JsonNodeModel $author): string
    {
        $handle = $author->string('handle');
        $displayName = $author->string('displayName');
        if ($handle === null) {
            return $displayName ?? 'Bluesky';
        }

        return $displayName === null ? '@' . $handle : sprintf('%s (@%s)', $displayName, $handle);
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) ? preg_replace('/^www\./i', '', $host) : null;
    }

    private static function dimensions(JsonNodeModel $ratio): string
    {
        $width = $ratio->int('width');
        $height = $ratio->int('height');

        return $width === null || $height === null ? '' : sprintf(' width="%d" height="%d"', $width, $height);
    }

    private static function optionalTag(string $tag, ?string $text): string
    {
        return $text === null ? '' : sprintf('<%1$s>%2$s</%1$s>', $tag, self::escaped($text));
    }

    private static function linkParagraph(string $url, string $label): string
    {
        return sprintf('<p><a href="%s">%s</a></p>', self::escaped($url), $label);
    }

    private static function escaped(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Bluesky`
Expected: PASS.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`. Then:

```bash
git add backend/src/Service/Bluesky backend/tests/Service/Bluesky backend/tests/Fixtures/Bluesky
git commit -m "feat(#1499): render a bluesky post's embed as html"
```

---

### Task 5: Fill a stored post with its embed

**Files:**
- Create: `backend/src/Service/Bluesky/Support/TrailingUrl.php`
- Create: `backend/src/Service/Bluesky/EntryEmbedWriter.php`
- Test: `backend/tests/Service/Bluesky/Support/TrailingUrlTest.php`
- Test: `backend/tests/Service/Bluesky/EntryEmbedWriterTest.php`

**Interfaces:**
- Consumes: `PostEmbedRenderer::render()` (Task 4), `HtmlDocumentParser::parseFragment()`, `EntrySanitizer::sanitize()`, `EntryImageWriter::write(Entry, DeclaredImageModel): bool`, `EntryMediaAssembler::assemble(?DeclaredImageModel, list<ParsedMediumModel>, list<ParsedAttachmentModel>): AssembledMediaModel`, `EntrySnippet::from(?string): ?string`.
- Produces: `TrailingUrl::removedFrom(string $html, string $url): string`; `EntryEmbedWriter::fill(Entry $entry, JsonNodeModel $post): bool` (false: the post embeds nothing, the entry is untouched).

- [ ] **Step 1: Write the failing tests**

`TrailingUrlTest` (the bodies are stored `contentHtml` as Rss2Parser + `EntrySanitizer` leave them; probed):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky\Support;

use App\Service\Bluesky\Support\TrailingUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrailingUrlTest extends TestCase
{
    private const string URL = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string TRACKED = 'https://www.motherjones.com/politics/2026/10/youre-funding-trumps-new-ads/'
        . '?utm_source=dlvr.it&utm_medium=slack';

    /** @return iterable<string, array{string, string, string}> */
    public static function bodies(): iterable
    {
        yield 'after a space in bare text' => [
            'Step down if ICE doesn&#039;t leave the city. ' . self::URL,
            self::URL,
            "Step down if ICE doesn't leave the city.",
        ];
        yield 'after a line break' => [
            '<p>“Foreign policy.</p><p>Russians.”<br />' . self::URL . '</p>',
            self::URL,
            '<p>“Foreign policy.</p><p>Russians.”</p>',
        ];
        yield 'with entities in its query' => [
            '<p>But will he ever pay you back?<br />https://www.motherjones.com/politics/2026/10/'
                . 'youre-funding-trumps-new-ads/?utm_source&#61;dlvr.it&amp;utm_medium&#61;slack</p>',
            self::TRACKED,
            '<p>But will he ever pay you back?</p>',
        ];
        yield 'in a paragraph of its own' => ['<p>Read this.</p><p>' . self::URL . '</p>', self::URL, '<p>Read this.</p>'];
        yield 'the whole body' => [self::URL, self::URL, ''];
    }

    #[DataProvider('bodies')]
    public function testRemovesTheUrlTheTextEndsWith(string $html, string $url, string $expected): void
    {
        self::assertSame($expected, TrailingUrl::removedFrom($html, $url));
    }

    /** @return iterable<string, array{string}> */
    public static function untouched(): iterable
    {
        yield 'the URL mid-text' => ['<p>See ' . self::URL . ' for more.</p>'];
        yield 'another URL at the end' => ['<p>See https://example.com/other</p>'];
        yield 'no text' => ['<p></p>'];
    }

    #[DataProvider('untouched')]
    public function testLeavesABodyThatDoesNotEndWithTheUrl(string $html): void
    {
        self::assertSame($html, TrailingUrl::removedFrom($html, self::URL));
    }
}
```

`EntryEmbedWriterTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\EntryMedium;
use App\Entity\Feed;
use App\Service\Bluesky\EntryEmbedWriter;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\TestCase;

final class EntryEmbedWriterTest extends TestCase
{
    use ReadsFixtures;

    private const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string CARD_THUMB = 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:qobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreie2nvxxbwowodsbtm3rksbshxxyrzr7jp6qkebllexxwjkmjs3a4y';
    private const string VIDEO = 'https://video.bsky.app/watch/did%3Aplc%3Aqobvnkudcv3zlaklxxjduqoi/'
        . 'bafkreibs4hca2whguuqvtnk4y5dahy5hvsxoygftczl4rgpxlwooqlycde/';
    private const string FULLSIZE = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:z72i7hdynmk6r22z27h6tvur/'
        . 'bafkreih3mb3cwnbc5kv5b2qyy24q6banms25i5ut3cbty4ej2x7vjvd6y4';

    public function testALinkCardReplacesTheTrailingUrlAndSetsSummaryAndImage(): void
    {
        $entry = self::entry('<p>Read this.<br />' . self::CARD . '</p>');

        self::assertTrue($this->writer()->fill($entry, $this->post('external')));

        self::assertSame(
            '<p>Read this.</p><figure class="link-card"><a href="' . self::CARD . '" rel="noopener noreferrer"'
                . ' target="_blank"><img src="' . self::CARD_THUMB . '" alt /><strong>After ICE Shooting, Progressives'
                . ' Want New York&#039;s Police Commissioner to Step Down</strong><span>28-year-old Oscar Belgal still'
                . ' has a bullet lodged in his body.</span><small>motherjones.com</small></a></figure>',
            $entry->getContentHtml(),
        );
        self::assertSame('Read this.', $entry->getSummary());
        self::assertSame(self::CARD_THUMB, $entry->getImageUrl());
        self::assertEquals([new EntryMedium(self::CARD_THUMB, 'image')], $entry->getMedia());
    }

    public function testAnotherUrlAtTheEndStaysInTheText(): void
    {
        $entry = self::entry('<p>See https://example.com/other</p>');

        $this->writer()->fill($entry, $this->post('external'));

        self::assertStringStartsWith('<p>See https://example.com/other</p><figure class="link-card">', (string) $entry->getContentHtml());
        self::assertSame('See https://example.com/other', $entry->getSummary());
    }

    public function testAVideoAddsThePosterAsImageAndThePlaylistAsMedia(): void
    {
        $entry = self::entry('<p>Watch.</p>');

        $this->writer()->fill($entry, $this->post('video'));

        self::assertStringStartsWith('<p>Watch.</p><figure class="post-video"><video controls', (string) $entry->getContentHtml());
        self::assertSame(self::VIDEO . 'thumbnail.jpg', $entry->getImageUrl());
        self::assertSame([1080, 1920], [$entry->getImageWidth(), $entry->getImageHeight()]);
        self::assertEquals(
            [
                new EntryMedium(self::VIDEO . 'thumbnail.jpg', 'image', 1080, 1920),
                new EntryMedium(self::VIDEO . 'playlist.m3u8', 'video', 1080, 1920, self::VIDEO . 'thumbnail.jpg'),
            ],
            $entry->getMedia(),
        );
    }

    public function testAnImageTheEntryAlreadyHasIsKeptAndLeadsTheMedia(): void
    {
        $entry = self::entry('<p>Apples.</p>');
        $entry->getImage()->storePending('https://example.com/own.jpg', 800, 600);

        $this->writer()->fill($entry, $this->post('images'));

        self::assertSame('https://example.com/own.jpg', $entry->getImageUrl());
        self::assertEquals(
            [
                new EntryMedium('https://example.com/own.jpg', 'image', 800, 600),
                new EntryMedium(self::FULLSIZE, 'image', 4000, 3000),
            ],
            $entry->getMedia(),
        );
    }

    public function testAnEmptyBodyGetsTheEmbedAndNoSummary(): void
    {
        $entry = self::entry(null);

        $this->writer()->fill($entry, $this->post('images'));

        self::assertStringStartsWith('<figure class="post-images"><img src="' . self::FULLSIZE . '"', (string) $entry->getContentHtml());
        self::assertNull($entry->getSummary());
        self::assertSame(self::FULLSIZE, $entry->getImageUrl());
    }

    public function testAPostWithoutAnEmbedLeavesTheEntryAlone(): void
    {
        $entry = self::entry('<p>Plain.</p>');
        $entry->setSummary('Plain.');

        self::assertFalse($this->writer()->fill($entry, JsonNodeModel::of(['uri' => 'at://did:plc:a/app.bsky.feed.post/b'])));

        self::assertSame('<p>Plain.</p>', $entry->getContentHtml());
        self::assertSame('Plain.', $entry->getSummary());
        self::assertNull($entry->getImageUrl());
        self::assertSame([], $entry->getMedia());
    }

    private static function entry(?string $contentHtml): Entry
    {
        $entry = new Entry(
            new Feed('https://bsky.app/profile/motherjones.com/rss'),
            'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t',
            null,
            'Post',
            new \DateTimeImmutable('2026-10-10 12:00:00'),
            new \DateTimeImmutable('2026-10-10 12:00:00'),
        );
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);

        return $entry;
    }

    private function post(string $fixture): JsonNodeModel
    {
        $answer = json_decode($this->fixture('Bluesky/' . $fixture . '.json'), true, flags: \JSON_THROW_ON_ERROR);
        $posts = JsonNodeModel::of($answer)->nodes('posts');
        self::assertCount(1, $posts);

        return $posts[0];
    }

    private function writer(): EntryEmbedWriter
    {
        return new EntryEmbedWriter(
            new PostEmbedRenderer(),
            new EntrySanitizer(new TrailingBlankRemover()),
            new EntryImageWriter(),
        );
    }
}
```

`storePending(null, …)` mirrors what `IngestedEntryFactory` (`writeOrMarkNone`) leaves on an image-less post. Wrap lines > 120 when transcribing.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Bluesky`
Expected: FAIL — classes `TrailingUrl`, `EntryEmbedWriter` not found.

- [ ] **Step 3: Implement**

`TrailingUrl`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/** Removes the URL a post's text ends with, and the line break or paragraph that leaves empty. */
final class TrailingUrl
{
    public static function removedFrom(string $html, string $url): string
    {
        $body = HtmlDocumentParser::parseFragment($html)->body;
        $last = $body === null ? null : self::lastText($body);
        $kept = $last === null ? '' : rtrim($last->data);
        if ($body === null || $last === null || !str_ends_with($kept, $url)) {
            return $html;
        }

        $last->data = rtrim(substr($kept, 0, -\strlen($url)));
        if ($last->data === '') {
            self::removeEmptied($last);
        }

        return $body->innerHTML;
    }

    private static function lastText(Node $node): ?Text
    {
        for ($child = $node->lastChild; $child !== null; $child = $child->previousSibling) {
            $found = $child instanceof Element ? self::lastText($child) : null;
            if ($child instanceof Text) {
                $found = $child;
            }
            if ($found !== null && trim($found->data) !== '') {
                return $found;
            }
        }

        return null;
    }

    private static function removeEmptied(Text $text): void
    {
        $previous = $text->previousSibling;
        if ($previous instanceof Element && $previous->localName === 'br') {
            $previous->remove();
        }
        $parent = $text->parentElement;
        $text->remove();
        if ($parent !== null && $parent->localName === 'p' && trim($parent->textContent ?? '') === '') {
            $parent->remove();
        }
    }

    private function __construct()
    {
    }
}
```

`EntryEmbedWriter`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\TrailingUrl;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Ingest\Support\EntryMediaAssembler;
use App\Service\Ingest\Support\EntrySnippet;
use App\Service\Sanitize\EntrySanitizer;

/** Fills a stored Bluesky post with its embed: the RSS text stays the body, and the embed follows it. */
final readonly class EntryEmbedWriter
{
    public function __construct(
        private PostEmbedRenderer $renderer,
        private EntrySanitizer $sanitizer,
        private EntryImageWriter $imageWriter,
    ) {
    }

    /** Whether the post had an embed to fill the entry with. */
    public function fill(Entry $entry, JsonNodeModel $post): bool
    {
        $embed = $this->renderer->render($post);
        if ($embed === null) {
            return false;
        }

        $body = $entry->getContentHtml() ?? '';
        if ($embed->linkCardUrl !== null) {
            $body = TrailingUrl::removedFrom($body, $embed->linkCardUrl);
        }
        $entry->setContentHtml($this->sanitizer->sanitize($body . $embed->html));
        $entry->setSummary(EntrySnippet::from($body));
        if ($embed->leadImage !== null && $entry->getImageUrl() === null) {
            $this->imageWriter->write($entry, $embed->leadImage);
        }
        $assembled = EntryMediaAssembler::assemble(self::storedImage($entry), $embed->media, []);
        $entry->setMedia($assembled->media, $entry->getAttachments());

        return true;
    }

    private static function storedImage(Entry $entry): ?DeclaredImageModel
    {
        $url = $entry->getImageUrl();

        return $url === null ? null : new DeclaredImageModel($url, $entry->getImageWidth(), $entry->getImageHeight());
    }
}
```

The lead passed to the assembler is the image the entry actually stores, so `media[0]` stays equal to `getImageUrl()` (the assembler's invariant).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Bluesky`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`. Then:

```bash
git add backend/src/Service/Bluesky backend/tests/Service/Bluesky
git commit -m "feat(#1499): fill a stored bluesky post with its rendered embed"
```

---

### Task 6: The enrichment pass

**Files:**
- Create: `backend/src/Service/Bluesky/PendingPostQueue.php`
- Create: `backend/src/Service/Bluesky/PostEnricher.php`
- Create: `backend/tests/Support/FakeAppView.php`
- Create: `backend/tests/Support/PostEnrichers.php`
- Test: `backend/tests/Service/Bluesky/PostEnricherTest.php`

**Interfaces:**
- Consumes: Tasks 2–5.
- Produces: `PendingPostQueue::queue(list<Entry> $entries): int` (persists a row per AT-post entry, no flush, returns how many); `PostEnricher::enrich(Feed $feed, list<Entry> $createdEntries): list<Entry>` (never throws; returns the entries it filled); test helpers `FakeAppView` (a `FeedFetcherInterface` answering `getPosts` from fixtures: `knowsFixture(string)`, `failsWith(FetchException)`, `answersWithBody(string)`, `recovers()`, public `list<list<string>> $requests`) and `PostEnrichers::build(EntityManagerInterface, ClockInterface, AppViewClient, LoggerInterface)` / `PostEnrichers::idle(EntityManagerInterface, ClockInterface)`.

- [ ] **Step 1: Write the test helpers**

`FakeAppView`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\Model\FetchResponseModel;
use PHPUnit\Framework\Assert;

/** The AppView's getPosts over recorded post views: it answers each requested URI it knows, as the real one does. */
final class FakeAppView implements FeedFetcherInterface
{
    private const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts?';

    /** @var array<string, array<mixed>> */
    private array $postViews = [];

    private ?FetchException $failure = null;

    private ?string $body = null;

    /** @var list<list<string>> the URIs each request asked for, in order */
    public array $requests = [];

    public function knowsFixture(string $name): void
    {
        $answer = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/Bluesky/' . $name . '.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        Assert::assertIsArray($answer);
        $views = $answer['posts'] ?? null;
        Assert::assertIsArray($views);
        foreach ($views as $view) {
            Assert::assertIsArray($view);
            $uri = $view['uri'] ?? null;
            Assert::assertIsString($uri);
            $this->postViews[$uri] = $view;
        }
    }

    public function failsWith(FetchException $failure): void
    {
        $this->failure = $failure;
    }

    public function answersWithBody(string $body): void
    {
        $this->body = $body;
    }

    public function recovers(): void
    {
        $this->failure = null;
        $this->body = null;
    }

    public function fetch(string $url): FetchResponseModel
    {
        Assert::assertStringStartsWith(self::GET_POSTS, $url);
        $uris = self::requestedUris($url);
        $this->requests[] = $uris;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $body = $this->body ?? json_encode(['posts' => $this->knownViews($uris)], \JSON_THROW_ON_ERROR);

        return FetchResponseModel::fetched($url, false, $body, null, null);
    }

    /** @return list<string> */
    private static function requestedUris(string $url): array
    {
        preg_match_all('/[?&]uris=([^&]+)/', $url, $matches);

        return array_map(rawurldecode(...), $matches[1]);
    }

    /**
     * @param list<string> $uris
     *
     * @return list<array<mixed>>
     */
    private function knownViews(array $uris): array
    {
        $views = [];
        foreach ($uris as $uri) {
            if (isset($this->postViews[$uri])) {
                $views[] = $this->postViews[$uri];
            }
        }

        return $views;
    }
}
```

`PostEnrichers`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\EntryEmbedWriter;
use App\Service\Bluesky\PendingPostQueue;
use App\Service\Bluesky\PostEmbedRenderer;
use App\Service\Bluesky\PostEnricher;
use App\Service\Fetch\HostThrottle;
use App\Service\Ingest\EntryImageWriter;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Sanitize\TrailingBlankRemover;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\ClockInterface;

/** A PostEnricher over the EntityManager's real repository: the one assembly the enrichment and refresh tests share. */
final class PostEnrichers
{
    public static function build(
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
        AppViewClient $appView,
        LoggerInterface $logger,
    ): PostEnricher {
        /** @var PendingPostEnrichmentRepository $pendingPosts */
        $pendingPosts = $entityManager->getRepository(PendingPostEnrichment::class);

        return new PostEnricher(
            $entityManager,
            $pendingPosts,
            new PendingPostQueue($entityManager, $clock),
            $appView,
            new EntryEmbedWriter(
                new PostEmbedRenderer(),
                new EntrySanitizer(new TrailingBlankRemover()),
                new EntryImageWriter(),
            ),
            $clock,
            $logger,
        );
    }

    /** For refresh tests without Bluesky posts: an AppView call would fail, unlogged. */
    public static function idle(EntityManagerInterface $entityManager, ClockInterface $clock): PostEnricher
    {
        $appView = new AppViewClient(new StubFeedFetcher(), new HostThrottle(new ArrayAdapter(clock: $clock), $clock));

        return self::build($entityManager, $clock, $appView, new NullLogger());
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\PostEnricher;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ReloadsEntities;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PostEnricherTest extends DbTestCase
{
    use ReloadsEntities;

    private const string GET_POSTS = 'https://public.api.bsky.app/xrpc/app.bsky.feed.getPosts';
    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string APPLES = 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3mv3shqdfuc2e';
    private const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string TEST_POST = 'at://did:plc:test/app.bsky.feed.post/';

    private MockClock $clock;
    private HostThrottle $throttle;
    private FakeAppView $appView;
    private RecordingLogger $logger;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 12:00:00', 'UTC');
        $this->throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);
        $this->appView = new FakeAppView();
        $this->logger = new RecordingLogger();
        $this->feed = $this->feed('https://bsky.app/profile/motherjones.com/rss');
    }

    public function testQueuesANewPostFillsItAndDequeuesIt(): void
    {
        $this->appView->knowsFixture('external');
        $post = $this->entry($this->feed, self::TISCH, '<p>Read this.<br />' . self::CARD . '</p>');
        $article = $this->entry($this->feed, 'https://example.com/article', '<p>Article.</p>');
        $this->entityManager->flush();

        $filled = $this->enricher()->enrich($this->feed, [$post, $article]);

        self::assertSame([$post], $filled);
        self::assertSame([[self::TISCH]], $this->appView->requests);
        self::assertSame(0, $this->pendingCount());
        $stored = $this->reload($post);
        self::assertStringStartsWith('<p>Read this.</p><figure class="link-card">', (string) $stored->getContentHtml());
        self::assertSame('Read this.', $stored->getSummary());
    }

    public function testAPostTheAppViewOmitsIsDequeuedAndKeepsItsText(): void
    {
        $post = $this->entry($this->feed, self::TEST_POST . 'deleted', '<p>Deleted since.</p>');
        $this->entityManager->flush();

        self::assertSame([], $this->enricher()->enrich($this->feed, [$post]));

        self::assertSame('<p>Deleted since.</p>', $this->reload($post)->getContentHtml());
        self::assertSame(0, $this->pendingCount());
    }

    public function testThirtyQueuedPostsTakeTwoRequests(): void
    {
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([25, 5], array_map(count(...), $this->appView->requests));
        self::assertSame(0, $this->pendingCount());
    }

    public function testAPassTakesTheOldestHundredPosts(): void
    {
        $guids = $this->queuePosts(101);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([25, 25, 25, 25], array_map(count(...), $this->appView->requests));
        self::assertSame($guids[0], $this->appView->requests[0][0]);
        self::assertSame([$guids[100]], $this->pendingGuids());
    }

    public function testAFailedRequestKeepsItsRowsAndTheNextChunkIsStillAsked(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $this->queuePosts(30);

        self::assertSame([], $this->enricher()->enrich($this->feed, []));

        self::assertCount(2, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(['warning', 'warning'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky AppView gave no usable answer for {url}', $this->logger->records[0]['message']);
        self::assertSame($this->feed->getUrl(), $this->logger->records[0]['context']['url']);
    }

    public function testAnUnreadableAnswerKeepsTheRows(): void
    {
        $this->appView->answersWithBody('<html>Bad gateway</html>');
        $this->queuePosts(1);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame(1, $this->pendingCount());
        self::assertSame(['warning'], array_column($this->logger->records, 'level'));
    }

    public function testARowQueuedMoreThanThreeDaysAgoIsDroppedUnasked(): void
    {
        $this->queuedPost($this->feed, self::TEST_POST . 'stale', '-3 days -1 second');
        $this->queuedPost($this->feed, self::TEST_POST . 'young', '-3 days +1 minute');
        $this->queuedPost($this->feed('https://bsky.app/profile/bsky.app/rss'), self::TEST_POST . 'other', '-4 days');
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([[self::TEST_POST . 'young']], $this->appView->requests);
        self::assertSame([self::TEST_POST . 'other'], $this->pendingGuids());
    }

    public function testAThrottledHostIsNotAsked(): void
    {
        $this->throttle->record(self::GET_POSTS, 120);
        $this->queuePosts(3);

        $this->enricher()->enrich($this->feed, []);

        self::assertSame([], $this->appView->requests);
        self::assertSame(3, $this->pendingCount());
    }

    public function testAThrottledAnswerStopsThePass(): void
    {
        $this->appView->failsWith(new FeedThrottledException('HTTP 429', 300));
        $this->queuePosts(30);

        $this->enricher()->enrich($this->feed, []);

        self::assertCount(1, $this->appView->requests);
        self::assertSame(30, $this->pendingCount());
        self::assertSame(300, $this->throttle->remainingSeconds(self::GET_POSTS));
    }

    public function testAnImageTheEntryAlreadyHasIsNotOverwritten(): void
    {
        $this->appView->knowsFixture('images');
        $post = $this->entry($this->feed, self::APPLES, '<p>Apples.</p>');
        $post->getImage()->storePending('https://example.com/own.jpg', 800, 600);
        $this->entityManager->flush();

        $this->enricher()->enrich($this->feed, [$post]);

        self::assertSame('https://example.com/own.jpg', $this->reload($post)->getImageUrl());
    }

    public function testAnUnexpectedFailureIsLoggedAndFillsNothing(): void
    {
        $post = $this->entry($this->feed, self::TISCH, '<p>Text.</p>');
        $this->entityManager->flush();
        $enricher = PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient(new StubFeedFetcher(), $this->throttle),
            $this->logger,
        );

        self::assertSame([], $enricher->enrich($this->feed, [$post]));

        self::assertSame(['error'], array_column($this->logger->records, 'level'));
        self::assertSame('Bluesky enrichment failed for {url}', $this->logger->records[0]['message']);
        self::assertSame(1, $this->pendingCount());
    }

    private function enricher(): PostEnricher
    {
        return PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($this->appView, $this->throttle),
            $this->logger,
        );
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $guid, string $contentHtml): Entry
    {
        $entry = new Entry($feed, $guid, null, 'Post', $this->clock->now(), $this->clock->now());
        $entry->setContentHtml($contentHtml);
        $entry->getImage()->storePending(null, null, null);
        $this->entityManager->persist($entry);

        return $entry;
    }

    private function queuedPost(Feed $feed, string $guid, string $queuedBefore): void
    {
        $entry = $this->entry($feed, $guid, '<p>Text.</p>');
        $this->entityManager->persist(new PendingPostEnrichment($entry, $this->clock->now()->modify($queuedBefore)));
    }

    /** @return list<string> the guids, oldest queued first, one minute apart and all within the last two hours */
    private function queuePosts(int $count): array
    {
        $guids = [];
        for ($index = 0; $index < $count; $index++) {
            $guids[] = $guid = self::TEST_POST . sprintf('%03d', $index);
            $this->queuedPost($this->feed, $guid, sprintf('-120 minutes +%d minutes', $index));
        }
        $this->entityManager->flush();

        return $guids;
    }

    private function pendingCount(): int
    {
        return $this->entityManager->getRepository(PendingPostEnrichment::class)->count([]);
    }

    /** @return list<string> */
    private function pendingGuids(): array
    {
        return array_map(
            static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(),
            $this->entityManager->getRepository(PendingPostEnrichment::class)->findAll(),
        );
    }
}
```

If PHPStan objects to the assignment inside `$guids[] = $guid = …`, split it into two statements.

- [ ] **Step 3: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Bluesky/PostEnricherTest.php`
Expected: FAIL — classes `PendingPostQueue`, `PostEnricher` not found.

- [ ] **Step 4: Implement**

`PendingPostQueue`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\PendingPostEnrichment;
use App\Service\Ingest\Support\AtPostUri;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Queues new Bluesky posts for the AppView. The caller flushes. */
final readonly class PendingPostQueue
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<Entry> $entries
     *
     * @return int how many of them were Bluesky posts
     */
    public function queue(array $entries): int
    {
        $queuedAt = $this->clock->now();
        $queued = 0;
        foreach ($entries as $entry) {
            if (AtPostUri::matches($entry->getGuid())) {
                $this->entityManager->persist(new PendingPostEnrichment($entry, $queuedAt));
                $queued++;
            }
        }

        return $queued;
    }
}
```

`PostEnricher`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Bluesky;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\PendingPostEnrichmentRepository;
use App\Service\Bluesky\Exception\AppViewAnswerException;
use App\Service\Fetch\Exception\FetchException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Fills queued Bluesky posts from the public AppView after a refresh, retrying on later refreshes for three days. */
final readonly class PostEnricher
{
    private const int POSTS_PER_PASS = 100;
    private const string GIVE_UP_AFTER = 'P3D';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PendingPostEnrichmentRepository $pendingPosts,
        private PendingPostQueue $queue,
        private AppViewClient $appView,
        private EntryEmbedWriter $embedWriter,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Never throws: a failure is logged and leaves the refresh as it was.
     *
     * @param list<Entry> $createdEntries already flushed
     *
     * @return list<Entry> the entries it filled
     */
    public function enrich(Feed $feed, array $createdEntries): array
    {
        try {
            return $this->enrichQueued($feed, $createdEntries);
        } catch (\Exception $exception) {
            $this->logger->error(
                'Bluesky enrichment failed for {url}',
                ['url' => $feed->getUrl(), 'exception' => $exception],
            );

            return [];
        }
    }

    /**
     * @param list<Entry> $createdEntries
     *
     * @return list<Entry>
     *
     * @throws \DateInvalidOperationException
     */
    private function enrichQueued(Feed $feed, array $createdEntries): array
    {
        if ($this->queue->queue($createdEntries) > 0) {
            $this->entityManager->flush();
        }
        $this->pendingPosts->deleteQueuedBefore($feed, $this->clock->now()->sub(new \DateInterval(self::GIVE_UP_AFTER)));
        $pending = $this->pendingPosts->findOldestForFeed($feed, self::POSTS_PER_PASS);
        if ($pending === []) {
            return [];
        }

        $filled = [];
        foreach (array_chunk($pending, AppViewClient::URIS_PER_CALL) as $chunk) {
            if ($this->appView->isThrottled()) {
                break;
            }
            array_push($filled, ...$this->settle($feed, $chunk));
        }
        $this->entityManager->flush();

        return $filled;
    }

    /**
     * Fills what the AppView answered and dequeues the whole chunk; a post it omits is deleted or hidden.
     *
     * @param list<PendingPostEnrichment> $chunk
     *
     * @return list<Entry>
     */
    private function settle(Feed $feed, array $chunk): array
    {
        try {
            $posts = $this->appView->posts(array_map(
                static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(),
                $chunk,
            ));
        } catch (FetchException | AppViewAnswerException $exception) {
            $this->logger->warning(
                'Bluesky AppView gave no usable answer for {url}',
                ['url' => $feed->getUrl(), 'exception' => $exception],
            );

            return [];
        }

        $filled = [];
        foreach ($chunk as $pending) {
            $entry = $pending->getEntry();
            $post = $posts[$entry->getGuid()] ?? null;
            if ($post !== null && $this->embedWriter->fill($entry, $post)) {
                $filled[] = $entry;
            }
            $this->entityManager->remove($pending);
        }

        return $filled;
    }
}
```

Line 2 of `enrichQueued` is 121 characters with its indentation; break it after `$feed,` when transcribing.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Bluesky tests/Repository/PendingPostEnrichmentRepositoryTest.php`
Expected: PASS.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md` (`composer tramp` is part of `check`: `$feed` reaches `settle()` inside one class only, so no tramp chain). Then:

```bash
git add backend/src/Service/Bluesky backend/tests/Service/Bluesky backend/tests/Support/FakeAppView.php backend/tests/Support/PostEnrichers.php
git commit -m "feat(#1499): enrich queued bluesky posts from the appview in chunks of 25"
```

---

### Task 7: Run the pass after every refresh and queue a subscription's first posts

**Files:**
- Modify: `backend/src/Service/Refresh/FeedOutcomePersister.php`
- Modify: `backend/src/Service/Subscription/FirstFetchRecorder.php`
- Modify: `backend/tests/Service/Refresh/FeedOutcomePersisterTest.php` (constructor call)
- Modify: `backend/tests/Support/RefreshRunners.php` (constructor call)
- Modify: `backend/tests/Service/Subscription/FirstFetchRecorderTest.php`, `backend/tests/Service/Subscription/SubscriptionServiceTest.php` (constructor calls)
- Create: `backend/tests/Fixtures/Bluesky/motherjones.rss`
- Test: `backend/tests/Service/Refresh/FeedOutcomePersisterBlueskyTest.php`
- Test: `backend/tests/Service/Subscription/FirstFetchRecorderTest.php`

**Interfaces:**
- Consumes: `PostEnricher::enrich()`, `PendingPostQueue::queue()` (Task 6).
- Produces: `FeedOutcomePersister::__construct(…, EntryIndexer $indexer, PostEnricher $postEnricher, LoggerInterface $logger)`; `FirstFetchRecorder::__construct(…, EntryIndexer $indexer, PendingPostQueue $postQueue)`.

- [ ] **Step 1: Fixture**

`tests/Fixtures/Bluesky/motherjones.rss` (the first item is a real Mother Jones item; the second is the video post `video.json` records, in the RSS shape Bluesky gives an embed: text, blank line, placeholder):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><description>Investigative journalism</description><link>https://bsky.app/profile/motherjones.com</link><title>@motherjones.com - Mother Jones</title><item><link>https://bsky.app/profile/motherjones.com/post/3mxjuesq6v62t</link><description>Billionaire heiress Jessica Tisch is a holdover from Eric Adams’ mayoral administration who has pushed to expand surveillance infrastructure in New York City. Progressives are calling on her to step down if ICE doesn&#39;t leave the city. https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/</description><pubDate>10 Oct 2026 16:01 +0000</pubDate><guid isPermaLink="false">at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t</guid></item><item><link>https://bsky.app/profile/motherjones.com/post/3mxhlfehxzi27</link><description>From battleground candidates to Elon Musk, Republicans can’t stop posting wistfully about Rhodesia, the defunct white supremacist state in southern Africa.&#xA;&#xA;History repeats. Sometimes as tragedy…sometimes as LARP.&#xA;&#xA;[contains quote post or other embedded content]</description><pubDate>09 Oct 2026 20:00 +0000</pubDate><guid isPermaLink="false">at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhlfehxzi27</guid></item></channel></rss>
```

- [ ] **Step 2: Write the failing tests**

`FeedOutcomePersisterBlueskyTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\PendingPostEnrichment;
use App\Repository\FeedRepository;
use App\Service\Bluesky\AppViewClient;
use App\Service\Bluesky\PostEnricher;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\HostThrottle;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Ingest\PlatformEntryRule\BlueskyEntryRule;
use App\Service\Ingest\PlatformEntryRules;
use App\Service\Refresh\FeedBodyParser;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\RefreshRunner\RefreshRunner;
use App\Service\Search\EntryIndexer;
use App\Service\Search\Index\Model\IndexedEntryModel;
use App\Tests\DbTestCase;
use App\Tests\Service\Search\RecordingSearchIndexWriter;
use App\Tests\Support\EntryIngestors;
use App\Tests\Support\FakeAppView;
use App\Tests\Support\FeedSchedulers;
use App\Tests\Support\PostEnrichers;
use App\Tests\Support\ReadsFixtures;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class FeedOutcomePersisterBlueskyTest extends DbTestCase
{
    use ReadsFixtures;

    private const string TISCH = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
    private const string VIDEO_POST = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxhlfehxzi27';
    private const string CARD = 'https://www.motherjones.com/politics/2026/10/ice-shooting-nypd-bronx-tisch/';
    private const string TISCH_TEXT = 'Billionaire heiress Jessica Tisch is a holdover from Eric Adams’ mayoral'
        . ' administration who has pushed to expand surveillance infrastructure in New York City. Progressives are'
        . " calling on her to step down if ICE doesn't leave the city.";

    private MockClock $clock;
    private FakeAppView $appView;
    private RecordingSearchIndexWriter $indexWriter;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-10 17:00:00', 'UTC');
        $this->appView = new FakeAppView();
        $this->appView->knowsFixture('external');
        $this->appView->knowsFixture('video');
        $this->indexWriter = new RecordingSearchIndexWriter();
        $this->feed = new Feed('https://bsky.app/profile/did:plc:qobvnkudcv3zlaklxxjduqoi/rss');
        $this->entityManager->persist($this->feed);
        $this->entityManager->flush();
    }

    public function testAFetchedBlueskyFeedStoresItsPostsWithTheirEmbeds(): void
    {
        $result = $this->persistFetched();

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        $tisch = $this->entry(self::TISCH);
        self::assertStringStartsWith(
            'Billionaire heiress Jessica Tisch',
            (string) $tisch->getContentHtml(),
        );
        self::assertStringContainsString(
            'leave the city.<figure class="link-card"><a href="' . self::CARD . '"',
            (string) $tisch->getContentHtml(),
        );
        self::assertSame(self::TISCH_TEXT, $tisch->getSummary());
        $video = $this->entry(self::VIDEO_POST);
        self::assertStringNotContainsString('[contains quote post', (string) $video->getContentHtml());
        self::assertStringContainsString('<figure class="post-video"><video', (string) $video->getContentHtml());
        self::assertSame('video', $video->getMedia()[1]->kind ?? null);
        self::assertSame(0, $this->pendingCount());
        self::assertCount(1, $this->indexWriter->upserts);
        self::assertCount(2, $this->indexWriter->upserts[0]);
        self::assertContains(self::TISCH_TEXT, array_map(
            static fn (IndexedEntryModel $indexed): ?string => $indexed->summary,
            $this->indexWriter->upserts[0],
        ));
    }

    public function testANotModifiedRefreshFillsThePostsTheAppViewMissedBefore(): void
    {
        $this->appView->failsWith(new FeedUnreachableException('connection reset'));
        $this->persistFetched();
        self::assertSame(2, $this->pendingCount());
        self::assertStringEndsWith(self::CARD, (string) $this->entry(self::TISCH)->getContentHtml());
        $this->feed = $this->reloadedFeed();

        $this->appView->recovers();
        $this->clock->sleep(1800);
        $result = $this->persister()->persist(
            $this->feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::notModified($this->feed->getUrl(), false, null, null)),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::NotModified, $result->outcome);
        self::assertSame(0, $this->pendingCount());
        self::assertStringContainsString('<figure class="link-card">', (string) $this->entry(self::TISCH)->getContentHtml());
        self::assertCount(2, $this->indexWriter->upserts[array_key_last($this->indexWriter->upserts)]);
    }

    public function testAFailingPassLeavesTheRefreshOutcomeAlone(): void
    {
        $result = $this->persisterWith(PostEnrichers::idle($this->entityManager, $this->clock))->persist(
            $this->feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
                $this->feed->getUrl(),
                false,
                $this->fixture('Bluesky/motherjones.rss'),
                null,
                null,
            )),
            $this->clock->now(),
        );

        self::assertSame(FeedOutcome::Fetched, $result->outcome);
        self::assertSame(2, $result->entriesCreated);
        self::assertSame(2, $this->pendingCount());
    }

    /** FeedOutcomePersister and PostEnricher may be inlined; building RefreshRunner autowires both. */
    public function testTheContainerBuildsTheRefreshWithTheEnricher(): void
    {
        self::assertInstanceOf(RefreshRunner::class, self::getContainer()->get(RefreshRunner::class));
    }

    private function persistFetched(): FeedRefreshResultModel
    {
        return $this->persister()->persist(
            $this->feed,
            FetchOutcomeModel::succeeded(FetchResponseModel::fetched(
                $this->feed->getUrl(),
                false,
                $this->fixture('Bluesky/motherjones.rss'),
                null,
                null,
            )),
            $this->clock->now(),
        );
    }

    private function persister(): FeedOutcomePersister
    {
        $throttle = new HostThrottle(new ArrayAdapter(clock: $this->clock), $this->clock);

        return $this->persisterWith(PostEnrichers::build(
            $this->entityManager,
            $this->clock,
            new AppViewClient($this->appView, $throttle),
            new NullLogger(),
        ));
    }

    private function persisterWith(PostEnricher $postEnricher): FeedOutcomePersister
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->entityManager->getRepository(Feed::class);
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedOutcomePersister(
            $this->entityManager,
            $feedRepository,
            $bodyParser,
            EntryIngestors::withPlatformRules(
                $this->entityManager,
                $this->clock,
                new PlatformEntryRules([new BlueskyEntryRule()]),
            ),
            FeedSchedulers::build($this->clock),
            new EntryIndexer($this->indexWriter, new NullLogger()),
            $postEnricher,
            new NullLogger(),
        );
    }

    private function entry(string $guid): Entry
    {
        $this->entityManager->clear();
        $entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guid' => $guid]);
        self::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    /** entry() clears the EntityManager, so a feed passed to persist() again must be managed anew. */
    private function reloadedFeed(): Feed
    {
        $feed = $this->entityManager->find(Feed::class, $this->feed->requireId());
        self::assertInstanceOf(Feed::class, $feed);

        return $feed;
    }

    private function pendingCount(): int
    {
        return $this->entityManager->getRepository(PendingPostEnrichment::class)->count([]);
    }
}
```

In `FirstFetchRecorderTest`, add `new PendingPostQueue($this->entityManager, $clock)` as the recorder's last constructor argument, and add:

```php
    public function testAFirstFetchQueuesItsBlueskyPostsForTheAppView(): void
    {
        $feed = $this->feed();
        $post = 'at://did:plc:qobvnkudcv3zlaklxxjduqoi/app.bsky.feed.post/3mxjuesq6v62t';
        $discovered = $this->discovered($feed, [
            $this->parsedEntry($post, new \DateTimeImmutable('2026-10-10 16:01:00')),
            $this->parsedEntry('article', new \DateTimeImmutable('2026-10-10 15:00:00')),
        ]);

        $this->recorder->record($feed, $discovered);

        $queued = $this->entityManager->getRepository(PendingPostEnrichment::class)->findAll();
        self::assertSame([$post], array_map(
            static fn (PendingPostEnrichment $pending): string => $pending->getEntry()->getGuid(),
            $queued,
        ));
    }
```

(`parsedEntry()` builds `url: 'https://example.com/' . $guid`; a guid with slashes gives an unusual but valid URL.)

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Refresh tests/Service/Subscription`
Expected: FAIL — `FeedOutcomePersister` and `FirstFetchRecorder` take no enricher/queue argument (ArgumentCountError / unknown argument), the first-fetch test finds no row.

- [ ] **Step 4: Implement**

`FeedOutcomePersister` — add the import `use App\Service\Bluesky\PostEnricher;`, the constructor parameter `private PostEnricher $postEnricher,` between `$indexer` and `$logger`, and:

```php
    /** @throws \DateMalformedStringException */
    private function storeNotModified(Feed $feed, FetchResponseModel $response): FeedRefreshResultModel
    {
        // A moved feed can answer 304 at its new address; without this the redirect chain is re-walked every time.
        $this->applyPermanentRedirect($feed, $response);
        $this->scheduler->recordNotModified($feed);
        $this->entityManager->flush();
        $this->indexer->index($this->postEnricher->enrich($feed, []));

        return FeedRefreshResultModel::of(FeedOutcome::NotModified);
    }
```

and in `storeFetched()` replace the two lines after `recordSuccess()`:

```php
        $this->entityManager->flush();
        $filledEntries = $this->postEnricher->enrich($feed, $createdEntries);
        // Only the flush assigns ids, so indexing has to follow it.
        $this->indexer->index(self::withoutRepeats([...$createdEntries, ...$filledEntries]));
```

with the helper:

```php
    /**
     * @param list<Entry> $entries
     *
     * @return list<Entry>
     */
    private static function withoutRepeats(array $entries): array
    {
        $distinct = [];
        foreach ($entries as $entry) {
            $distinct[spl_object_id($entry)] = $entry;
        }

        return array_values($distinct);
    }
```

`FirstFetchRecorder` — import `App\Service\Bluesky\PendingPostQueue`, add `private PendingPostQueue $postQueue,` as the last constructor parameter, and call it before the flush:

```php
        $feed->recordCacheValidators($discovered->etag, $discovered->lastModified);
        $this->scheduler->recordSuccess($feed, \count($createdEntries));
        $this->postQueue->queue($createdEntries);
        $this->entityManager->flush();
```

Construction sites:
- `tests/Service/Refresh/FeedOutcomePersisterTest.php::persister()` and `tests/Support/RefreshRunners.php::build()`: pass `PostEnrichers::idle($this->entityManager, $this->clock)` between the `EntryIndexer` and the logger argument (in `RefreshRunners` the properties are `$this->entityManager` and `$this->clock`).
- `tests/Service/Subscription/SubscriptionServiceTest.php`: append `new PendingPostQueue($this->entityManager, $clock)` to the `new FirstFetchRecorder(…)` call.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Refresh tests/Service/Subscription tests/Command tests/Controller/Api/RefreshControllerTest.php tests/Service/Worker`
Expected: PASS.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md && composer test:parallel`. Expected: all green (the cycle rule confirms `Refresh → Bluesky` and `Subscription → Bluesky` close no cycle). Then:

```bash
git add backend/src/Service/Refresh/FeedOutcomePersister.php backend/src/Service/Subscription/FirstFetchRecorder.php backend/tests
git commit -m "feat(#1499): fill bluesky embeds after each refresh and queue a new subscription's posts"
```

---

### Task 8: Article styles for the embeds

**Files:**
- Modify: `frontend/src/app/reader/article/reader-view/reader-view.component.content.scss` (append before the `/* The "about the author" furniture … */` block or at the end)

**Interfaces:**
- Consumes: the markup of Task 4 (`figure.link-card > a > img? strong? span? small`, `figure.quote-post > blockquote > p* footer > a`, `figure.post-images > img+`, `figure.post-video > video` then `p > a`). `addCinemaToggles` will wrap the video in `div.reader-cinema` inside the figure; the selectors below are descendant selectors, so that holds.

- [ ] **Step 1: Add the styles**

```scss
/* Bluesky embeds (#1499), filled from the AppView after ingest. A link card is
   one quiet block: thumbnail edge to edge, then title, description and host. */
.content ::ng-deep .link-card {
  @include glass-rim.filled(var(--surface-1));

  margin: var(--space-5) 0;
  border-radius: var(--radius);
  overflow: hidden;
}

.content ::ng-deep .link-card a[href] {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  padding-bottom: var(--space-3);
  color: var(--text-primary);
  text-decoration: none;
}

.content ::ng-deep .link-card img {
  display: block;
  width: 100%;
  margin-bottom: var(--space-2);
  border-radius: 0;
}

.content ::ng-deep .link-card :is(strong, span, small) {
  padding-inline: var(--space-4);
}

.content ::ng-deep .link-card a > :first-child:not(img) {
  padding-top: var(--space-3);
}

.content ::ng-deep .link-card span {
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  line-height: 1.5;
}

.content ::ng-deep .link-card small {
  color: var(--text-muted);
  font-size: var(--fs-xs);
}

.content ::ng-deep .quote-post blockquote {
  margin: 0;
}

.content ::ng-deep .quote-post footer {
  margin-top: var(--space-2);
  font-size: var(--fs-sm);
}

.content ::ng-deep .post-images {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr));
  gap: var(--space-2);
}

.content ::ng-deep .post-images img {
  width: 100%;
}

/* The generic rule pins a video to 16:9; a Bluesky clip keeps its poster's shape, short of the viewport. */
.content ::ng-deep .post-video video {
  aspect-ratio: auto;
  max-height: 80dvh;
}
```

All values are tokens or units the Stylelint `declaration-property-unit-allowed-list` admits (`dvh` for `max-height`, `rem` inside `minmax`). No hex.

- [ ] **Step 2: Format and check**

Run: `docker compose exec -T frontend npx prettier --write src/app/reader/article/reader-view/reader-view.component.content.scss && docker compose exec -T frontend npm run check`
Expected: PASS (no new TS, so Jest is unchanged; `no-descending-specificity` holds because every new rule follows the generic ones it refines).

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/article/reader-view/reader-view.component.content.scss
git commit -m "feat(#1499): reader styles for bluesky link cards, quotes, image grids and video"
```

---

### Task 9: README and full verification

**Files:**
- Modify: `README.md` (the **Feeds** list, after the Mastodon/Bluesky line)

- [ ] **Step 1: README**

Below `- Mastodon and Bluesky profiles show as posts: …` add:

```markdown
- A Bluesky post shows what it embeds — images, video, a link card or the
  post it quotes — fetched from Bluesky's public AppView once the post
  arrives, and retried on later refreshes for three days.
```

- [ ] **Step 2: Both suites**

Run in parallel: `cd backend && composer test:parallel` and `docker compose exec php composer test`.
Expected: both green. (The Docker `php` container shares the code volume; its DB already has the Task 2 migration.)

- [ ] **Step 3: Gates over everything touched**

Run (from `backend/`): `composer check && composer md`, then PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file. Expected: no ERROR or WARNING.

- [ ] **Step 4: Mutation gate**

Run: `cd backend && composer infection:diff` (it ignores untracked files: everything must be committed first).
Expected: MSI at or above `infection.json5`'s `minMsi`. Kill escaped mutants with test rows (new concrete inputs), not by changing code or the threshold.

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs(#1499): bluesky posts show their embeds"
```

---

### Task 10: Live smoke on the Docker stack

**Files:** none (fixes only, each with its own failing test first, if the smoke finds a bug).

Entries stored before this change are never enriched, so the smoke needs entries created after it. Two sources: a Bluesky profile nobody subscribed to yet (its first fetch is queued by `FirstFetchRecorder`, then filled by the next refresh), and new posts on the already-stored feeds 533 (bsky.app, e2e-admin's subscription 1720) and 534 (Mother Jones, subscribed by user 2 only, subscription 1721; e2e-admin is **not** subscribed to it — do not add a duplicate feed, subscribing reuses feed 534).

- [ ] **Step 1: Current code everywhere**

```bash
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose exec php bin/console doctrine:migrations:status | grep -i 'new migrations'
```
Expected: `New Migrations: 0` (Task 2 applied it).

- [ ] **Step 2: Fresh profiles**

Read-only check that the candidates are not stored yet:
`docker compose exec -T php bin/console dbal:run-sql "SELECT id, url FROM feed WHERE url LIKE '%bsky.app/profile/%'"`

Log in to the SPA as e2e-admin (credentials: `frontend/e2e/support/auth.ts`) and subscribe through "Add feed" to two fresh profiles, e.g. `https://bsky.app/profile/theverge.com/rss` (link cards, images, video) and `https://bsky.app/profile/atproto.com/rss` (quotes). Also subscribe e2e-admin to `https://bsky.app/profile/motherjones.com/rss` (reuses feed 534). Note the new feed ids from the query above.

Expected right after subscribing (read-only):
`docker compose exec -T php bin/console dbal:run-sql "SELECT e.feed_id, COUNT(*) FROM pending_post_enrichment p JOIN entry e ON e.id = p.entry_id GROUP BY e.feed_id"` → a row count per fresh feed equal to its stored entries; none for feeds 533/534.

- [ ] **Step 3: Refresh and inspect**

After the 5-minute cooldown, for each fresh feed id and for 533 and 534:
`docker compose exec php bin/console app:feeds:refresh --feed <id>`

Expected: the pending count of each fresh feed drops to 0 (≤ 100 rows per pass, so a feed with more takes another refresh). Inspect (read-only):

```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT id, LEFT(summary, 60), image_url, SUBSTRING(content_html, LOCATE('<figure', content_html), 80) FROM entry WHERE feed_id = <id> ORDER BY id DESC LIMIT 15"
```
Check: link-card posts no longer end their text with the card URL; no `[contains quote post` anywhere (`SELECT COUNT(*) FROM entry WHERE feed_id IN (<fresh ids>) AND content_html LIKE '%contains quote post%'` → 0); quotes, image grids and videos carry their figures; `image_url` is set for image, video and card posts. New posts on 533/534 since the deploy (if any) are filled the same way; older ones stay as they were.

- [ ] **Step 4: The reader, on the real render**

In the browser (built-in browser pane, Mobile viewport if the desktop UA is bot-blocked), open one post of each kind as e2e-admin: a link card (bordered block, thumbnail, title, description, host; no bare URL above it), a quote (indented card with the author line linking to bsky.app), multiple images (a grid), a video (poster in its own shape, plays on click via hls.js, "Watch on Bluesky" below), a starter pack (the fallback link). Screenshot desktop and Mobile. Check the list/magazine card shows the new entry image.

- [ ] **Step 5: Logs**

`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name == "ERROR" or .level_name == "WARNING")'`
Expected: no `Bluesky enrichment failed` and no unexplained warnings. A `Bluesky AppView gave no usable answer` warning is acceptable only if the AppView was unreachable at that moment; its rows must still be queued.

- [ ] **Step 6: Leave the fixtures as found**

Unsubscribe e2e-admin from the profiles added in Step 2 (through the SPA), so the account holds what it held before. Do not delete feeds or entries by SQL.
