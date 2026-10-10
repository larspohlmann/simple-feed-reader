# #1502 — Link cards for the links a post shares Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every link a post shares gets a structured card (url, title, description, image, site). The card is built at ingest, with a fallback when the post is opened. It reaches clients as `linkCards`, and is shown in the reader and in a new stacked magazine block.

**Architecture:**
- **Backend:**
  - A new `link_card` table holds the cards.
  - A new `Service/LinkCard` module finds the shared links (`SharedLinks`), reads a card from a page (`PageCardReader`), and builds and stores the cards (`PostLinkCards`). It runs after the refresh flush and from `GET /api/entries/{id}`.
  - Bluesky's AppView external embed is stored as a row instead of body HTML.
  - A `LinkCardLoader` batch-attaches the cards to list rows, the way `EntryCategoryLoader` does.
- **Frontend:** `EntryDto.linkCards`, cards under a post in the reader, and a new `entry-link` magazine block.

**Tech Stack:** Symfony 7.4, PHP 8.4 `Dom\HTMLDocument`, Doctrine migrations, PHPUnit 12, Angular 20 signals, Jest.

**Spec:** `docs/superpowers/specs/2026-10-10-1502-post-link-cards-design.md` (approved 2026-10-10). Read it first. It is binding where this plan is silent.

## Global Constraints

- Host-agnostic: no host lists and no class names from Mastodon; the sanitizer strips classes anyway.
- CLAUDE.md Clean Code rules:
  - Role folders: `Support/` static helpers, services in the module root, `Model/`, `Pass/`.
  - `final readonly`. No boolean flag parameters.
  - Comments only where the spec's bar demands one.
  - Every touched `src` file PHPMD-clean. No phptramp chains.
- The controller stays thin. `EntryController::get` gains one delegating call, made after the ownership check.
- No Service-module cycle. `LinkCard` may use `Reader` (`HtmlPageFetcher`), `Html`, `Text`, `Url`, `Fetch` (`HostKey`), `Ingest` (`AtPostUri`) and `Sanitize`. `Bluesky` and `Refresh` may use `LinkCard`.
- Queries live in `src/Repository`.
- Datetimes are naive UTC.
- Tests make no real network calls (`MockHttpClient`, as the existing `HtmlPageFetcher` tests do).
- Numbers from the spec, used verbatim:
  - at most **5** cards per post;
  - at most **10** page fetches per feed refresh;
  - descriptions capped at **300** characters on a word boundary, with `…`;
  - the cache pool `post_link_cards.cache` (filesystem), `default_lifetime: 2592000`.
- Frontend:
  - Tokens only (no hex, no ad-hoc px).
  - Styles in a sibling `.scss`.
  - Prettier at 100 columns.
  - Tests run in the Docker frontend container: `docker compose exec -T frontend npm run check`.
- Backend gates:
  - `composer check`, `composer md`, `composer test:parallel`, `docker compose exec php composer test`, `composer infection:diff`.
  - Apply new migrations to the live Docker DB (`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`).
- Commits: `type(#1502): …`.

---

### Task 1: The `link_card` table and the card model

**Files:**
- Create: `backend/src/Entity/LinkCard.php`.
  - Columns: `entry` (ManyToOne `Entry`, JoinColumn not null, `onDelete: 'CASCADE'`), `position` (smallint), `url` (string 2048), `title` (string 500), `description` (text, nullable), `imageUrl` (string 2048, nullable) and `siteName` (string 255).
  - Unique constraint `(entry_id, position)`, plus an index on `entry_id`.
  - Constructor-only with getters, no setters.
- Create: `backend/src/Entity/LinkCardData.php` or the module's `Model/LinkCardModel.php`.
  - It is the value `{url, title, ?description, ?imageUrl, siteName}`.
  - Where it lives follows `PersistenceKnowsNoServiceRule`: if the entity's constructor or the repository takes it, it lives in `App\Entity`; read docs/architecture.md §8.
- Create: `backend/src/Repository/LinkCardRepository.php`.
  - `replaceFor(Entry $entry, list<card value> $cards): void` deletes the old rows and persists the new ones in order; the caller flushes.
  - `hasAnyFor(Entry $entry): bool`.
  - `findByEntryIds(list<int>): array<int, list<LinkCard>>`, in position order.
- Create: a migration in `backend/migrations/` (next timestamp after `Version20261010180000`).
  - SQL valid on both SQLite and MySQL. Follow the newest existing migration's dialect handling.
- Test: `backend/tests/Repository/LinkCardRepositoryTest.php`: replace keeps order; replace twice leaves only the second set; deleting an entry cascades; `findByEntryIds` groups.

