# Clean tracking parameters from stored article URLs (#2237) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every article URL is stored without its tracking parameters: feed entries, link cards and restored backups.
The dedupe hash uses the same list. A bounded command cleans the rows already stored.

**Architecture:**
- `Service/Foundation/Url/Support/TrackingFreeUrl` (static) holds the vendor-documented list and the pair test.
- `UrlNormalizer` filters its query through it, and `withoutTracking()` delegates to it.
- `IngestedEntryFactory`, `StorableLinkCard` and `EntryLine::fromLine` store `TrackingFreeUrl::of()`.
- `app:urls:clean-tracking` (`CleanTrackingUrlsCommand` → `Service/Operations/UrlCleanup/TrackingUrlCleanup`) walks
  `entry` and `link_card` by id keyset. It rewrites only the rows whose URL or hash changes, and it has `--dry-run`.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM, PHPUnit 12, Infection.

**Spec:** `docs/superpowers/specs/2026-11-15-2237-clean-tracking-urls-design.md`. It is the binding authority. Read
its Design, Decisions D1–D11 and the key tables before Task 1.

## Global Constraints

- **The list is exactly the spec's two tables** (vendor keys and prefixes, and the three per-host rules), minus any
  key whose citation Task 1 cannot confirm. Add no key from the dev DB, the news window or another project's list.
  Never copy from Brave, ClearURLs or AdGuard.
