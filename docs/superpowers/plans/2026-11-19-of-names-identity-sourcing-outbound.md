# Retire `…Of` Names: Identity, Sourcing, Outbound Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Identity`, `Service/Sourcing` or `Service/Outbound` is named `of`, `of…` or `…Of`, except yes/no questions that start with `is…` or `has…`.

**Architecture:** Renames only, 22 methods in the three smallest service groups, as the first of several PRs for #2360. Each method gets the dictionary verb for what it does; the table below is the decision.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::keyOf(...)`) and strings included. Test method names are not renamed.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule is added for the names (Lars, 2026-11-19: "No rule"). No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression. No new class.
- Commits `refactor(#2360): …`. The PR says `Refs #2360`, **not** `Closes`: the issue stays open for the other groups. No attribution lines.
- A method of these three groups that matches the pattern but is not in the table: stop and report; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19): "Please always look at what the method really does and chose the name based on that." A dictionary verb is used where its meaning matches the body; where no row fits, the plain verb for what happens (`pick…`, `read…`, `compute…`, `detect…`). No rule keyed on return type or nullability.
- **`of()` and `of…()` by meaning** (Lars, 2026-11-19): a named constructor that wraps or converts one value is `from<Source>()`; a helper that assembles from parts is `build<Object>()`; one that reads a value out of a string or document is `parse<Object>()`; a lookup is `find<Object>()`.
- **A yes/no question keeps a trailing `Of`.** `ImageIdentityModel::isRenditionOf($other)` already starts with the dictionary's `is…`; it is not renamed.
- **Smallest groups first** (Lars, 2026-11-19); Identity (1 method), Sourcing (6) and Outbound (15 renamed, 1 kept) go in one PR because none alone proves the approach.

## The renames

| File (under `backend/src/Service/`) | Old | New |
|---|---|---|
| `Identity/Account/AccountSettings.php` | `of(User $user)` | `loadSettings` |
| `Sourcing/Catalog/Model/BundledCatalogSummaryModel.php` | `of(ParsedCatalogModel $document)` | `fromCatalog` |
| `Sourcing/Discovery/Model/FeedCandidateModel.php` | `of(DiscoveredFeedModel $feed)` | `fromDiscoveredFeed` |
| `Sourcing/Discovery/WellKnownFeedProbe.php` | `feedOf` (private) | `parseFeed` |
| `Sourcing/Discovery/FeedOffer/MixcloudProfileFeed.php` | `cloudcastsOf` (private) | `buildCloudcastsCandidate` |
| `Sourcing/Discovery/ShareLinkFeed/ApplePodcastShowFeed.php` | `feedUrlOf` (private) | `parseFeedUrl` |
| `Sourcing/Discovery/ShareLinkFeed/SubstackProfileFeed.php` | `subdomainOf` (private) | `parseSubdomain` |
| `Outbound/Fetch/ConcurrentRequestEngine.php` | `longestTimeoutOf` (private) | `pickLongestTimeout` |
| `Outbound/Fetch/ConcurrentRequestEngine.php` | `headersOf` (private) | `buildHeaders` |
| `Outbound/Fetch/Model/ApiBudgetModel.php` | `maxDurationOf(float $timeoutSeconds)` | `computeMaxDuration` |
| `Outbound/Fetch/Pass/RequestSlots.php` | `keysOf` (private) | `buildKeys` |
| `Outbound/Fetch/Pass/RequestSlots.php` | `keyOf` (private) | `buildKey` |
| `Outbound/Fetch/Support/ContentTypeCharset.php` | `of(?string $contentType)` | `parseCharset` |
| `Outbound/Fetch/Support/EgressOptions.php` | `extraOf` (private) | `readExtraOptions` |
| `Outbound/Fetch/Support/EgressOptions.php` | `curlOf` (private) | `readCurlOptions` |
| `Outbound/Fetch/Support/LandingSite.php` | `hostOf(string $postedUrl, string $landingUrl)` | `pickHost` |
| `Outbound/Fetch/Support/ResponseBody.php` | `urlOf` (private) | `readUrl` |
| `Outbound/Image/Support/ImageMagicBytes.php` | `typeOf(string $bytes)` | `detectType` |
| `Outbound/Image/Support/MediaType.php` | `of(?string $contentTypeHeader)` | `fromHeader` |
| `Outbound/Image/Support/OriginRoot.php` | `of(string $url)` | `fromUrl` |
| `Outbound/Image/Support/RenditionTargets.php` | `bandOf(int $width)` | `pickBand` |
| `Outbound/Mail/Transport/Factory/ActiveMailTransportFactory.php` | `signatureOf(ResolvedMailTransportModel $resolved)` | `buildSignature` |
| `Outbound/Image/Model/ImageIdentityModel.php` | `isRenditionOf` | kept |