- [ ] Write the failing test, run it (FAIL), implement, run it (PASS).
- [ ] Run the migration on the Docker DB. Run `bin/console doctrine:schema:validate` in the container.
- [ ] Commit `feat(#1502): a table for the link cards a post shares`.

### Task 2: `SharedLinks` and `PageCardReader`

**Files:**
- Create: `backend/src/Service/LinkCard/Support/SharedLinks.php`.
  - `static linked(string $bodyHtml): string` links the bare URLs in text nodes outside `<a>`.
  - `static in(string $bodyHtml): list<string>` returns the shared URLs: deduped, in document order, at most 5.
  - The spec's §2 rule: a link whose visible text without whitespace starts with `http://`/`https://` or with the href's host, optionally ending in `…`, is shared. A link whose paragraph text before it is exactly `RE:` is not.
  - Reuse `Text\Support\LinkedPlainText`'s URL pattern by exposing it as a public constant, or by moving it to one shared home both read; no copy.
- Create: `backend/src/Service/LinkCard/PageCardReader.php`.
  - `read(string $sharedUrl): ?<card value>`. It fetches with `HtmlPageFetcher::fetch`, and lets `PageFetchException` propagate (the caller skips the card).
  - Fields per spec §3. Add a `MetaProperty`-style lookup for `meta[name=…]` if needed, in `Html/Support`.
  - Resolve a relative image against `PageResponseModel::$finalUrl`; look for an existing URL resolver in the Url, Reader or Html Support folders before writing one.
  - Normalize the text fields (collapse whitespace, trim).
- Test: `backend/tests/Service/LinkCard/Support/SharedLinksTest.php`, with rows using the spec's measured Mastodon markup verbatim:
  - hashtag → not shared; mention → not shared; text link `read more` → not shared;
  - span-split URL link → shared; `RE:` link → not shared;
  - a bare URL after `<br />` → linked and shared; trailing `.` or `)` stays outside the link;
  - a duplicate counts once; 6 links give 5.
- Test: `backend/tests/Service/LinkCard/PageCardReaderTest.php` (`MockHttpClient` behind the real `HtmlPageFetcher` wiring, or however its existing tests build it):
  - og only; twitter fallback; `<title>` and meta-description fallback;
  - a relative `og:image`; an `http` image upgraded; a `javascript:` image → null image;
  - no title → null; a 400-character description → capped with `…`;
  - the card url is the shared URL even after a redirect.

- [ ] Write the failing tests, run them (FAIL), implement, run them (PASS).
- [ ] Commit `feat(#1502): find the links a post shares and read a card from each page`.

### Task 3: `PostLinkCards` at ingest and on open

**Files:**
- Create: `backend/src/Service/LinkCard/PostLinkCards.php`.
  - `forCreated(list<Entry> $entries): void`: ingest. Only posts (`isTitleDerived()`) that are not AT posts (`AtPostUri::matches(guid)`), with at most 10 page fetches in total for the call. Posts left over get no marker, so open builds them.
  - `forOpened(int $entryId): void`: the fallback. It returns early unless the entry is such a post, has no cards (`hasAnyFor`) and is not marked.
  - Shared per-entry work, in a `Pass/` object or a private method:
    1. `SharedLinks::linked` on the body. When it changed, store it through `EntrySanitizer`.
    2. `SharedLinks::in`, then `PageCardReader::read` for each. `PageFetchException` or any `\Exception` logs at warning (url, exception class) and skips the card.
    3. `LinkCardRepository::replaceFor`.
    4. Flush.
    5. Mark `entry id → tried` in the cache.
  - An exception escaping the whole pass is logged and swallowed. Copy `PostEnricher`'s pattern, including its rethrow when the EntityManager is closed.
- Modify: `backend/config/packages/cache.yaml`. Add the pool `post_link_cards.cache`, with a one-line comment like its neighbours, and inject it by its pool name.
- Modify: `backend/src/Service/Refresh/FeedOutcomePersister.php`. In `storeFetched`, after the flush and beside `PostEnricher`, call `forCreated($createdEntries)`.
  - The indexer call stays correct. Cards are not indexed, so nothing new is passed to it.
- Modify: `backend/src/Controller/Api/EntryController.php`. In `get()`, call `forOpened($id)` after the ownership-checked row read, then re-read the row, so that the response carries the cards (Task 5 adds them to JSON).
  - An unowned id must 404 before any fetch.
