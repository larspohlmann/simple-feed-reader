# Consolidate the feed-hero duplicate-image rule into the backend

Issue: [#592](https://github.com/larspohlmann/simple-feed-reader/issues/592).
Related: #505, #520, #590.

## Problem

The duplicate-hero rule exists twice. `backend/src/Service/Reader/LeadImageSelector.php`
holds it in PHP, `frontend/src/app/reader/feed-hero-image.ts` holds the same
algorithm in TypeScript. #590 had to fix both copies. A native Swift client would
have to write the rule a third time, against the standing `keep-iOS-viable`
constraint.

The two copies do not run on the same input. The rule is applied to three
(candidate, body) pairs:

| # | Candidate | Body | Runs where today |
|---|---|---|---|
| A | extraction `og:image` | extracted body | backend `LeadImageSelector`, emitted as `leadImage` |
| B | feed `entry.imageUrl` | extracted body | frontend, when `leadImage` is null |
| C | feed `entry.imageUrl` | feed body (`contentHtml ?? summary`) | frontend, Original view and failed extraction |

The issue names A and C. B is the reader-mode fallback at
`reader-view.component.ts:241`. All three have to move, or the frontend file
cannot be deleted.

Two facts make the move possible without a new round-trip:

- `EntryController::reader()` already holds the managed `Entry`, so
  `contentHtml`, `summary` and the image triple cost no extra query.
- The client always calls `GET /api/entries/{id}/reader` when it opens an entry,
  in both modes. `reader-view.component.ts` calls `readerMode.reset()` and then
  `runLoad()` unconditionally. So one payload can carry both decisions, and the
  Reader/Original toggle stays instant.

## Goals

- One implementation of the rule, server-side.
- `feed-hero-image.ts` and `feed-hero-image.spec.ts` deleted.
- The Reader/Original toggle still suppresses duplicates, with no new request.
- A native client needs zero hero logic.

## Non-goals

- The list, search and for-you payloads keep their own thumbnail rule
  (`preview-image.ts`). That is a different rule with a different purpose.
- No stored/precomputed hero column. A stored decision would need a migration
  and a backfill, and would freeze the rule into data.
- No API versioning. The `leadImage` field is replaced, not deprecated.

## Payload contract

`GET /api/entries/{id}/reader` carries both heroes on both branches. A hero is
`{url: string, width: int|null, height: int|null}` or `null`.

```
ok:     { status: "ok", url, title, byline, siteName, contentHtml, excerpt,
          readerHero: HeroImage|null, originalHero: HeroImage|null, extractedAt }
failed: { status: "failed", url, reason,
          readerHero: null, originalHero: HeroImage|null }
```

`readerHero` is always `null` on the failed branch: there is no extracted body to
lead. The field is still present, so a client reads the same two fields whatever
the status.

`leadImage: string|null` is removed. `width` and `height` are the dimensions the
feed declared. They are `null` for an extraction `og:image`, because readability
reports no dimensions; `null` means unknown, so the client reserves no space.

## Backend architecture

New units, all in `backend/src/Service/Reader/`:

| Unit | Responsibility |
|---|---|
| `HeroImage` | `final readonly class {string $url, ?int $width, ?int $height}`. One shape for every hero. |
| `HeroImageSelector` | `LeadImageSelector` renamed. `select(?HeroImage $candidate, string $bodyHtml): ?HeroImage`. The one implementation of the lead/repeat rule. |
| `ReaderHeroes` | `final readonly class {?HeroImage $readerHero, ?HeroImage $originalHero}`. |
| `ReaderHeroResolver` | `resolve(Entry $entry, ExtractionResult $result): ReaderHeroes`. Holds the whole policy. |

`HeroImageSelector` keeps its algorithm unchanged: the `^https?://` scheme guard,
`bodyLeadsWithImage()`, `bodyRepeatsImage()` and `imageIdentity()`. Only the
signature changes, from string in / string out to `HeroImage` in / `HeroImage`
out. An accepted candidate is returned as the same instance.

### The policy

```
feedCandidate      = entry.imageUrl === null
                   ? null
                   : HeroImage(entry.imageUrl, entry.imageWidth, entry.imageHeight)
extractedCandidate = result.imageCandidate === null
                   ? null
                   : HeroImage(result.imageCandidate, null, null)
feedBody           = entry.contentHtml ?? entry.summary ?? ''

readerHero         = result.failed
                   ? null
                   : select(extractedCandidate, result.contentHtml)
                     ?? select(feedCandidate, result.contentHtml)

originalHero       = select(feedCandidate, feedBody)
```

The third line is case A, the fourth is case B, the last is case C. The `??`
chain reproduces the current frontend fallback, written once.

`feedBody` mirrors `displayHtml()` in `reader-view.component.ts`: many feeds
populate only one of `contentHtml` and `summary`.

### Changed units

- `ArticleExtractor` drops its `LeadImageSelector` dependency and passes
  readability's `$article->image` through undecided. Readability already
  absolutises it (`fixRelativeURLs: true, originalURL: $finalUrl`).
- `ExtractionResult::$image` is renamed `$imageCandidate`, with a docblock that
  says it is a candidate and not a decision.
- `EntryController::reader()` gains one delegation:
  `$heroes = $this->readerHeroes->resolve($entry, $result);`, then
  `ReaderJson::one($result, $heroes, $this->clock->now())`. The action still only
  reads the request, delegates and returns, so `ThinControllerRule` holds.
- `ReaderJson::one()` takes the `ReaderHeroes` and emits the two fields on both
  branches. Its array shape docblock is updated.

No schema change, so no migration.

## Frontend changes

- Delete `frontend/src/app/reader/feed-hero-image.ts` and
  `feed-hero-image.spec.ts`.
- `models.ts`: add `HeroImageDto {url: string; width: number|null; height: number|null}`.
  On `ReaderArticle`, replace `leadImage: string|null` with
  `readerHero: HeroImageDto|null` and `originalHero: HeroImageDto|null`. Add the
  same two fields to `ReaderFailure`.
- `reader-view.component.ts`: the `leadImage`, `feedHero` and `visibleFeedHero`
  computeds collapse into one:

  ```ts
  readonly hero = computed(() => {
    if (this.heroError()) return null;
    const content = this.loadedContent();
    return this.mode() === 'reader'
      ? (content?.readerHero ?? null)
      : (content?.originalHero ?? null);
  });
  ```

  The heroes must survive a failed extraction, so the component state has to keep
  the failure payload instead of collapsing it to `{status:'failed'}`. The
  transport-error branch of `runLoad()` has no payload to keep, which is the
  accepted regression below.
- `reader-view.component.html`: the `@if (leadImage())` / `@else if
  (visibleFeedHero())` pair becomes one `@if (hero(); as image)` block. The
  extraction hero therefore gains the `width`, `height`, `loading`, `decoding`,
  `referrerpolicy` and `(error)` handling that only the feed hero carried.
- `ReaderCacheService.VERSION` 3 to 4. Without the bump, cached articles come
  back with `leadImage` and no heroes.

## Behaviour changes

Two, both intended:

1. The `^https?://` guard now also covers the feed's `imageUrl`. The TypeScript
   copy had no scheme guard; it trusted the persisted value.
2. `heroError` now hides a broken extraction hero as well. Today only the feed
   hero has an error handler.

One accepted regression:

3. When the reader request itself fails at transport level — a timeout, a 5xx,
   or an offline client — `runLoad()` gets no payload, so there is no hero. The
   article still renders from the feed's own content. Today the client computes
   a hero from the entry it already holds. Every alternative either keeps the
   duplicated rule in TypeScript or puts an HTML parse on every list row, so
   losing the picture on a failed request is the cheaper cost.

## Edge cases

| Case | Behaviour | Changed? |
|---|---|---|
| Feed body unparsable | Selector returns the candidate, hero shows | no |
| `contentHtml` and `summary` both null | `feedBody` is `''`, parses to an empty body, hero shows | no |
| Feed `imageUrl` is not `http(s)` | Rejected | yes, new guard |
| Extraction failed, or `no_url` | `readerHero` null, `originalHero` resolved | no effect |
| Hero URL fails to load | `heroError` hides it, client-side | now covers both heroes |
| Stale cached article | `VERSION` bump evicts it | new |
| Reader request times out or 5xxs | Feed content renders with no hero | yes, see below |

Cost: one extra parse of the feed body per reader request, and one more parse of
the extracted body when case B runs. The endpoint already fetches a remote page
and runs readability twice, so the added parse is not measurable.

Deploy: a browser holding old JS against the new backend shows no hero until it
reloads. This is a single-user application with no API versioning, so that is
accepted.

## Testing

Tests come first, per the repository's TDD rule.

- `HeroImageSelectorTest` — the 22 cases of `LeadImageSelectorTest` carried to the
  new signature, plus one that dimensions survive acceptance.
- `ReaderHeroResolverTest`, new — A wins over B; B runs when A is null or
  suppressed; C is independent of the extraction outcome; `feedBody` falls back
  through `summary` to `''`; a failed result gives `readerHero` null and a live
  `originalHero`; a non-`http(s)` feed URL is rejected; an entry with no image
  gives both heroes null.
- Three cases in `feed-hero-image.spec.ts` have no PHP counterpart — the
  `imgur-embed` false positive, a null `imageUrl`, and null-dimension
  passthrough. They move into the two suites above. The other eleven already
  exist in PHP.
- `ArticleExtractorTest` — its three lead-image tests now assert that the raw
  candidate passes through. The suppression assertions move to
  `ReaderHeroResolverTest`.
- `EntryReaderControllerTest` — `seedEntry()` gains `contentHtml` and
  `setImage(...)`. Assert both hero fields on the ok branch and on the failed
  branch. The unit tests alone would not catch a wiring mistake.
- `reader-view.component.spec.ts` — reader mode renders `readerHero`; the toggle
  to Original renders `originalHero` and makes no second HTTP call; a failed
  extraction renders `originalHero`; `heroError` hides the image; a null hero
  renders no `.lead-image`.

Gates before the pull request: `composer check`, `composer md`,
`php bin/phpunit` natively and in Docker, `composer infection:diff`,
`npm run check`, and PhpStorm inspections on the changed PHP.

## Acceptance

- `feed-hero-image.ts` and its spec no longer exist.
- No hero decision remains in TypeScript.
- The Reader/Original toggle suppresses duplicates with no extra request.
- All gates pass.
