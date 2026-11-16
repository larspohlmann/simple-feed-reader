# "Boosted by" Names the Booster's Handle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A newly ingested Mastodon boost stores `Boosted by <a href="{profile}">@user@host</a>`, so the #2261 body rule opens the booster on the reader's instance.

**Architecture:** One caller changes: `MastodonTimelinePage::withBoostAttribution` names the booster by `'@' . MastodonAuthor::handle()`, falling back to `MastodonAuthor::name()`. `ShareAttribution`, Bluesky and the frontend stay as they are.

**Tech Stack:** Symfony 8.1 / PHP 8.5, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-16-boosted-by-handle-design.md`

## Global Constraints

- Link text is exactly `@` + `MastodonAuthor::handle($booster)`; a local account's handle is completed with its profile host by `handle()` itself.
- No handle → the display name, as today. No name and no handle → no line.
- Clean Code rules of `CLAUDE.md`; no new comments; commit format `type(#2267): …`.

## Review Focus

1. A remote booster (`acct` = `carol@other.example`) — text is `@carol@other.example`, not doubled (Task 1 test).
2. A booster with an acct but an unusable profile URL — `@carol` as plain text, no link (Task 1 test).
3. The existing "no name" case — still no line at all (existing provider case stays green).

---

### Task 1: Name the booster by handle

**Files:**
- Modify: `backend/src/Service/Social/Social/Support/MastodonTimelinePage.php` (`withBoostAttribution`, ~line 186)
- Test: `backend/tests/Service/Social/Social/Support/MastodonTimelinePageTest.php`

- [ ] **Step 1: Update and add failing tests.**
  - `testABoostShowsTheOriginalWithATrailingAttributionAtTheBoostTime`: expected line becomes `'<p>Boosted by <a href="https://mastodon.example/@carol">@carol@mastodon.example</a></p>'`.
  - `malformedBoosters` gains:
    - `'a remote handle'` → `['acct' => 'carol@other.example', 'display_name' => 'Carol', 'url' => 'https://other.example/@carol']` → `self::CONTENT . '<p>Boosted by <a href="https://other.example/@carol">@carol@other.example</a></p>'`
    - `'a handle but no web page'` → `['acct' => 'carol', 'display_name' => 'Carol', 'url' => 'javascript:alert(1)']` → `self::CONTENT . '<p>Boosted by @carol</p>'`
  - The existing `'no name'` and `'a name but no web page'` cases (no `acct`) stay as they are.
- [ ] **Step 2:** `php bin/phpunit tests/Service/Social/Social/Support/MastodonTimelinePageTest.php` from `backend/` — expect FAIL on the first test and the two new cases.
- [ ] **Step 3:** In `withBoostAttribution`, name the booster with a private static `boosterName(JsonNodeModel $booster): ?string` returning `'@' . $handle` when `MastodonAuthor::handle($booster)` is not null, else `MastodonAuthor::name($booster)`.
- [ ] **Step 4:** Re-run — expect PASS. Then `composer cs`, `composer stan`, `composer md` (after `rm -rf ~/.pdepend`) — clean.
- [ ] **Step 5:** Commit `feat(#2267): a boost's attribution names the booster by handle`.
