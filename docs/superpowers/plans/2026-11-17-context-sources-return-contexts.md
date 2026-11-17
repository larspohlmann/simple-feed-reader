# Context Sources Return Contexts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Notification context sources return `array<string, NotificationContext>` keyed by remote id; the page applies it in one pass (#2300).

**Architecture:** `NotificationPageModel::withContexts()` replaces `withSubjectsOf()`; the interface method `withContext()` becomes `contextsOf()`; `FetchedNotificationModel::withSubject()` goes. Behaviour is unchanged.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-context-sources-return-contexts-design.md`

## Global Constraints

- No behaviour change: same HTTP calls, same contexts stored.
- CLAUDE.md house style: `final readonly`, withers use `clone($this, [...])` with `#[\NoDiscard]`, no abbreviations, no comment unless it clears the bar.
- Every touched `src` file PHPMD-clean; `composer check` green; no new classes.

## Review Focus

- A page holding the same remote id twice (seen and unseen copy) gets the context on both rows — kept from today's `withSubjectsOf` test.
- A key for a remote id not on the page is ignored, not appended.
- Lemmy throttle mid-batch: rows before the throttle keep their context, rows after get no key and no further calls are made.
- A source given only rows without references makes no HTTP call and returns `[]`.
- The returned context builds on the row's existing context (Lemmy's thread title/place survive the answered excerpt).

---

### Task 1: Page applies a context map

**Files:**
- Modify: `backend/src/Service/Social/Notification/Model/NotificationPageModel.php`
- Modify: `backend/src/Service/Social/Notification/Model/FetchedNotificationModel.php`
- Test: `backend/tests/Service/Social/Notification/Model/NotificationPageModelTest.php`

**Interfaces:**
- Produces: `NotificationPageModel::withContexts(array<string, NotificationContext> $contexts): self` (`#[\NoDiscard]`); `FetchedNotificationModel::withContext(NotificationContext $context): self` as a single clone.

- [ ] **Step 1:** Replace `testWithSubjectsOfSwapsSubjectsByRemoteId` with `testWithContextsSetsTheContextOfEveryRowWithThatRemoteId`: same page (`a` unseen, `a` seen, `b`), call `$page->withContexts(['a' => $context, 'z' => new NotificationContext('ignored')])`; assert contexts `[$context, $context, new NotificationContext()]`, seen flags `[false, true, false]`, three rows, `seenUntil` kept.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Social/Notification/Model/NotificationPageModelTest.php` — FAIL (undefined method).
- [ ] **Step 3:** Add `withContexts()` (map each row: key present → `$row->withContext($contexts[$row->remoteId])`, else the row). Make `FetchedNotificationModel::withContext()` clone with `['subject' => $this->subject->withContext($context)]` and delete `withSubject()`. Leave `withSubjectsOf()` in place until Task 2, rewritten to call `withContext($subjects[...]->context)` so `withSubject()` can go now.
- [ ] **Step 4:** Rerun the test — PASS.
- [ ] **Step 5:** Commit `refactor(#2300): the notification page applies contexts keyed by remote id`.

### Task 2: Sources return contexts

**Files:**
- Modify: `backend/src/Service/Social/Notification/NotificationContextSource/NotificationContextSourceInterface.php`
- Modify: `…/NotificationContextSource/BlueskyNotificationContextSource.php`, `MastodonNotificationContextSource.php`, `LemmyNotificationContextSource.php`
- Modify: `backend/src/Service/Social/Notification/NotificationContextLookup.php`
- Modify: `backend/src/Service/Social/Notification/Model/NotificationPageModel.php` (delete `withSubjectsOf()`)
- Modify: `backend/tests/Support/RecordingContextSource.php`
- Test: the three `tests/Service/Social/Notification/NotificationContextSource/*Test.php`

**Interfaces:**
- Consumes: `NotificationPageModel::withContexts()` from Task 1.
- Produces: `NotificationContextSourceInterface::contextsOf(SocialAccount $account, array $fresh): array` with `@param non-empty-list<FetchedNotificationModel> $fresh`, `@return array<string, NotificationContext>` keyed by remote id, `@throws SocialCredentialsRejectedException`. Docblock: only rows whose context was found get a key, the value builds on the row's existing context; no call when no row has references; a failed lookup, a missing token scope included, leaves the key out; only a rejected credential propagates.

- [ ] **Step 1:** Reshape the three source tests to call `contextsOf()` and assert on the map: a found context is `$contexts['reply:7']->answeredExcerpt` etc.; every "leaves the row unchanged" test becomes `assertSame([], $contexts)` or `assertArrayNotHasKey(<id>, $contexts)`; multi-row tests assert `array_keys($contexts)` exactly. Lemmy throttle test asserts the keys stop at the throttled row and the request count is unchanged. Keep every existing test's scenario and HTTP expectations.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Social/Notification/NotificationContextSource` — FAIL (undefined method).
- [ ] **Step 3:** Rename the interface method and implement:
  - Bluesky: build `contextOf(...)` per row returning `?NotificationContext` (null when neither own-answered nor thread root was found), collect non-null into the map; unreachable → `[]`.
  - Mastodon: `answered()` returns `?NotificationContext`; collect non-null by remote id.
  - Lemmy: loop with `continue` for no answered id or null excerpt, `break` on `SocialNetworkThrottledException`; keep the per-id excerpt cache.
  - `NotificationContextLookup`: `return $page->withContexts($source->contextsOf($account, $fresh));`
  - Delete `NotificationPageModel::withSubjectsOf()`.
  - `RecordingContextSource::contextsOf()` records the ids and returns `[]` when `$adds` is null, else `[remoteId => $adds]` for each row.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/Social/Notification tests/Controller/Api/SocialNotificationControllerTest.php` — PASS; `grep -rn "withSubjectsOf\|withSubject(\|->withContext(\$account" src tests` — no hits.
- [ ] **Step 5:** From `backend/`: `composer check`, `composer md` on the touched files, `php bin/phpunit` and `docker compose exec php composer test:parallel` — all green.
- [ ] **Step 6:** Commit `refactor(#2300): notification context sources return only the contexts they found`.