## Review Focus

- **A caller missed because it is not a plain call.** First-class callables (`self::keyOf(...)`, `MediaType::of(...)`), `array_map([Foo::class, 'of'], …)` strings and docblock `@see` references. PHPStan finds the code ones; Task 4 greps for the rest.
- **A rename that collides with an existing method** of the same class or of a class it extends or implements (`parseUrl`, `buildHeaders`, `buildKey` are common names). Check each target class before renaming; on a collision stop and report.
- **A same-named method elsewhere renamed by accident.** `of(` is the most common method name in the tree; only the calls on these classes change (`$this->accountSettings->of($user)` in `Http/MeProfileJson`, `Operations/Backup/AccountBackupExporter`, `Operations/Backup/RestoreLoader` and `tests/Http/MeProfileJsonTest`). The diff must hold no `of(` change outside the table's classes and their callers.
- **A mock that names the method as a string** (`->method('of')`, `->method('signatureOf')`). Grep the tests for each old name in quotes.
- **A new name that says something the method does not do.** `pickBand` returns null below the smallest width; `fromHeader` returns `''`, never null. If a body contradicts its new verb's row in `CLAUDE.md`, stop and report rather than change the body.

---

### Task 1: Identity and Sourcing

**Files:** the seven Identity and Sourcing rows of the table, their callers in `backend/src` and `backend/tests`.

- [ ] **Step 1:** Rename the seven methods and every caller.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Identity backend/src/Service/Sourcing`. Expected: no output.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Identity and Sourcing methods say what they do`.

### Task 2: Outbound/Fetch

**Files:** the ten `Outbound/Fetch` rows, their callers.

- [ ] **Step 1:** Rename the ten methods and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Outbound/Fetch`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Outbound/Fetch methods say what they do`.

### Task 3: Outbound/Image and Outbound/Mail

**Files:** the five renamed `Outbound/Image` and `Outbound/Mail` rows, their callers.

- [ ] **Step 1:** Rename the five methods and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Outbound`. Expected: one line, `ImageIdentityModel::isRenditionOf`.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Outbound/Image and Outbound/Mail methods say what they do`.

### Task 4: Cross-checks and PR

- [ ] **Step 1:** For each old name in the table, run `grep -rnE "['\"](of|feedOf|cloudcastsOf|feedUrlOf|subdomainOf|longestTimeoutOf|headersOf|maxDurationOf|keysOf|keyOf|extraOf|curlOf|hostOf|urlOf|typeOf|bandOf|signatureOf)['\"]" backend/src backend/tests`. Expected: no hit that refers to a renamed method; name any remaining hit and its owner in the PR.
- [ ] **Step 2:** Run `git diff develop --stat -- backend/src | tail -1` and read the diff once for an `of(` change outside the table's classes and their callers. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text, not the code.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the rename table. Merge when CI is green. Then comment on #2360: these three groups are done, 681 methods remain (Social 280, Curation 96, Content 80, Http 43, Repository 43, Library 41, Operations 30, Ingestion 25, Foundation 23, Entity 8, Command 5, Enum 4, Doctrine 1, EventListener 1), with the two rulings and the `is…Of` exemption from Decisions.
