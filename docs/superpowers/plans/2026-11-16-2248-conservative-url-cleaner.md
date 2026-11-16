# Conservative URL cleaner (#2248) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The URL cleaner stops stripping keys whose content use is plausible or unproven, the never-stripped keys are
pinned by a test, and `app:urls:clean-tracking` also recomputes `shared_link.url_hash`.

**Architecture:** The rule lists in `Service/Foundation/Url/Pass/TrackedUrl` shrink (three prefixes become exact
vendor keys; five keys and one feed-marker key go). `TrackingFreeUrlTest`'s gift-key invariant grows into a
never-stripped invariant. `Operations/UrlCleanup/TrackingUrlCleanup`'s card walk reads and rewrites the stored hash.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine ORM, PHPUnit 12, MySQL 8.4 / SQLite.

**Spec:** `docs/superpowers/specs/2026-11-16-2248-conservative-url-cleaner-design.md`

## Global Constraints

- `CLAUDE.md` PHP Clean Code rules are mandatory: `final readonly`, names reveal intent, ≤3 parameters (constructor
  injection aside), no boolean flags, no comment unless a future reader would get the code wrong.
- Commit messages: `type(#2248): lower-case imperative summary`; no Claude attribution, no Co-Authored-By.
- Never edit `tests/PhpStan`, never add a PHPStan ignore or docblock to silence a finding, never lower a threshold.
- Every touched `src` file is PHPMD-clean: `rm -rf ~/.pdepend && composer md`, then
  `HOME=$(mktemp -d) vendor/bin/phpmd analyze <touched src files> --no-progress` from `backend/`.
- Dev DB `feedreader` and `feedreader_2237_uncleaned` are never written; scratch copies are `feedreader_2248_*`.
- Every command runs in the foreground (timeout up to 600000 ms); never background, poll or loop-wait. Never push.
- No list entry or tuned value comes from the current news window; every added key cites its vendor page (spec table).
- The JSON shapes the reader and Trending return do not change (native iOS client).

## Review Focus

1. A key that only *starts* like a removed prefix (`at_bbc_team`, `mtm_foo`, `wt_zmc`) on a non-vendor host must stay —
   pinned by the never-stripped list (Task 1) and a content-URL case.
2. A URL mixing an exact vendor key with a removed key (`?at_medium=x&at_bbc_team=y`) must lose only the vendor key —
   Task 1, `testOnlyTheVendorsExactKeyGoes`.
3. A tracking fragment holding a removed key (`#at_bbc_team=x`) is now a navigation fragment and stays whole —
   Task 1, `navigationFragments`.
4. A link whose stored hash is already current must not be rewritten or counted by the cleanup (idempotence; second
   run reports 0) — Task 2.
5. Upper-case and percent-encoded spellings of the new exact keys (`AT_Medium`, `mtm%5Fcampaign`) are still stripped —
   Task 1, `documentedKeys`.

---

### Task 1: Narrow the strip lists and pin the never-stripped keys

**Files:**
- Modify: `backend/src/Service/Foundation/Url/Pass/TrackedUrl.php` (constants at the top, `isTracking()`)
- Modify: `backend/tests/Service/Foundation/Url/Support/TrackingFreeUrlTest.php`
- Modify: `backend/tests/Service/Foundation/Url/Pass/TrackedUrlTest.php`
- Modify (fixture `?ref=rss` → `?cmp=rss`, spec D7): `backend/tests/Service/Content/LinkCard/ShareLinkCardsTest.php`,
  `backend/tests/Service/Social/Share/Support/SharedLinksTest.php`,
  `backend/tests/Service/Social/Share/Support/ShareTargetsTest.php`,
  `backend/tests/Service/Content/LinkCard/PostedUrlCheckTest.php`,
  `backend/tests/Service/Content/LinkCard/ShareTargetReaderTest.php`
- Modify: `docs/superpowers/specs/2026-11-15-2237-clean-tracking-urls-design.md` (one line under each list table:
  "Narrowed by #2248, see `2026-11-16-2248-conservative-url-cleaner-design.md`.")

**Interfaces:**
- Consumes: nothing new.
- Produces: no signature change. `TrackedUrl` public API (`of`, `inQuery`, `cleaned`, `keptQuery`, `strippedKeys`,
  `strippedAnUndocumentedKey`) is unchanged; only which keys it strips changes.