- Test: `backend/tests/Service/LinkCard/PostLinkCardsTest.php` (kernel, `MockHttpClient` serving an og page; count the mock's requests):
  - ingest fills rows and links bare URLs;
  - 12 shared links across 3 posts make exactly 10 fetches;
  - the open fallback fills a leftover post, and a second open fetches nothing;
  - a 404 page gives no card but marks the entry tried;
  - a non-post and an AT post are untouched;
  - a post that already has cards is not fetched on open.
- Test: extend the existing functional test of `FeedOutcomePersister` (or the refresh) so that a post entry gets rows.

- [ ] Write the failing tests, run them (FAIL), implement, run them (PASS). Run `composer test:parallel`.
- [ ] Commit `feat(#1502): build a post's link cards at ingest, and on open for any still missing`.

### Task 4: Bluesky's external embed becomes a row

**Files:**
- Modify: `backend/src/Service/Bluesky/PostEmbedRenderer.php`.
  - `external#view` no longer renders HTML. The `RenderedEmbedModel` carries the card value (uri, title, description, https thumb, host as site) in place of `linkCardUrl`'s HTML.
  - Keep the lead image (thumb).
  - A `recordWithMedia` whose media is external follows the same rule.
- Modify: `backend/src/Service/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php`.
  - It still removes the trailing URL (`TrailingUrl::removedFrom`).
  - It appends no card HTML, and stores the card through `LinkCardRepository::replaceFor($entry, [$card])`.
- Update their tests and the Bluesky fixtures' expectations. The sanitizer test's `link-card` row goes if nothing renders it any more; `figure` class stays allowed.

- [ ] Change the tests first (FAIL), implement, run `php bin/phpunit tests/Service/Bluesky` (PASS).
- [ ] Commit `feat(#1502): a Bluesky link card is stored as a card, not as body HTML`.

### Task 5: `linkCards` in the entry JSON

**Files:**
- Create: `backend/src/Repository/LinkCardLoader.php`. `loadInto(list<EntryListRow>): list<EntryListRow>` uses one `findByEntryIds` query, the way `EntryCategoryLoader` works.
- Modify: `EntryListRowEnrichment` (add `linkCards`), `EntryListRow` and `EntryListRowEnricher` (chain the loader).
- Modify: `backend/src/Http/EntryJson.php`. `listRow` emits `linkCards: list<{url, title, description, imageUrl, siteName}>`.
  - Check whether `imageUrl` in `listRow` goes through a proxy rewrite, and apply the same to the card image.
  - The duplicates' rows get the key too, as `[]` when not loaded.
- Test: the functional tests for `GET /api/entries` and `GET /api/entries/{id}` assert `linkCards`. Assert the list's query count does not grow per row, if such an assertion helper exists.

- [ ] Write the failing tests, run them (FAIL), implement, run them (PASS).
- [ ] Commit `feat(#1502): entries carry their link cards`.

### Task 6: Frontend — reader cards and the magazine `entry-link` block

**Files:**
- Modify: `frontend/src/app/reader/models.ts`. Add `LinkCardDto {url; title; description: string | null; imageUrl: string | null; siteName}` and `EntryDto.linkCards: LinkCardDto[]`. Fix the test builders/fixtures that construct `EntryDto`.
- Reader:
  - Under the body of an entry with `linkCards`, render one `figure.link-card > a[href][target=_blank][rel="noopener noreferrer"]` per card: `img` (when imageUrl), `strong`, `span` (when description), `small`.
  - Put it in the reader-view template, inside `.content`, after the body, so that the #1499 `.link-card` styles apply unchanged.
  - Route the image through the same proxy and recovery as body images, if the reader does that for `imageUrl`.
- Magazine:
  - Add `'link'` to `ENTRY_KINDS`, with `BLOCK_HEIGHT.link = BLOCK_HEIGHT.hero`.
  - In `magazine-planner.ts`, an entry with `titleDerived && linkCards.length > 0` always becomes a `link` block, whatever slot it was assigned. Do it where kinds are settled (`settle`/`toBlock`), and keep the height budget honest.
  - Create `frontend/src/app/reader/list/magazine/blocks/entry-link/entry-link.component.{ts,html,scss,spec.ts}`, built like `entry-quote` (`EntryBlockBase`). It contains:
    1. `app-entry-kicker-line`;
    2. `p.title.post`, the headline;
    3. `a.card[href=card.url][target=_blank]`, whose click stops propagation and holds `img` (1.91:1, `object-fit: cover`, lazy, `referrerpolicy="no-referrer"`), `.text` > `small` site, `strong` title and `span` description clamped to 2 lines;
    4. `app-entry-meta` and `app-entry-duplicates`.
  - The card style follows the approved mockup:
    - glass-rim filled `--surface-1`, `--radius`, `overflow: hidden`;
    - text padding `--space-3` / `--space-4`;
    - site `--text-muted` at `--fs-xs`; title `--text-primary` at `--fs-base`, `--lh-tight`; description `--text-secondary` at `--fs-sm`.
  - Wire it into `magazine-block.component.html`.
- Tests:
  - The planner: a post with cards is a `link` block in any slot; a post without cards keeps today's kinds.
  - The block: renders the first card, and a card click does not open the entry.
  - The reader: one `figure.link-card` per card, and none for an entry without cards.

- [ ] Write the failing tests, run them (FAIL), implement, run them (PASS), then run `docker compose exec -T frontend npm run check`.
- [ ] Commit `feat(#1502): show a post's link cards in the reader and as a stacked magazine block`.

### Task 7: Gates and the real render (controller)

- [ ] Run all backend gates on both DB legs, `composer infection:diff`, and the frontend check.
- [ ] Clear the dev container's cache.
- [ ] Trigger a refresh of the Mother Jones Bluesky subscription (1721) and of a Mastodon feed. Check rows in `link_card`.
- [ ] In the built-in browser at 390px and 1280px, light and dark:
  - The magazine shows `entry-link` blocks matching the approved mockup (A, stacked).
  - The reader shows the cards under the post.
  - A pre-existing Mastodon post gains cards on open.
- [ ] Scan today's dev log for warnings.

### Task 8: `pending` and `POST /api/entries/complete` (backend)

Spec §8. (Task 7 above stays the controller's final gate and runs last.)

**Files:**
- Create: `backend/src/Repository/PendingLoader.php`, or a better name such as `EntryPendingLoader`. It batch-attaches `pending` the way `LinkCardLoader` does, and is chained in `EntryListRowEnricher`. It uses `EntryListRowEnrichment`, `EntryListRow` and `EntryJson::listRow` (`pending`).
  - The tried-marker lookup lives in `LinkCard\TriedPosts`. Give it a batch method if it has none.
  - Keep the repository free of Service imports beyond what `PersistenceKnowsNoServiceRule` allows. If the marker check cannot live in a repository, compute `pending` in a Service and pass it in, following how the enrichment is attached.
- Modify: `backend/src/Service/Bluesky/PostEnricher.php`.
  - Add `enrichEntries(list<Entry>): list<Entry>`. It fills those entries' queued rows through the same chunk settling (extract the shared part; do not copy it) and returns the filled entries.
  - The throttle and failure handling stay as they are.
- Modify: `backend/src/Service/LinkCard/PostLinkCards.php`. Add `forPending(list<Entry>)`, which builds as on open with a shared 5-fetch budget (`FetchBudget`).
- Create: a service in the right module, e.g. `Service/Reading/EntryCompletion` or the module that owns both calls without a cycle. Check the module graph: Bluesky and LinkCard are both used by Refresh. It runs Bluesky first, then link cards, then flushes.
- Create: a controller action, `POST /api/entries/complete`, with a request DTO holding `ids` (a list of positive ints, max 25, validated → 422). It scopes ids to the user through the existing list repository and is rate-limited with `readerLimiter` via `RateLimitGuard`.
  - It returns `{entries: [EntryJson::listRow…]}` built through `EntryListRowEnricher`.
  - Controller thin.
- Tests:
  - Loader unit tests.
  - A functional test of the endpoint (AppView and page `MockHttpClient`) per spec §8 Testing.
  - `PostEnricher::enrichEntries`.

- [ ] Write the tests and run them red, implement, run them green. Run all backend gates on both DB legs and `composer infection:diff`.
- [ ] Commit `feat(#1502): entries say when they are still being completed, and a client can complete them`.

### Task 9: Placeholder and polling (frontend)

Spec §8. The brief is the spec section plus these files:
- `LinkCardDto`'s neighbour: `EntryDto.pending: boolean`. Fix the builders.
- The magazine planner rule becomes: a post is a `link` block when `linkCards.length > 0 || pending`.
- `entry-link`: when pending and without cards, it renders the placeholder.
  - The placeholder is a shared small component, e.g. `link-card-placeholder`, used by both `entry-link` and the reader's `app-link-cards`.
  - The shimmer is a token-only animation, off under `prefers-reduced-motion`.
- `reader-api`: `complete(ids: number[]): Observable<EntryDto[]>`.
- A polling service (`pending-entries-poller` or similar) owned by the list state:
  - every 3 s, at most 20 rounds per list load;
  - stop rules per spec;
  - pause while `document.hidden`;
  - merge into the list store and the open entry's state, through existing update paths (find how a patched entry is merged today, e.g. the state PATCH merge).
- i18n keys in en/de.
- Tests: placeholder render, planner rule, poller with fake timers (memory: never let fake timers advance by an undefined ms).
- [ ] Red, then green. Run `docker compose exec -T frontend npm run check`.
- [ ] Commit `feat(#1502): a post still being completed shows a placeholder card and fills in by itself`.
