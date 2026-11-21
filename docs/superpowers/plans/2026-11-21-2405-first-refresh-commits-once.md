# A feed's first refresh commits once (#2405) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a refresh brings a category the database doesn't know yet, its new entries stop being committed before the rest of the refresh. A failed refresh then rolls back as a whole, and the retry classifies and queues the entries.

**Architecture:** `EntryCategoryWriter::resolveCategories()` flushes whenever it persists a new `Category`. That flush commits the just-created entries mid-ingest, before share classification, the `share_enrichment` rows and the category links. Remove it so that `IncomingPageStore::store()`'s single flush writes everything. Also run `StoredEntryRefresher` before `EntryIngestor`, so an entry this ingest creates is never refreshed as a stored one, even if a flush ever comes back mid-ingest.

**Tech Stack:** Symfony 8.1, Doctrine ORM 3.6.7, PHPUnit 12.

**Spec:** GitHub issue #2405 (root cause and proposed fix).

## Global Constraints

- The flush in `EntryCategoryWriter` was added in #953 "so identities exist before links are written": `EntryCategory`'s id goes through `Entry` and `Category`. ORM 3.6.7 writes both in one flush. Removing the flush failed only `EntryCategoryWriterTest::testFlushesImmediatelyWhenANewCategoryIsCreated` across all 1189 tests in `tests/Service/Ingestion`, and that test pins the flush itself.
- Since #2404, `LemmyPostRepository::storeCommunities()` upserts by `canonical_item_id`, so the duplicate-key crash from the issue no longer fires. The split commit that made the damage permanent is still there. That is what this plan fixes.
- Commit format: `fix(#2405): …` / `test(#2405): …`. No attribution lines.
- Every touched `src` file must be PHPMD-clean; `composer check` must pass.
- Out of scope: repairing feed 578's 20 entries on Strato. That is a production write and needs Lars's explicit go.

## Review Focus

1. Two entries in one page that bring the same new category: one `Category` row, two links. This is already covered by `distinctCategories()` and `testCreatesANewCategoryEvenWhenAnEarlierOneAlreadyExists`; keep it green.
2. Two feeds in one pass that bring the same new category: each store flushes before the next one starts, so the second store finds the row. `RefreshRunnerTest` stays green.
3. A store that brings no category is unchanged: `testDoesNotFlushEarlyWhenNoNewCategoryIsCreated` stays as it is.

---

### Task 1: The category writer leaves the flush to its caller, and a failed first refresh can be retried in full

**Files:**
- Modify: `backend/src/Service/Ingestion/Ingest/EntryCategoryWriter.php` (`resolveCategories()`: drop `$persistedNew` and the flush)
- Modify: `backend/src/Service/Ingestion/Refresh/IncomingPageStore.php` (`store()`: call `refreshStoredEntries()` before `ingest()`)
- Test: `backend/tests/Service/Ingestion/Ingest/EntryCategoryWriterTest.php`
- Test: `backend/tests/Service/Ingestion/Refresh/FeedOutcomePersisterTest.php`

**Interfaces:** No signature changes.

- [ ] **Step 1: Write the failing regression test** in `FeedOutcomePersisterTest`

`testAFailedFirstRefreshThatBroughtANewCategoryIsRetriedInFull()`:
- feed `https://mastodon.example/@someone.rss`; body = `postRss()` with `<category>Europe</category>` added to the item (a new private helper `postRssInCategory()`, or a parameter on `postRss()`)
- persist once through `new FlushFailingEntityManager($this->entityManager)` (the first flush throws) → `assertSame(FeedOutcome::Aborted, …)`
- `$this->entityManager->clear()` to stand in for the rolled-back transaction, then reload the feed
- persist again through `$this->persister($this->entityManager)`
- assert: `Entry` with guid `post-1` is found once; one `ShareEnrichment` row for it, `ShareEnrichmentKind::LinkCards`; one `EntryCategory` row for it

- [ ] **Step 2: Run it and confirm it fails**

Run: `TEST_TOKEN=w2405 php bin/phpunit --filter testAFailedFirstRefreshThatBroughtANewCategoryIsRetriedInFull`
Expected: FAIL on the `ShareEnrichment` count (0 instead of 1), because the category flush already committed the entry.

- [ ] **Step 3: Replace the test that pins the flush** in `EntryCategoryWriterTest`

Replace `testFlushesImmediatelyWhenANewCategoryIsCreated` with `testLeavesANewCategoryForTheCallersFlush`:
- after `attach()` with a new `Politics` category, `assertNull($entry->getId())` on an unflushed entry, same as the sibling no-new-category test
- after `$this->entityManager->flush(); clear()`, one `Category` with `canonicalKey` `politics` and one `EntryCategory` for the entry

- [ ] **Step 4: Remove the flush** in `EntryCategoryWriter::resolveCategories()`. Then move `refreshStoredEntries()` above `ingest()` in `IncomingPageStore::store()`, keeping its comment.

- [ ] **Step 5: Run the ingestion and refresh suites**

Run: `TEST_TOKEN=w2405 php bin/phpunit tests/Service/Ingestion`
Expected: all pass, including both new tests.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Ingestion backend/tests/Service/Ingestion
git commit -m "fix(#2405): a new category no longer commits a refresh's entries before the rest of it"
```

### Task 2: Gates

- [ ] `composer check` and `composer md` (touched files clean); `php bin/phpunit` (SQLite) green.
- [ ] The MySQL leg: the Docker stack belongs to the main checkout, so `docker compose exec php composer test:parallel` cannot run from this worktree. CI's MySQL leg covers it; say so in the PR.
- [ ] `composer infection:diff` on the changed lines, at or above `minMsi`.
- [ ] PhpStorm inspections can't see `.claude/worktrees/`: say so in the report rather than claiming a clean inspection.