- [ ] **Step 1: Write the failing tests in `TrackingFreeUrlTest`**

  - `DOCUMENTED_KEYS`: remove `mkt_tok`, `oly_enc_id`; add `at_platform`, `at_creation`, `at_variant`, `at_network`,
    `at_term`, `mtm_source`, `mtm_medium`, `mtm_content`, `mtm_cid`, `mtm_group`, `mtm_placement`, `mtm_kwd` (keep
    `at_medium`, `at_campaign`, `wt_mc`, `wt_cc1`, `mtm_campaign`, `mtm_keyword`). In `documentedKeys()` add
    `yield 'upper-case piano key' => ['AT_Medium'];` and `yield 'percent-encoded matomo key' => ['mtm%5Fcampaign'];`.
  - Rename `GIFT_KEYS` → `NEVER_STRIPPED_KEYS`, `GIFT_KEYS_BY_DOMAIN` → `NEVER_STRIPPED_KEYS_BY_DOMAIN`,
    `giftKeysOnEveryHost` → `neverStrippedKeysOnEveryHost`, `testNoRuleEverMatchesAGiftKey` →
    `testNoRuleEverMatchesAKeyThatMayNameContent`; its docblock: `Never stripped, on any host: gift and gift-like keys,
    and the keys #2248 ruled out.` The lists become:

    ```php
    private const array NEVER_STRIPPED_KEYS = [
        'unlocked_article_code', 'gift', 'pwapi_token', 'accesstoken', 'sharetype', 'gifttoken',
        'giftcode', 'gift_token', 'share_token', 'access_token',
        'freebie', 'shared', 'view_token', 'reduced', 'premium', 'leadsource',
        'link_source', 'taid',
        'mkt_tok', 'oly_enc_id', 'traffic_source', 'ref', 'at_bbc_team', 'at_specific',
    ];
    private const array NEVER_STRIPPED_KEYS_BY_DOMAIN = [
        'wsj.com' => ['st', 'reflink'],
        'nytimes.com' => ['source', 'smtyp'],
    ];
    ```
  - `sharedOnTheirHost` 'the times keeps its gift link': expected becomes
    `https://www.nytimes.com/2026/a.html?smtyp=cur&unlocked_article_code=k`.
  - `undocumentedTrackers`: drop `traffic_source` from `$keys`.
  - `feedMarkers`: drop 'standard ref, upper case'. `keysWithAnotherValue`: add
    `yield 'a ref feed marker' => ['https://www.derstandard.at/story/1?ref=RSS'];`.
  - `contentUrls`: add `yield 'a key that only starts like a piano, mapp or matomo key' =>
    ['https://example.com/a?at_bbc_team=x&wt_zmc=y&mtm_foo=z'];` and
    `yield 'a marketo token' => ['https://example.com/unsubscribe?mkt_tok=abc'];`.
  - Add provider `vendorKeysBesideCustomKeys` with one case
    `['https://www.bbc.com/news/a?at_medium=RSS&at_bbc_team=x', 'https://www.bbc.com/news/a?at_bbc_team=x']` and test
    `testOnlyTheVendorsExactKeyGoes(string $url, string $expected)` asserting `TrackingFreeUrl::of($url)`.
  - `navigationFragments`: add `yield 'a custom piano key' => ['at_bbc_team=x'];`.
  - `strippedKeys` 'query and fragment': `ref=rss` → `cmp=rss`, expected `['utm_source', 'cmp', 'xtor']`.
  - Add `everyExample` providers: `self::vendorKeysBesideCustomKeys()`, `self::keysWithAnotherValue()`.

- [ ] **Step 2: Update `TrackedUrlTest`**

  'a documented key beside a feed marker': `ref=feed` → `cmp=feed` (still `true`). Add
  `yield 'an exact piano key is documented' => ['https://www.bbc.com/a?at_campaign=rss', 'https://www.bbc.com/a', false];`.

- [ ] **Step 3: Run the two test classes and see them fail**

  Run: `php bin/phpunit tests/Service/Foundation/Url` (from `backend/`)
  Expected: FAIL — among others `testNoRuleEverMatchesAKeyThatMayNameContent` for `mkt_tok`, `oly_enc_id`,
  `traffic_source`, `ref`, `at_bbc_team`, `at_specific` and `smtyp on nytimes.com`, and the new content-URL cases.