- **Out of scope, by decision:**
  - the value rule (D4) and publisher-internal keys (D3);
  - other URL columns: enclosures, media, author, discussion, quoted and image URLs;
  - redirect resolution (#2236) and `shared_link` (#2239);
  - a Doctrine migration (D7) and any frontend change.
- **Invariants that every test suite keeps:**
  - `normalize(TrackingFreeUrl::of(u)) === normalize(u)`;
  - `TrackingFreeUrl::of(TrackingFreeUrl::of(u)) === TrackingFreeUrl::of(u)`;
  - `TrackingFreeUrl::of(u) === u` when no pair of `u` is tracking.
- **Dev DB:** read only. The command runs only against the scratch copy `feedreader_2237` (Task 5), never against
  `feedreader`. Never run tests or `tests/bootstrap.php` against MySQL dev.
- **House rules (CLAUDE.md):**
  - `final readonly` by default; `TrackingFreeUrl` is a `final class` with a private constructor, like
    `StorableLinkCard`;
  - one role per folder, and queries only in `src/Repository`;
  - the controller/command stays thin;
  - no comment unless a future reader would get the code wrong. The class docblock of `TrackingFreeUrl` is one line
    that points to the spec's source table;
  - every touched `src` file is PHPMD-clean (`rm -rf ~/.pdepend`, then
    `HOME=$(mktemp -d) vendor/bin/phpmd analyze <files> --no-progress`), with no suppressions and no count-driven
    splits;
  - no PHPStan ignores, docblocks or `tests/PhpStan` edits; HTTP statuses are never at stake here;
  - tests mirror `src/`, data providers use `#[DataProvider]`, and invocation matchers are called on `$this`.
- Commit format `type(#2237): lower-case imperative summary`, with no Claude attribution. Work on the main checkout,
  branch `feature/2237-clean-tracking-urls`. Never push. Commands run in the foreground. No background jobs, no
  polling, no subagents.

## Review Focus

1. **Byte identity:** a URL with no tracking pair, including a bare `?`, an empty pair, a text fragment and an
   uppercase host, must come back identical. A cleaned URL keeps its kept pairs in order and byte for byte.
2. **Hash agreement:** the three invariants above, as a data-provider property over every example in
   `TrackingFreeUrlTest`. Existing `UrlNormalizerTest` expectations stay green unchanged, except the two
   `withoutTracking` tests the spec's D10 touches. Those must still pass as written.
3. **Per-host scoping:** `si` and `feature` are stripped on `youtube.com`, `m.youtube.com` and `youtu.be` only;
   `si` on `spotify.com` only; `smid`/`smtyp` on `nytimes.com` only. `example.com/?si=1` keeps `si`, and
   `notyoutube.com/?si=1` keeps `si` (a suffix needs the dot).
4. **The command:**
   - writes nothing under `--dry-run`;
   - writes a row only when its URL or hash differs;
   - reports 0/0/0 on a second run;
   - keeps a Bluesky entry's GUID-derived hash;
   - fills a `NULL` hash;
   - crosses a batch boundary without skipping or repeating a row.
5. **The citation trail:** every key in the source's list has its row in the spec's table, and the Outcome records any
   key dropped because its page did not confirm it.

---

### Task 1: Confirm the citations

**Files:**
- Modify: `docs/superpowers/specs/2026-11-15-2237-clean-tracking-urls-design.md` (the source table and the Outcome only)

- [ ] **Step 1:** Open each source in the spec's vendor table that is not marked *checked 2026-11-15*. Confirm that
  the page names each key in its row. Use WebFetch on the vendor's own domain only. Do not consult any tracking-list
  project.
- [ ] **Step 2:** For each row, record the exact page title and URL you confirmed in the table. For any key the vendor
  does not name, remove it from the table and add one Outcome line: "dropped `<key>`: `<vendor>` does not document
  it (`<url>`)".
- [ ] **Step 3:** Commit `docs(#2237): confirm the tracking-key sources`.

### Task 2: `TrackingFreeUrl` and `UrlNormalizer` on it

**Files:**
- Create: `backend/src/Service/Foundation/Url/Support/TrackingFreeUrl.php`
- Modify: `backend/src/Service/Foundation/Url/UrlNormalizer.php`
- Test: `backend/tests/Service/Foundation/Url/Support/TrackingFreeUrlTest.php`,
  `backend/tests/Service/Foundation/Url/UrlNormalizerTest.php`

**Interfaces:**
- `final class TrackingFreeUrl`
  - `public static function of(string $url): string`
  - `public static function query(string $query, string $host): string`: the kept pairs joined by `&`, without a `?`;
    `''` when none is kept
  - private constants for the prefixes (`utm_`, `at_`, `wt_`, `mtm_`), the exact keys, and the per-host map
    `domain => list<key>`; a private constructor
  - private helpers stay single-purpose, with no boolean parameter (for example `isTracking(string $pair, string $host)`,
    `keyOf(string $pair)`, `isOnDomain(string $host, string $domain)`, `isTrackingFragment(string $fragment, string $host)`)
- `UrlNormalizer`: the same public API (`normalize`, `withoutTracking`, `hash`). `TRACKING_PREFIXES`, `TRACKING_KEYS`
  and `isTracking()` are deleted. `query()` prefixes `?` to a non-empty `TrackingFreeUrl::query()`, and
  `withoutTracking()` is `normalize($url) === null ? null : TrackingFreeUrl::of($url)`.

- [ ] **Step 1: Failing tests.** `TrackingFreeUrlTest`:
  - `testEveryDocumentedKeyIsStripped`: a `#[DataProvider]` with one case per key and per prefix family in the
    spec's table. `https://example.com/a?<key>=1` becomes `https://example.com/a`, and the case and percent-encoded
    variants (`UTM_Source`, `utm%5Fsource`) are stripped too.
  - `testKeysThatNameThePageStay`: `?id=42`, `?p=7`, `?v=abc`, `?t=90`, `?list=PL1`, `?page=2`,
    `?unlocked_article_code=x` and `?gift=y` come back unchanged.
  - `testKeptPairsKeepTheirOrderAndBytes`: `?b=2&utm_source=x&a=%20&c` becomes `?b=2&a=%20&c`.
  - `testAUrlWithoutTrackingComesBackByteForByte`: cases include an uppercase host, `:443`, a bare `?`, `a=1&&b=2`,
    `#section-3` and `#:~:text=foo`.
  - `testTheQuestionMarkGoesWhenEveryPairGoes`: `/a?utm_source=x&fbclid=y` becomes `/a`, and
    `/a?utm_source=x#top` becomes `/a#top`.
  - `testAMisEscapedAmpersandStillHidesNoTracker`: `/a?utm_source=feed&amp;utm_medium=rss` becomes `/a`, and
    `/a?id=5&amp;utm_medium=rss` becomes `/a?id=5`.
  - `testATrackingFragmentGoes`: `#xtor=RSS-3-[lestitres]`, `#Echobox=1762000000` and
    `#utm_source=x&at_medium=y` go.
  - `testANavigationFragmentStays`: `#comment-5`, `#:~:text=a,b`, `#xtor=1&chapter=2` (mixed) and `#a=b` (not a
    tracker) stay.
  - `testPerHostKeysOnlyOnTheirDomain`: a provider:
    - `youtube.com/watch?v=x&si=y&feature=share` becomes `?v=x`;
    - `youtu.be/x?si=y&t=30` becomes `?t=30`;
    - `m.youtube.com` behaves the same;
    - `open.spotify.com/track/1?si=z` becomes `/track/1`;
    - `www.nytimes.com/…?smid=url-share&unlocked_article_code=k` becomes `?unlocked_article_code=k`;
    - `example.com/?si=1` and `notyoutube.com/?si=1` stay unchanged.
  - `testANonAbsoluteUrlComesBackUnchanged`: `not a url`, `/relative?utm_source=x` and `''` stay as they are.
  - `testCleaningAgreesWithTheHashAndIsIdempotent`: over every input URL in this class's providers, assert
    `normalize(of(u)) === normalize(u)` and `of(of(u)) === of(u)`, with `new UrlNormalizer()`.

  `UrlNormalizerTest` adds:
  - `testTheWiderListReachesTheHash`: `?mc_cid=a&mc_eid=b` and `?si=x` on youtu.be hash like the bare URL;
  - `testTheSharedAddressLosesATrackingFragment`: `withoutTracking('https://example.com/a#xtor=RSS-1')` gives
    `https://example.com/a`.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Foundation/Url`. Expected: FAIL (`TrackingFreeUrl` is missing).
- [ ] **Step 3:** Implement. Split the URL on its raw string at the first `#`, then the first `?` in the part before.
  Read the host with `parse_url(..., PHP_URL_HOST)` and lower-case it. Reject a URL without scheme and host via
  `parse_url`. The key is `strtolower(rawurldecode(explode('=', $pair, 2)[0]))` with one leading `amp;` removed.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Foundation/Url tests/Service/Social/Social/LinkPostsTest.php`.
  Expected: PASS, including every existing `UrlNormalizerTest` case unchanged.
- [ ] **Step 5:** `composer stan`, `composer cs`, PHPMD on both files. Expected: clean.
- [ ] **Step 6:** Commit `feat(#2237): strip vendor-documented tracking parameters in one cleaner`.

### Task 3: Store cleaned URLs on ingest, on cards and on restore

**Files:**
- Modify: `backend/src/Service/Ingestion/Ingest/Factory/IngestedEntryFactory.php`,
  `backend/src/Service/Content/LinkCard/Support/StorableLinkCard.php`,
  `backend/src/Service/Operations/Backup/Dto/EntryLine.php`
- Test: `backend/tests/Service/Ingestion/Ingest/Factory/IngestedEntryFactoryTest.php`,
  `backend/tests/Service/Content/LinkCard/Support/StorableLinkCardTest.php`,
  `backend/tests/Service/Operations/Backup/Dto/EntryLineTest.php`, and one functional test in the existing ingest
  suite (`backend/tests/Service/Ingestion/Ingest/EntryIngestorTest.php` or its functional sibling)

**Interfaces:** no signature changes.
- `IngestedEntryFactory::create` stores `self::cut(self::clean($parsed->url), self::URL_MAX)`, where a `null` URL
  stays `null`.
- `StorableLinkCard::of` builds the returned card with `TrackingFreeUrl::of($card->url)`.
- `EntryLine::fromLine` maps `url` through `TrackingFreeUrl::of()` when it is a string.

- [ ] **Step 1: Failing tests.**
  - `IngestedEntryFactoryTest::testStoresTheUrlWithoutTrackingParameters`:
    `https://example.com/a?id=1&utm_source=rss#xtor=RSS-3` gives `getUrl() === 'https://example.com/a?id=1'`.
  - `IngestedEntryFactoryTest::testAUrlWithoutTrackersIsStoredAsGiven` uses `HTTPS://Example.com/A?` (byte identity).
  - `StorableLinkCardTest::testTheCardUrlLosesItsTrackers`:
    `https://www.heise.de/news/x.html?wt_mc=sm.red&utm_source=mastodon#Echobox=1` gives
    `https://www.heise.de/news/x.html`.
  - `EntryLineTest::testARestoredUrlLosesItsTrackers`.
  - A functional test that ingests a parsed feed through the real container. One entry at
    `https://example.com/a?utm_source=rss` and an existing entry at `https://example.com/a` in another feed end up
    with the same `url_hash`, and the new row stores `https://example.com/a`. This backs the factory test with the
    real wiring (CLAUDE.md: direct-invocation tests mislead).
- [ ] **Step 2:** Run them. Expected: FAIL on the stored URL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Ingestion tests/Service/Content/LinkCard tests/Service/Social
  tests/Service/Operations/Backup`. Expected: PASS. A fixture that asserted a tracked URL verbatim is updated to the
  cleaned one; list each such change in the commit body.
- [ ] **Step 5:** `composer stan`, PHPMD on the three files. Expected: clean.
- [ ] **Step 6:** Commit `feat(#2237): store article and card urls without tracking parameters`.

### Task 4: `app:urls:clean-tracking`

**Files:**
- Create: `backend/src/Command/CleanTrackingUrlsCommand.php`,
  `backend/src/Service/Operations/UrlCleanup/TrackingUrlCleanup.php`,
  `backend/src/Service/Operations/UrlCleanup/Model/StoredEntryUrlModel.php`,
  `backend/src/Service/Operations/UrlCleanup/Model/TrackingUrlCleanupReportModel.php`
- Modify: `backend/src/Repository/EntryRepository.php`, `backend/src/Repository/LinkCardRepository.php`
- Test: `backend/tests/Command/CleanTrackingUrlsCommandTest.php`,
  `backend/tests/Service/Operations/UrlCleanup/TrackingUrlCleanupTest.php` (integration, on the SQLite test DB)

**Interfaces:**
- `final readonly class StoredEntryUrlModel { __construct(public int $id, public string $guid, public string $url, public ?string $urlHash) }`
- `final readonly class TrackingUrlCleanupReportModel { __construct(public int $entriesCleaned, public int $hashesChanged, public int $cardsCleaned) }`
- `EntryRepository::storedUrlsAfterId(int $afterId, int $limit): list<StoredEntryUrlModel>`: scalar select of id,
  `guid.value`, `location.url` and `location.urlHash` where the URL is not null, ordered by id, keyset.
- `EntryRepository::relocate(int $entryId, string $url, ?string $urlHash): void`: a DQL `UPDATE` of
  `location.url` and `location.urlHash`.
- `LinkCardRepository::urlsAfterId(int $afterId, int $limit): array<int, string>` and
  `LinkCardRepository::moveTo(int $cardId, string $url): void`.
- `final readonly class TrackingUrlCleanup`:
  - `__construct(EntryRepository $entries, LinkCardRepository $cards, EntryUrlHash $urlHash, EntityManagerInterface $entityManager, int $batchSize = 500)`;
  - `public function count(): TrackingUrlCleanupReportModel` (the dry run);
  - `public function clean(): TrackingUrlCleanupReportModel`.

  Two public methods, no boolean flag. They share a private walk that takes the per-row action as a closure, or two
  small passes: pick the shape that stays PHPMD-clean without a count-driven split. Each batch of `clean()` runs in
  `$entityManager->wrapInTransaction()` and is followed by `$entityManager->clear()`.
- `CleanTrackingUrlsCommand` (`#[AsCommand(name: 'app:urls:clean-tracking', description: 'Remove tracking parameters from stored entry and link-card URLs and recompute their hashes')]`):
  - option `--dry-run`;
  - prints `N entries cleaned, M hashes changed, K cards cleaned`, prefixed `Would clean:` under `--dry-run`;
  - returns `Command::SUCCESS`.

- [ ] **Step 1: Failing tests.** `TrackingUrlCleanupTest`, with batch size 2 and five entries plus three cards:
  - a tracked outlet entry `https://example.com/a?utm_source=rss`;
  - a clean entry;
  - an entry with a `NULL` hash;
  - a Bluesky entry with an `at://` GUID and a tracked URL;
  - an entry stored with the old hash of `https://example.com/b?amp;utm_medium=rss`.

  The tests:
  - `testTheDryRunCountsAndWritesNothing`: the report equals the expected counts, and every row is unchanged
    (re-read through DBAL).
  - `testCleaningRewritesOnlyRowsThatChange`: the tracked URL is clean, and its hash equals
    `EntryUrlHash::of(guid, cleaned)`. The `NULL` hash is filled. The Bluesky hash still equals the hash of its
    identity URL, and its URL is cleaned. The clean entry's row is untouched.
  - `testASecondRunFindsNothing`: the report is 0/0/0.
  - `testTheWalkCrossesBatchBoundaries`: all five entries are visited with batch size 2 (assert on the counts).
  - `testCardsAreCleaned`: a card `…?si=x` on youtu.be becomes the bare URL, and a clean card is untouched.

  `CleanTrackingUrlsCommandTest`:
  - `testDryRunPrintsWhatItWouldClean`: `CommandTester` output contains `Would clean:`;
  - `testRunPrintsWhatItCleaned`: the exit code is `Command::SUCCESS`.
- [ ] **Step 2:** Run them. Expected: FAIL (classes missing).
- [ ] **Step 3:** Implement. The command takes only the service. The service takes only repositories, `EntryUrlHash`
  and the entity manager. Queries stay in the repositories.
- [ ] **Step 4:** `php bin/phpunit tests/Command/CleanTrackingUrlsCommandTest.php tests/Service/Operations/UrlCleanup
  tests/Repository`. Expected: PASS.
- [ ] **Step 5:** `composer stan` (the `ServiceRoleRule`, `QueriesLiveInRepositoriesRule` and `ThinControllerRule`
  family), `composer cs`, and PHPMD on every new and touched file. Expected: clean. If the PHPMD commit hook denies
  the new classes, report BLOCKED with its message.
- [ ] **Step 6:** Commit `feat(#2237): add a command that cleans tracking parameters from stored urls`.

### Task 5: Measure (A = develop, B = HEAD)

**Files:** none in the repository; scratch in `scratchpad/2237/final/`.

- [ ] **Step 1: Snapshot.** Create the scratch copy as root inside the MySQL container:
  - `CREATE DATABASE feedreader_2237; GRANT ALL ON feedreader_2237.* TO 'feedreader'@'%';`
  - `mysqldump --single-transaction feedreader | mysql feedreader_2237`.

  The worker never writes to it, so the window is frozen. Record the row counts of `entry` and `link_card` in both.
- [ ] **Step 2: A.** Make a scratch copy of the backend at `develop` (`git archive develop backend | tar -x`, with
  `vendor/` copied, not symlinked). Run the kit's `simp-explain-at.php` against `feedreader_2237` (`DATABASE_URL`
  with dbname `feedreader_2237`) at the pin `2026-11-15 12:00:00`:
  - day and week, all languages;
  - French-only and Spanish-only, using `run.sh`'s `COALESCE(x.language, y.language)` filter in the copy's
    `TrendingEntryRepository`.

  Time every run with `/usr/bin/time -l`.
- [ ] **Step 3: The command on the copy.** From HEAD, against `feedreader_2237`:
  - run `app:urls:clean-tracking --dry-run` and record its counts. Expected: close to spec R1 (B1) plus the 1,458
    `NULL` fills: about 9,560 entry URLs cleaned, about 1,710 hashes changed (252 from the wider list, 1,458 `NULL` fills), about 1,476 cards;
  - run it for real; record the run time and peak memory;
  - run it again; expected: 0/0/0.
- [ ] **Step 4: B.** Run the same six explain runs from HEAD against the cleaned copy. No read-time rehash patch is
  needed now: the copy holds the recomputed hashes.
- [ ] **Step 5: Compare.** Run `compare.py` and the #2235 `pairs.py` for each window: grouped pairs per language pair
  (de–de, en–en, fr–fr, es–es, mixed/none), new, lost and net. Check by eye every changed group, the largest new and
  grown groups, and any split. Expected: day ≈ 0 and week ≈ +49 net with 0 lost (spec R3). A lost pair or a split needs
  its cause named before it counts as accepted.
- [ ] **Step 6: Eye check.** Draw 20 cleaned URLs from the copy at random: 5 French, 5 Spanish-tagged where they
  exist, 5 German and 5 English. Confirm each keeps the path and the content keys. Fetch at most 5 of them live to
  confirm they open the same page.
- [ ] **Step 7:** Drop `feedreader_2237`. Fill the spec's Outcome: the dry-run and run counts, the timings, the
  per-language-pair table for day, week, fr-only and es-only, and the eye-check verdicts. Commit
  `docs(#2237): record the measured outcome`.

### Task 6: Gates

- [ ] **Step 1:** `rm -rf ~/.pdepend && composer md`, then
  `HOME=$(mktemp -d) vendor/bin/phpmd analyze <every touched src file> --no-progress`. Expected: no finding.
- [ ] **Step 2:** `composer check` (cs + stan + tramp). Expected: clean. If phptramp flags a chain, check
  `composer show larspohlmann/phptramp` before changing code.
- [ ] **Step 3:** `composer test:parallel` (SQLite) and `docker compose exec -T php composer test:parallel` (MySQL),
  at the same time. Expected: green.
- [ ] **Step 4:** `composer infection:diff`. Expected: at or above `minMsi`. Kill escaped mutants in
  `TrackingFreeUrl` with cases, never by lowering the gate.
- [ ] **Step 5:** PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP. Expected: no ERROR or
  WARNING.
- [ ] **Step 6:** Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1`) for deprecations raised by the
  new code.
- [ ] **Step 7:** Write the PR notes in the ledger: `Closes #2237`, the command to run after deploy (dev Docker:
  `docker compose exec php bin/console app:urls:clean-tracking --dry-run`, then without; Strato: Lars's step), and the
  interaction with #2239/#2236. Do not open the PR or push.
