# Notification list in a fixed number of queries Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `SocialNotificationRepository::findNewestPerGroup()` loads the newest rows of every shown group in a fixed number of statements instead of one per group.

**Architecture:** One native statement ranks the shown groups' rows with `ROW_NUMBER() OVER (PARTITION BY social_account_id, kind, seen, subject_group_key ORDER BY id DESC)` and returns the ids ranked `<= :perGroup`; one DQL query loads those entities by id, newest first; PHP buckets them under each group's `latestId`. Two statements per page, none for an empty page.

**Tech Stack:** Symfony 8.1, Doctrine ORM/DBAL, MySQL 8.4, SQLite 3.45+ (window functions need 3.25 / MySQL 8.0).

**Spec:** GitHub issue #2411.

## Global Constraints

- Signature and result shape of `findNewestPerGroup(array $groups, int $perGroup): array` stay as today: `latestId => list<SocialNotification>`, newest first; a group whose rows are gone maps to `[]` (or is absent — `NotificationList` filters both the same way).
- Each group is matched on all four of its keys (`accountId`, `kind`, `seen`, `groupKey`) **and** `id <= latestId`, applied *before* ranking, as today.
- The query stays in `src/Repository/` (`QueriesLiveInRepositoriesRule`); native SQL through `$this->getEntityManager()->getConnection()`.
- Existing tests pass unchanged — no loosened assertions.

## Review Focus

- A row arriving between `findGroupPage` and `findNewestPerGroup` (id above the group's `latestId`) must not displace the group's newest three — pinned by the `id <= latestId` test in Task 1.
- Two groups sharing a `groupKey` but differing in `kind` or `seen` must not lend each other rows — existing `testNewestOfGroupsReturnsTheNewestRowsPerGroupNewestFirst` (like vs reply on one key) plus the seen/unseen case in Task 1.
- An empty `$groups` list must issue no statement at all.
- SQLite binds `seen` as an integer and MySQL as tinyint; bind it as `ParameterType::BOOLEAN` / int so both dialects match.
- Group keys up to 512 chars with quotes or `%` must bind as parameters, never be interpolated.

---

### Task 0: Measure the current statements

- [ ] **Step 1:** On the main checkout's dev stack, open the notifications list once, then pull today's `doctrine` lines from the dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs jq -c 'select(.channel=="doctrine")'`) and record the per-group statement with its params.
- [ ] **Step 2:** `EXPLAIN ANALYZE` that statement inlined, for one group and summed over a page, in the `mysql` container against `feedreader`; repeat on SQLite against a copy of the test DB seeded by the suite (or `sqlite3` with `.timer on`). Write the numbers into the PR description draft.

### Task 1: One ranking statement for all groups

**Files:**
- Modify: `backend/src/Repository/SocialNotificationRepository.php` (`findNewestPerGroup`, replaces private `findNewestInGroup`)
- Test: `backend/tests/Repository/SocialNotificationRepositoryTest.php`

**Interfaces:**
- Produces: `findNewestPerGroup(list<Group> $groups, int $perGroup): array<int, list<SocialNotification>>` (unchanged)

- [ ] **Step 1: Write the failing tests**

`testNewestOfGroupsCostsTwoStatementsWhateverTheNumberOfGroups`: create 5 groups (likes on `at://x/1`…`at://x/5`, two rows each), call `findGroupPage`, reset `doctrine.debug_data_holder`, call `findNewestPerGroup($groups, 3)`; assert the holder's `default` statements count is `2`. Repeat with 1 group in the same test (reset between) and assert `2` again.

`testNewestOfNoGroupsRunsNoStatement`: `findNewestPerGroup([], 3)` returns `[]` and the holder records `0` statements.

`testNewestOfAGroupIgnoresRowsAboveItsLatestId`: three likes on `at://x/1`, take the page, then add a fourth like; `findNewestPerGroup($groups, 3)` returns the first three ids, newest first.

`testNewestOfSeenAndUnseenGroupsOnOneKeyStayApart`: two seen + one unseen like on `at://x/1`; each of the two groups gets only its own rows (ids asserted).

- [ ] **Step 2:** `php bin/phpunit tests/Repository/SocialNotificationRepositoryTest.php` — the statement-count test fails with `6` (or `2` for one group passes; five-group assertion fails); the others may already pass.

- [ ] **Step 3: Implement**

Native SQL: `SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY social_account_id, kind, seen, subject_group_key ORDER BY id DESC) AS position FROM social_notification WHERE <OR of per-group tuples>) ranked WHERE position <= :perGroup`, each tuple `(social_account_id = ? AND kind = ? AND seen = ? AND subject_group_key = ? AND id <= ?)` with positional parameters. Then `createQueryBuilder` → `id IN (:ids)` ordered `id DESC`, and bucket each row under the `latestId` of the group its keys match (build a lookup keyed by account/kind/seen/groupKey). Every group appears in the result, `[]` when nothing matched. Guard clause for `$groups === []`.

- [ ] **Step 4:** Run the repository test, then `php bin/phpunit tests/Controller` filtered to notifications (`--filter Notification`) — all green.

- [ ] **Step 5:** `composer check` and `composer md` on the changed file — clean.

- [ ] **Step 6: Commit** `fix(#2411): the notifications list loads every group's newest rows in one ranking statement`

### Task 2: Measure after, both legs

- [ ] **Step 1:** Re-run Task 0's capture with the branch's statement (inline the params from a test run's debug holder, or the dev log once the branch is in the main checkout) and `EXPLAIN ANALYZE` it on MySQL and SQLite; record before/after in the PR.
- [ ] **Step 2:** `php bin/phpunit` (SQLite) and the MySQL leg (`composer test:parallel` against the Docker MySQL from this worktree, or in the main checkout after merge-in) — both green; `composer infection:diff` meets `minMsi`.
