# Mixcloud Profiles Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Subscribe to a `www.mixcloud.com/<user>/` profile through Mixcloud's public cloudcasts API and play each free show in the internal audio player through the Mixcloud widget, seekable like SoundCloud.

**Architecture:** A new `mixcloud-api` source format with a thin `FeedBodyParser` strategy delegating to `Service/Parser/MixcloudJsonParser`; a `MixcloudEmbedProvider` that turns every show URL into one canonical widget URL (so the existing `VideoPageEntryRule` gives each entry its player); a `MixcloudProfileFeed` discovery offer; one merged verbatim subscribe/preview path. On the frontend a `mixcloud-widget-protocol` module and a `MixcloudPlayback extends FramePlayback`, wired in as a third frame kind.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12, PHPStan max, PHPMD, phptramp, Infection), Angular 20 standalone + signals (Jest in the Docker `frontend` container).

**Spec:** `docs/superpowers/specs/2026-10-31-mixcloud-profiles-design.md`

## Global Constraints

- Branch `feature/1932-mixcloud-profiles`; commit format `type(#1932): summary`; no Claude attribution anywhere.
- `declare(strict_types=1)` in every PHP file; `final readonly class` by default; PSR-12; PHPStan level max, no baseline, no `@phpstan-ignore` without a reason.
- No abbreviations, no single-letter names (closure params, caught exceptions, loop counters included).
- Default to no comment; a comment only where a future reader would get the code wrong without it, one line.
- HTTP statuses are `Response::HTTP_*`, never a number.
- PHPUnit 12: `#[DataProvider('…')]`, `$this->once()` not `self::once()`.
- Frontend: Prettier at 100 columns; no `.scss` changes are needed; frontend tests run inside Docker.
- Subscriber-exclusive shows are skipped: they never become entries.
- No show descriptions, no chapters, no Mixcloud login, no stream URLs outside the widget.
- Canonical widget URL: `https://player-widget.mixcloud.com/widget/iframe/?feed=<rawurlencoded key>`; label "Listen on Mixcloud"; `EmbedKind::Audio`, `EmbedShape::Strip`; `sourceHosts()` = `player-widget.mixcloud.com`.
- Widget origin `https://player-widget.mixcloud.com`; incoming `{mixcloud: "playerWidget", type, data}`; outgoing `{type: "getApi"}` and `{type: "method", data: {methodId, methodName, args}}`; position only from `progress` events.
- `SourceFormat::VERBATIM = [SCRAPED, WP_JSON, MIXCLOUD_API]`; the scrape gate stays for `scraped` only; the candidate title passes through for the published formats (wp-json, mixcloud-api), not for a scrape (#787).

## Facts measured while writing this plan (they correct or sharpen the spec)

- **`is_exclusive` is present only on exclusive shows** (`true`); a free show has no `is_exclusive` key at all (Digweed, NTS, Worldwide FM, 2026-10-31). The parser skips a show only when `is_exclusive === true`.
- **Show keys can carry percent-encoded UTF-8 and capitals**: `/NTSRadio/ma%C3%AF-linh-invites-tasya-kudryk-29th-october-2026/`, `/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/`. Mixcloud's own oEmbed encodes the key once more (`…mel%25C3%25A9…`), which is exactly what `rawurlencode($key)` produces, so the frame pattern allows `%25XX` inside a segment.
- **A `feed` parameter can be a whole show URL** (Mixcloud's oEmbed iframe: `feed=https%3A%2F%2Fwww.mixcloud.com%2F…`); the provider reads both forms.
- **Tag links look like shows**: `https://www.mixcloud.com/genres/techno/`. `genres` and the other site sections are reserved, and a profile's own tabs (`/user/uploads/`, …) are not shows.
- The profile page answers our fetch (`200`, `<title>John Digweed | Mixcloud</title>`); `FeedDiscovery` hands every offer the page's `finalUrl` and body once the page arrived and is not itself a feed, so the offer runs. If the page fetch fails, no offer runs — accepted.

---

## File Structure

Backend (`backend/`)

| File | Responsibility |
|---|---|
| `src/Enum/SourceFormat.php` (modify) | `MIXCLOUD_API`, `VERBATIM`, `isVerbatim()` |
| `src/Service/Parser/MixcloudJsonParser.php` (create) | cloudcasts JSON → `ParsedFeedModel` |
| `src/Service/Refresh/FeedBodyParser/MixcloudBodyParser.php` (create) | refresh strategy for `mixcloud-api` |
| `src/Service/Refresh/FeedBodyParser/FeedBodyParserInterface.php`, `XmlBodyParser.php`, `ScrapedBodyParser.php`, `WpJsonBodyParser.php` (modify) | strategy takes the feed URL, not the `Feed` row |
| `src/Service/Refresh/FeedBodyParser.php` (modify) | new `parseAs(format, body, feedUrl)` seam |
| `src/Service/Url/Support/MixcloudUrl.php` (create) | every rule for reading a Mixcloud URL: profile user, show key |
| `src/Service/Reader/Media/EmbedProvider/MixcloudEmbedProvider.php` (create) | show URL → canonical widget URL; frame pattern |
| `src/Service/Discovery/FeedOffer/MixcloudProfileFeed.php` (create) | profile page → cloudcasts candidate |
| `src/Service/Subscription/SubscriptionService.php`, `SubscriptionCreator.php` (modify) | one verbatim branch |
| `src/Service/Preview/FeedPreviewService.php` (modify) | parses through `FeedBodyParser::parseAs` |
| `tests/Fixtures/mixcloud/cloudcasts.json` (create) | recorded cloudcasts list, trimmed |
| tests mirroring each of the above |  |

Frontend (`frontend/src/`)

| File | Responsibility |
|---|---|
| `app/reader/media/mixcloud-widget-protocol.ts` (create) | origin, message builders, message reader, post helper |
| `app/reader/audio/playback/mixcloud-playback.ts` (create) | `MixcloudPlayback extends FramePlayback` |
| `app/reader/audio/playback/playback-kind.ts`, `playback-slots.ts` (modify) | `'mixcloud'` kind, its playback |
| `app/reader/audio/embed-media.ts` (modify) | recognises the Mixcloud widget URL |
| `app/reader/media/embed-frame-commands.ts`, `media-embeds.ts` (modify) | pause command, `allow: 'autoplay'` |
| `app/reader/embed-details-api.ts` (modify) | exhaustive switch: Mixcloud asks nothing |
| `app/reader/feeds/add-feed/add-feed-dialog.component.ts` (modify) | one verbatim list, "Mixcloud" label |
| `app/reader/media/embed-frame-allowlist.generated.json` (regenerated) | allow-list from the providers |
| `testing/embed-media-testing.ts` (modify) | Mixcloud test constants |

Docs: `README.md`, `docs/homepage/index.html`.

---

### Task 1: The `mixcloud-api` format and its parser

**Files:**
- Modify: `backend/src/Enum/SourceFormat.php`
- Create: `backend/src/Service/Parser/MixcloudJsonParser.php`
- Create: `backend/src/Service/Refresh/FeedBodyParser/MixcloudBodyParser.php`
- Create: `backend/tests/Fixtures/mixcloud/cloudcasts.json`
- Create: `backend/tests/Service/Parser/MixcloudJsonParserTest.php`
- Modify: `backend/tests/Enum/SourceFormatTest.php`
- Modify: `backend/tests/Service/Refresh/FeedBodyParserWiringTest.php`

**Interfaces:**
- Produces: `SourceFormat::MIXCLOUD_API = 'mixcloud-api'`, `SourceFormat::VERBATIM`, `SourceFormat::isVerbatim(?string $sourceFormat): bool` (asserts `value-of<self::VERBATIM>` when true); `MixcloudJsonParser::parse(string $body): ParsedFeedModel` (throws `FeedParseException`); `MixcloudBodyParser` keyed `mixcloud-api`; fixture `mixcloud/cloudcasts.json` (3 shows: one exclusive, two free → 2 entries; feed title "John Digweed").

- [ ] **Step 1: Add the fixture**

Recorded with `curl -s "https://api.mixcloud.com/johndigweed/cloudcasts/?limit=5"` on 2026-10-31 and trimmed to three shows (`hosts` dropped, pictures and user cut to the fields read). Hand edits: the second show's `created_time` is rewritten with a `+02:00` offset (same instant as the recorded `2026-10-23T15:00:14Z`); the third show lost `pictures`, `tags`, `created_time`, `updated_time` and `audio_length`. Note the free shows carry **no** `is_exclusive` key — that is how the API sends them.

Create `backend/tests/Fixtures/mixcloud/cloudcasts.json`:

```json
{
  "data": [
    {
      "key": "/johndigweed/transitions-with-john-digweed-moshic/",
      "url": "https://www.mixcloud.com/johndigweed/transitions-with-john-digweed-moshic/",
      "name": "Transitions with John Digweed (Live in Amsterdam) and Moshic",
      "tags": [
        {
          "key": "/genres/electronica/",
          "url": "https://www.mixcloud.com/genres/electronica/",
          "name": "Electronica"
        },
        {
          "key": "/genres/progressive-house/",
          "url": "https://www.mixcloud.com/genres/progressive-house/",
          "name": "Progressive house"
        }
      ],
      "created_time": "2026-10-30T16:00:36Z",
      "updated_time": "2026-10-31T07:54:12Z",
      "play_count": 414,
      "favorite_count": 37,
      "comment_count": 7,
      "listener_count": 261,
      "repost_count": 2,
      "pictures": {
        "large": "https://thumbnailer.mixcloud.com/unsafe/300x300/extaudio/9/d/8/2/83ba-416d-4af9-b22a-2a58d510751d",
        "extra_large": "https://thumbnailer.mixcloud.com/unsafe/600x600/extaudio/9/d/8/2/83ba-416d-4af9-b22a-2a58d510751d"
      },
      "slug": "transitions-with-john-digweed-moshic",
      "user": {
        "key": "/johndigweed/",
        "url": "https://www.mixcloud.com/johndigweed/",
        "name": "John Digweed",
        "username": "johndigweed"
      },
      "is_exclusive": true,
      "audio_length": 7177
    },
    {
      "key": "/johndigweed/transitions-with-john-digweed-and-tigerhook/",
      "url": "https://www.mixcloud.com/johndigweed/transitions-with-john-digweed-and-tigerhook/",
      "name": "Transitions with John Digweed and Tigerhook",
      "tags": [
        {
          "key": "/genres/techno/",
          "url": "https://www.mixcloud.com/genres/techno/",
          "name": "Techno"
        },
        {
          "key": "/genres/dub-techno/",
          "url": "https://www.mixcloud.com/genres/dub-techno/",
          "name": "Dub techno"
        }
      ],
      "created_time": "2026-10-23T17:00:14+02:00",
      "updated_time": "2026-10-31T07:54:14Z",
      "play_count": 7813,
      "favorite_count": 351,
      "comment_count": 46,
      "listener_count": 4404,
      "repost_count": 42,
      "pictures": {
        "large": "https://thumbnailer.mixcloud.com/unsafe/300x300/extaudio/0/0/8/3/13e4-324d-45f4-bd8f-70c23dd0781f",
        "extra_large": "https://thumbnailer.mixcloud.com/unsafe/600x600/extaudio/0/0/8/3/13e4-324d-45f4-bd8f-70c23dd0781f"
      },
      "slug": "transitions-with-john-digweed-and-tigerhook",
      "user": {
        "key": "/johndigweed/",
        "url": "https://www.mixcloud.com/johndigweed/",
        "name": "John Digweed",
        "username": "johndigweed"
      },
      "audio_length": 7093
    },
    {
      "key": "/johndigweed/transitions-john-digweed-and-martin-garcia/",
      "url": "https://www.mixcloud.com/johndigweed/transitions-john-digweed-and-martin-garcia/",
      "name": "Transitions with John Digweed and Martin Garcia",
      "play_count": 9977,
      "favorite_count": 397,
      "comment_count": 56,
      "listener_count": 5638,
      "repost_count": 55,
      "slug": "transitions-john-digweed-and-martin-garcia",
      "user": {
        "key": "/johndigweed/",
        "url": "https://www.mixcloud.com/johndigweed/",
        "name": "John Digweed",
        "username": "johndigweed"
      }
    }
  ],
  "paging": {
    "next": "https://api.mixcloud.com/johndigweed/cloudcasts/?limit=5&until=2026-10-02+15%3A00%3A47",
    "previous": "https://api.mixcloud.com/johndigweed/cloudcasts/?limit=5&since=2026-10-30+16%3A00%3A36"
  },
  "name": "John Digweed's Cloudcasts"
}
```

- [ ] **Step 2: Write the failing parser test**

Create `backend/tests/Service/Parser/MixcloudJsonParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\MixcloudJsonParser;
use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Tests\Support\ReadsFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudJsonParserTest extends TestCase
{
    use ReadsFixtures;

    private function parse(string $body): ParsedFeedModel
    {
        return (new MixcloudJsonParser())->parse($body);
    }

    private function recorded(): ParsedFeedModel
    {
        return $this->parse($this->fixture('mixcloud/cloudcasts.json'));
    }

    public function testTheFeedIsNamedAndLinkedAfterTheUser(): void
    {
        $feed = $this->recorded();

        self::assertSame('John Digweed', $feed->title);
        self::assertSame('https://www.mixcloud.com/johndigweed/', $feed->siteUrl);
        self::assertNull($feed->description);
        self::assertNull($feed->imageUrl);
    }

    public function testAnExclusiveShowNeverBecomesAnEntry(): void
    {
        $guids = array_map(
            static fn (ParsedEntryModel $entry): string => $entry->guid,
            $this->recorded()->entries,
        );

        self::assertSame([
            '/johndigweed/transitions-with-john-digweed-and-tigerhook/',
            '/johndigweed/transitions-john-digweed-and-martin-garcia/',
        ], $guids);
    }

    public function testMapsAShow(): void
    {
        $entry = $this->recorded()->entries[0];

        self::assertSame(
            'https://www.mixcloud.com/johndigweed/transitions-with-john-digweed-and-tigerhook/',
            $entry->url,
        );
        self::assertSame('Transitions with John Digweed and Tigerhook', $entry->headline->text);
        self::assertFalse($entry->headline->derived);
        self::assertSame('John Digweed', $entry->author);
        self::assertNull($entry->summary);
        self::assertNull($entry->contentHtml);
        self::assertSame(
            'https://thumbnailer.mixcloud.com/unsafe/600x600/extaudio/0/0/8/3/13e4-324d-45f4-bd8f-70c23dd0781f',
            $entry->media->image?->url,
        );
        self::assertEquals(
            [new ParsedCategoryModel('Techno'), new ParsedCategoryModel('Dub techno')],
            $entry->categories,
        );
    }

    public function testAnOffsetCreationTimeIsStoredAsUtc(): void
    {
        $publishedAt = $this->recorded()->entries[0]->publishedAt;

        self::assertNotNull($publishedAt);
        self::assertSame('2026-10-23T15:00:14+00:00', $publishedAt->format('c'));
    }

    public function testAShowWithoutTheOptionalFieldsStillBecomesAnEntry(): void
    {
        $entry = $this->recorded()->entries[1];

        self::assertSame('Transitions with John Digweed and Martin Garcia', $entry->headline->text);
        self::assertNull($entry->media->image);
        self::assertSame([], $entry->categories);
        self::assertNull($entry->publishedAt);
        self::assertSame('John Digweed', $entry->author);
    }

    public function testAShowMarkedNotExclusiveIsAnEntry(): void
    {
        $body = '{"data":[{"key":"/dj/free/","url":"https://www.mixcloud.com/dj/free/","name":"Free",'
            . '"is_exclusive":false}]}';

        self::assertCount(1, $this->parse($body)->entries);
    }

    /** @return iterable<string, array{string}> */
    public static function showsMissingARequiredField(): iterable
    {
        yield 'no key' => ['{"url":"https://www.mixcloud.com/dj/a/","name":"A"}'];
        yield 'no url' => ['{"key":"/dj/a/","name":"A"}'];
        yield 'no name' => ['{"key":"/dj/a/","url":"https://www.mixcloud.com/dj/a/"}'];
        yield 'a blank name' => ['{"key":"/dj/a/","url":"https://www.mixcloud.com/dj/a/","name":"  "}'];
        yield 'a key that is no string' => ['{"key":7,"url":"https://www.mixcloud.com/dj/a/","name":"A"}'];
        yield 'a show that is no object' => ['"junk"'];
    }

    #[DataProvider('showsMissingARequiredField')]
    public function testAShowMissingAKeyUrlOrNameIsSkipped(string $show): void
    {
        $body = '{"data":[' . $show . ',{"key":"/dj/b/","url":"https://www.mixcloud.com/dj/b/","name":"B"}]}';

        $entries = $this->parse($body)->entries;

        self::assertCount(1, $entries);
        self::assertSame('/dj/b/', $entries[0]->guid);
    }

    public function testAnEmptyListIsAnEmptyUntitledFeed(): void
    {
        $feed = $this->parse('{"data":[],"paging":{},"name":"Nobody\'s Cloudcasts"}');

        self::assertSame([], $feed->entries);
        self::assertNull($feed->title);
    }

    /** @return iterable<string, array{string}> */
    public static function bodiesThatAreNoCloudcastsList(): iterable
    {
        yield 'an empty body' => ['   '];
        yield 'text that is no JSON' => ['<html>'];
        yield 'a JSON null' => ['null'];
        yield 'a JSON list' => ['[]'];
        yield 'an API error' => ['{"error":{"type":"NotFound","message":"Not found"}}'];
        yield 'a data object, not a list' => ['{"data":{"key":"/dj/a/"}}'];
    }

    #[DataProvider('bodiesThatAreNoCloudcastsList')]
    public function testABodyThatIsNoCloudcastsListThrows(string $body): void
    {
        $this->expectException(FeedParseException::class);

        $this->parse($body);
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Parser/MixcloudJsonParserTest.php`
Expected: FAIL — `Class "App\Service\Parser\MixcloudJsonParser" not found`.

- [ ] **Step 4: Add the format to `SourceFormat`**

In `backend/src/Enum/SourceFormat.php`, after the `WP_JSON` constant add:

```php
    /** A Mixcloud user's shows, read through the public cloudcasts API (#1932). */
    public const string MIXCLOUD_API = 'mixcloud-api';

    /** The formats a discovery candidate is subscribed and previewed by as offered, without discovery again. */
    public const array VERBATIM = [self::SCRAPED, self::WP_JSON, self::MIXCLOUD_API];
```

and after `isSaved()`:

```php
    /** @phpstan-assert-if-true value-of<self::VERBATIM> $sourceFormat */
    public static function isVerbatim(?string $sourceFormat): bool
    {
        return \in_array($sourceFormat, self::VERBATIM, true);
    }
```

- [ ] **Step 5: Write the parser**

Create `backend/src/Service/Parser/MixcloudJsonParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Model\ParsedCategoryModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;
use App\Service\Parser\Support\DateParser;
use App\Service\Parser\Support\EntryTitle;

/**
 * Turns a Mixcloud `/<user>/cloudcasts/` list into a ParsedFeedModel. The list carries no descriptions, and an
 * exclusive show never plays for a reader without a subscription, so it is skipped.
 */
final readonly class MixcloudJsonParser
{
    public function parse(string $body): ParsedFeedModel
    {
        $shows = $this->shows($body);
        $owner = $this->record($shows[0] ?? null, 'user');

        return new ParsedFeedModel(
            $this->text($owner, 'name'),
            $this->text($owner, 'url'),
            null,
            null,
            $this->entries($shows),
        );
    }

    /** @return list<mixed> */
    private function shows(string $body): array
    {
        /** @var mixed $document */
        $document = json_decode(trim($body), true);
        $shows = \is_array($document) ? ($document['data'] ?? null) : null;
        if (!\is_array($shows) || !array_is_list($shows)) {
            throw new FeedParseException('Mixcloud body is not a cloudcasts list');
        }

        return $shows;
    }

    /**
     * @param list<mixed> $shows
     *
     * @return list<ParsedEntryModel>
     */
    private function entries(array $shows): array
    {
        $entries = [];
        foreach ($shows as $show) {
            $entry = \is_array($show) ? $this->entry($show) : null;
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Null for a show that becomes no entry: an exclusive one, or one without a key, url or name.
     *
     * @param array<mixed> $show
     */
    private function entry(array $show): ?ParsedEntryModel
    {
        $key = $this->text($show, 'key');
        $url = $this->text($show, 'url');
        $name = $this->text($show, 'name');
        if ($key === null || $url === null || $name === null || ($show['is_exclusive'] ?? false) === true) {
            return null;
        }

        return new ParsedEntryModel(
            guid: $key,
            url: $url,
            headline: EntryTitle::of($name, null),
            author: $this->text($this->record($show, 'user'), 'name'),
            summary: null,
            contentHtml: null,
            publishedAt: DateParser::parse($this->text($show, 'created_time')),
            media: new ParsedEntryMediaModel($this->image($show)),
            categories: $this->categories($show),
        );
    }

    /** @param array<mixed> $show */
    private function image(array $show): ?DeclaredImageModel
    {
        $url = $this->text($this->record($show, 'pictures'), 'extra_large');

        return $url === null ? null : new DeclaredImageModel($url);
    }

    /**
     * @param array<mixed> $show
     *
     * @return list<ParsedCategoryModel>
     */
    private function categories(array $show): array
    {
        $tags = $show['tags'] ?? null;
        $categories = [];
        foreach (\is_array($tags) ? $tags : [] as $tag) {
            $label = \is_array($tag) ? $this->text($tag, 'name') : null;
            if ($label !== null) {
                $categories[] = new ParsedCategoryModel($label);
            }
        }

        return $categories;
    }

    /** @return array<mixed> the object under `$field`, or an empty one */
    private function record(mixed $container, string $field): array
    {
        $value = \is_array($container) ? ($container[$field] ?? null) : null;

        return \is_array($value) ? $value : [];
    }

    /** @param array<mixed> $record */
    private function text(array $record, string $field): ?string
    {
        $value = $record[$field] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
```

- [ ] **Step 6: Run the parser test**

Run: `cd backend && php bin/phpunit tests/Service/Parser/MixcloudJsonParserTest.php`
Expected: PASS (all tests).

- [ ] **Step 7: Write the failing wiring and format tests**

In `backend/tests/Service/Refresh/FeedBodyParserWiringTest.php` add `use App\Enum\SourceFormat;` and `use App\Tests\Support\ReadsFixtures;`, add `use ReadsFixtures;` beside `use ScrapedFixtures;`, and append:

```php
    public function testMixcloudFormatResolvesToTheCloudcastsParser(): void
    {
        $feed = new Feed('https://api.mixcloud.com/johndigweed/cloudcasts/');
        $feed->setSourceFormat(SourceFormat::MIXCLOUD_API);

        $parsed = $this->parser()->parse($feed, $this->fixture('mixcloud/cloudcasts.json'));

        self::assertSame('John Digweed', $parsed->title);
        self::assertCount(2, $parsed->entries);
    }
```

In `backend/tests/Enum/SourceFormatTest.php` add `yield 'mixcloud' => [SourceFormat::MIXCLOUD_API, false];` to `formats()`, and append:

```php
    #[DataProvider('verbatimFormats')]
    public function testOnlyTheOfferedSourcesAreVerbatim(?string $sourceFormat, bool $verbatim): void
    {
        self::assertSame($verbatim, SourceFormat::isVerbatim($sourceFormat));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function verbatimFormats(): iterable
    {
        yield 'scraped' => [SourceFormat::SCRAPED, true];
        yield 'wp-json' => [SourceFormat::WP_JSON, true];
        yield 'mixcloud' => [SourceFormat::MIXCLOUD_API, true];
        yield 'xml' => [SourceFormat::XML, false];
        yield 'bluesky' => [SourceFormat::BLUESKY_API, false];
        yield 'a discovery syntax' => ['rss', false];
        yield 'none' => [null, false];
    }
```

- [ ] **Step 8: Run them to verify the wiring test fails**

Run: `cd backend && php bin/phpunit tests/Service/Refresh/FeedBodyParserWiringTest.php tests/Enum/SourceFormatTest.php`
Expected: `SourceFormatTest` PASS; `testMixcloudFormatResolvesToTheCloudcastsParser` FAIL with `No parser for source format "mixcloud-api"; tried xml: …`.

- [ ] **Step 9: Write the body parser**

Create `backend/src/Service/Refresh/FeedBodyParser/MixcloudBodyParser.php` (Task 4 changes the second parameter to `string $feedUrl` for every strategy):

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\FeedBodyParser;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Parser\MixcloudJsonParser;
use App\Service\Parser\Model\ParsedFeedModel;

/** Refresh strategy for a Mixcloud user's cloudcasts list, through the parser the subscribe preview uses too. */
final readonly class MixcloudBodyParser implements FeedBodyParserInterface
{
    public function __construct(private MixcloudJsonParser $parser)
    {
    }

    public static function format(): string
    {
        return SourceFormat::MIXCLOUD_API;
    }

    public function parse(string $body, Feed $feed): ParsedFeedModel
    {
        return $this->parser->parse($body);
    }
}
```

- [ ] **Step 10: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/Refresh/FeedBodyParserWiringTest.php tests/Enum/SourceFormatTest.php tests/Service/Parser/MixcloudJsonParserTest.php`
Expected: PASS.

- [ ] **Step 11: Lint the touched files**

Run: `cd backend && vendor/bin/phpcs src/Enum/SourceFormat.php src/Service/Parser/MixcloudJsonParser.php src/Service/Refresh/FeedBodyParser/MixcloudBodyParser.php tests/Service/Parser/MixcloudJsonParserTest.php && composer stan && composer md`
Expected: no findings. If PHPStan rejects `value-of<self::VERBATIM>` in the assert tag, spell the type as `'scraped'|'wp-json'|'mixcloud-api'`.

- [ ] **Step 12: Commit**

```bash
git add backend/src/Enum/SourceFormat.php backend/src/Service/Parser/MixcloudJsonParser.php \
  backend/src/Service/Refresh/FeedBodyParser/MixcloudBodyParser.php backend/tests/Fixtures/mixcloud/cloudcasts.json \
  backend/tests/Service/Parser/MixcloudJsonParserTest.php backend/tests/Enum/SourceFormatTest.php \
  backend/tests/Service/Refresh/FeedBodyParserWiringTest.php
git commit -m "feat(#1932): read a Mixcloud user's shows from the cloudcasts API"
```

---

### Task 2: Mixcloud URLs and the widget embed provider

**Files:**
- Create: `backend/src/Service/Url/Support/MixcloudUrl.php`
- Create: `backend/tests/Service/Url/Support/MixcloudUrlTest.php`
- Create: `backend/src/Service/Reader/Media/EmbedProvider/MixcloudEmbedProvider.php`
- Create: `backend/tests/Service/Reader/Media/EmbedProvider/MixcloudEmbedProviderTest.php`
- Modify: `backend/tests/Service/Reader/Media/EmbedFrameAllowlistTest.php` (`sourceUrls()`)
- Regenerate: `frontend/src/app/reader/media/embed-frame-allowlist.generated.json`

**Interfaces:**
- Produces: `MixcloudUrl::profileUser(string $url): ?string`, `MixcloudUrl::showKey(string $url): ?string` (returns `/<user>/<show>/`), `MixcloudUrl::isShowKey(string $key): bool`; `MixcloudEmbedProvider` (tagged `app.embed_provider` by `_instanceof`, no config edit). Canonical URL example: `https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fjohndigweed%2Ftransitions-1154%2F`.

No change is needed in `VideoPageEntryRule`: it calls `EmbedProviders::resolve($entry->url)`, and every Mixcloud entry's `url` is a `www.mixcloud.com/<user>/<show>/` page that this provider resolves, so each entry is prepended its "Listen on Mixcloud" player link. `PlayerPageExtractor` then answers such a page as a player page, so the reader shows the entry body with the player instead of scraping Mixcloud's page.

- [ ] **Step 1: Write the failing URL test**

Create `backend/tests/Service/Url/Support/MixcloudUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Url\Support;

use App\Service\Url\Support\MixcloudUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudUrlTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function profilePages(): iterable
    {
        yield 'a profile' => ['https://www.mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'without the trailing slash' => ['https://www.mixcloud.com/johndigweed', 'johndigweed'];
        yield 'with a query' => ['https://www.mixcloud.com/johndigweed/?utm_source=x', 'johndigweed'];
        yield 'capitals kept' => ['https://www.mixcloud.com/NTSRadio/', 'NTSRadio'];
        yield 'the bare host' => ['https://mixcloud.com/johndigweed/', 'johndigweed'];
        yield 'a mixed-case host' => ['https://www.MixCloud.com/johndigweed/', 'johndigweed'];
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', null];
        yield 'a site section' => ['https://www.mixcloud.com/discover/', null];
        yield 'the upload page' => ['https://www.mixcloud.com/upload/', null];
        yield 'the select page' => ['https://www.mixcloud.com/select/', null];
        yield 'the home page' => ['https://www.mixcloud.com/', null];
        yield 'another host' => ['https://soundcloud.com/johndigweed', null];
        yield 'a look-alike host' => ['https://www.mixcloud.com.evil.test/johndigweed/', null];
        yield 'the API host' => ['https://api.mixcloud.com/johndigweed/', null];
    }

    #[DataProvider('profilePages')]
    public function testReadsTheUserOfAProfilePage(string $url, ?string $user): void
    {
        self::assertSame($user, MixcloudUrl::profileUser($url));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function showPages(): iterable
    {
        yield 'a show' => [
            'https://www.mixcloud.com/johndigweed/transitions-1154/',
            '/johndigweed/transitions-1154/',
        ];
        yield 'without the trailing slash, with a fragment' => [
            'https://www.mixcloud.com/johndigweed/transitions-1154#comments',
            '/johndigweed/transitions-1154/',
        ];
        yield 'an encoded non-ASCII slug, kept encoded' => [
            'https://www.mixcloud.com/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/',
            '/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/',
        ];
        yield 'a slug ending in an underscore' => [
            'https://www.mixcloud.com/johndigweed/transitions-with-john-digweed-and-tim-green-_/',
            '/johndigweed/transitions-with-john-digweed-and-tim-green-_/',
        ];
        yield 'a profile' => ['https://www.mixcloud.com/johndigweed/', null];
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/uploads/', null];
        yield 'a genre' => ['https://www.mixcloud.com/genres/techno/', null];
        yield 'an old widget' => ['https://www.mixcloud.com/widget/iframe/?feed=x', null];
        yield 'three segments' => ['https://www.mixcloud.com/johndigweed/transitions-1154/extra/', null];
        yield 'a raw non-ASCII slug' => ['https://www.mixcloud.com/worldwidefm/melé/', null];
        yield 'another host' => ['https://example.com/johndigweed/transitions-1154/', null];
    }

    #[DataProvider('showPages')]
    public function testReadsTheKeyOfAShowPage(string $url, ?string $key): void
    {
        self::assertSame($key, MixcloudUrl::showKey($url));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function keys(): iterable
    {
        yield 'a show' => ['/johndigweed/transitions-1154/', true];
        yield 'an encoded slug' => ['/NTSRadio/ma%C3%AF-linh-invites-tasya-kudryk-29th-october-2026/', true];
        yield 'no trailing slash' => ['/johndigweed/transitions-1154', false];
        yield 'a profile' => ['/johndigweed/', false];
        yield 'a profile tab' => ['/johndigweed/favorites/', false];
        yield 'a site section' => ['/discover/house/', false];
        yield 'a full url' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', false];
        yield 'a space' => ['/johndigweed/two words/', false];
    }

    #[DataProvider('keys')]
    public function testTellsAShowKeyFromAnythingElse(string $key, bool $isShowKey): void
    {
        self::assertSame($isShowKey, MixcloudUrl::isShowKey($key));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Url/Support/MixcloudUrlTest.php`
Expected: FAIL — `Class "App\Service\Url\Support\MixcloudUrl" not found`.

- [ ] **Step 3: Write `MixcloudUrl`**

Create `backend/src/Service/Url/Support/MixcloudUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Url\Support;

/** Every rule for reading a Mixcloud page URL: its hosts, a profile's user and a show's key. */
final class MixcloudUrl
{
    private const array PAGE_HOSTS = ['www.mixcloud.com', 'mixcloud.com', 'm.mixcloud.com'];

    /** A key segment as the API spells it: ASCII, with any other character percent-encoded. */
    private const string SEGMENT = '(?:[A-Za-z0-9_-]|%[0-9A-Fa-f]{2})+';

    /** First path segments that are the site's own sections, never a user. */
    private const array SITE_SECTIONS = [
        'about', 'categories', 'dashboard', 'developers', 'discover', 'genres', 'help', 'live', 'messages',
        'notifications', 'oembed', 'player', 'pro', 'search', 'select', 'settings', 'tag', 'upload', 'widget',
    ];

    /** Second path segments that are a profile's own tabs, never a show. */
    private const array PROFILE_TABS = [
        'favorites', 'followers', 'following', 'hosts', 'listens', 'playlists', 'reposts', 'stream', 'uploads',
    ];

    public static function profileUser(string $url): ?string
    {
        $path = self::pagePath($url);
        if ($path === null || preg_match('#^/(' . self::SEGMENT . ')/?\z#', $path, $matches) !== 1) {
            return null;
        }

        return self::isSiteSection($matches[1]) ? null : $matches[1];
    }

    public static function showKey(string $url): ?string
    {
        $path = self::pagePath($url);
        if ($path === null) {
            return null;
        }
        $key = rtrim($path, '/') . '/';

        return self::isShowKey($key) ? $key : null;
    }

    public static function isShowKey(string $key): bool
    {
        if (preg_match('#^/(' . self::SEGMENT . ')/(' . self::SEGMENT . ')/\z#', $key, $matches) !== 1) {
            return false;
        }

        return !self::isSiteSection($matches[1]) && !\in_array(strtolower($matches[2]), self::PROFILE_TABS, true);
    }

    private static function pagePath(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !\in_array(strtolower($parts['host'] ?? ''), self::PAGE_HOSTS, true)) {
            return null;
        }

        return $parts['path'] ?? '/';
    }

    private static function isSiteSection(string $segment): bool
    {
        return \in_array(strtolower($segment), self::SITE_SECTIONS, true);
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run the URL test**

Run: `cd backend && php bin/phpunit tests/Service/Url/Support/MixcloudUrlTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing provider test**

Create `backend/tests/Service/Reader/Media/EmbedProvider/MixcloudEmbedProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\EmbedProvider\MixcloudEmbedProvider;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudEmbedProviderTest extends TestCase
{
    use MatchesEmbedFrames;

    private const string WIDGET = 'https://player-widget.mixcloud.com/widget/iframe/?feed=';
    private const string SHOW_WIDGET = self::WIDGET . '%2Fjohndigweed%2Ftransitions-1154%2F';

    private MixcloudEmbedProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MixcloudEmbedProvider();
    }

    /** @return iterable<string, array{string}> */
    public static function spellingsOfOneShow(): iterable
    {
        yield 'the show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'the show page with a query' => [
            'https://www.mixcloud.com/johndigweed/transitions-1154/?utm_source=x',
        ];
        yield 'the canonical widget' => [self::SHOW_WIDGET];
        yield 'a widget with autoplay and chrome flags' => [
            self::WIDGET . '%2Fjohndigweed%2Ftransitions-1154%2F&hide_cover=1&autoplay=1&light=1',
        ];
        yield 'a widget without the trailing slash' => [
            'https://player-widget.mixcloud.com/widget/iframe?feed=%2Fjohndigweed%2Ftransitions-1154%2F',
        ];
        yield 'a widget fed the whole show url, as the oEmbed iframe is' => [
            self::WIDGET . 'https%3A%2F%2Fwww.mixcloud.com%2Fjohndigweed%2Ftransitions-1154%2F&hide_cover=1',
        ];
    }

    #[DataProvider('spellingsOfOneShow')]
    public function testEverySpellingOfAShowBecomesTheCanonicalWidget(string $url): void
    {
        self::assertSame(self::SHOW_WIDGET, $this->provider->target($url)?->url);
    }

    /** Mixcloud's own oEmbed encodes the key once more, so an encoded slug's `%` arrives as `%25`. */
    public function testEncodesAnEncodedSlugOnceMoreAsMixcloudItselfDoes(): void
    {
        self::assertSame(
            self::WIDGET . '%2Fworldwidefm%2Fgilles-peterson-w-moses-boyd-flo-real-mel%25C3%25A9-29-10-26%2F',
            $this->provider->target(
                'https://www.mixcloud.com/worldwidefm/gilles-peterson-w-moses-boyd-flo-real-mel%C3%A9-29-10-26/',
            )?->url,
        );
    }

    public function testHasNoPosterSoItFallsBackToALabel(): void
    {
        $target = $this->provider->target(self::SHOW_WIDGET);

        self::assertNotNull($target);
        self::assertNull($target->posterUrl);
        self::assertSame('Listen on Mixcloud', $target->label);
    }

    /** @return iterable<string, array{string}> */
    public static function urlsThatAreNoShow(): iterable
    {
        yield 'a profile' => ['https://www.mixcloud.com/johndigweed/'];
        yield 'a profile tab' => ['https://www.mixcloud.com/johndigweed/uploads/'];
        yield 'a genre' => ['https://www.mixcloud.com/genres/techno/'];
        yield 'a widget without a feed' => [self::WIDGET];
        yield 'a widget fed a profile' => [self::WIDGET . '%2Fjohndigweed%2F'];
        yield 'a widget on another path' => ['https://player-widget.mixcloud.com/other/?feed=%2Fdj%2Fshow%2F'];
        yield 'the API host' => ['https://api.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'another host' => ['https://w.soundcloud.com/player/?url=x'];
        yield 'a look-alike host' => [
            'https://player-widget.mixcloud.com.evil.test/widget/iframe/?feed=%2Fdj%2Fshow%2F',
        ];
    }

    #[DataProvider('urlsThatAreNoShow')]
    public function testRejectsAUrlThatPlaysNoShow(string $url): void
    {
        self::assertNull($this->provider->target($url));
    }

    public function testIsAnAudioPlayerInAFixedHeightStrip(): void
    {
        $frame = self::onlyFrame($this->provider);

        self::assertSame(EmbedKind::Audio, $frame->kind);
        self::assertSame(EmbedShape::Strip, $frame->shape);
    }

    public function testTheFrameTakesTheCanonicalWidgetOfAnEncodedSlug(): void
    {
        $target = $this->provider->target('https://www.mixcloud.com/NTSRadio/ma%C3%AF-linh-invites-tasya-kudryk/');
        self::assertNotNull($target);

        self::assertSame(EmbedShape::Strip, self::frameMatching($this->provider->frames(), $target->url)->shape);
    }

    public function testTheFrameRefusesAWidgetWithFlags(): void
    {
        self::assertSame([], self::framesMatching($this->provider->frames(), self::SHOW_WIDGET . '&autoplay=1'));
    }

    public function testClaimsTheWidgetHost(): void
    {
        self::assertSame(['player-widget.mixcloud.com'], $this->provider->sourceHosts());
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/EmbedProvider/MixcloudEmbedProviderTest.php`
Expected: FAIL — class `MixcloudEmbedProvider` not found.

- [ ] **Step 7: Write the provider**

Create `backend/src/Service/Reader/Media/EmbedProvider/MixcloudEmbedProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\EmbedProvider;

use App\Service\Reader\Media\Model\EmbedFrameModel;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use App\Service\Url\Support\MixcloudUrl;

/**
 * The Mixcloud widget. A show is its key, `/<user>/<show>/`; the URL is rebuilt from the key alone, so `autoplay`
 * and every chrome flag cannot survive.
 */
final readonly class MixcloudEmbedProvider implements EmbedProviderInterface
{
    private const string HOST = 'player-widget.mixcloud.com';
    private const string PLAYER_URL = 'https://' . self::HOST . '/widget/iframe/?feed=';
    /** A key segment after rawurlencode(): a `%` of the API's own encoding arrives as `%25`. */
    private const string ENCODED_SEGMENT = '(?:[A-Za-z0-9_-]|%25[0-9A-Fa-f]{2})+';
    private const string LABEL = 'Listen on Mixcloud';

    public function target(string $url): ?EmbedTargetModel
    {
        $key = $this->showKey($url);

        return $key === null ? null : new EmbedTargetModel(self::PLAYER_URL . rawurlencode($key), null, self::LABEL);
    }

    public function frames(): array
    {
        return [new EmbedFrameModel(
            '^https://' . preg_quote(self::HOST, '#') . '/widget/iframe/\?feed=%2F'
                . self::ENCODED_SEGMENT . '%2F' . self::ENCODED_SEGMENT . '%2F$',
            EmbedKind::Audio,
            EmbedShape::Strip,
        )];
    }

    public function sourceHosts(): array
    {
        return [self::HOST];
    }

    private function showKey(string $url): ?string
    {
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }
        if (strtolower($parts['host'] ?? '') !== self::HOST) {
            return MixcloudUrl::showKey($url);
        }

        return $this->widgetKey($parts['path'] ?? '', $parts['query'] ?? '');
    }

    /** The widget's `feed` is a key, or a whole show URL in the iframe Mixcloud's oEmbed hands out. */
    private function widgetKey(string $path, string $query): ?string
    {
        if (!\in_array(rtrim($path, '/'), ['', '/widget/iframe'], true)) {
            return null;
        }
        parse_str($query, $queryParameters);
        $feed = $queryParameters['feed'] ?? null;
        if (!\is_string($feed)) {
            return null;
        }

        return MixcloudUrl::isShowKey($feed) ? $feed : MixcloudUrl::showKey($feed);
    }
}
```

- [ ] **Step 8: Run the provider test**

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/EmbedProvider/MixcloudEmbedProviderTest.php`
Expected: PASS.

- [ ] **Step 9: Extend the allow-list test and see it fail**

In `backend/tests/Service/Reader/Media/EmbedFrameAllowlistTest.php`, `sourceUrls()`, after the `dailymotion` yield add:

```php
        yield 'mixcloud show' => ['https://www.mixcloud.com/johndigweed/transitions-1154/', EmbedShape::Strip];
```

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/EmbedFrameAllowlistTest.php`
Expected: FAIL — `testTheCommittedClientAllowlistMatchesTheProviders` reports the allow-list is stale.

- [ ] **Step 10: Regenerate the frontend allow-list**

Run: `cd backend && php bin/console app:embed:dump-frame-allowlist`
Expected: `Wrote the reader embed allow-list to …/frontend/src/app/reader/media/embed-frame-allowlist.generated.json.` The diff adds exactly one entry (sorted first, `^https://player\\-widget…`):

```json
    {
        "pattern": "^https://player\\-widget\\.mixcloud\\.com/widget/iframe/\\?feed=%2F(?:[A-Za-z0-9_-]|%25[0-9A-Fa-f]{2})+%2F(?:[A-Za-z0-9_-]|%25[0-9A-Fa-f]{2})+%2F$",
        "kind": "audio",
        "shape": "strip"
    },
```

(`\-` is an identity escape, valid in the frontend's `new RegExp(pattern)` without the `u` flag.)

Run: `cd backend && php bin/phpunit tests/Service/Reader/Media/EmbedFrameAllowlistTest.php tests/Service/Reader`
Expected: PASS (the whole Reader suite, since `InBodyEmbedRewriter`, `DuplicateBlockCollapser` and `PlayerPageExtractor` now see one more provider).

- [ ] **Step 11: Lint and commit**

Run: `cd backend && vendor/bin/phpcs src/Service/Url/Support/MixcloudUrl.php src/Service/Reader/Media/EmbedProvider/MixcloudEmbedProvider.php tests/Service/Url/Support/MixcloudUrlTest.php tests/Service/Reader/Media/EmbedProvider/MixcloudEmbedProviderTest.php && composer stan && composer md`
Expected: no findings.

```bash
git add backend/src/Service/Url/Support/MixcloudUrl.php backend/tests/Service/Url/Support/MixcloudUrlTest.php \
  backend/src/Service/Reader/Media/EmbedProvider/MixcloudEmbedProvider.php \
  backend/tests/Service/Reader/Media/EmbedProvider/MixcloudEmbedProviderTest.php \
  backend/tests/Service/Reader/Media/EmbedFrameAllowlistTest.php \
  frontend/src/app/reader/media/embed-frame-allowlist.generated.json
git commit -m "feat(#1932): play Mixcloud shows through the canonical widget"
```

---

### Task 3: Discovery offers a profile's shows

**Files:**
- Create: `backend/src/Service/Discovery/FeedOffer/MixcloudProfileFeed.php`
- Create: `backend/tests/Service/Discovery/FeedOffer/MixcloudProfileFeedTest.php`
- Modify: `backend/tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php`
- Modify: `backend/tests/Service/Discovery/BuildsFeedDiscovery.php`

**Interfaces:**
- Consumes: `MixcloudUrl::profileUser()` (Task 2), `SourceFormat::MIXCLOUD_API` (Task 1).
- Produces: candidate `FeedCandidateModel('https://api.mixcloud.com/<user>/cloudcasts/', <page title>, 'mixcloud-api')`.

- [ ] **Step 1: Write the failing offer test**

Create `backend/tests/Service/Discovery/FeedOffer/MixcloudProfileFeedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Enum\SourceFormat;
use App\Service\Discovery\FeedOffer\MixcloudProfileFeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MixcloudProfileFeedTest extends TestCase
{
    private const string PROFILE_URL = 'https://www.mixcloud.com/johndigweed/';

    /** The profile page's head on 2026-10-31, trimmed to what an offer may read. */
    private const string PROFILE_PAGE = /** @lang HTML */ <<<'HTML'
        <html><head><title>John Digweed | Mixcloud</title>
        <link rel="alternate" href="https://app.mixcloud.com/oembed/?url=https%3A%2F%2Fwww.mixcloud.com%2Fjohndigweed%2F"
              type="application/json+oembed" title="John Digweed"/></head><body></body></html>
        HTML;

    public function testOffersTheCloudcastsOfTheProfileUser(): void
    {
        $candidate = (new MixcloudProfileFeed())->offer(self::PROFILE_PAGE, self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertSame('https://api.mixcloud.com/johndigweed/cloudcasts/', $candidate->url);
        self::assertSame('John Digweed | Mixcloud', $candidate->title);
        self::assertSame(SourceFormat::MIXCLOUD_API, $candidate->format);
    }

    public function testAPageWithoutATitleIsStillOffered(): void
    {
        $candidate = (new MixcloudProfileFeed())->offer('', self::PROFILE_URL);

        self::assertNotNull($candidate);
        self::assertNull($candidate->title);
    }

    /** @return iterable<string, array{string}> */
    public static function pagesThatAreNoProfile(): iterable
    {
        yield 'a show page' => ['https://www.mixcloud.com/johndigweed/transitions-1154/'];
        yield 'the discover section' => ['https://www.mixcloud.com/discover/'];
        yield 'the upload page' => ['https://www.mixcloud.com/upload/'];
        yield 'the select page' => ['https://www.mixcloud.com/select/'];
        yield 'the home page' => ['https://www.mixcloud.com/'];
        yield 'another host' => ['https://soundcloud.com/johndigweed'];
        yield 'a look-alike host' => ['https://www.mixcloud.com.evil.test/johndigweed/'];
    }

    #[DataProvider('pagesThatAreNoProfile')]
    public function testOffersNothingForAPageThatIsNoProfile(string $pageUrl): void
    {
        self::assertNull((new MixcloudProfileFeed())->offer(self::PROFILE_PAGE, $pageUrl));
    }
}
```

In `FeedOffersAreCollectedTest.php` add `use App\Service\Discovery\FeedOffer\MixcloudProfileFeed;` and `self::assertContains(MixcloudProfileFeed::class, $classes);`.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Discovery/FeedOffer`
Expected: FAIL — `MixcloudProfileFeed` not found.

- [ ] **Step 3: Write the offer**

Create `backend/src/Service/Discovery/FeedOffer/MixcloudProfileFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Enum\SourceFormat;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Url\Support\MixcloudUrl;

/** Offers the shows of the Mixcloud user a profile page belongs to; Mixcloud publishes no RSS, only this API. */
final readonly class MixcloudProfileFeed implements FeedOfferInterface
{
    private const string CLOUDCASTS_URL = 'https://api.mixcloud.com/%s/cloudcasts/';

    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        $user = MixcloudUrl::profileUser($pageUrl);
        if ($user === null) {
            return null;
        }

        return new FeedCandidateModel(
            sprintf(self::CLOUDCASTS_URL, $user),
            $this->pageTitle($body),
            SourceFormat::MIXCLOUD_API,
        );
    }

    private function pageTitle(string $body): ?string
    {
        $document = HtmlDocumentParser::parseOrEmpty($body);
        $title = TextNormalizer::normalize($document->querySelector('title')?->textContent ?? '');

        return '' === $title ? null : $title;
    }
}
```

- [ ] **Step 4: Hand the offer to the test-built discovery too**

In `backend/tests/Service/Discovery/BuildsFeedDiscovery.php` add `use App\Service\Discovery\FeedOffer\MixcloudProfileFeed;` and extend the offers list:

```php
            [
                new WordPressRestProbe($fetcher),
                new SoundCloudProfileFeed(),
                new YouTubeVideoChannelFeed(),
                new MixcloudProfileFeed(),
            ],
```

- [ ] **Step 5: Run the discovery suite**

Run: `cd backend && php bin/phpunit tests/Service/Discovery`
Expected: PASS.

- [ ] **Step 6: Lint and commit**

Run: `cd backend && vendor/bin/phpcs src/Service/Discovery/FeedOffer/MixcloudProfileFeed.php tests/Service/Discovery && composer stan`
Expected: no findings.

```bash
git add backend/src/Service/Discovery/FeedOffer/MixcloudProfileFeed.php \
  backend/tests/Service/Discovery/FeedOffer/MixcloudProfileFeedTest.php \
  backend/tests/Service/Discovery/FeedOffer/FeedOffersAreCollectedTest.php \
  backend/tests/Service/Discovery/BuildsFeedDiscovery.php
git commit -m "feat(#1932): offer a Mixcloud profile's shows as a feed"
```

---

### Task 4: One verbatim path for subscribe and preview

**Design of the preview seam.** `FeedBodyParser::parse(Feed $feed, string $body)` reads two things from the row: its format (for the locator) and, in `ScrapedBodyParser` only, its URL (to anchor relative links). The preview has no row. So the strategies' contract changes from `parse(string $body, Feed $feed)` to `parse(string $body, string $feedUrl)`, and `FeedBodyParser` gains `parseAs(string $format, string $body, string $feedUrl)`; `parse(Feed, body)` becomes `parseAs($feed->getSourceFormat(), $body, $feed->getUrl())`. The preview then calls `parseAs($format ?? SourceFormat::XML, $body, $response->finalUrl)` for **every** format: `scraped` still runs the extractor, now through `ScrapedBodyParser` (same call, same URL, same `HtmlExtractionException` message it keeps), and an unknown format (an old client's `rss`) takes the same xml fallback a refresh takes. This drops three collaborators from `FeedPreviewService` and its hand-written `match`. Nothing depends on `Service/Preview`, so `Preview → Refresh` creates no module cycle.

**Files:**
- Modify: `backend/src/Service/Refresh/FeedBodyParser/FeedBodyParserInterface.php`, `XmlBodyParser.php`, `ScrapedBodyParser.php`, `WpJsonBodyParser.php`, `MixcloudBodyParser.php`
- Modify: `backend/src/Service/Refresh/FeedBodyParser.php`
- Modify: `backend/src/Service/Preview/FeedPreviewService.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`, `SubscriptionCreator.php` (docblock)
- Modify: `backend/tests/Service/Preview/FeedPreviewServiceTest.php`
- Modify: `backend/tests/Service/Subscription/SubscriptionServiceTest.php`
- Modify: `backend/tests/Service/Refresh/FeedBodyParserTest.php`

**Interfaces:**
- Consumes: `SourceFormat::isVerbatim()`, `SourceFormat::MIXCLOUD_API`, fixture `mixcloud/cloudcasts.json` (Task 1).
- Produces: `FeedBodyParserInterface::parse(string $body, string $feedUrl): ParsedFeedModel`; `FeedBodyParser::parseAs(string $format, string $body, string $feedUrl): ParsedFeedModel`; `FeedPreviewService::__construct(FeedFetcherInterface $fetcher, FeedBodyParser $bodyParser, ScrapeFallbackPolicy $scrapeFallbackPolicy)`.

- [ ] **Step 1: Write the failing subscribe tests**

In `backend/tests/Service/Subscription/SubscriptionServiceTest.php` add `use PHPUnit\Framework\Attributes\DataProvider;`.

Keep `testScrapedSubscribeDoesNotSeedTheCandidateTitle` as it is: only published sources seed the candidate title (#787), a scrape does not.

Append:

```php
    /** @return iterable<string, array{string, string}> */
    public static function publishedSources(): iterable
    {
        yield 'a WordPress REST endpoint' => [SourceFormat::WP_JSON, 'https://wp.example/wp-json/wp/v2/posts'];
        yield 'a Mixcloud cloudcasts list' => [
            SourceFormat::MIXCLOUD_API,
            'https://api.mixcloud.com/johndigweed/cloudcasts/',
        ];
    }

    /** Neither is a page scrape, so a user with scraping off (the default) subscribes them too. */
    #[DataProvider('publishedSources')]
    public function testAPublishedSourceIsStoredVerbatimWithItsTitleAndNoDiscovery(string $format, string $url): void
    {
        $user = $this->factory()->create($format . '-verbatim@example.com');

        $outcome = $this->service($this->discoveryNeverAsked())
            ->subscribe($user, $url, $format, [], 'Candidate Title');

        self::assertNotNull($outcome->subscription);
        $feed = $outcome->subscription->getFeed();
        self::assertSame($url, $feed->getUrl());
        self::assertSame($format, $feed->getSourceFormat());
        self::assertSame('Candidate Title', $feed->getTitle());
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php`
Expected: FAIL — the Mixcloud case of `testAPublishedSourceIsStoredVerbatimWithItsTitleAndNoDiscovery` (`Discovery must not run for a URL a route took.`).

- [ ] **Step 3: Merge the verbatim branches**

In `backend/src/Service/Subscription/SubscriptionService.php` replace the two `if (SourceFormat::SCRAPED …)` / `if (SourceFormat::WP_JSON …)` blocks at the top of `subscribe()` with:

```php
        if (SourceFormat::isVerbatim($format)) {
            return $this->subscribeVerbatim($user, $url, $format, $tags, $initialTitle);
        }
```

and replace `subscribeVerbatim()` (docblock included) with:

```php
    /**
     * A candidate whose URL is the source itself is stored as offered: discovery run again could fail this time and
     * block the subscribe it just offered.
     *
     * @param value-of<SourceFormat::VERBATIM> $format
     * @param list<Tag>                        $tags
     */
    private function subscribeVerbatim(
        User $user,
        string $url,
        string $format,
        array $tags,
        ?string $initialTitle,
    ): SubscribeOutcomeModel {
        if (SourceFormat::SCRAPED === $format) {
            // This shortcut skips discovery's scrape gate, so a hand-made request must meet the preference here.
            $this->scrapeFallbackPolicy->assertMayScrape($user);

            return SubscribeOutcomeModel::subscribed($this->creator->create($user, $url, $format, $tags, null));
        }

        return SubscribeOutcomeModel::subscribed($this->creator->create($user, $url, $format, $tags, $initialTitle));
    }
```

In `backend/src/Service/Subscription/SubscriptionCreator.php` change the `create()` docblock's format line to:

```php
     * @param 'xml'|'scraped'|'wp-json'|'mixcloud-api'|'bluesky-api'|'mastodon-api' $sourceFormat
     * @param list<Tag>                                                            $tags
```

- [ ] **Step 4: Run the subscribe tests**

Run: `cd backend && php bin/phpunit tests/Service/Subscription/SubscriptionServiceTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing preview tests**

In `backend/tests/Service/Preview/FeedPreviewServiceTest.php`:
- remove the imports of `FeedParser`, `ItemImageExtractor`, `WordPressJsonParser`, `HtmlItemExtractor`; add `use App\Service\Refresh\FeedBodyParser;` and `use App\Tests\Support\ReadsFixtures;`; add `use ReadsFixtures;` beside `use ScrapedFixtures;`;
- replace `service()` with:

```php
    private function service(StubFeedFetcher $fetcher): FeedPreviewService
    {
        $bodyParser = self::getContainer()->get(FeedBodyParser::class);
        self::assertInstanceOf(FeedBodyParser::class, $bodyParser);

        return new FeedPreviewService($fetcher, $bodyParser, new ScrapeFallbackPolicy());
    }
```

- append:

```php
    public function testPreviewsAMixcloudCandidateWithoutItsExclusiveShows(): void
    {
        $fetcher = $this->fetcherWithBody($this->fixture('mixcloud/cloudcasts.json'));

        $preview = $this->service($fetcher)->preview($this->user(), self::URL, SourceFormat::MIXCLOUD_API);

        self::assertSame('John Digweed', $preview->title);
        self::assertSame(2, $preview->itemCount);
        self::assertSame('title-only', $preview->content);
        self::assertTrue($preview->hasImages);
    }

    public function testAMixcloudBodyThatIsNoListIsNotAReadableFeed(): void
    {
        $fetcher = $this->fetcherWithBody('{"error":{"type":"NotFound"}}');

        $this->expectException(FeedPreviewException::class);
        $this->expectExceptionMessage('That address is not a readable feed.');
        $this->service($fetcher)->preview($this->user(), self::URL, SourceFormat::MIXCLOUD_API);
    }

    /** A format this build has no parser for reads as a feed document, as a refresh reads it. */
    public function testAnUnknownFormatPreviewsAsAFeedDocument(): void
    {
        $fetcher = $this->fetcherWithBody($this->rss(''));

        self::assertSame('Example Feed', $this->service($fetcher)->preview($this->user(), self::URL, 'rss')->title);
    }
```

In `backend/tests/Service/Refresh/FeedBodyParserTest.php` append (and add `use App\Enum\SourceFormat;`, `use App\Service\Parser\MixcloudJsonParser;`, `use App\Service\Refresh\FeedBodyParser\MixcloudBodyParser;`):

```php
    public function testParsesABodyInTheFormatItIsGivenWithoutAFeedRow(): void
    {
        $parser = new FeedBodyParser(new ServiceLocator([
            SourceFormat::MIXCLOUD_API => static fn (): MixcloudBodyParser => new MixcloudBodyParser(
                new MixcloudJsonParser(),
            ),
        ]));

        $parsed = $parser->parseAs(SourceFormat::MIXCLOUD_API, '{"data":[]}', 'https://api.mixcloud.com/dj/cloudcasts/');

        self::assertSame([], $parsed->entries);
    }
```

- [ ] **Step 6: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Preview/FeedPreviewServiceTest.php tests/Service/Refresh/FeedBodyParserTest.php`
Expected: FAIL — `FeedPreviewService::__construct()` argument type errors, and `Call to undefined method …FeedBodyParser::parseAs()`.

- [ ] **Step 7: Change the strategy contract**

`backend/src/Service/Refresh/FeedBodyParser/FeedBodyParserInterface.php` — replace the `use App\Entity\Feed;` import (delete it) and the `parse` declaration:

```php
    /**
     * @param string $feedUrl the address the body came from; it anchors relative links
     *
     * @throws FeedParseException when the body cannot be read in this format
     */
    public function parse(string $body, string $feedUrl): ParsedFeedModel;
```

In `XmlBodyParser.php`, `WpJsonBodyParser.php` and `MixcloudBodyParser.php`: delete `use App\Entity\Feed;` and change the signature to `public function parse(string $body, string $feedUrl): ParsedFeedModel` (bodies unchanged).

`ScrapedBodyParser.php`: delete `use App\Entity\Feed;` and replace `parse()` with:

```php
    public function parse(string $body, string $feedUrl): ParsedFeedModel
    {
        return $this->extractor->extract($body, $feedUrl);
    }
```

`backend/src/Service/Refresh/FeedBodyParser.php` — replace `parse()` with:

```php
    /** @throws FeedParseException */
    public function parse(Feed $feed, string $body): ParsedFeedModel
    {
        return $this->parseAs($feed->getSourceFormat(), $body, $feed->getUrl());
    }

    /**
     * The body read as `$format`, for a caller with no feed row yet (the subscribe preview).
     *
     * @throws FeedParseException
     */
    public function parseAs(string $format, string $body, string $feedUrl): ParsedFeedModel
    {
        if ($this->parsers->has($format)) {
            return $this->resolve($format)->parse($body, $feedUrl);
        }

        // A row whose format has no parser here (a newer deployment's, or a removed strategy's) is read as xml,
        // which is what every row meant before the seam existed.
        try {
            return $this->resolve(SourceFormat::XML)->parse($body, $feedUrl);
        } catch (FeedParseException $exception) {
            throw new FeedParseException(
                sprintf('No parser for source format "%s"; tried xml: %s', $format, $exception->getMessage()),
                0,
                $exception,
            );
        }
    }
```

- [ ] **Step 8: Preview through the locator**

In `backend/src/Service/Preview/FeedPreviewService.php`:
- imports: delete `FeedParser`, `WordPressJsonParser`, `HtmlItemExtractor`; add `use App\Service\Refresh\FeedBodyParser;`;
- constructor:

```php
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedBodyParser $bodyParser,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
    ) {
    }
```

- replace the `try { … $feed = match ($format) { … }; }` head with:

```php
        try {
            // One catch covers every format: HtmlExtractionException is a FeedParseException.
            $feed = $this->bodyParser->parseAs($format ?? SourceFormat::XML, $body, $response->finalUrl);
        } catch (FeedParseException $exception) {
```

(the catch body stays as it is).

- [ ] **Step 9: Run the affected suites**

Run: `cd backend && php bin/phpunit tests/Service/Preview tests/Service/Refresh tests/Service/Subscription tests/Controller/Api/FeedPreviewControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS.

- [ ] **Step 10: Clear the dev container's DI cache**

The preview service's constructor changed and two services were added.
Run: `docker compose exec php bin/console cache:clear && docker compose restart worker`
Expected: `[OK] Cache for the "dev" environment (debug=true) was successfully cleared.`

- [ ] **Step 11: Gates and commit**

Run: `cd backend && composer check && composer md`
Expected: green. phptramp: `feedUrl` crosses `FeedPreviewService → FeedBodyParser::parseAs → *BodyParser::parse` (2 hops, read by `ScrapedBodyParser`), under the warn threshold of 3.

```bash
git add backend/src/Service/Refresh backend/src/Service/Preview/FeedPreviewService.php \
  backend/src/Service/Subscription/SubscriptionService.php backend/src/Service/Subscription/SubscriptionCreator.php \
  backend/tests/Service/Preview/FeedPreviewServiceTest.php backend/tests/Service/Subscription/SubscriptionServiceTest.php \
  backend/tests/Service/Refresh/FeedBodyParserTest.php
git commit -m "refactor(#1932): subscribe and preview every verbatim format through one path"
```

---

### Task 5: The Mixcloud widget protocol

**Files:**
- Create: `frontend/src/app/reader/media/mixcloud-widget-protocol.ts`
- Create: `frontend/src/app/reader/media/mixcloud-widget-protocol.spec.ts`

**Interfaces:**
- Produces:
  - `MIXCLOUD_WIDGET_ORIGIN = 'https://player-widget.mixcloud.com'`, `MIXCLOUD_PLAYER_URL = 'https://player-widget.mixcloud.com/widget/iframe/'`
  - `GET_API: { type: 'getApi' }`
  - `methodCall(methodName: string, methodArguments?: readonly unknown[]): MixcloudMethodCall` — ids count up from 1 per page
  - `mixcloudMessageOf(data: unknown): MixcloudMessage | null` (`{ type: string; data: unknown }`)
  - `mixcloudEventOf(message: MixcloudMessage): MixcloudEvent | null` (`{ eventName: string; args: readonly unknown[] }`)
  - `postToMixcloudFrame(frame: HTMLIFrameElement, message: object): void`
- The origin check lives where it does for SoundCloud and YouTube: `FramePlayback.onMessage` drops every message whose `event.origin` is not the playback's origin, so `mixcloudMessageOf` reads shape only.

- [ ] **Step 1: Write the failing spec**

Create `frontend/src/app/reader/media/mixcloud-widget-protocol.spec.ts`:

```ts
import { frameWithWindow } from '../../../testing/frame-playback-testing';
import {
  GET_API,
  methodCall,
  MIXCLOUD_WIDGET_ORIGIN,
  mixcloudEventOf,
  mixcloudMessageOf,
  postToMixcloudFrame,
} from './mixcloud-widget-protocol';

describe('methodCall', () => {
  it('names the method and its arguments under a method id', () => {
    const call = methodCall('seek', [1800]);

    expect(call).toEqual({
      type: 'method',
      data: { methodId: expect.any(Number), methodName: 'seek', args: [1800] },
    });
  });

  it('calls without arguments by default', () => {
    expect(methodCall('play').data.args).toEqual([]);
  });

  it('gives every call the next id', () => {
    const first = methodCall('play');
    const second = methodCall('pause');

    expect(second.data.methodId).toBe(first.data.methodId + 1);
  });
});

describe('mixcloudMessageOf', () => {
  it('reads a player widget message', () => {
    expect(
      mixcloudMessageOf('{"mixcloud":"playerWidget","type":"api","data":{"methods":["play"]}}'),
    ).toEqual({ type: 'api', data: { methods: ['play'] } });
  });

  it('reads a message without data', () => {
    expect(mixcloudMessageOf('{"mixcloud":"playerWidget","type":"ready"}')).toEqual({
      type: 'ready',
      data: undefined,
    });
  });

  it.each([
    ['a message that is no string', { mixcloud: 'playerWidget', type: 'ready' }],
    ['text that is no JSON', '{not json'],
    ['a JSON null', 'null'],
    ['a JSON list', '[1]'],
    ['our own outgoing message, which has no mixcloud key', '{"type":"getApi"}'],
    ['another widget', '{"mixcloud":"footerWidget","type":"ready"}'],
    ['a type that is no string', '{"mixcloud":"playerWidget","type":1}'],
  ])('yields null for %s', (_shape, data) => {
    expect(mixcloudMessageOf(data)).toBeNull();
  });
});

describe('mixcloudEventOf', () => {
  it('reads an event and its arguments', () => {
    expect(
      mixcloudEventOf({ type: 'event', data: { eventName: 'progress', args: [42.5, 3600] } }),
    ).toEqual({ eventName: 'progress', args: [42.5, 3600] });
  });

  it('reads an event without arguments', () => {
    expect(mixcloudEventOf({ type: 'event', data: { eventName: 'play' } })).toEqual({
      eventName: 'play',
      args: [],
    });
  });

  it.each([
    ['another message type', { type: 'methodResponse', data: { methodId: 1, value: true } }],
    ['an event without a name', { type: 'event', data: { args: [] } }],
    ['arguments that are no list', { type: 'event', data: { eventName: 'progress', args: 3 } }],
    ['data that is no record', { type: 'event', data: 'play' }],
  ])('yields null for %s', (_shape, message) => {
    expect(mixcloudEventOf(message)).toBeNull();
  });
});

describe('postToMixcloudFrame', () => {
  it('posts the message as JSON to the widget origin', () => {
    const { frame, posted } = frameWithWindow();

    postToMixcloudFrame(frame, GET_API);

    expect(posted).toEqual([{ message: { type: 'getApi' }, origin: MIXCLOUD_WIDGET_ORIGIN }]);
  });

  it('survives a frame that has no window yet', () => {
    expect(() => postToMixcloudFrame(document.createElement('iframe'), GET_API)).not.toThrow();
  });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npm test -- src/app/reader/media/mixcloud-widget-protocol.spec.ts`
Expected: FAIL — `Cannot find module './mixcloud-widget-protocol'`.

- [ ] **Step 3: Write the protocol**

Create `frontend/src/app/reader/media/mixcloud-widget-protocol.ts`:

```ts
export const MIXCLOUD_WIDGET_ORIGIN = 'https://player-widget.mixcloud.com';
export const MIXCLOUD_PLAYER_URL = `${MIXCLOUD_WIDGET_ORIGIN}/widget/iframe/`;

const PLAYER_WIDGET = 'playerWidget';

export interface MixcloudMessage {
  readonly type: string;
  readonly data: unknown;
}

export interface MixcloudEvent {
  readonly eventName: string;
  readonly args: readonly unknown[];
}

export interface MixcloudMethodCall {
  readonly type: 'method';
  readonly data: {
    readonly methodId: number;
    readonly methodName: string;
    readonly args: readonly unknown[];
  };
}

export const GET_API = { type: 'getApi' } as const;

let lastMethodId = 0;

export function methodCall(
  methodName: string,
  methodArguments: readonly unknown[] = [],
): MixcloudMethodCall {
  lastMethodId += 1;
  return { type: 'method', data: { methodId: lastMethodId, methodName, args: methodArguments } };
}

/** A message the player widget posts; ours carry no `mixcloud` key, so they never read as one. */
export function mixcloudMessageOf(data: unknown): MixcloudMessage | null {
  const message = parsedRecordOf(data);
  const type = message?.['type'];
  if (message?.['mixcloud'] !== PLAYER_WIDGET || typeof type !== 'string') return null;
  return { type, data: message['data'] };
}

export function mixcloudEventOf(message: MixcloudMessage): MixcloudEvent | null {
  if (message.type !== 'event') return null;
  const event = recordOf(message.data);
  const eventName = event?.['eventName'];
  const args: unknown = event?.['args'] ?? [];
  if (typeof eventName !== 'string' || !Array.isArray(args)) return null;
  return { eventName, args: args as unknown[] };
}

export function postToMixcloudFrame(frame: HTMLIFrameElement, message: object): void {
  frame.contentWindow?.postMessage(JSON.stringify(message), MIXCLOUD_WIDGET_ORIGIN);
}

function parsedRecordOf(data: unknown): Record<string, unknown> | null {
  if (typeof data !== 'string') return null;
  try {
    return recordOf(JSON.parse(data));
  } catch {
    return null;
  }
}

function recordOf(value: unknown): Record<string, unknown> | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null;
  return value as Record<string, unknown>;
}
```

- [ ] **Step 4: Run the spec**

Run: `docker compose exec -T frontend npm test -- src/app/reader/media/mixcloud-widget-protocol.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/media/mixcloud-widget-protocol.ts frontend/src/app/reader/media/mixcloud-widget-protocol.spec.ts
git commit -m "feat(#1932): the Mixcloud widget protocol"
```

---

### Task 6: `MixcloudPlayback`

**Files:**
- Create: `frontend/src/app/reader/audio/playback/mixcloud-playback.ts`
- Create: `frontend/src/app/reader/audio/playback/mixcloud-playback.spec.ts`
- Modify: `frontend/src/testing/embed-media-testing.ts`

**Interfaces:**
- Consumes: Task 5's exports; `FramePlayback` (`frame-playback.ts`): abstract `frameUrlFor`, `isReadyMessage`, `readMessage`, `start(seconds | null)`, `stop`, `seekTo`; hooks `promptProvider` (on frame load and on every play before ready), `post`, `reportTime/Duration/Playing/Paused/Ended/Failure`; `finiteNumberOf` from `youtube-player-protocol.ts`.
- Produces: `class MixcloudPlayback extends FramePlayback` with `constructor(host: HTMLElement)`; testing constants `MIXCLOUD_WIDGET_URL = 'https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fjohndigweed%2Ftransitions-1154%2F'` and `MIXCLOUD_MEDIA` (the latter typed `EmbedMedia`, added in Task 7 once the kind exists).

Behaviour: `getApi` on frame load and again on `ready`; the `api` message makes it ready; `start` → `play`, `stop` → `pause`, `seekTo` → `seek(seconds)`; position and duration only from `progress(position, duration)`; a `pause` between a `start` and the first `progress` after it is the widget's start-up flicker and is ignored (a `stop` ends that window, so the listener's own pause is never swallowed); a start position is sought on the first `progress` after `start`, and that progress's stale position is not reported.

- [ ] **Step 1: Add the test constant**

In `frontend/src/testing/embed-media-testing.ts` add after `WIDGET_URL`:

```ts
export const MIXCLOUD_WIDGET_URL =
  'https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fjohndigweed%2Ftransitions-1154%2F';
```

- [ ] **Step 2: Write the failing spec**

Create `frontend/src/app/reader/audio/playback/mixcloud-playback.spec.ts`:

```ts
import { MIXCLOUD_WIDGET_URL } from '../../../../testing/embed-media-testing';
import {
  deliverMessage,
  messagesOf,
  playbackFixture,
  PlaybackFixture,
  Posted,
} from '../../../../testing/frame-playback-testing';
import { MIXCLOUD_WIDGET_ORIGIN } from '../../media/mixcloud-widget-protocol';
import { MixcloudPlayback } from './mixcloud-playback';

const API = {
  methods: ['play', 'pause', 'seek'],
  events: ['progress', 'play', 'pause', 'ended', 'error', 'buffering'],
};

type Fixture = PlaybackFixture<MixcloudPlayback>;

function widgetMessage(type: string, data?: unknown): string {
  return JSON.stringify({ mixcloud: 'playerWidget', type, data });
}

function fromWidget(frame: HTMLIFrameElement, type: string, data?: unknown): void {
  deliverMessage(frame, widgetMessage(type, data), MIXCLOUD_WIDGET_ORIGIN);
}

function widgetEvent(frame: HTMLIFrameElement, eventName: string, args: unknown[] = []): void {
  fromWidget(frame, 'event', { eventName, args });
}

/** What was posted: `getApi`, or a method by name and arguments, since method ids count across tests. */
function sent(posted: Posted[]): unknown[] {
  return messagesOf(posted).map((message) => {
    const { type, data } = message as {
      type: string;
      data?: { methodName: string; args: unknown[] };
    };
    return type === 'method' ? [data?.methodName, data?.args] : type;
  });
}

function playbackWithSource(): Fixture {
  return playbackFixture((host) => new MixcloudPlayback(host), MIXCLOUD_WIDGET_URL);
}

function loadedPlayback(): Fixture {
  return playbackWithSource().loaded();
}

function readyPlayback(): Fixture {
  return loadedPlayback().ready(widgetMessage('api', API));
}

async function startedPlayback(): Promise<Fixture> {
  const fixture = readyPlayback();
  await fixture.playback.play();
  return fixture;
}

describe('MixcloudPlayback', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  describe('frame', () => {
    it('loads the widget url as it is', () => {
      const { playback, frame } = playbackWithSource();

      expect(frame.getAttribute('src')).toBe(MIXCLOUD_WIDGET_URL);
      expect(frame.getAttribute('title')).toBe('Mixcloud');
      playback.dispose();
    });

    it('asks for the API once the frame has loaded', () => {
      const { playback, frame, posted } = playbackWithSource();

      expect(posted).toEqual([]);
      frame.dispatchEvent(new Event('load'));

      expect(posted).toEqual([{ message: { type: 'getApi' }, origin: MIXCLOUD_WIDGET_ORIGIN }]);
      playback.dispose();
    });

    it('asks again when the widget announces it is ready', () => {
      const { playback, frame, posted } = loadedPlayback();

      fromWidget(frame, 'ready');

      expect(sent(posted)).toEqual(['getApi', 'getApi']);
      playback.dispose();
    });
  });

  describe('play', () => {
    it('plays once the API arrives when asked before', async () => {
      const { playback, frame, events, posted } = loadedPlayback();

      await playback.play();
      expect(events).toEqual(['play']);
      expect(sent(posted)).toEqual(['getApi', 'getApi']);

      fromWidget(frame, 'api', API);

      expect(sent(posted)).toEqual(['getApi', 'getApi', ['play', []]]);
      playback.dispose();
    });

    it('plays at once when the API has arrived', async () => {
      const { playback, posted } = await startedPlayback();

      expect(sent(posted)).toEqual(['getApi', ['play', []]]);
      playback.dispose();
    });

    it('becomes ready on the API message only', async () => {
      const { playback, frame, posted } = loadedPlayback();
      await playback.play();

      widgetEvent(frame, 'progress', [1, 3600]);
      fromWidget(frame, 'methodResponse', { methodId: 1, value: true });

      expect(sent(posted)).toEqual(['getApi', 'getApi']);
      playback.dispose();
    });

    it('emits play when the widget starts on its own', () => {
      const { playback, frame, events } = readyPlayback();

      widgetEvent(frame, 'play');
      widgetEvent(frame, 'play');

      expect(events).toEqual(['play']);
      playback.dispose();
    });
  });

  describe('starting up', () => {
    it("ignores the widget's pause between a start and its first progress", async () => {
      const { playback, frame, events } = await startedPlayback();

      widgetEvent(frame, 'play');
      widgetEvent(frame, 'pause');
      widgetEvent(frame, 'play');

      expect(events).toEqual(['play']);
      playback.dispose();
    });

    it('reports a pause once the show has made progress', async () => {
      const { playback, frame, events } = await startedPlayback();
      widgetEvent(frame, 'play');
      widgetEvent(frame, 'progress', [0.5, 3600]);

      widgetEvent(frame, 'pause');

      expect(events).toEqual(['play', 'loadedmetadata', 'durationchange', 'timeupdate', 'pause']);
      playback.dispose();
    });

    it("reports the listener's own pause even before the first progress", async () => {
      const { playback, frame, events, posted } = await startedPlayback();

      playback.pause();
      widgetEvent(frame, 'pause');

      expect(sent(posted)).toEqual(['getApi', ['play', []], ['pause', []]]);
      expect(events).toEqual(['play', 'pause']);
      playback.dispose();
    });
  });

  describe('seeking', () => {
    it('seeks to a restored position on the first progress after the start, and only once', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 1800;
      await playback.play();
      expect(sent(posted)).toEqual(['getApi', ['play', []]]);

      widgetEvent(frame, 'play');
      widgetEvent(frame, 'progress', [0.4, 7093]);
      widgetEvent(frame, 'progress', [1800.2, 7093]);

      expect(sent(posted)).toEqual(['getApi', ['play', []], ['seek', [1800]]]);
      expect(playback.currentTime).toBe(1800.2);
      playback.dispose();
    });

    it('keeps the restored position while the seek is under way', async () => {
      const { playback, frame } = readyPlayback();
      playback.currentTime = 1800;
      await playback.play();

      widgetEvent(frame, 'progress', [0.4, 7093]);

      expect(playback.currentTime).toBe(1800);
      expect(playback.duration).toBe(7093);
      playback.dispose();
    });

    it('starts from the beginning without a seek', async () => {
      const { playback, frame, posted } = readyPlayback();
      playback.currentTime = 0;
      await playback.play();

      widgetEvent(frame, 'progress', [0.4, 7093]);

      expect(sent(posted)).toEqual(['getApi', ['play', []]]);
      playback.dispose();
    });

    it('seeks in seconds once the show plays', () => {
      const { playback, frame, posted } = readyPlayback();
      widgetEvent(frame, 'play');

      playback.currentTime = 300;

      expect(sent(posted)).toEqual(['getApi', ['seek', [300]]]);
      playback.dispose();
    });

    it("reads the position from progress, never from the seek's answer", () => {
      const { playback, frame, events } = readyPlayback();

      fromWidget(frame, 'methodResponse', { methodId: 1, value: true });
      expect(events).toEqual([]);

      widgetEvent(frame, 'progress', [42.5, 3600]);

      expect(playback.currentTime).toBe(42.5);
      expect(playback.duration).toBe(3600);
      expect(playback.readyState).toBe(HTMLMediaElement.HAVE_METADATA);
      expect(events).toEqual(['loadedmetadata', 'durationchange', 'timeupdate']);
      playback.dispose();
    });
  });

  describe('ending and failing', () => {
    it('emits pause, then ended when the show ends', () => {
      const { playback, frame, events } = readyPlayback();
      widgetEvent(frame, 'play');

      widgetEvent(frame, 'ended');

      expect(events).toEqual(['play', 'pause', 'ended']);
      playback.dispose();
    });

    it('marks the element dead on a widget error', () => {
      const { playback, frame, events } = readyPlayback();

      widgetEvent(frame, 'error');

      expect(playback.error?.code).toBe(4);
      expect(events).toEqual(['error']);
      playback.dispose();
    });
  });

  describe('playback rate', () => {
    it('sends no rate, the widget has none', () => {
      const { playback, posted } = readyPlayback();

      playback.playbackRate = 2;

      expect(sent(posted)).toEqual(['getApi']);
      expect(playback.playbackRate).toBe(2);
      playback.dispose();
    });
  });

  describe('unwanted messages', () => {
    it('accepts nothing from the mixcloud.com site itself', () => {
      const { playback, frame, events, posted } = loadedPlayback();

      deliverMessage(frame, widgetMessage('api', API), 'https://www.mixcloud.com');
      deliverMessage(
        frame,
        widgetMessage('event', { eventName: 'error', args: [] }),
        'https://www.mixcloud.com',
      );

      expect(sent(posted)).toEqual(['getApi']);
      expect(events).toEqual([]);
      playback.dispose();
    });

    it('ignores what is not a widget message', () => {
      const { playback, frame, events } = readyPlayback();

      deliverMessage(frame, { mixcloud: 'playerWidget', type: 'event' }, MIXCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, '{not json', MIXCLOUD_WIDGET_ORIGIN);
      deliverMessage(frame, JSON.stringify({ type: 'event' }), MIXCLOUD_WIDGET_ORIGIN);
      widgetEvent(frame, 'progress', ['long', 'longer']);
      widgetEvent(frame, 'buffering');
      widgetEvent(frame, 'unknown');

      expect(events).toEqual([]);
      expect(playback.duration).toBeNaN();
      expect(playback.currentTime).toBe(0);
      playback.dispose();
    });
  });
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `docker compose exec -T frontend npm test -- src/app/reader/audio/playback/mixcloud-playback.spec.ts`
Expected: FAIL — `Cannot find module './mixcloud-playback'`.

- [ ] **Step 4: Write the playback**

Create `frontend/src/app/reader/audio/playback/mixcloud-playback.ts`:

```ts
import {
  GET_API,
  methodCall,
  MIXCLOUD_WIDGET_ORIGIN,
  MixcloudEvent,
  mixcloudEventOf,
  mixcloudMessageOf,
} from '../../media/mixcloud-widget-protocol';
import { finiteNumberOf } from '../../media/youtube-player-protocol';
import { FramePlayback } from './frame-playback';

/** The Mixcloud widget API over the hidden frame: `getApi` and `method` out, `{ mixcloud, type, data }` in (#1932). */
export class MixcloudPlayback extends FramePlayback {
  /** The widget plays, pauses and plays again while it starts; only a pause after its first progress is real. */
  private startingUp = false;
  /** A start position waits for the first progress: a seek before it does not land. */
  private seekOnProgress: number | null = null;

  constructor(host: HTMLElement) {
    super(host, MIXCLOUD_WIDGET_ORIGIN, 'Mixcloud');
  }

  protected frameUrlFor(url: string): string {
    return url;
  }

  protected override promptProvider(): void {
    this.post(GET_API);
  }

  protected isReadyMessage(data: unknown): boolean {
    return mixcloudMessageOf(data)?.type === 'api';
  }

  protected readMessage(data: unknown): void {
    const message = mixcloudMessageOf(data);
    if (message?.type === 'ready') return this.promptProvider();
    const event = message === null ? null : mixcloudEventOf(message);
    if (event !== null) this.readEvent(event);
  }

  protected start(seconds: number | null): void {
    this.startingUp = true;
    this.seekOnProgress = seconds !== null && seconds > 0 ? seconds : null;
    this.post(methodCall('play'));
  }

  protected stop(): void {
    this.startingUp = false;
    this.post(methodCall('pause'));
  }

  protected seekTo(seconds: number): void {
    this.post(methodCall('seek', [seconds]));
  }

  private readEvent({ eventName, args }: MixcloudEvent): void {
    switch (eventName) {
      case 'progress':
        return this.readProgress(args);
      case 'play':
        return this.reportPlaying();
      case 'pause':
        return this.readPause();
      case 'ended':
        return this.reportEnded();
      case 'error':
        return this.reportFailure();
      default:
        return;
    }
  }

  private readProgress([position, duration]: readonly unknown[]): void {
    const positionSeconds = finiteNumberOf(position);
    if (positionSeconds === null) return;
    const durationSeconds = finiteNumberOf(duration);
    if (durationSeconds !== null && durationSeconds > 0) this.reportDuration(durationSeconds);
    this.startingUp = false;
    const target = this.seekOnProgress;
    this.seekOnProgress = null;
    if (target !== null) return this.seekTo(target);
    this.reportTime(positionSeconds);
  }

  private readPause(): void {
    if (!this.startingUp) this.reportPaused();
  }
}
```

- [ ] **Step 5: Run the spec**

Run: `docker compose exec -T frontend npm test -- src/app/reader/audio/playback/mixcloud-playback.spec.ts`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/audio/playback/mixcloud-playback.ts \
  frontend/src/app/reader/audio/playback/mixcloud-playback.spec.ts frontend/src/testing/embed-media-testing.ts
git commit -m "feat(#1932): play a Mixcloud show through the widget API"
```

---

### Task 7: Frontend wiring

**Chapters decision.** Mixcloud is out of scope for chapters, and it never reaches a chapter branch: `chapterFrames()` (`article-chapters.ts`) selects only `iframe[src*="enablejsapi=1"]` and `iframe[src^="https://w.soundcloud.com/player/"]`, and `chapteredEmbedOf()` uses `apiPlayerFrame()`, the YouTube API player alone — those two are the only callers of `ArticleChapters.attachToFrame` and of `EmbedDetailsApi.detailsOf`. So `ArticleChapters.embedPlayer`'s "youtube, else SoundCloud" ternary stays unreached by a Mixcloud frame; a spec pins the selector. `EmbedDetailsApi`'s `detailsPath` gets an exhaustive `switch`, so the compiler, not an accident of `soundCloudTrackId` returning null, says a Mixcloud show has no details endpoint.

**Files:**
- Modify: `frontend/src/app/reader/audio/playback/playback-kind.ts` (+ spec)
- Modify: `frontend/src/app/reader/audio/playback/playback-slots.ts` (+ spec)
- Modify: `frontend/src/app/reader/audio/embed-media.ts` (+ spec)
- Modify: `frontend/src/app/reader/media/embed-frame-commands.ts` (+ spec)
- Modify: `frontend/src/app/reader/media/media-embeds.ts` (+ spec)
- Modify: `frontend/src/app/reader/embed-details-api.ts` (+ spec)
- Modify: `frontend/src/app/reader/article/reader-view/article-chapters.spec.ts`
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.ts` (+ spec)
- Modify: `frontend/src/testing/embed-media-testing.ts`

**Interfaces:**
- Consumes: `MixcloudPlayback` (Task 6); `MIXCLOUD_PLAYER_URL`, `methodCall`, `postToMixcloudFrame` (Task 5); the regenerated allow-list (Task 2); `MIXCLOUD_WIDGET_URL` (Task 6).
- Produces: `PlaybackKind = 'file' | 'youtube' | 'soundcloud' | 'mixcloud'`; `isMixcloudPlayerUrl(url: string): boolean`; testing `MIXCLOUD_MEDIA`.

- [ ] **Step 1: Add the media test constant**

In `frontend/src/testing/embed-media-testing.ts` add after `SOUND_MEDIA`:

```ts
export const MIXCLOUD_MEDIA: EmbedMedia = {
  kind: 'mixcloud',
  url: MIXCLOUD_WIDGET_URL,
  posterUrl: null,
};
```

- [ ] **Step 2: Write the failing specs**

`audio/playback/playback-kind.spec.ts` — import `MIXCLOUD_WIDGET_URL` beside the others and add:

```ts
  it('plays the Mixcloud widget url through the Mixcloud backend', () => {
    expect(playbackKindOf(MIXCLOUD_WIDGET_URL)).toBe('mixcloud');
  });
```

`audio/playback/playback-slots.spec.ts` — add `import { MixcloudPlayback } from './mixcloud-playback';`, add `expect(createSlot('mixcloud').element).toBe(playback);` to 'builds a frame slot from the frame factory for the other kinds', and in `describe('providerPlayback')`:

```ts
  it('plays Mixcloud through the Mixcloud adapter', () => {
    const playback = providerPlayback('mixcloud');

    expect(playback).toBeInstanceOf(MixcloudPlayback);
    playback.dispose();
  });
```

`audio/embed-media.spec.ts` — import `MIXCLOUD_MEDIA`, `MIXCLOUD_WIDGET_URL` and `isMixcloudPlayerUrl`; add:

```ts
describe('isMixcloudPlayerUrl', () => {
  it('accepts the allow-listed widget url', () => {
    expect(isMixcloudPlayerUrl(MIXCLOUD_WIDGET_URL)).toBe(true);
  });

  it('rejects a widget url the allow-list does not carry', () => {
    expect(isMixcloudPlayerUrl(`${MIXCLOUD_WIDGET_URL}&autoplay=1`)).toBe(false);
    expect(
      isMixcloudPlayerUrl('https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fjohndigweed%2F'),
    ).toBe(false);
  });

  it('rejects a Mixcloud url that is not the widget', () => {
    expect(isMixcloudPlayerUrl('https://www.mixcloud.com/johndigweed/transitions-1154/')).toBe(
      false,
    );
  });
});
```

and in `describe('embedMediaOfUrl')`:

```ts
  it('reads the Mixcloud widget url as the show, kept as is and without a poster', () => {
    expect(embedMediaOfUrl(MIXCLOUD_WIDGET_URL)).toEqual(MIXCLOUD_MEDIA);
  });
```

plus `['a Mixcloud widget the allow-list does not carry', `${MIXCLOUD_WIDGET_URL}&autoplay=1`],` in its `takes no media from %s` table, and `expect(embedMediaOfFrame(frameWithSource(MIXCLOUD_WIDGET_URL))).toEqual(MIXCLOUD_MEDIA);` in 'reads the media of the frame source'.

`media/embed-frame-commands.spec.ts` — add:

```ts
  it('pauses a Mixcloud widget', () => {
    const { frame, posted } = frameWithWindow();

    pauseEmbedFrame(frame, 'mixcloud');

    expect(posted).toEqual([
      {
        message: {
          type: 'method',
          data: { methodId: expect.any(Number), methodName: 'pause', args: [] },
        },
        origin: 'https://player-widget.mixcloud.com',
      },
    ]);
  });
```

`media/media-embeds.spec.ts` — in `describe('upgradeMediaEmbeds')`:

```ts
  it('replaces a Mixcloud widget link', () => {
    const element = host(
      '<a href="https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fjohndigweed%2Ftransitions-1154%2F">x</a>',
    );

    expect(element.querySelector('.reader-embed--strip iframe')).not.toBeNull();
  });
```

and in `describe('withScriptablePlayer')`:

```ts
  it('lets a Mixcloud widget autoplay, its URL unchanged', () => {
    const widget =
      'https://player-widget.mixcloud.com/widget/iframe/?feed=%2Fjohndigweed%2Ftransitions-1154%2F';
    expect(withScriptablePlayer(widget, 'http://localhost:4200')).toEqual({
      url: widget,
      allow: 'autoplay',
    });
  });
```

`embed-details-api.spec.ts` — import `MIXCLOUD_MEDIA` and add:

```ts
  it('asks nothing for a Mixcloud show, and completes', () => {
    const completed = jest.fn();

    api.detailsOf(MIXCLOUD_MEDIA).subscribe({ complete: completed });

    controller.expectNone(() => true);
    expect(completed).toHaveBeenCalled();
  });
```

`article/reader-view/article-chapters.spec.ts` — import `MIXCLOUD_WIDGET_URL` beside `WIDGET_URL`, and inside `describe('per embed')` after 'finds every API video frame and SoundCloud widget in order':

```ts
    it('leaves a Mixcloud widget out, chapters are not offered for it', () => {
      const host = document.createElement('div');
      host.innerHTML = `<iframe src="${MIXCLOUD_WIDGET_URL}"></iframe>${EMBEDS_BODY}`;

      expect(chapterFrames(host).map((frame) => frame.getAttribute('src'))).toEqual([
        VIDEO_SRC,
        WIDGET_SRC,
      ]);
    });
```

`feeds/add-feed/add-feed-dialog.component.spec.ts` — in 'labels a scraped candidate and subscribes/previews with the scraped format', the subscribe body now carries the candidate title:

```ts
    expect(subTestRequest.request.body).toEqual({
      url: 'https://page.example/',
      format: 'scraped',
      title: 'Page',
    });
```

and add after the WordPress test:

```ts
  it('labels a Mixcloud candidate and subscribes and previews with its format and title', () => {
    const fixture = create();
    fixture.componentInstance.form.setValue({ url: 'https://www.mixcloud.com/johndigweed/' });
    fixture.componentInstance.submit();
    ctrl.expectOne('https://api.test/api/subscriptions').flush({
      candidates: [
        {
          url: 'https://api.mixcloud.com/johndigweed/cloudcasts/',
          title: 'John Digweed | Mixcloud',
          format: 'mixcloud-api',
        },
      ],
    });
    fixture.detectChanges();

    const previewTestRequest = ctrl.expectOne((request) =>
      request.url.endsWith('/api/feeds/preview'),
    );
    expect(previewTestRequest.request.body).toEqual({
      url: 'https://api.mixcloud.com/johndigweed/cloudcasts/',
      format: 'mixcloud-api',
    });
    previewTestRequest.flush({
      feed: { title: 'John Digweed', itemCount: 2, content: 'title-only', hasImages: true, items: [] },
    });
    fixture.detectChanges();

    const card = (fixture.nativeElement as HTMLElement).querySelector('.card')!;
    expect(card.querySelector('.badge.format')?.textContent?.trim()).toBe('Mixcloud');

    (card.querySelector('.subscribe') as HTMLButtonElement).click();
    const subTestRequest = ctrl.expectOne('https://api.test/api/subscriptions');
    expect(subTestRequest.request.body).toEqual({
      url: 'https://api.mixcloud.com/johndigweed/cloudcasts/',
      format: 'mixcloud-api',
      title: 'John Digweed | Mixcloud',
    });
    subTestRequest.flush({ subscription: { id: 9 } }, { status: 201, statusText: 'Created' });
    expect(close).toHaveBeenCalledWith({ id: 9 });
  });
```

- [ ] **Step 3: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/reader/audio src/app/reader/media src/app/reader/embed-details-api.spec.ts src/app/reader/article/reader-view/article-chapters.spec.ts src/app/reader/feeds/add-feed`
Expected: FAIL — the new cases (type errors for `'mixcloud'`, `isMixcloudPlayerUrl` missing, label `Mixcloud-api`, missing `title` in the scraped subscribe). The chapters case already passes; that is the pin.

- [ ] **Step 4: Add the kind and its playback**

`audio/playback/playback-kind.ts`:

```ts
export type PlaybackKind = 'file' | 'youtube' | 'soundcloud' | 'mixcloud';
```

`audio/playback/playback-slots.ts` — add `import { MixcloudPlayback } from './mixcloud-playback';` and replace `providerPlayback`:

```ts
export function providerPlayback(kind: FrameKind): FramePlayback {
  switch (kind) {
    case 'youtube':
      return new YouTubePlayback(hiddenPlaybackHost());
    case 'soundcloud':
      return new SoundCloudPlayback(hiddenPlaybackHost());
    case 'mixcloud':
      return new MixcloudPlayback(hiddenPlaybackHost());
  }
}
```

- [ ] **Step 5: Recognise the widget URL**

`audio/embed-media.ts` — add `import { MIXCLOUD_PLAYER_URL } from '../media/mixcloud-widget-protocol';`, then after `isSoundCloudPlayerUrl`:

```ts
export function isMixcloudPlayerUrl(url: string): boolean {
  return url.startsWith(MIXCLOUD_PLAYER_URL) && isAllowedEmbed(url);
}
```

and in `embedMediaOfUrl`, after the SoundCloud line:

```ts
  if (isMixcloudPlayerUrl(url)) return { kind: 'mixcloud', url, posterUrl: null };
```

- [ ] **Step 6: Pause and autoplay the visible widget**

`media/embed-frame-commands.ts` — add `import { methodCall, postToMixcloudFrame } from './mixcloud-widget-protocol';`, change the docblock to `/** Pauses a visible YouTube API player, SoundCloud widget or Mixcloud widget (#1815). */`, and add the case:

```ts
    case 'mixcloud':
      postToMixcloudFrame(frame, methodCall('pause'));
      return;
```

`media/media-embeds.ts` — add `import { MIXCLOUD_PLAYER_URL } from './mixcloud-widget-protocol';`, and in `withScriptablePlayer` change the docblock's first line to `/** The YouTube iframe API player or a SoundCloud or Mixcloud widget, for a page driving it (#1808). Only` and the SoundCloud line to:

```ts
  if (url.startsWith(SOUNDCLOUD_PLAYER_URL) || url.startsWith(MIXCLOUD_PLAYER_URL)) {
    return { url, allow: 'autoplay' };
  }
```

- [ ] **Step 7: Make the details lookup exhaustive**

`embed-details-api.ts` — replace `detailsPath`:

```ts
function detailsPath(media: EmbedMedia): string | null {
  switch (media.kind) {
    case 'youtube': {
      const videoId = youTubeVideoId(media.url);
      return videoId === null ? null : `/api/youtube/videos/${videoId}`;
    }
    case 'soundcloud': {
      const trackId = soundCloudTrackId(media.url);
      return trackId === null ? null : `/api/soundcloud/tracks/${trackId}`;
    }
    case 'mixcloud':
      return null;
  }
}
```

- [ ] **Step 8: One verbatim list in the add-feed dialog**

`feeds/add-feed/add-feed-dialog.component.ts` — after `FAILURE_TEXT_KEYS` add:

```ts
/** Formats whose candidate URL is the source itself: the backend stores it as offered, title and all. */
const VERBATIM_FORMATS: ReadonlySet<string> = new Set(['scraped', 'wp-json', 'mixcloud-api']);

const FORMAT_LABELS = new Map<string, string>([
  ['rss', 'RSS'],
  ['atom', 'Atom'],
  ['wp-json', 'WordPress'],
  ['mixcloud-api', 'Mixcloud'],
]);
```

replace `formatLabel` (its docblock stays):

```ts
  formatLabel(format: string): string {
    const label = FORMAT_LABELS.get(format);
    if (label) return label;
    return format ? format.charAt(0).toUpperCase() + format.slice(1) : 'Feed';
  }
```

replace `pick`:

```ts
  pick(candidate: FeedCandidate): void {
    const format = this.storedFormat(candidate);
    this.subscribe(candidate.url, format, format ? (candidate.title ?? undefined) : undefined);
  }
```

and replace `storedFormat` together with its docblock:

```ts
  private storedFormat(candidate: FeedCandidate): string | undefined {
    return VERBATIM_FORMATS.has(candidate.format) ? candidate.format : undefined;
  }
```

- [ ] **Step 9: Run the specs and the static checks**

Run: `docker compose exec -T frontend npm test -- src/app/reader/audio src/app/reader/media src/app/reader/embed-details-api.spec.ts src/app/reader/article/reader-view/article-chapters.spec.ts src/app/reader/feeds/add-feed`
Expected: PASS.

Run: `docker compose exec -T frontend npm run check:static`
Expected: green (ESLint, Prettier, Stylelint, three typechecks). Fix Prettier with `docker compose exec -T frontend npx prettier --write <file>` if it reports a file.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader frontend/src/testing/embed-media-testing.ts
git commit -m "feat(#1932): wire Mixcloud into the player, the embeds and the add-feed dialog"
```

---

### Task 8: README and homepage

Docs only, no tests. `CHANGELOG.md` gets no entry: its version sections are generated from the merged PR titles when a release tag is pushed, and `[Unreleased]` is kept empty.

`docs/homepage/` auto-deploys through `.github/workflows/deploy-homepage.yml` on any push to `develop` touching `docs/homepage/**`, so this change goes live when the PR merges.

Decided: the homepage meta descriptions (lines 10, 20, 29, 38) and the JSON-LD `featureList` line "Podcast and YouTube player with playlist and chapters" stay unchanged — they name no audio platform (not even SoundCloud), and adding one would bloat them. Only the "Podcasts &amp; YouTube" card changes.

**Files:**
- Modify: `README.md` (~line 40 and the Listening bullets ~line 86)
- Modify: `docs/homepage/index.html` (~line 298)

- [ ] **Step 1: README — the listening paragraph**

Replace

```markdown
Podcasts and SoundCloud feeds play in a playlist: episode artwork, previous and
next, and a panel to reorder the queue. A podcast can also be added by pasting
its share link from Apple Podcasts.
```

with

```markdown
Podcasts, SoundCloud feeds and Mixcloud shows play in a playlist, seekable like
any episode: episode artwork, previous and next, and a panel to reorder the
queue. A podcast can also be added by pasting its share link from Apple Podcasts.
```

- [ ] **Step 2: README — the Mixcloud bullet**

After the bullet

```markdown
- Paste a SoundCloud profile link into "Add feed" and the reader offers that
  account's RSS feed.
```

add

```markdown
- Paste a Mixcloud profile link (e.g. `https://www.mixcloud.com/johndigweed/`)
  into "Add feed" and the reader subscribes to its shows; they play and seek in
  the playlist. Subscriber-exclusive shows are left out.
```

- [ ] **Step 3: Homepage card**

In `docs/homepage/index.html`, in the "Podcasts &amp; YouTube" card, replace

```html
An Apple Podcasts, SoundCloud or YouTube link pasted into “Add feed” subscribes to the show, channel or playlist;
```

with

```html
An Apple Podcasts, SoundCloud, Mixcloud or YouTube link pasted into “Add feed” subscribes to the show, account, channel or playlist;
```

(curly quotes kept as they are).

- [ ] **Step 4: Check and commit**

Run: `git diff --stat` — expected: `README.md` and `docs/homepage/index.html` only.

```bash
git add README.md docs/homepage/index.html
git commit -m "docs(#1932): mention Mixcloud in the README and on the homepage"
```

---

### Task 9: Gates and live checks

**Files:** none planned; fixes found here go into a `fix(#1932): …` commit.

- [ ] **Step 1: Bring the Docker stack current**

Run: `git log -1 --format=%h && docker compose up -d && docker compose exec php bin/console cache:clear && docker compose restart worker`
Expected: all services up; worker restarted on the new code (a running worker daemon keeps stale code otherwise).

- [ ] **Step 2: Backend gates**

Run, from `backend/`, these can run in parallel:
- `composer check` — expected green (cs, stan, tramp).
- `composer md` — expected no findings in any touched `src` file.
- `php bin/phpunit` — expected OK (SQLite leg).
- `docker compose exec php composer test:parallel` — expected OK (MySQL leg).
- `composer infection:diff` — expected MSI ≥ the `minMsi` of `infection.json5` (80); escaped mutants on changed lines are killed with a test, never by lowering the gate.

- [ ] **Step 3: Frontend gates**

Run: `docker compose exec -T frontend npm run check` then `docker compose exec -T frontend npx ng build`
Expected: both green (`ng build` catches template errors `npm run check` misses).

- [ ] **Step 4: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`
Expected: no new deprecation or error from the Mixcloud code.

- [ ] **Step 5: Run /simplify on the branch diff**

Apply its findings in place; re-run the gates of whichever side changed.

- [ ] **Step 6: Live check in Lars's Brave on http://localhost:4200 (human)**

1. Add feed → `https://www.mixcloud.com/johndigweed/`. Expect one candidate titled "John Digweed | Mixcloud" with the badge "Mixcloud", its preview showing shows with artwork; subscribe.
2. Refresh the new feed (worker tick or the refresh control). Expect the feed renamed "John Digweed", only free shows — the latest exclusive ("…Moshic") absent — with correct titles, artwork, genres and dates.
3. Open a show: the article shows the Mixcloud widget as a strip; pressing play inside the visible widget plays.
4. Press Listen: the visible widget pauses, the player bar plays the show; scrub to 30:00 and to 1:00:00 — audio jumps and the clock follows within a few seconds; pause and resume from the bar.
5. Reload the page and resume the track: it starts at the saved position (the start seek on first progress).
6. Add to playlist a second show; let the first end or press next: the second starts.
7. Subscribe `https://www.mixcloud.com/worldwidefm/` and play a show whose title has an accent (e.g. "…Melé…"): the widget loads that show — this proves the `%25`-encoded key.

- [ ] **Step 7: Live check on the iPhone (human)**

On a production build (`:4300`, not `:4200`), Safari on the iPhone: steps 3–5 above. Expect play, seek and pause to work, and the lock-screen controls to show the show.

- [ ] **Step 8: Commit fixes, if any**

Only when a gate or the live check needed a change: stage exactly the files that change touched (`git add <each file>`, never `-A`: another session may share the checkout) and commit them as `fix(#1932): ` followed by what was wrong, e.g. `fix(#1932): the widget strip is tall enough for Mixcloud's 120 px player`.
