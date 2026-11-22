# Mastodon `/ap/users/` guid post address (#2464) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Mastodon entry whose guid is the new `https://<host>/ap/users/<numeric id>/statuses/<id>` uri gets a post address that names its status, so deleting your own post, the public thread, self-reply folding, counts, the home-server check and author identity all work for it.

**Architecture:** Every Mastodon post action starts at `MastodonPostAddressRule::findPost`. It returns the absolute guid before the url, and for a new mastodon.social post that guid is the `/ap/` uri, which `MastodonStatusAddress::parseAddress` can't read. `findPost` now prefers a status-shaped address (guid, then url) before a raw absolute one, the same order `MastodonStatusKey::parseEntryKey` uses for keys (#2462). It returns the address string itself, unchanged. Nothing downstream changes.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit.

**Spec:** GitHub issue #2464.

## Global Constraints

- `MastodonStatusAddress` doesn't learn the `/ap/` shape: the numeric id can't be mapped to an account name.
- `findPost` returns one of the entry's own two addresses, verbatim. It never rebuilds or normalises one.
- Clean Code rules from CLAUDE.md. `MastodonPostAddressRule.php` stays PHPMD-clean.

## Review Focus

- A guid that is itself status-shaped (`/users/<name>/statuses/<id>`, the RSS `@` form) still wins over the url, as before. Pinned in Task 1.
- Neither address status-shaped (a Pleroma `/objects/…` guid, a GoToSocial ULID): the absolute guid still wins, then the url. Pinned in Task 1.
- Only the url status-shaped (the `/ap/` case): the url wins. Pinned in Task 1.
- The address reaches the delete path: `requireOwnPostId(findPost(entry))` gives the status id for an `/ap/` guid entry on the account's server. Pinned in Task 1.
- **Known gap, out of scope:** `requireOwnReplyGuids` tombstones a deleted self-reply under `[users-form uri, url]`. A self-reply ingested with an `/ap/` guid isn't covered. A reply deleted on Mastodon doesn't come back from its timeline, and `thread_post` stores no uri to tombstone by, so closing this would need a migration. Recorded in the PR, not fixed.

---

### Task 1: `findPost` prefers a status-shaped address

**Files:**
- Modify: `backend/src/Service/Social/Mastodon/MastodonPostAddressRule.php` (`findPost`)
- Test: `backend/tests/Service/Social/Mastodon/MastodonPostAddressRuleTest.php`; build entries with `App\Tests\...\CanonicalKeyRuleEntries::buildEntry(?string $guid, ?string $url, SocialNetwork)` as `MastodonCanonicalKeyRuleTest` does

**Interfaces:**
- Produces: `findPost(Entry): ?SocialPostAddressModel`, same signature, new preference order.

- [ ] **Step 1: Failing tests**, one data-provider test `testAPostIsAddressedByItsStatusShapedAddressFirst(?string $guid, ?string $url, ?string $address)` asserting `findPost(...)?->address === $address`:
  - `/ap/ guid, web url` → guid `https://mastodon.social/ap/users/1174/statuses/120`, url `https://mastodon.social/@alice/120` → `https://mastodon.social/@alice/120`
  - `status guid wins` → guid `https://mastodon.social/users/alice/statuses/120`, url `https://mastodon.social/@alice/120` → the guid
  - `neither a status` → guid `https://pl.example/objects/9f1c`, url `https://pl.example/notice/9f1c` → the guid
  - `url only` → guid `null`, url `https://pl.example/notice/9f1c` → the url
  - `nothing absolute` → guid `not a url`, url `null` → `null`

  Plus `testAnApGuidPostIsDeletedByItsIdOnTheAccountsServer`: an entry with guid `https://mastodon.example/ap/users/1174/statuses/120` and url `https://mastodon.example/@alice/120`. `requireOwnPostId(findPost($entry), SocialAccounts::mastodon())` is `'120'`.
- [ ] **Step 2:** `php bin/phpunit --filter MastodonPostAddressRuleTest`: the `/ap/` cases FAIL.
- [ ] **Step 3:** Implement. A private static helper picks the first of guid and url that `MastodonStatusAddress::parseAddress` reads, falling back to the current absolute pick.
- [ ] **Step 4:** Same filter PASSES, existing cases unchanged. Also run `--filter 'Mastodon|SocialPostAddresses|OwnPostDeletion|SelfThread'`.
- [ ] **Step 5:** Commit `fix(#2464): a Mastodon post is addressed by its status-shaped address first`.

### Task 2: Gates and live check (controller)

- [ ] `composer check`, PHPMD on the file, `php bin/phpunit`, then `docker compose exec -T -e COMPOSER_PROCESS_TIMEOUT=0 php composer test:parallel`, one after the other.
- [ ] Live, on :4200: open entry 838695 (the v1.10.0 post, `/ap/` guid). Its public thread and counts load, and the dev log shows no "could not be read" for it. Don't delete it.
