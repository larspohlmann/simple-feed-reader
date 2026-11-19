# Social Import and Link-Post Leftovers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The import rule takes no parameter it never reads, the follow matcher takes the import rule once, and Lemmy's title limits live in the Lemmy module.

**Architecture:** Three signature changes, one commit each. No class is added or moved.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit, PHPStan, PHPMD, phptramp.

**Spec:** GitHub issue #2375.

## Global Constraints

- No behaviour, API or JSON change. The composer context still emits `minTitleCharacters: 3` and `maxTitleCharacters: 200`; the refusal text `A Lemmy title needs at least 3 characters.` stays byte-identical.
- Every existing test survives; tests change only where a signature does.
- Per task: `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No new `src` class. No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no phptramp threshold or baseline change.
- No `…Of` method is renamed (#2360) or added. `composer cs` now gates import order; run `composer cs:fix` before each commit.
- Commits `refactor(#2375): …`. No attribution lines.

## Decisions

- **Point 4, the phptramp warning on `$feedUrl`: closed, no change.** The chain `buildExportUrl()` → `BlueskyProfileActor::parseAuthorDid()` → `SocialFeedUrl::parseAuthor()` is a URL read through three parsers that each narrow the answer, the same shape as four other parse-chain warnings in the tree. It is below the fail limit. Shortening it means copying `parseAuthorDid()`'s DID check back into the rule, which #2367's review just removed. The PR text says so.
- **Point 3: the limits are constants of `LemmyPostWriter`**, the class that enforces them; `LinkPostChoicesModel` carries them as fields.
- **Point 2: `FollowMatching` asks its own rule.** The pass already holds the import rule; the matcher stops taking it.

## Review Focus

- **Import with the network unreachable mid-resolution.** `FollowMatcher` must still stop resolving at the first `SocialNetworkUnreachableException` and skip a `SocialAccountUnresolvableException`; the existing `FollowMatcherTest` cases for both must pass with only their call signature changed.
- **The resolution cap.** At most 25 leftover identities are resolved per load; the constant stays in `FollowMatcher`.
- **Composer context JSON.** `ComposerContextJsonTest` asserts both limits by value, not by constant.
- **A Lemmy title of 2 and of 201 characters.** The existing `LemmyPostWriterTest` cases keep their expected texts.
- **Fakes and doubles of `ImportRuleInterface`.** Every implementation in `tests/` loses the parameter too; PHPStan finds them.

---

### Task 1: `findAccountOnlyFeeds()` takes the feeds only

**Files:**
- Modify: `backend/src/Service/Social/Social/ImportRule/ImportRuleInterface.php`
- Modify: `backend/src/Service/Social/Bluesky/BlueskyImportRule.php`, `backend/src/Service/Social/Mastodon/MastodonImportRule.php`, `backend/src/Service/Social/Lemmy/LemmyImportRule.php`
- Modify: `backend/src/Service/Social/Social/SocialImporter.php:48`
- Test: `backend/tests/Service/Social/{Bluesky,Mastodon,Lemmy}/*ImportRuleTest.php` and any double of the interface

**Interfaces:**
- Produces: `ImportRuleInterface::findAccountOnlyFeeds(array $feeds): array` — `@param list<SocialImportFeedModel> $feeds`, `@return array<string, PrivateFeedModel>` as today.

- [ ] **Step 1:** Change the three test calls to pass the feeds only. Run `php bin/phpunit tests/Service/Social --filter ImportRule`. Expected: FAIL (argument type or count).
- [ ] **Step 2:** Drop `SocialAccount $account` from the interface and the three rules, and the argument in `SocialImporter`. Remove imports that become unused.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green.
- [ ] **Step 4:** Commit `refactor(#2375): the import rule takes no account it never reads`.

### Task 2: The follow matcher takes the import rule once

**Files:**
- Modify: `backend/src/Service/Social/Social/Pass/FollowMatching.php`
- Modify: `backend/src/Service/Social/Social/FollowMatcher.php`
- Modify: `backend/src/Service/Social/Social/ImportCandidates.php` (the one caller of `match()`)
- Test: `backend/tests/Service/Social/Social/FollowMatcherTest.php`, `backend/tests/Service/Social/Social/Pass/FollowMatchingTest.php` if it exists

**Interfaces:**
- Produces: `FollowMatching::claimResolved(ProfileFeedSubscriptionModel $profileFeed): void` — fetches the resolved identity from the pass's own import rule and claims the feed under it; `@throws SocialAccountUnresolvableException`, `@throws SocialNetworkUnreachableException`.
- `FollowMatching::claimAs()` becomes private when `claimResolved()` is its only caller in `src` and `tests`; otherwise it stays public.
- `FollowMatcher::match(FollowMatching $matching, array $profileFeeds): array` — `@param list<ProfileFeedSubscriptionModel> $profileFeeds`, `@return list<ImportCandidateModel>`.

- [ ] **Step 1:** Change the `match()` calls in `FollowMatcherTest` to two arguments. Run it. Expected: FAIL.
- [ ] **Step 2:** Add `claimResolved()`, make the matcher's loop call it and keep both `catch` arms and `MAX_IDENTITY_RESOLUTIONS` where they are; drop the parameter from `match()` and from the call in `ImportCandidates`.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green.
- [ ] **Step 4:** Commit `refactor(#2375): the follow matching asks its own import rule`.

### Task 3: Lemmy's title limits live with Lemmy

**Files:**
- Modify: `backend/src/Service/Social/Social/Model/LinkPostChoicesModel.php`
- Modify: `backend/src/Service/Social/Lemmy/LemmyPostWriter.php`, `backend/src/Service/Social/Lemmy/LemmyComposerDefaults.php`
- Modify: `backend/src/Http/ComposerContextJson.php:76-77`
- Test: `backend/tests/Http/ComposerContextJsonTest.php`, `backend/tests/Service/Social/Lemmy/LemmyComposerDefaultsTest.php`, `backend/tests/Support/FakeLemmyComposerDefaults.php`

**Interfaces:**
- Produces: `LemmyPostWriter::MIN_TITLE_CHARACTERS = 3` and `LemmyPostWriter::MAX_TITLE_CHARACTERS = 200`, both `public const int`.
- Produces: `new LinkPostChoicesModel(array $communities, string $title, int $minTitleCharacters, int $maxTitleCharacters)` with all four as public readonly properties; the two class constants are removed.

- [ ] **Step 1:** In `ComposerContextJsonTest` build the model with `3, 200` changed to `4, 150` and assert `minTitleCharacters` is 4 and `maxTitleCharacters` is 150 in the JSON; in `LemmyComposerDefaultsTest` assert the built model carries 3 and 200. Run both. Expected: FAIL (too many arguments).
- [ ] **Step 2:** Move the constants to `LemmyPostWriter`, add the two fields, have `LemmyComposerDefaults` fill them from the writer's constants and `ComposerContextJson` read the fields; `FakeLemmyComposerDefaults` passes `3, 200`.
- [ ] **Step 3:** Run `grep -rn 'LinkPostChoicesModel::M' backend/src backend/tests`. Expected: no output.
- [ ] **Step 4:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green.
- [ ] **Step 5:** Commit `refactor(#2375): Lemmy's title limits are the Lemmy writer's`.

### Task 4: PR

- [ ] **Step 1:** Run `composer tramp`. Expected: 0 errors, the same 5 warnings as on develop.
- [ ] **Step 2:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 3:** Scan today's `backend/var/log/dev-*.log`; run `/simplify` on the branch.
- [ ] **Step 4:** Push, open the PR into `develop` with `Closes #2375`; the body records point 4 as closed with the reason under Decisions. Merge when CI is green; verify #2375 closed.
