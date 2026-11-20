# Retire Method Names With `Of` in the Middle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `backend/src` carries `Of` in the middle of its name, apart from two yes/no questions.

**Architecture:** Renames only, one PR. #2360 covered names starting with `of` or ending in `Of`; this covers the 16 its pattern missed. The table below is the decision. Callers in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2394 and the method-name paragraph in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method. Constants keep their names (`MIN_SHARE_OF_LIGHTER`).
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables, string method names in mocks and docblock references included. Test method names are not renamed.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class, no `CLAUDE.md` edit.
- Commits `refactor(#2394): …`. The PR says `Closes #2394`. No attribution lines.
- No tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A new name that collides in its class, or a name that does not say what the body does: stop and report the row to the planner; do not pick a name.

## Decisions

- Every row was named from the method's full body and its callers. A `CLAUDE.md` verb is used where its meaning matches; otherwise the plain verb the tree already uses for that act.
- A repository method that runs a query is `find…`; one that returns a `QueryBuilder` or a DQL fragment is `build…Query` / `build…Condition` (as `buildReaderCondition`).
- `classify…` for mapping a value to a kind, as #2360 used it.
- **Kept names:** `CompoundSplitModel::hasMeanClassOfAtLeast` and `RedirectBudget::isOutOfTime` are yes/no questions that start with their verb.
- Out of scope: the bare names next to these rows (`flaggedIn`, `groupedQuery`, `failure`, `MastodonAuthor::identity`, …). They belong to #2400.

## The renames

Paths are under `backend/src/`. The last column is what the reviewer checks the name against.

| File | Old | New | What it does |
|---|---|---|---|
| `Repository/SavedPostRepository.php` | `entriesOfFeedWith` | `buildMarkedEntriesQuery` | Returns a `QueryBuilder` over a feed's entries joined to the user's rows of one mark class; runs nothing |
| `Repository/SavedSearchEntryRepository.php` | `memberOfAnySearch` | `buildSearchMembershipCondition` | Returns the DQL `EXISTS` fragment "member of one of the user's searches" for an entry alias |
| `Repository/SocialFeedRepository.php` | `subscribedCollectionsOfKinds` | `findSubscribedCollectionsByKinds` | Queries an account's subscribed social feeds of the given kinds; `findSubscribedCollections` already exists and calls it with every kind |
| `Repository/SocialNotificationRepository.php` | `pageOfGroups` | `findGroupPage` | Queries one page of notification groups, newest first, one extra row as the next-page signal |
| `Repository/SocialNotificationRepository.php` | `newestOfGroups` | `findNewestPerGroup` | For each group, queries its newest notifications; keyed by the group's latest id |
| `Repository/SocialNotificationRepository.php` | `newestOfGroup` | `findNewestInGroup` | Queries one group's newest notifications, limited |
| `Service/Curation/Recommendation/Llm/Prompt/ModelReplyJsonDecoder.php` | `endOfString` | `skipString` | Moves the scan past the string literal opening at the given index: answers the index of its closing quote, honouring escapes, or the text's length when unterminated |
| `Service/Curation/Trending/Model/HeadlineOverlapModel.php` | `shareOfLighter` | `computeLighterShare` | Divides the shared weight by the lighter headline's total |
| `Service/Ingestion/Parser/ItemMediaExtractor.php` | `firstOfKind` | `findFirstWithKind` | Returns the first media node of a kind, or null |
| `Service/Ingestion/Parser/Support/FeedMediaClassifier.php` | `kindOfMimeType` | `classifyMimeType` | Maps a MIME type's prefix to a media kind, `Other` when none matches |
| `Service/Ingestion/Parser/Support/FeedMediaClassifier.php` | `kindOfUrl` | `classifyUrl` | Maps a URL's file extension to a media kind, `Unknown` when none matches |
| `Service/Ingestion/Refresh/MissingFaviconResolver.php` | `baseUrlsOfFeedsWithoutIcon` | `collectBaseUrlsWithoutIcon` | Gathers, by feed id, the site URL (or feed URL) of each feed that has no favicon |
| `Service/Outbound/Image/Model/ImageVerificationBudgetModel.php` | `halfOfWhatIsLeft` | `fromHalfTheRemainingTime` | Named constructor: a budget of half the seconds until the deadline, capped, never negative |
| `Service/Social/Lemmy/LemmyApiClient.php` | `failureOfCode` | `findFailureForCode` | Looks a Lemmy error code up in a fixed map and returns its exception, or null for an unknown code |
| `Service/Social/Mastodon/Support/MastodonAuthor.php` | `identityOfProfileUrl` | `parseProfileIdentity` | Reads `user@host` out of a profile URL string; null when the string is not a profile URL |
| `Service/Social/Social/Pass/FollowMatching.php` | `firstOfEachIdentity` | `keepFirstPerIdentity` | Drops follows whose identity (case-insensitive) already appeared, keeping the first of each |

## Review Focus

- A string method name in a mock (`->method('pageOfGroups')`) that a search for `->pageOfGroups(` misses: the test then fails with "method does not exist"; search for the bare name.
- `findSubscribedCollectionsByKinds` beside the existing `findSubscribedCollections`: a replace of the old name must not touch the existing method or its callers.
- `classifyUrl` and `classifyMimeType` are public statics used from `PodcastArtwork` and `VisualMedium`; both callers follow.
- A consumer-owned interface or test double declaring `pageOfGroups`, `newestOfGroups` or `subscribedCollectionsOfKinds`: rename it with the repository.
- Docblocks and comments that name an old method (`{@see …}`): update the name only.

---

### Task 1: Repository renames

**Files:**
- Modify: `backend/src/Repository/SavedPostRepository.php`, `SavedSearchEntryRepository.php`, `SocialFeedRepository.php`, `SocialNotificationRepository.php`, their callers and tests

- [ ] **Step 1:** Rename the six repository rows of the table and every caller.
- [ ] **Step 2:** Run `grep -rnwE 'entriesOfFeedWith|memberOfAnySearch|subscribedCollectionsOfKinds|pageOfGroups|newestOfGroups|newestOfGroup' backend/src backend/tests backend/migrations`. Expected: no output.
- [ ] **Step 3:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2394): repository methods say what they do`.

### Task 2: Service renames

**Files:**
- Modify: the ten service files of the table, their callers and tests

- [ ] **Step 1:** Rename the ten service rows of the table and every caller.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?\w+[a-z]Of[A-Z]\w*\s*\(' backend/src`. Expected: exactly `hasMeanClassOfAtLeast` and `isOutOfTime`.
- [ ] **Step 3:** Run `grep -rnwE 'endOfString|shareOfLighter|firstOfKind|kindOfMimeType|kindOfUrl|baseUrlsOfFeedsWithoutIcon|halfOfWhatIsLeft|failureOfCode|identityOfProfileUrl|firstOfEachIdentity' backend/src backend/tests backend/migrations`. Expected: no output.
- [ ] **Step 4:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 5:** Commit `refactor(#2394): service methods say what they do`.

### Task 3: Gates and PR

- [ ] **Step 1:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; escaped mutants only on lines whose logic this PR did not change, listed in the PR.
- [ ] **Step 2:** Open the PR into `develop` with the rename table, the two kept names and `Closes #2394`; merge when green; verify #2394 closed.
