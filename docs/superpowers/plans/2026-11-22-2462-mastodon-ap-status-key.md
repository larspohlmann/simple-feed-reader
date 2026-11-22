# Mastodon `/ap/users/` status key (#2462) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Mastodon notification on a status whose `uri` has the new `/ap/users/<numeric id>/statuses/<id>` form stores the same canonical-key hash as the entries for that status, so the row opens the reader entry.

**Architecture:** Entries already key a status from guid and url (`MastodonStatusKey::parseEntryKey`); the status-address form of either wins over a raw absolute address. Notifications key from `uri` alone. Add one `MastodonStatusKey::parseStatusKey(JsonNodeModel $status)` that reads a status's `uri` and `url` through `parseEntryKey`, and use it at all three notification call sites. Then re-hash the stored local rows.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit.

**Spec:** GitHub issue #2462 (root cause and evidence there).

## Global Constraints

- No new key shape: `MastodonStatusAddress` stays as it is. The numeric `/ap/users/<id>` part can't be mapped to an account name, so the `url` fallback is the fix.
- The private `MastodonStatusKey::parseStatusKey(?string)` is renamed to `parseAddressKey(?string)` so the public name is free.
- Clean Code rules from CLAUDE.md. Every touched `src` file stays PHPMD-clean.

## Review Focus

- A status with no `url` (older servers, the existing fixture): the key stays what `parseKey(uri)` gave before. Existing tests pin this.
- A status with `uri` in the `/users/` form and a `url`: same key as before (status form from uri wins).
- A quote whose quoted status has the `/ap/` uri: the answered hash must come from the quoted status's own `url`. Task 2's test pins it.
- A reply's answered status fetched in the context source with an `/ap/` uri: Task 2's test pins it.
- Lemmy and Bluesky keys: untouched.

---

### Task 1: `MastodonStatusKey::parseStatusKey`

**Files:**
- Modify: `backend/src/Service/Social/Mastodon/Support/MastodonStatusKey.php`
- Test: `backend/tests/Service/Social/Mastodon/Support/MastodonStatusKeyTest.php`

**Interfaces:**
- Produces: `public static function parseStatusKey(JsonNodeModel $status): ?CanonicalKeyModel` (returns `parseEntryKey($status->string('uri'), $status->string('url'))`)

- [ ] **Step 1: Failing test** `testAStatusOnAnApAddressTakesItsKeyFromItsPage`:
  `parseStatusKey(JsonNodeModel::fromValue(['uri' => 'https://mastodon.example/ap/users/1174/statuses/123', 'url' => 'https://mastodon.example/@alice/123']))?->key`
  `=== 'https://mastodon.example/users/alice/statuses/123'`.
  Also `testAStatusWithoutAPageKeepsItsUri`: `['uri' => 'https://mastodon.example/users/alice/statuses/123']` gives that same key.
- [ ] **Step 2:** `php bin/phpunit --filter MastodonStatusKeyTest`: FAIL, undefined method.
- [ ] **Step 3:** Implement it. Rename the private helper to `parseAddressKey`.
- [ ] **Step 4:** Same filter: PASS.

### Task 2: Notification sources use the status key

**Files:**
- Modify: `backend/src/Service/Social/Mastodon/MastodonNotificationSource.php` (`buildKeys`)
- Modify: `backend/src/Service/Social/Mastodon/MastodonNotificationContextSource.php` (`findAnsweredSubject`)
- Create: `backend/tests/Fixtures/Mastodon/notifications-ap-uri.json`: one `favourite` (status `id` 7001, `uri` `https://mastodon.example/ap/users/1174/statuses/7001`, `url` `https://mastodon.example/@alice/7001`) and one `quote` whose `quote.quoted_status` has `uri` `https://mastodon.example/ap/users/1174/statuses/7002` and `url` `https://mastodon.example/@alice/7002`.
- Test: `MastodonNotificationSourceTest`, `MastodonNotificationContextSourceTest`

**Interfaces:**
- Consumes: `MastodonStatusKey::parseStatusKey(JsonNodeModel)` from Task 1.

- [ ] **Step 1: Failing tests.**
  - `testAStatusOnAnApAddressCarriesTheKeyOfItsPage` (source, LIST routed to the new fixture): the like's `postHash` is `MastodonStatusKey::parseKey('https://mastodon.example/@alice/7001')?->keyHash()`. The quote's `answeredHash` is the same thing for `@alice/7002`.
  - `testAnAnsweredStatusOnAnApAddressCarriesTheKeyOfItsPage` (context source, inline answer `[{id: 4000, uri: …/ap/users/1174/statuses/4000, url: …/@alice/4000}]`): `answeredHash` is `parseKey('https://mastodon.example/@alice/4000')?->keyHash()`.
- [ ] **Step 2:** Run both test classes: the new tests FAIL (hash of the raw `/ap/` address).
- [ ] **Step 3:** Replace the three `parseKey($node->string('uri'))` calls with `parseStatusKey($node)`. For a quote, `buildKeys` passes the `quoted_status` node; for any other kind, the answered hash is null.
- [ ] **Step 4:** Both classes PASS, existing tests unchanged.
- [ ] **Step 5:** Commit `fix(#2462): a Mastodon notification keys a status from its page too`.

### Task 3: Gates and local re-hash

- [ ] `composer check`, `composer md` on the touched files, `php bin/phpunit`, `docker compose exec php composer test:parallel`: all green.
- [ ] Re-hash local Mastodon notifications with a throwaway script: for each row with a `post_key_hash`, get the status from the account's API, recompute the keys with `parseStatusKey`, and update. Uncommitted, deleted afterwards. Expected: notification 195 resolves to an entry of canonical item 22064.
- [ ] Live check on :4200: the v1.10.0 like opens `?entry=` in the same tab.