- [ ] **Step 4: Narrow the lists in `TrackedUrl`**

  - `DOCUMENTED_PREFIXES` (array) becomes `private const string DOCUMENTED_PREFIX = 'utm_';`, and `isTracking()` tests
    `str_starts_with($pair['key'], self::DOCUMENTED_PREFIX)` instead of the `array_any` over prefixes.
  - `DOCUMENTED_KEYS`: remove `mkt_tok`, `oly_enc_id`; add the spec table's keys: `at_medium`, `at_campaign`,
    `at_platform`, `at_creation`, `at_variant`, `at_network`, `at_term`, `wt_mc`, `wt_cc1`, `mtm_campaign`,
    `mtm_source`, `mtm_medium`, `mtm_keyword`, `mtm_kwd`, `mtm_content`, `mtm_cid`, `mtm_group`, `mtm_placement`.
  - `UNDOCUMENTED_KEYS`: remove `traffic_source`.
  - `KEYS_BY_DOMAIN['nytimes.com']`: `['smid' => true]`.
  - `FEED_MARKER_KEYS`: `['cmp' => true, 'via' => true]`.

- [ ] **Step 5: Switch the posted-form fixtures to `cmp=rss`**

  In the five posted-form test files listed above, replace every `?ref=rss` / `ref=rss&` in a URL with
  `?cmp=rss` / `cmp=rss&` (spec D7). Nothing else in them changes.

- [ ] **Step 6: Run the affected tests and see them pass**

  Run: `php bin/phpunit tests/Service/Foundation/Url tests/Service/Content/LinkCard tests/Service/Social/Share tests/Service/Curation/Trending`
  Expected: PASS.

- [ ] **Step 7: Annotate the #2237 spec** (the one line under its vendor, per-host and undocumented tables, Files above).

- [ ] **Step 8: Gate the touched file and commit**

  Run: `composer cs && composer stan`, and the PHPMD lines of Global Constraints on `TrackedUrl.php`.
  Expected: clean.

  ```bash
  git add backend/src/Service/Foundation/Url/Pass/TrackedUrl.php backend/tests docs/superpowers/specs/2026-11-15-2237-clean-tracking-urls-design.md
  git commit -m "fix(#2248): strip only the vendors' exact keys and keep keys that may name content"
  ```

### Task 2: Recompute `shared_link.url_hash` in `app:urls:clean-tracking`

**Files:**
- Create: `backend/src/Service/Operations/UrlCleanup/Model/StoredLinkAddressModel.php`
- Modify: `backend/src/Repository/SharedLinkRepository.php` (`urlsAfterId`, `moveTo` → `relocate`)
- Modify: `backend/src/Service/Operations/UrlCleanup/TrackingUrlCleanup.php`
- Modify: `backend/src/Service/Operations/UrlCleanup/Model/TrackingUrlCleanupReportModel.php`
- Modify: `backend/src/Command/CleanTrackingUrlsCommand.php` (output line, description)
- Test: `backend/tests/Service/Operations/UrlCleanup/TrackingUrlCleanupTest.php`,
  `backend/tests/Command/CleanTrackingUrlsCommandTest.php`

**Interfaces:**
- Consumes: Task 1's lists (through `TrackingFreeUrl::of()` and `UrlNormalizer::hash()`).
- Produces:
  - `final readonly class StoredLinkAddressModel { public function __construct(public string $url, public string $urlHash) }`
  - `SharedLinkRepository::urlsAfterId(int $afterId, int $limit): array<int, StoredLinkAddressModel>` (by link id;
    select `link.id`, `link.url`, `link.urlHash`)
  - `SharedLinkRepository::relocate(int $linkId, string $url, string $urlHash): void` (one DQL UPDATE of both columns;
    replaces `moveTo`)
  - `TrackingUrlCleanupReportModel(int $entriesCleaned = 0, int $hashesChanged = 0, int $cardsCleaned = 0, int $cardHashesChanged = 0)`; `plus()` sums all four
  - `TrackingUrlCleanup::__construct(EntryRepository $entries, SharedLinkRepository $sharedLinks, EntryUrlHash $urlHash, UrlNormalizer $urlNormalizer, EntityManagerInterface $entityManager, int $batchSize = 500)`
  - command output: `%d entries cleaned, %d hashes changed, %d cards cleaned, %d card hashes changed`

