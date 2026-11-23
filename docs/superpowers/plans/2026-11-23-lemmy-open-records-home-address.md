# Lemmy: an Opened Post Records Its Home Address — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Once a Lemmy post has been opened, its "Open original" and "All comments" links go straight to the post on the reader's own instance instead of that instance's search page.

**Architecture:** Opening a Lemmy post already calls `GET /api/entries/{id}/vote` (#2499), which resolves the post on the reader's instance through their account. The voter turns the resolved id into `home/post/<id>`, `DiscussionVotes` stores it on the opened entry (the `entry.discussion_home_url` column from #2496), and the vote JSON returns it. The SPA lays it on the opened entry and its copies; the existing `ownInstanceUrl` rule already prefers a home address. Separately, the rule maps a Mastodon-shaped actor in a Lemmy thread to `home/u/<name>@host`.

**Tech Stack:** Symfony 8.1 / Doctrine / PHPUnit (backend), Angular 20 signals / Jest (frontend).

**Spec:** GitHub issue #2501 (follow-up to #2496; design `docs/superpowers/specs/2026-11-23-own-instance-links-design.md`).

## Global Constraints

- The home address is `LemmyPostKey::buildPostPage(<account service URL>, <post id on that instance>)`: `https://lemmy.example` + post 990 → `https://lemmy.example/post/990`.
- JSON key on the vote answer: `discussionHomeUrl`, `string|null` — the same name as on the entry JSON. A comment vote answers `null`.
- A null home address never erases a stored one (`EntryDiscussion::store` already keeps the stored value on null).
- The SPA never lays a null home address over a known one, and adopts it whatever the vote guard decides (a vote on the wire still wins for `myVote`/`score` only).
- No backfill, no migration: the column exists.
- CLAUDE.md naming rules, PHPMD-clean touched files, no new comments unless they clear the bar.

## Review Focus

1. **A federated copy** (post on `feddit.example`, account on `lemmy.example`): the stored address is the *resolved* home id (990), not the feed instance's id (7). Pinned in Task 1 (`testReadingAFederatedPostStoresItsHomeAddress`).
2. **A comment vote** must not touch the entry's address. Pinned in Task 1 (`testACommentVoteLeavesTheHomeAddressAlone`).
3. **A second open after a stored address**: the answer still carries it; nothing breaks when the address is unchanged. Covered by `EntryDiscussion::store` semantics; Task 1's controller test reads twice.
4. **Copies of the same item** in other lists get the address; a copy that already has it keeps its identity (no needless recompute). Pinned in Task 2.
5. **An older backend answer without the key** (rolling deploy, cached SW bundle): the SPA must treat a missing `discussionHomeUrl` like null. Pinned in Task 2 (existing flushes without the key keep passing, plus `keepsTheHomeAddressWhenTheAnswerHasNone`).

---

### Task 1: The vote answer carries and stores the home address

**Files:**
- Modify: `backend/src/Service/Social/Social/Model/DiscussionVoteResultModel.php`
- Modify: `backend/src/Service/Social/Lemmy/LemmyPostVoter.php`
- Modify: `backend/src/Service/Social/Social/DiscussionVotes.php`
- Modify: `backend/src/Http/DiscussionVoteJson.php`
- Test: `backend/tests/Service/Social/Lemmy/LemmyPostVoterTest.php`, `backend/tests/Service/Social/Social/DiscussionVotesTest.php`, `backend/tests/Http/DiscussionVoteJsonTest.php`, `backend/tests/Controller/Api/EntryVoteControllerTest.php`

**Interfaces:**
- Produces: `DiscussionVoteResultModel(VoteScore $myVote, int $score, ?string $homeUrl = null)`; vote JSON `{myVote: int, score: int, discussionHomeUrl: string|null}` from `PUT|GET /api/entries/{id}/vote` and `PUT /api/entries/{id}/comments/vote`.

- [ ] **Step 1: Write the failing tests**

`LemmyPostVoterTest`:
- `testAReadPostCarriesItsHomeAddress` — `fetchPostVoteResult` on the home-instance post 42 → `homeUrl === 'https://lemmy.example/post/42'`.
- `testACastVoteCarriesTheHomeAddressOfThePostItLiked` — `castVoteOnPost` → `homeUrl` built from the `post_view.post.id` in the like answer.
- `testACommentVoteCarriesNoHomeAddress` — `castVoteOnComment` → `homeUrl === null`.

`DiscussionVotesTest`:
- `testASyncStoresTheHomeAddressOnTheEntry` — voter double answers `new DiscussionVoteResultModel(VoteScore::Up, 5, 'https://lemmy.example/post/9')` → `$entry->getDiscussion()->homeUrl === 'https://lemmy.example/post/9'` after `syncFromNetwork`.
- `testAVoteStoresTheHomeAddressOnTheEntry` — same through `vote()`.
- `testACommentVoteLeavesTheHomeAddressAlone` — entry carrying `https://lemmy.example/post/9`, comment vote → unchanged.

`DiscussionVoteJsonTest::testAResultCarriesTheVoteAndTheScore` → expected `['myVote' => 1, 'score' => 7, 'discussionHomeUrl' => null]`; add `testAResultCarriesTheHomeAddress` with a non-null address.

`EntryVoteControllerTest`:
- every exact payload assertion gains its `discussionHomeUrl` (home-instance post 42 → `https://lemmy.example/post/42`; comment votes → `null`).
- `testReadingAFederatedPostStoresItsHomeAddress` — routes as in `testAVoteOnAFederatedCopyActsOnTheResolvedPost` but `GET` the vote; payload `discussionHomeUrl === 'https://lemmy.example/post/990'`, and the entry re-read from the database carries the same address.

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit --filter 'LemmyPostVoterTest|DiscussionVotesTest|DiscussionVoteJsonTest|EntryVoteControllerTest'`
Expected: FAIL — unknown named argument / missing `discussionHomeUrl` key.

- [ ] **Step 3: Implement**

- `DiscussionVoteResultModel`: add `public ?string $homeUrl = null`.
- `LemmyPostVoter`: post results (cast and read) are built with `LemmyPostKey::buildPostPage($account->getServiceUrl(), $target->postId)`; comment results stay without one. Split `buildResult` into a post and a comment builder rather than passing a flag.
- `DiscussionVotes`: `vote()` and `syncFromNetwork()` record `$result->homeUrl` on the entry through `$entry->setDiscussion($entry->getDiscussion()->withHomeUrl(...))` before `adopt()` flushes. `voteOnComment()` does not.
- `DiscussionVoteJson::result()`: add `'discussionHomeUrl' => $result->homeUrl`; update the array-shape docblock.

- [ ] **Step 4: Run them and watch them pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "fix(#2501): opening a lemmy post stores and returns its home address"
```

### Task 2: The SPA adopts the home address

**Files:**
- Modify: `frontend/src/app/reader/social-post-api.ts` (`VoteAnswer`)
- Modify: `frontend/src/app/reader/state/entry-row-cache.ts`
- Test: `frontend/src/app/reader/state/entries.store.spec.ts` (vote `describe`)

**Interfaces:**
- Consumes: Task 1's JSON.
- Produces: `VoteAnswer { myVote: VoteValue; score: number; discussionHomeUrl?: string | null }`.

- [ ] **Step 1: Write the failing specs** (in the existing vote `describe`, using `loadVotable` and `votePath`)

- `adoptsTheHomeAddressTheReadReports` — `refreshVote`, flush `{ myVote: 0, score: 42, discussionHomeUrl: 'https://home.test/post/9' }` → `store.entries()[0].discussionHomeUrl === 'https://home.test/post/9'`.
- `adoptsTheHomeAddressEvenWhenAVoteIsOnTheWire` — as `lets a vote on the wire win over the network report`, GET answer carries the address → vote/score stay `{1, 10}`, address adopted.
- `laysTheHomeAddressOnEveryCopyOfTheItem` — two rows sharing a `canonicalItemId` → both carry it.
- `adoptsTheHomeAddressACastVoteReports` — `setVote` PUT answer carries it → adopted.
- `keepsTheHomeAddressWhenTheAnswerHasNone` — row starts with an address, answer without the key → unchanged and the row keeps its object identity (`toBe` the same reference).

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/entries.store.spec.ts`
Expected: the five new specs FAIL.

- [ ] **Step 3: Implement** a private `adoptHomeUrl(entryId: number, homeUrl: string | null | undefined): void` in `EntryRowCache`, called from `refreshVote`'s and `setVote`'s `next`; it patches `collectIdsWithCopies([entryId])`, skips when `homeUrl` is null/undefined, and hands back a row that already carries it unchanged.

- [ ] **Step 4: Run them and watch them pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader
git commit -m "fix(#2501): an opened lemmy post's links adopt its home address"
```

### Task 3: A Mastodon-shaped actor in a Lemmy thread opens on the home Lemmy

**Files:**
- Modify: `frontend/src/app/reader/own-instance/own-instance-url.ts` (`lemmyTarget`)
- Test: `frontend/src/app/reader/own-instance/own-instance-url.spec.ts`

- [ ] **Step 1: Write the failing specs**

- `opensAMastodonAuthorOfALemmyThreadOnTheHomeLemmy` — `ownInstanceUrl('https://expressional.social/users/Peter_Link', { network: 'lemmy' }, instances)` → `'https://home.lemmy/u/Peter_Link@expressional.social'` (use the spec file's Lemmy instance constant).
- `leavesAUsersStatusPathOutOfTheLemmyRule` — `https://host.example/users/name/statuses/1` with `network: 'lemmy'` stays unchanged.

- [ ] **Step 2: Run them and watch them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/own-instance`
Expected: the first spec FAILS (address returned unchanged).

- [ ] **Step 3: Implement** in `lemmyTarget`: a path matching `/^\/users\/([^/]+)$/` returns `${instance}/u/${name}@${target.host}`.

- [ ] **Step 4: Run them and watch them pass**

Same command. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/own-instance
git commit -m "fix(#2501): a mastodon author in a lemmy thread opens on the home lemmy"
```

### Task 4: Gates and live check

- [ ] `cd backend && composer check && composer md` on touched files; `php bin/phpunit` (SQLite) and `docker compose exec php composer test:parallel` (MySQL); `composer infection:diff`.
- [ ] `docker compose exec -T frontend npm run check`.
- [ ] Live on :4200 with a Lemmy account: open a federated post, then confirm "Open original" and "All comments" read `home/post/<id>`.
