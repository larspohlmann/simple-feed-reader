# Substack Comments Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Substack post's comments show in the article view, read-only, through the existing comments panel (#1737).

**Architecture:** A feed learns it is a Substack from its channel `<generator>Substack</generator>` at every ingest and keeps that as `Feed::$platform`. A post of such a feed whose URL is `<origin>/p/<slug>` is a *Substack post*. A third `CommentsSourceInterface`, `SubstackCommentsSource`, serves those posts from Substack's public JSON API (slug → post id → comment tree). `EntryListRowEnricher` gives a Substack post a discussion at read time, as it already does for social posts, so stored entries light up with no backfill once their feed's next refresh marks it. The frontend already renders nested comments by `depth`; it does not change.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM, PHPUnit 12.

**Spec:** [larspohlmann/simple-feed-reader#1737](https://github.com/larspohlmann/simple-feed-reader/issues/1737)

## Global Constraints

- Read-only: no replying, no liking (needs a Substack login).
- Every outbound request goes through `FeedFetcherInterface` (the SSRF boundary); never a raw HTTP client.
- A fetch spends one unit of the existing `commentsLimiter` budget, whatever the number of requests it makes, and honours `HostThrottle`.
- Comment bodies arrive as plain text; they become HTML only through `LinkedPlainText::asHtml()` (escaped, paragraphed, bare URLs linked).
- The cache scope is the post URL: every reader of a post gets the same answer.
- CLAUDE.md Clean Code rules apply in full: `final readonly`, roles per folder, no comments that restate code, PHPMD-clean touched files, `Response::HTTP_*` in tests.
- Test fixtures carry invented commenters, never real people's comments.

## Facts verified against the live API (2026-10-21, `joedolce.substack.com/p/true-or-false-the-more-mdma-you-take`)

- `GET <origin>/api/v1/posts/<slug>` → JSON object, `id` (int, e.g. `220865934`), `comment_count`.
- `GET <origin>/api/v1/post/<id>/comments?all_comments=true&sort=best_first` → `{"comments": [ … ]}`; each node has `id` (int), `name`, `handle`, `photo_url` (nullable), `date` (`2026-10-20T20:44:53.893Z`), `body` (plain text, `\n\n` between paragraphs), `deleted` (bool), `children` (same shape, nested).
- Comment permalink `<origin>/p/<slug>/comment/<id>` and profile `https://substack.com/@<handle>` both answer 200.
- Custom domains (e.g. `www.astralcodexten.com/feed`) carry `<generator>Substack</generator>` in the channel; items carry nothing that marks them.

## File Structure

| File | Role |
|---|---|
| `backend/src/Enum/FeedPlatform.php` (new) | The platform a feed is published on; `fromGenerator()` |
| `backend/src/Service/Parser/Model/ParsedFeedModel.php` | Gains `?string $generator` |
| `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php` | Reads channel `<generator>` |
| `backend/src/Entity/Feed.php` | `platform` column, `getPlatform()`, `recordPlatform()` |
| `backend/migrations/Version20261021120000.php` (new) | Adds `feed.platform` |
| `backend/src/Service/Ingest/EntryIngestor.php` | Records the platform on every ingest |
| `backend/src/Service/Comments/Model/SubstackPostModel.php` (new) | A post's origin and slug, and the URLs derived from them |
| `backend/src/Service/Comments/Support/SubstackPostAddress.php` (new) | Entry → `?SubstackPostModel` without a query |
| `backend/src/Service/Comments/Pass/SubstackThread.php` (new) | Flattens one answer's comment tree into `EntryCommentModel`s |
| `backend/src/Service/Comments/CommentsSource/SubstackCommentsSource.php` (new) | The third comments source |
| `backend/src/Repository/EntryListRowEnricher.php` | A Substack post's discussion |
| `backend/tests/Fixtures/substack/post.json`, `post-comments.json` (new) | Invented API answers |

---

### Task 1: A feed knows it is a Substack

**Files:**
- Create: `backend/src/Enum/FeedPlatform.php`, `backend/migrations/Version20261021120000.php`, `backend/tests/Enum/FeedPlatformTest.php`
- Modify: `backend/src/Service/Parser/Model/ParsedFeedModel.php`, `backend/src/Service/Parser/FeedFormatParser/Rss2Parser.php:60-66`, `backend/src/Entity/Feed.php` (after the `$network` column, ~line 64; accessors after `classifyAs()`), `backend/src/Service/Ingest/EntryIngestor.php` (`updateFeedMetadata()`)
- Test: `backend/tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php`, `backend/tests/Service/Ingest/EntryIngestorTest.php`

**Interfaces:**
- Produces: `enum App\Enum\FeedPlatform: string { case Substack = 'substack'; static fromGenerator(?string): ?self }`; `Feed::getPlatform(): ?FeedPlatform`; `Feed::recordPlatform(?FeedPlatform): void`; `ParsedFeedModel::$generator` (`?string`, last constructor parameter, default `null`).

- [ ] **Step 1: Write the failing tests**

`backend/tests/Enum/FeedPlatformTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\FeedPlatform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedPlatformTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function substackGenerators(): iterable
    {
        yield 'as Substack writes it' => ['Substack'];
        yield 'any case, padded' => ['  substack '];
    }

    #[DataProvider('substackGenerators')]
    public function testSubstacksGeneratorNamesSubstack(string $generator): void
    {
        self::assertSame(FeedPlatform::Substack, FeedPlatform::fromGenerator($generator));
    }

    /** @return iterable<string, array{string|null}> */
    public static function otherGenerators(): iterable
    {
        yield 'none' => [null];
        yield 'WordPress' => ['https://wordpress.org/?v=6.6'];
        yield 'Substack only as a part' => ['Substack Clone 2.0'];
    }

    #[DataProvider('otherGenerators')]
    public function testAnyOtherGeneratorNamesNoPlatform(?string $generator): void
    {
        self::assertNull(FeedPlatform::fromGenerator($generator));
    }
}
```

Append to `Rss2ParserTest` (same style as `testReadsTheChannelImage`):

```php
    public function testReadsTheChannelGenerator(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <generator>Substack</generator>
                    <item><title>One</title><link>https://example.com/p/one</link></item>
                </channel>
            </rss>
            XML;

        self::assertSame('Substack', FeedFormatParsers::feed($xml)->generator);
    }

    public function testAChannelWithoutAGeneratorParsesToNull(): void
    {
        $xml = /** @lang TEXT */ <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0">
                <channel>
                    <title>Example</title>
                    <item><title>One</title><link>https://example.com/1</link></item>
                </channel>
            </rss>
            XML;

        self::assertNull(FeedFormatParsers::feed($xml)->generator);
    }
```

Append to `EntryIngestorTest` (next to `testAFetchStoresTheFeedImage`, using its `$this->feed()` and `self::context()` helpers):

```php
    public function testAFeedGeneratedBySubstackIsASubstack(): void
    {
        $feed = $this->feed();

        $this->ingestor->ingest($feed, new ParsedFeedModel('Example', null, null, null, [], 'Substack'), self::context());

        self::assertSame(FeedPlatform::Substack, $feed->getPlatform());
    }

    public function testAFeedThatStopsNamingSubstackIsNoLongerOne(): void
    {
        $feed = $this->feed();
        $feed->recordPlatform(FeedPlatform::Substack);

        $this->ingestor->ingest($feed, new ParsedFeedModel('Example', null, null, null, []), self::context());

        self::assertNull($feed->getPlatform());
    }
```

(add `use App\Enum\FeedPlatform;`).

- [ ] **Step 2: Run them to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Enum/FeedPlatformTest.php tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php tests/Service/Ingest/EntryIngestorTest.php`
Expected: FAIL — `FeedPlatform` not found, unknown property `generator`, undefined method `getPlatform`.

- [ ] **Step 3: Implement**

`backend/src/Enum/FeedPlatform.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum FeedPlatform: string
{
    case Substack = 'substack';

    public static function fromGenerator(?string $generator): ?self
    {
        return null !== $generator && 0 === strcasecmp(trim($generator), 'Substack') ? self::Substack : null;
    }
}
```

`ParsedFeedModel`: add `public ?string $generator = null,` after `public array $entries,` and pass it on in `withEntries()`:

```php
        return new self($this->title, $this->siteUrl, $this->description, $this->imageUrl, $entries, $this->generator);
```

`Rss2Parser::parseFeed()`: add `$channel->text('generator'),` after `$entries,` in the `new ParsedFeedModel(…)` call.

`Feed.php`, after the `$network` property:

```php
    #[ORM\Column(length: 16, nullable: true, enumType: FeedPlatform::class)]
    private ?FeedPlatform $platform = null;
```

and after `classifyAs()`:

```php
    public function getPlatform(): ?FeedPlatform
    {
        return $this->platform;
    }

    public function recordPlatform(?FeedPlatform $platform): void
    {
        $this->platform = $platform;
    }
```

`EntryIngestor::updateFeedMetadata()`, as its first line (unconditional: the platform reflects the latest document, unlike the title, which a sparse fetch must not erase):

```php
        $feed->recordPlatform(FeedPlatform::fromGenerator($parsed->generator));
```

`backend/migrations/Version20261021120000.php` — modelled on `Version20261019130000.php` (same `abortIfUnsupportedPlatform()`, `isTransactional(): false`):

```php
final class Version20261021120000 extends AbstractMigration
{
    private const string COLUMN = 'platform';

    public function getDescription(): string
    {
        return 'Add feed.platform, the platform a feed is published on (#1737)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->getTable('feed')->hasColumn(self::COLUMN), 'feed.platform already exists.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? 'ALTER TABLE feed ADD platform VARCHAR(16) DEFAULT NULL'
            : 'ALTER TABLE feed ADD COLUMN platform VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->getTable('feed')->hasColumn(self::COLUMN), 'feed.platform does not exist.');

        $this->abortIfUnsupportedPlatform();
        $this->addSql('ALTER TABLE feed DROP COLUMN ' . self::COLUMN);
    }
```

- [ ] **Step 4: Run the tests and the migration checks**

Run: `php bin/phpunit tests/Enum/FeedPlatformTest.php tests/Service/Parser tests/Service/Ingest/EntryIngestorTest.php` — Expected: PASS.

Migration (CLAUDE.md: no test ever runs one). SQLite, from empty, in the worktree:

```bash
DATABASE_URL="sqlite:///$PWD/var/migrate-check.db" bin/console doctrine:migrations:migrate --no-interaction --env=test && DATABASE_URL="sqlite:///$PWD/var/migrate-check.db" bin/console doctrine:schema:validate --env=test; rm -f var/migrate-check.db
```

Expected: migrate succeeds, `[OK] The database schema is in sync with the mapping files.` The MySQL leg runs in CI (`migrations` job); a worktree cannot use the shared Docker stack.

- [ ] **Step 5: Commit**

```bash
git add src/Enum/FeedPlatform.php src/Service/Parser/Model/ParsedFeedModel.php src/Service/Parser/FeedFormatParser/Rss2Parser.php src/Entity/Feed.php src/Service/Ingest/EntryIngestor.php migrations/Version20261021120000.php tests/Enum/FeedPlatformTest.php tests/Service/Parser/FeedFormatParser/Rss2ParserTest.php tests/Service/Ingest/EntryIngestorTest.php
git commit -m "feat(#1737): a feed knows it is a Substack from its generator"
```

---

### Task 2: An entry knows its Substack post

**Files:**
- Create: `backend/src/Service/Comments/Model/SubstackPostModel.php`, `backend/src/Service/Comments/Support/SubstackPostAddress.php`
- Test: `backend/tests/Service/Comments/Support/SubstackPostAddressTest.php`, `backend/tests/Service/Comments/Model/SubstackPostModelTest.php`

**Interfaces:**
- Consumes: `Feed::getPlatform()`, `FeedPlatform::Substack` (Task 1).
- Produces: `SubstackPostAddress::of(Entry): ?SubstackPostModel`; `SubstackPostModel(string $origin, string $slug)` with `url(): string`, `postApiUrl(): string`, `commentsApiUrl(int $postId): string`, `commentUrl(int $commentId): string`.

- [ ] **Step 1: Write the failing tests**

`SubstackPostAddressTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\FeedPlatform;
use App\Service\Comments\Support\SubstackPostAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubstackPostAddressTest extends TestCase
{
    private static function entry(?string $url, ?FeedPlatform $platform = FeedPlatform::Substack): Entry
    {
        $feed = new Feed('https://www.astralcodexten.com/feed');
        $feed->recordPlatform($platform);

        return new Entry($feed, 'guid', $url, 'Title', new \DateTimeImmutable(), new \DateTimeImmutable());
    }

    public function testAPostOfASubstackIsAddressedByItsOriginAndSlug(): void
    {
        $post = SubstackPostAddress::of(self::entry('https://www.astralcodexten.com/p/against-against-handover'));

        self::assertNotNull($post);
        self::assertSame('https://www.astralcodexten.com', $post->origin);
        self::assertSame('against-against-handover', $post->slug);
    }

    public function testATrailingSlashAndAQueryAreNoPartOfTheSlug(): void
    {
        $post = SubstackPostAddress::of(self::entry('https://joe.substack.com/p/one-post/?utm_source=rss'));

        self::assertSame('one-post', $post?->slug);
    }

    /** @return iterable<string, array{string|null}> */
    public static function notPosts(): iterable
    {
        yield 'no URL' => [null];
        yield 'a note' => ['https://joe.substack.com/note/c-123'];
        yield 'a podcast page' => ['https://joe.substack.com/podcast'];
        yield 'a comment' => ['https://joe.substack.com/p/one-post/comment/5'];
        yield 'plain http' => ['http://joe.substack.com/p/one-post'];
    }

    #[DataProvider('notPosts')]
    public function testAnythingButAPostPageIsNoPost(?string $url): void
    {
        self::assertNull(SubstackPostAddress::of(self::entry($url)));
    }

    public function testAPostPathOnAFeedThatIsNoSubstackIsNoPost(): void
    {
        self::assertNull(SubstackPostAddress::of(self::entry('https://blog.example.org/p/one-post', null)));
    }
}
```

`SubstackPostModelTest`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Comments\Model;

use App\Service\Comments\Model\SubstackPostModel;
use PHPUnit\Framework\TestCase;

final class SubstackPostModelTest extends TestCase
{
    public function testDerivesThePageTheApiAndTheCommentAddresses(): void
    {
        $post = new SubstackPostModel('https://joe.substack.com', 'one-post');

        self::assertSame('https://joe.substack.com/p/one-post', $post->url());
        self::assertSame('https://joe.substack.com/api/v1/posts/one-post', $post->postApiUrl());
        self::assertSame(
            'https://joe.substack.com/api/v1/post/42/comments?all_comments=true&sort=best_first',
            $post->commentsApiUrl(42),
        );
        self::assertSame('https://joe.substack.com/p/one-post/comment/7', $post->commentUrl(7));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Comments/Support tests/Service/Comments/Model/SubstackPostModelTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement**

`SubstackPostModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments\Model;

final readonly class SubstackPostModel
{
    public function __construct(public string $origin, public string $slug)
    {
    }

    public function url(): string
    {
        return $this->origin . '/p/' . $this->slug;
    }

    public function postApiUrl(): string
    {
        return $this->origin . '/api/v1/posts/' . $this->slug;
    }

    public function commentsApiUrl(int $postId): string
    {
        return $this->origin . '/api/v1/post/' . $postId . '/comments?all_comments=true&sort=best_first';
    }

    public function commentUrl(int $commentId): string
    {
        return $this->url() . '/comment/' . $commentId;
    }
}
```

`SubstackPostAddress.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments\Support;

use App\Entity\Entry;
use App\Enum\FeedPlatform;
use App\Service\Comments\Model\SubstackPostModel;

final class SubstackPostAddress
{
    private const string POST_PATH = '#^/p/([A-Za-z0-9-]+)/?$#';

    public static function of(Entry $entry): ?SubstackPostModel
    {
        $url = $entry->getUrl();
        if (null === $url || FeedPlatform::Substack !== $entry->getFeed()->getPlatform()) {
            return null;
        }
        $host = parse_url($url, \PHP_URL_HOST);
        $isPostPage = 'https' === parse_url($url, \PHP_URL_SCHEME)
            && \is_string($host)
            && 1 === preg_match(self::POST_PATH, (string) parse_url($url, \PHP_URL_PATH), $slug);

        return $isPostPage ? new SubstackPostModel('https://' . strtolower($host), $slug[1]) : null;
    }

    private function __construct()
    {
    }
}
```

(If PHPStan cannot see `$slug` as set past the `&&` chain, split into a guard: compute `$host`, return null unless https + string host, then `preg_match` into `$slug` and return.)

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Service/Comments/Support tests/Service/Comments/Model/SubstackPostModelTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Comments/Model/SubstackPostModel.php src/Service/Comments/Support/SubstackPostAddress.php tests/Service/Comments/Support/SubstackPostAddressTest.php tests/Service/Comments/Model/SubstackPostModelTest.php
git commit -m "feat(#1737): an entry of a Substack feed knows its post"
```

---

### Task 3: Substack comments source

**Files:**
- Create: `backend/src/Service/Comments/Pass/SubstackThread.php`, `backend/src/Service/Comments/CommentsSource/SubstackCommentsSource.php`, `backend/tests/Fixtures/substack/post.json`, `backend/tests/Fixtures/substack/post-comments.json`
- Test: `backend/tests/Service/Comments/CommentsSource/SubstackCommentsSourceTest.php`

**Interfaces:**
- Consumes: `SubstackPostAddress::of()`, `SubstackPostModel` (Task 2); `JsonNodeModel` (`App\Service\Bluesky\Model`, the house reader for untyped JSON, already used by Social); `LinkedPlainText::asHtml()`; `DateParser::parse()`; `FeedFetcherInterface`; `HostThrottle`; `RateLimitGuard`; `RateLimiterFactoryInterface $commentsLimiter`.
- Produces: `SubstackCommentsSource implements CommentsSourceInterface` (autoconfigured into `app.comments_source`); `SubstackThread(SubstackPostModel $post, ?string $entryAuthor)` with `comments(list<JsonNodeModel>): list<EntryCommentModel>`.

- [ ] **Step 1: Write the fixtures**

`backend/tests/Fixtures/substack/post.json`:

```json
{"id": 4242, "slug": "one-post", "comment_count": 4}
```

`backend/tests/Fixtures/substack/post-comments.json` — invented people; covers a reply, the post's author replying, a deleted comment that keeps its reply, a bare URL and two paragraphs:

```json
{"comments": [
  {"id": 11, "name": "Ada Reader", "handle": "adareader", "photo_url": "https://substackcdn.com/image/fetch/ada.png",
   "date": "2026-10-20T20:44:53.893Z", "deleted": false,
   "body": "First paragraph.\n\nSee https://example.org/study for more.",
   "children": [
     {"id": 12, "name": "Joe Writer", "handle": "joewriter", "photo_url": null,
      "date": "2026-10-20T21:00:00.000Z", "deleted": false, "body": "Thanks <b>Ada</b>.", "children": []}
   ]},
  {"id": 13, "name": null, "handle": null, "photo_url": null, "date": "2026-10-20T22:00:00.000Z",
   "deleted": true, "body": null,
   "children": [
     {"id": 14, "name": "Bo Second", "handle": "bosecond", "photo_url": null,
      "date": "2026-10-20T22:30:00.000Z", "deleted": false, "body": "Still here.", "children": []}
   ]}
]}
```

- [ ] **Step 2: Write the failing test**

`SubstackCommentsSourceTest` — a `KernelTestCase` set up exactly as `CommentsFeedSourceTest::setUp()` (clear `test.cache.rate_limiter`, `StubFeedFetcher`, `MockClock('2026-10-21 12:00:00')`, `HostThrottle` on an `ArrayAdapter`, swap both into the container, take `SubstackCommentsSource` from it):

```php
    private const string POST = 'https://joe.substack.com/p/one-post';
    private const string POST_API = 'https://joe.substack.com/api/v1/posts/one-post';
    private const string COMMENTS_API =
        'https://joe.substack.com/api/v1/post/4242/comments?all_comments=true&sort=best_first';

    private static function entry(?string $author = 'Joe Writer'): Entry
    {
        $feed = new Feed('https://joe.substack.com/feed');
        $feed->recordPlatform(FeedPlatform::Substack);
        $entry = new Entry($feed, self::POST, self::POST, 'One post', new \DateTimeImmutable(), new \DateTimeImmutable());
        $entry->setAuthor(new EntryAuthor($author));

        return $entry;
    }

    private function servePost(): void
    {
        $this->fetcher->willReturnBody(self::POST_API, (string) file_get_contents(self::FIXTURES . '/post.json'));
        $this->fetcher->willReturnBody(
            self::COMMENTS_API,
            (string) file_get_contents(self::FIXTURES . '/post-comments.json'),
        );
    }

    /** @return list<EntryCommentModel> */
    private function comments(): array
    {
        $this->servePost();
        $entry = self::entry();
        $answer = $this->source->fetch(new CommentsRequest($this->source, $this->user, $entry));

        return $this->source->read($entry, $answer->body)->comments;
    }

    public function testServesAPostOfASubstack(): void
    {
        self::assertTrue($this->source->serves(self::entry()));
    }

    public function testServesNoEntryOfAnotherFeed(): void
    {
        $entry = self::entry();
        $entry->getFeed()->recordPlatform(null);

        self::assertFalse($this->source->serves($entry));
    }

    public function testEveryReaderOfAPostSharesItsAnswer(): void
    {
        $other = new User('other@example.com', $this->clock->now());

        self::assertSame(self::POST, $this->source->audienceOf($this->user, self::entry())->scope);
        self::assertSame(self::POST, $this->source->audienceOf($other, self::entry())->scope);
    }

    public function testFlattensTheTreeDepthFirstAndDropsDeletedComments(): void
    {
        self::assertSame(
            [[self::POST . '/comment/11', 0], [self::POST . '/comment/12', 1], [self::POST . '/comment/14', 1]],
            array_map(static fn (EntryCommentModel $comment): array => [$comment->url, $comment->depth], $this->comments()),
        );
    }

    public function testACommenterIsNamedLinkedAndPictured(): void
    {
        $author = $this->comments()[0]->author;

        self::assertSame('Ada Reader', $author->name);
        self::assertSame('@adareader', $author->handle);
        self::assertSame('https://substack.com/@adareader', $author->profileUrl);
        self::assertSame('https://substackcdn.com/image/fetch/ada.png', $author->avatarUrl);
    }

    public function testABodyIsEscapedParagraphedAndLinked(): void
    {
        $comments = $this->comments();

        self::assertSame(
            '<p>First paragraph.</p><p>See <a href="https://example.org/study"',
            substr($comments[0]->html, 0, 59),
        );
        self::assertStringContainsString('Thanks &lt;b&gt;Ada&lt;/b&gt;.', $comments[1]->html);
    }

    public function testMarksThePostAuthorsReplies(): void
    {
        self::assertSame(
            [false, true, false],
            array_map(static fn (EntryCommentModel $comment): bool => $comment->byEntryAuthor, $this->comments()),
        );
    }

    public function testCarriesTheCommentDate(): void
    {
        self::assertEquals(new \DateTimeImmutable('2026-10-20 20:44:53.893'), $this->comments()[0]->publishedAt);
    }

    public function testAnAnswerThatIsNoCommentListIsUnavailable(): void
    {
        $this->expectException(CommentsUnavailableException::class);

        $this->source->read(self::entry(), '{"error": "not found"}');
    }

    public function testAPostAnswerWithoutAnIdIsUnavailable(): void
    {
        $this->fetcher->willReturnBody(self::POST_API, '{"slug": "one-post"}');
        $this->expectException(CommentsUnavailableException::class);

        $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
    }

    public function testAnUnreachablePostIsUnavailable(): void
    {
        $this->fetcher->willThrow(self::POST_API, new FeedUnreachableException('gone'));
        $this->expectException(CommentsUnavailableException::class);

        $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
    }

    public function testAThrottledHostIsThrottledAndNotAskedAgain(): void
    {
        $this->fetcher->willThrow(self::POST_API, new FeedThrottledException('slow down', 120));
        try {
            $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
            self::fail('The fetch was not throttled.');
        } catch (CommentsThrottledException $exception) {
            self::assertSame(120, $exception->retryAfterSeconds);
        }
        $this->fetcher->fetchedUrls = [];

        try {
            $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
        } catch (CommentsThrottledException) {
        }

        self::assertSame([], $this->fetcher->fetchedUrls);
    }

    public function testAFetchSpendsOneUnitOfTheCommentsBudget(): void
    {
        $this->servePost();
        for ($fetch = 0; $fetch < self::COMMENTS_BUDGET; ++$fetch) {
            $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
        }
        $this->expectException(RateLimitedException::class);

        $this->source->fetch(new CommentsRequest($this->source, $this->user, self::entry()));
    }
```

with `private const string FIXTURES = __DIR__ . '/../../../Fixtures/substack';` and `private const int COMMENTS_BUDGET = 30;` (doc comment: must match `framework.rate_limiter.comments.limit`, as in `CommentsFeedSourceTest`). Before writing the `substr(…, 0, 59)` assertion, print `LinkedPlainText::asHtml("First paragraph.\n\nSee https://example.org/study for more.")` and assert on the real prefix up to the `href`; check `FeedThrottledException`'s constructor and `CommentsThrottledException::$retryAfterSeconds` against `CommentsFeedSourceTest::throttledFetch()` and match them. Check how `HostThrottle::record()` clamps 120 (it may raise it to a floor) and assert the returned value.

- [ ] **Step 3: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Comments/CommentsSource/SubstackCommentsSourceTest.php`
Expected: FAIL — `SubstackCommentsSource` not found.

- [ ] **Step 4: Implement**

`backend/src/Service/Comments/Pass/SubstackThread.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments\Pass;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Model\CommentAuthorModel;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Comments\Model\SubstackPostModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Text\Support\LinkedPlainText;

final readonly class SubstackThread
{
    private const string PROFILE = 'https://substack.com/@';

    /** @noinspection AutowireWrongClass Built with new, never autowired */
    public function __construct(private SubstackPostModel $post, private ?string $entryAuthor)
    {
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<EntryCommentModel>
     */
    public function comments(array $nodes, int $depth = 0): array
    {
        $comments = [];
        foreach ($nodes as $node) {
            $comment = $this->commentOrNull($node, $depth);
            if (null !== $comment) {
                $comments[] = $comment;
            }
            $replyDepth = null === $comment ? $depth : $depth + 1;
            array_push($comments, ...$this->comments($node->nodes('children'), $replyDepth));
        }

        return $comments;
    }

    private function commentOrNull(JsonNodeModel $node, int $depth): ?EntryCommentModel
    {
        $id = $node->int('id');
        $html = LinkedPlainText::asHtml($node->text('body') ?? '');
        if (null === $id || null === $html || $node->isTrue('deleted')) {
            return null;
        }
        $name = $node->string('name');

        return (new EntryCommentModel(
            self::author($node, $name),
            $this->post->commentUrl($id),
            DateParser::parse($node->string('date')),
            $html,
            null !== $name && $name === $this->entryAuthor,
        ))->atDepth($depth);
    }

    private static function author(JsonNodeModel $node, ?string $name): CommentAuthorModel
    {
        $handle = $node->string('handle');

        return new CommentAuthorModel(
            $name,
            null === $handle ? null : '@' . $handle,
            null === $handle ? null : self::PROFILE . $handle,
            $node->string('photo_url'),
        );
    }
}
```

A deleted comment's replies take the deleted comment's own depth, so they read as replies to the thread above rather than to a gap.

`backend/src/Service/Comments/CommentsSource/SubstackCommentsSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Comments\CommentsSource;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Comments\Exception\CommentsThrottledException;
use App\Service\Comments\Exception\CommentsUnavailableException;
use App\Service\Comments\Model\CommentsAnswerModel;
use App\Service\Comments\Model\CommentsAudienceModel;
use App\Service\Comments\Model\CommentsPageModel;
use App\Service\Comments\Model\SubstackPostModel;
use App\Service\Comments\Pass\CommentsRequest;
use App\Service\Comments\Pass\SubstackThread;
use App\Service\Comments\Support\SubstackPostAddress;
use App\Service\Fetch\Exception\FeedThrottledException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Fetch\HostThrottle;
use App\Service\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class SubstackCommentsSource implements CommentsSourceInterface
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private HostThrottle $hostThrottle,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $commentsLimiter,
    ) {
    }

    public function serves(Entry $entry): bool
    {
        return null !== SubstackPostAddress::of($entry);
    }

    public function audienceOf(User $user, Entry $entry): CommentsAudienceModel
    {
        return new CommentsAudienceModel(self::postOf($entry)->url());
    }

    public function fetch(CommentsRequest $request): CommentsAnswerModel
    {
        $post = self::postOf($request->entry);
        $wait = $this->hostThrottle->remainingSeconds($post->url());
        if ($wait > 0) {
            throw new CommentsThrottledException('Substack asked us to wait.', $wait);
        }
        $this->rateLimitGuard->enforceForUser($this->commentsLimiter, $request->user);

        try {
            return CommentsAnswerModel::settled($this->body($post->commentsApiUrl($this->postIdOf($post))));
        } catch (FeedThrottledException $exception) {
            $wait = $this->hostThrottle->record($post->url(), $exception->retryAfterSeconds);

            throw new CommentsThrottledException('Substack asked us to wait.', $wait, $exception);
        } catch (FetchException $exception) {
            throw new CommentsUnavailableException('Substack could not be reached.', previous: $exception);
        }
    }

    public function read(Entry $entry, string $answer): CommentsPageModel
    {
        $nodes = JsonNodeModel::of(json_decode($answer, true))->listAt('comments')
            ?? throw new CommentsUnavailableException('Substack answered without a comment list.');

        return new CommentsPageModel((new SubstackThread(self::postOf($entry), $entry->getAuthor()))->comments($nodes));
    }

    /** @throws FetchException */
    private function postIdOf(SubstackPostModel $post): int
    {
        return JsonNodeModel::of(json_decode($this->body($post->postApiUrl()), true))->int('id')
            ?? throw new CommentsUnavailableException('Substack answered without a post id.');
    }

    /** @throws FetchException */
    private function body(string $url): string
    {
        return $this->fetcher->fetch($url)->modifiedBody();
    }

    private static function postOf(Entry $entry): SubstackPostModel
    {
        return SubstackPostAddress::of($entry)
            ?? throw new CommentsUnavailableException('The entry is not a Substack post.');
    }
}
```

`$commentsLimiter` binds by name to the existing `comments` limiter, as in `CommentsFeedSource`. No priority attribute: a Substack post never has a comments feed, so the order against `CommentsFeedSource` does not matter.

- [ ] **Step 5: Run the tests and the module gates**

Run: `php bin/phpunit tests/Service/Comments` — Expected: PASS.
Run: `composer stan` — Expected: no errors. `ServiceModuleCycleRule` must accept `Comments → Bluesky` (for `JsonNodeModel`); if it reports a cycle, read the JSON with `\is_array`/`\is_int`/`\is_string` in `SubstackThread` and the source instead, and say so in the commit body.
Run: `vendor/bin/phpmd src/Service/Comments text phpmd.xml.dist` (or `composer md`) — Expected: clean for the touched files.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Comments tests/Service/Comments tests/Fixtures/substack
git commit -m "feat(#1737): Substack posts take their comments from Substack's public API"
```

---

### Task 4: A Substack post offers its comments

**Files:**
- Modify: `backend/src/Repository/EntryListRowEnricher.php` (`enrichmentsByEntryId()`, `repliesDiscussionOf()`)
- Test: `backend/tests/Repository/EntryListRowEnricherTest.php`, `backend/tests/Controller/Api/EntryCommentsControllerTest.php`

**Interfaces:**
- Consumes: `SubstackPostAddress::of()` (Task 2), `SubstackCommentsSource` (Task 3).
- Produces: a Substack post's row carries `EntryListRowDiscussion($entry->getUrl(), CommentsLoad::Auto)`, so `EntryJson` sends `comments: 'auto'` and the client loads the panel on sight.

- [ ] **Step 1: Write the failing tests**

`EntryListRowEnricherTest`, modelled on `testAPostsRepliesAreItsDiscussionAndAnArticleKeepsItsOwn` (read it and reuse its row/entry helpers; persist a feed with `recordPlatform(FeedPlatform::Substack)`):

```php
    public function testASubstackPostsCommentsAreItsDiscussion(): void
    {
        $feed = new Feed('https://joe.substack.com/feed');
        $feed->recordPlatform(FeedPlatform::Substack);
        $post = $this->entryOf($feed, 'https://joe.substack.com/p/one-post');
        $page = $this->entryOf($feed, 'https://joe.substack.com/podcast');

        $rows = $this->enrich([$this->row($post), $this->row($page)]);

        self::assertEquals(
            new EntryListRowDiscussion('https://joe.substack.com/p/one-post', CommentsLoad::Auto),
            $rows[0]->enrichment->discussion,
        );
        self::assertNull($rows[1]->enrichment->discussion);
    }
```

(`entryOf()` is whatever the test already uses to persist an entry with a URL on a given feed; add a small private helper only if none fits.)

`EntryCommentsControllerTest`, after `testAMastodonPostsRepliesComeFromItsOriginWithoutAnAccount`, seeding a Substack feed + entry the way `seedEntry()` does:

```php
    public function testASubstackPostsCommentsComeFromSubstack(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('comments-substack@example.com');
        $fetcher = $this->installFetcher();
        $fixtures = __DIR__ . '/../../Fixtures/substack';
        $fetcher->willReturnBody('https://joe.substack.com/api/v1/posts/one-post', (string) file_get_contents($fixtures . '/post.json'));
        $fetcher->willReturnBody(
            'https://joe.substack.com/api/v1/post/4242/comments?all_comments=true&sort=best_first',
            (string) file_get_contents($fixtures . '/post-comments.json'),
        );
        $entry = $this->seedSubstackPost($user);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/comments', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame('ok', $body['status']);
        self::assertIsArray($body['comments']);
        self::assertSame([0, 1, 1], array_column($body['comments'], 'depth'));
    }
```

with `seedSubstackPost(User)` mirroring `seedEntry()` but on `new Feed('https://joe.substack.com/feed')` + `recordPlatform(FeedPlatform::Substack)`, entry URL and guid `https://joe.substack.com/p/one-post`, and no discussion. If `seedEntry()` and `seedSubstackPost()` would duplicate the subscription/persist block, extract the shared part into one private helper taking the `Feed` and `Entry` (DRY).

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Repository/EntryListRowEnricherTest.php tests/Controller/Api/EntryCommentsControllerTest.php`
Expected: the enricher test FAILS (discussion is null); the controller test PASSES already (the source is wired in Task 3) — it pins the wiring, not this task's change.

- [ ] **Step 3: Implement**

In `enrichmentsByEntryId()`, replace the discussion argument

```php
                isset($socialStates[$entryId]) ? self::repliesDiscussionOf($entries[$entryId]) : null,
```

with (a lookup array, not a boolean flag parameter, which CLAUDE.md forbids):

```php
                self::discussionOf($entries[$entryId], $socialStates),
```

```php
    /** @param array<int, EntryListRowSocial> $socialStates */
    private static function discussionOf(Entry $entry, array $socialStates): ?EntryListRowDiscussion
    {
        $isPlatformThread = isset($socialStates[$entry->requireId()]) || null !== SubstackPostAddress::of($entry);

        return $isPlatformThread ? new EntryListRowDiscussion($entry->getUrl(), CommentsLoad::Auto) : null;
    }
```

and delete `repliesDiscussionOf()`, which it replaces. Add `use App\Service\Comments\Support\SubstackPostAddress;`. Update the class docblock only if it names the discussion's sources (it says "discussion" — leave it).

- [ ] **Step 4: Run the tests**

Run: `php bin/phpunit tests/Repository/EntryListRowEnricherTest.php tests/Controller/Api/EntryCommentsControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Repository/EntryListRowEnricher.php tests/Repository/EntryListRowEnricherTest.php tests/Controller/Api/EntryCommentsControllerTest.php
git commit -m "feat(#1737): a Substack post offers its comments in the article view"
```

---

### Task 5: Verification

- [ ] **Step 1: Gates** — from `backend/`: `composer check`, `composer md`, `composer test:parallel`. All green. Run PhpStorm `lint_files` on the changed PHP if the IDE can see the worktree (it cannot see `.claude/worktrees`; then say so).
- [ ] **Step 2: Mutation** — `composer infection:diff` (commit first: it ignores untracked files). Kill any escaped mutant with a test, not by lowering `minMsi`.
- [ ] **Step 3: Real run against Substack** — a throwaway script in the scratchpad (never committed) that boots the `test` kernel from the worktree, builds a `Feed('https://joedolce.substack.com/feed')` with `recordPlatform(Substack)` and an `Entry` for `https://joedolce.substack.com/p/true-or-false-the-more-mdma-you-take`, and calls `SubstackCommentsSource::fetch()` + `read()` with the real fetcher. Expected: 10 comments, depths 0/1, Jim Fadiman first. Repeat for a custom-domain post (`https://www.astralcodexten.com/p/against-against-handover`) to prove the custom-domain path, and for `FeedPlatform::fromGenerator()` against that feed's live `<generator>`.
- [ ] **Step 4: Push and open the PR** into `develop`, body `Closes #1737`, no attribution lines.
- [ ] **Step 5: Live check (after the branch reaches the main checkout)** — the Docker stack belongs to the main checkout, which another session holds. Hand Lars this checklist: apply the migration to the live Docker DB (`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`), refresh the "Modern Psychedelics" feed, open the example entry on :4200, see the comments panel load with Joe Dolce's reply indented; scan today's dev log for errors.