- [ ] **Step 1: Write the failing tests**

  In `TrackingUrlCleanupTest`:
  - `storeLinks()` gains a third link `'stale' => 'https://www.aljazeera.com/news/1?traffic_source=rss'` stored with
    `url_hash` = `self::hashOf('https://www.aljazeera.com/news/1')` (the old list's hash). Give `storeLinks` the hash
    per name instead of computing `self::hashOf($url)` for all, so `clean` and `tracked` keep their current hashes.
  - Every `new TrackingUrlCleanupReportModel(3, 2, 1)` becomes `(3, 2, 1, 1)`; the second-run and batch-boundary zero
    reports become `(0, 0, 0, 0)`.
  - New `testAStaleLinkHashIsRecomputedAndItsUrlKept`:
    `assertSame(['url' => 'https://www.aljazeera.com/news/1?traffic_source=rss', 'url_hash' => self::hashOf('https://www.aljazeera.com/news/1?traffic_source=rss')], $this->linkRow('stale'))`
    after `clean()`, and `assertNotSame(self::hashOf('https://www.aljazeera.com/news/1'), …['url_hash'])`.
  - `cleanup()` passes `new UrlNormalizer()` as the fourth argument.

  In `CleanTrackingUrlsCommandTest`: expected strings become
  `'Would clean: 1 entries cleaned, 1 hashes changed, 0 cards cleaned, 0 card hashes changed'` and
  `'1 entries cleaned, 1 hashes changed, 0 cards cleaned, 0 card hashes changed'`.

- [ ] **Step 2: Run them and see them fail**

  Run: `php bin/phpunit tests/Service/Operations/UrlCleanup tests/Command/CleanTrackingUrlsCommandTest.php`
  Expected: FAIL (constructor arity, report shape, stale hash unchanged).

- [ ] **Step 3: Implement**

  The card batch turns non-static and, per `StoredLinkAddressModel`, computes `$url = TrackingFreeUrl::of($stored->url)`
  and `$urlHash = $this->urlNormalizer->hash($url)`; a `null` hash (not an absolute URL) leaves the row as it is. It
  calls the relocate closure only when `$url !== $stored->url || $urlHash !== $stored->urlHash`, and reports
  `cardsCleaned` = URL changed, `cardHashesChanged` = hash changed. `clean()` passes `$this->sharedLinks->relocate(...)`;
  `count()` passes the no-op. The command's `description` becomes
  `'Remove tracking parameters from stored entry and link-card URLs and recompute their hashes'` (unchanged text, it
  already says so) and its output line the four-count form above.

- [ ] **Step 4: Run the tests and see them pass**

  Run: `php bin/phpunit tests/Service/Operations/UrlCleanup tests/Command/CleanTrackingUrlsCommandTest.php`
  Expected: PASS.

- [ ] **Step 5: Gate and commit**

  Run: `composer check`, and the PHPMD lines of Global Constraints on every touched `src` file. Expected: clean. If the
  PHPMD hook denies the commit for the new model class, report BLOCKED with its message.

  ```bash
  git add backend/src/Service/Operations/UrlCleanup backend/src/Repository/SharedLinkRepository.php backend/src/Command/CleanTrackingUrlsCommand.php backend/tests/Service/Operations/UrlCleanup backend/tests/Command/CleanTrackingUrlsCommandTest.php
  git commit -m "fix(#2248): recompute the shared link hash when cleaning stored urls"
  ```

### Task 3: Measure A against B and record it in the spec

**Files:**
- Modify: `docs/superpowers/specs/2026-11-16-2248-conservative-url-cleaner-design.md` (an `## Outcome` section at the end)
- Scratch only: `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/5bb3d5ca-28c2-427b-85d0-779b6950f4b5/scratchpad/2248/` (`run.sh`, `pairs-lang.py`, `stored.sql` already exist; trees `A/backend`, `B/backend`)

**Interfaces:**
- Consumes: Tasks 1–2 at HEAD.

- [ ] **Step 1: Refresh the B tree from HEAD**

  `git archive HEAD backend | tar -x -C <scratch>/2248/B` (its `vendor` stays; this replaces the spec's prototype
  `TrackedUrl.php`). The A tree stays develop 290990c6f.

- [ ] **Step 2: Rebuild the B copy**

  `feedreader_2248_a` (develop, migrated and cleaned during the spec) is kept as is; A's runs in `runs/A-*.txt` stay
  valid. Drop and recreate `feedreader_2248_b` from `feedreader_2237_uncleaned` through the mysql container
  (`docker compose exec -T mysql mysqldump -uroot -proot --single-transaction --routines --triggers feedreader_2237_uncleaned | docker compose exec -T mysql mysql -uroot -proot feedreader_2248_b`,
  after `CREATE DATABASE … CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` and a grant to `feedreader`). Then with
  `DATABASE_URL='mysql://root:root@127.0.0.1:33306/feedreader_2248_b?serverVersion=8.4&charset=utf8mb4' APP_ENV=dev`,
  from `B/backend/`, one command per call: `bin/console cache:clear -q`, `bin/console doctrine:migrations:migrate --no-interaction`,
  `/usr/bin/time -l bin/console app:urls:clean-tracking` (about 6 minutes; record the line, wall time, peak RSS),
  then the command again. Expected: `12171 entries cleaned, 4309 hashes changed, 896 cards cleaned, 0 card hashes
  changed` (the migration already hashed with the narrowed lists), and all zeros on the second run.

- [ ] **Step 3: Stored data**

  Run: `docker compose exec -T mysql mysql -uroot -proot -t < <scratch>/2248/stored.sql`. Record: entry URLs and
  `url_hash` that differ by key and by language, `shared_link` rows per side, B links whose hash A lacks.
  Expected (spec Measurements): 2,018 entries differ (2,001 `traffic_source`, 17 BBC `at_*`, all `en`), 5,944 links on
  each side, 2 B links whose hash A lacks (zdfheute `at_specific`, derstandard `ref=rss`); any other difference is a
  finding to report. (The last two queries in `stored.sql` need `COLLATE utf8mb4_0900_ai_ci` on both sides of each
  hash comparison; fix that first.)

- [ ] **Step 4: Trending**

  For B and each of `day`, `week`, `day fr`, `week fr`, `day es`, `week es`: `<scratch>/2248/run.sh B <window> [lang]`,
  one week run per call (about 76 s each); A's files are already in `runs/`. Then `python3 <scratch>/measure-kit/compare.py runs/A-<…>.txt runs/B-<…>.txt` and
  `python3 <scratch>/2248/pairs-lang.py runs/A-<…>.txt runs/B-<…>.txt` for each window. Record groups / pairs, new,
  lost, net per language pair (de–de, en–en, fr–fr, es–es, mixed/none), run time and peak RSS; check every changed
  group by eye and name the key behind each. Expected (spec Measurements): identical to A in every window.

- [ ] **Step 5: Dev dry run**

  Run from the main `backend/`: `bin/console app:urls:clean-tracking --dry-run` against `feedreader` (read-only).
  Expected: `Would clean: 0 entries cleaned, 0 hashes changed, 0 cards cleaned, 0 card hashes changed`, or new
  ingest since the cleanup only (spec D9).

- [ ] **Step 6: Eye check**

  20 URLs from the B copy that keep a now-kept key (Al Jazeera `traffic_source`, BBC custom `at_*`, derstandard `ref`,
  any French or Spanish one): each keeps its path and its content keys, and only documented trackers left.

- [ ] **Step 7: Write the Outcome and commit**

  The spec's `## Outcome` holds the tables of Steps 2–4, the dry-run line and the eye check. Spec D8 (DB-match rule
  out) rests on 0 lost pairs: if B now loses any grouped pair in any window, do not build the rule; stop and report
  the pairs and the key behind each.

  ```bash
  git add docs/superpowers/specs/2026-11-16-2248-conservative-url-cleaner-design.md
  git commit -m "docs(#2248): record the measured effect of the narrowed strip lists"
  ```

### Task 4: Gates

- [ ] **Step 1:** From `backend/`: `composer check` — expected clean.
- [ ] **Step 2:** `rm -rf ~/.pdepend && composer md`, then `HOME=$(mktemp -d) vendor/bin/phpmd analyze src/Service/Foundation/Url/Pass/TrackedUrl.php src/Service/Operations/UrlCleanup/TrackingUrlCleanup.php src/Service/Operations/UrlCleanup/Model/TrackingUrlCleanupReportModel.php src/Service/Operations/UrlCleanup/Model/StoredLinkAddressModel.php src/Repository/SharedLinkRepository.php src/Command/CleanTrackingUrlsCommand.php --no-progress` — expected no finding.
- [ ] **Step 3:** `composer test:parallel` (SQLite) and, from the repo root, `docker compose exec -T php composer test:parallel` (MySQL), side by side — expected green.
- [ ] **Step 4:** `composer infection:diff` — expected MSI at or above `infection.json5`'s `minMsi`; kill escaped mutants with tests, never by lowering it.
- [ ] **Step 5:** PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files — no ERROR or WARNING.
- [ ] **Step 6:** Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`) — no new deprecation or error from this change.
