# Social Module Leftovers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Outside the network modules, only the per-network connect requests, the Bluesky problem mapper and the hidden-profile wiring name a network module.

**Architecture:** Four small changes, each on its own commit: the Bluesky exception gets its own problem mapper; the visibility value list moves to the generic model that holds the field; the entry list asks the Lemmy profile feed rule through `ProfileFeeds`; the moved tests read fixtures through `ReadsFixtures`.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2377.

## Global Constraints

- No behaviour, API, JSON or problem-text change. Problem type `bluesky_profile_hidden`, title `Profile hidden from logged-out readers`, status 422 and the accepted visibilities `public`, `unlisted`, `private`, `direct` stay exactly as they are.
- Every existing test survives; a test case may move to another test class, none is deleted.
- Per task: `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression. The one new `src` class is `BlueskyProblems`; any other needs Lars's "approve classes" in the implementing session.
- Commits `refactor(#2377): …` (`test(#2377): …` for a test-only commit). No attribution lines.
- No `…Of` method is renamed here (#2360), and none is added.

**Out of scope:** #2375 and #2360; the `Completion` imports of `Social/BlueskyPost`; `Operations` and `Http` naming the hidden-profile switch; frontend.

## Decisions

- **Point 5, enum names: closed, no change.** docs/architecture.md §10 says `Model/` holds "the module's enums (plain names)", so `ProfileVisibility`, `HiddenProfileMoveOutcome`, `FillOutcome` and `LemmyListing` are named correctly.
- **Point 6, import order: closed, not done.** 551 of 3613 PHP files in `src`, `tests` and `migrations` have an unsorted `use` block, 201 of them in social code, and no gate checks it. A pass over the 80 moved files leaves 470 and decays; a gate needs a new dev dependency and a tree-wide commit, which is Lars's call and not part of this issue. The PR text says so.
- **Point 2: the list lives on `PostDraftModel`.** The model that carries `$visibility` owns its allowed values; no new class.
- **Point 3: `selectRule(SocialNetwork::Lemmy)`, not `findRule($feedUrl)`.** `findRule()` answers for whichever network recognises the URL, which is a different question from "is this a Lemmy community feed".

## Review Focus

- **A hidden profile through the real resolver.** `BlueskyProblems` must be picked up by the tag; `tests/Controller/Api/SubscriptionHiddenProfileTest` pins the 422 end to end and must stay green untouched.
- **An unknown visibility is still refused with the same violation.** `Assert\Choice` on both draft requests; Task 2 pins it before the change.
- **A Lemmy post in its own community's feed shows no community; in any other feed it does.** Task 3 pins both before the change, including a feed URL in mixed case against the lower-cased stored identity.
- **A non-Lemmy feed URL carrying a Lemmy post** (a Lemmy post in an RSS feed): the community shows. Covered by the same Task 3 test.
- **Fixture reads in static data providers.** `ReadsFixtures::fixture()` is an instance method; Task 4 says how providers use it.

---

### Task 1: `BlueskyProblems`

**Files:**
- Create: `backend/src/Http/Problem/ExceptionProblems/BlueskyProblems.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php` (drop the `BlueskyProfileHiddenException` arm and import)
- Create: `backend/tests/Http/Problem/ExceptionProblems/BlueskyProblemsTest.php`
- Modify: `backend/tests/Http/Problem/ExceptionProblems/SocialProblemsTest.php`

**Interfaces:**
- Produces: `final readonly class BlueskyProblems implements ExceptionProblemsInterface` with `resolve(\Throwable $exception): ?ResolvedProblem`; null for anything but `App\Service\Social\Bluesky\Exception\BlueskyProfileHiddenException`.

- [ ] **Step 1:** Move `testAHiddenProfileIsAnUnprocessableRequestThatSaysWhatToDo` from `SocialProblemsTest` to the new `BlueskyProblemsTest`, building `new BlueskyProblems()`; keep its three assertions. Add `testAnotherExceptionBelongsToAnotherModule`: `self::assertNull((new BlueskyProblems())->resolve(new \RuntimeException('x')))`. In `SocialProblemsTest` add `testAHiddenBlueskyProfileBelongsToAnotherModule` asserting `SocialProblems` resolves that exception to null.
- [ ] **Step 2:** Run `php bin/phpunit tests/Http/Problem/ExceptionProblems`. Expected: FAIL, class `BlueskyProblems` not found.
- [ ] **Step 3:** Write `BlueskyProblems` with the arm moved verbatim; remove it from `SocialProblems`.
- [ ] **Step 4:** Run `php bin/phpunit tests/Http/Problem/ExceptionProblems tests/Controller/Api/SubscriptionHiddenProfileTest.php`. Expected: PASS.
- [ ] **Step 5:** Run `grep -rn 'Social\\\(Bluesky\|Mastodon\|Lemmy\)\\' backend/src/Http/Problem/ExceptionProblems/SocialProblems.php backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`. Expected: no output. If `SocialInteractionProblems` has a hit, stop and report it to the planner.
- [ ] **Step 6:** `composer check && composer md && php bin/phpunit`; commit `refactor(#2377): the hidden-profile problem has a Bluesky mapper`.

### Task 2: The visibility list belongs to the draft

**Files:**
- Modify: `backend/src/Service/Social/Social/Model/PostDraftModel.php`
- Modify: `backend/src/Dto/Social/PostDraftRequest.php`, `backend/src/Dto/Social/LinkPostDraftRequest.php`
- Modify: `backend/src/Service/Social/Mastodon/Support/MastodonVisibility.php`
- Test: the controller tests that post a draft (find them with `grep -rln "'visibility'" backend/tests/Controller`)

**Interfaces:**
- Produces: `PostDraftModel::VISIBILITIES` — `public const array VISIBILITIES = ['public', 'unlisted', 'private', 'direct'];`, with the one-line comment `/** From the most open to the most closed. */` (Mastodon's ordering reads it).
- `MastodonVisibility::ALL` is removed; `closedness()` and `known()` read `PostDraftModel::VISIBILITIES`.

- [ ] **Step 1:** Pin first. If no controller test posts a draft and a link-post draft with `visibility: 'everyone'` and asserts the 422 validation problem, add one for each request to the existing controller test class and commit `test(#2377): pin the refused visibility`. Expected: PASS against unchanged code.
- [ ] **Step 2:** Add the constant, switch both `#[Assert\Choice(choices: …)]` to `PostDraftModel::VISIBILITIES`, drop the `MastodonVisibility` import from both requests, and make `MastodonVisibility` read the constant.
- [ ] **Step 3:** Run `grep -rn 'MastodonVisibility' backend/src/Dto backend/tests | grep -v 'tests/Service/Social/Mastodon'`. Expected: no output.
- [ ] **Step 4:** `composer check && composer md && php bin/phpunit`; commit `refactor(#2377): the draft owns its visibilities`.

### Task 3: The entry list asks the Lemmy profile feed rule

**Files:**
- Modify: `backend/src/Service/Library/EntryList/ConnectedAccountStates.php`
- Test: `backend/tests/Service/Library/EntryList/ConnectedAccountStatesTest.php` (create if no test already builds states for a Lemmy post)

**Interfaces:**
- Consumes: `App\Service\Social\Social\ProfileFeeds::selectRule(SocialNetwork $network): ProfileFeedRuleInterface` and `ProfileFeedRuleInterface::parseIdentity(string $feedUrl): ?string`.
- `ConnectedAccountStates::__construct` gains `private ProfileFeeds $profileFeeds` as its last parameter; `buildStates()` keeps its signature.

- [ ] **Step 1:** Pin first, against unchanged code, as a kernel test that takes `ConnectedAccountStates` from the container. Three cases, each an entry with an `EntryLemmyPost` whose community identity is `technology@lemmy.world`: (a) in the feed `LemmyProfileFeed::buildUrl('technology', 'lemmy.world')` the row's community is null; (b) the same feed URL with the community name upper-cased gives null too; (c) in the feed `https://example.com/feed.xml` the row carries the community. Commit `test(#2377): pin the Lemmy community of an entry row`. Expected: PASS.
- [ ] **Step 2:** Inject `ProfileFeeds`; the private method that builds the Lemmy row becomes an instance method (its name stays) and reads `$this->profileFeeds->selectRule(SocialNetwork::Lemmy)->parseIdentity($entry->getFeed()->getUrl())`. Drop the `LemmyProfileFeed` import.
- [ ] **Step 3:** Run the Step 1 test. Expected: PASS.
- [ ] **Step 4:** Run `grep -rnE 'Social\\(Bluesky|BlueskyPost|Mastodon|Lemmy)\\' backend/src/Service/Library`. Expected: no output.
- [ ] **Step 5:** `composer check && composer md && php bin/phpunit`; commit `refactor(#2377): the entry list asks the profile feed rule`.

### Task 4: Moved tests read fixtures through `ReadsFixtures`

**Files:**
- Modify: every test under `backend/tests/Service/Social/Bluesky`, `…/Mastodon`, `…/Lemmy` that matches `grep -rln "__DIR__ . '/\.\./.*Fixtures" backend/tests/Service/Social/{Bluesky,Mastodon,Lemmy}` (20 files)
- Move: `backend/tests/Service/Social/Social/MastodonSelfThreadFoldTest.php` to `backend/tests/Service/Social/Mastodon/MastodonSelfThreadFoldTest.php`, with the same change

**Interfaces:**
- Consumes: `App\Tests\Support\ReadsFixtures::fixture(string $path): string`, `$path` relative to `tests/Fixtures`. The trait is not changed.

- [ ] **Step 1:** In each file, `use ReadsFixtures;` and replace every hand-built path. A constant holding a path or a folder (`private const string FIXTURES = __DIR__ . '/../../../Fixtures/lemmy/';`) becomes the relative name (`'lemmy/'`) or is inlined at its uses. A static data provider yields the relative fixture name and the test method reads it with `$this->fixture()`. A fixture passed as a file path to something that opens it itself stays as it is; list such cases in the commit body.
- [ ] **Step 2:** Run `grep -rn "Fixtures" backend/tests/Service/Social/Bluesky backend/tests/Service/Social/Mastodon backend/tests/Service/Social/Lemmy | grep "__DIR__"`. Expected: only the cases listed in Step 1's commit body.
- [ ] **Step 3:** `composer check && php bin/phpunit`; the test count equals develop's plus the tests Tasks 1–3 added. Commit `test(#2377): moved social tests read fixtures through ReadsFixtures`.

### Task 5: Acceptance and PR

- [ ] **Step 1:** Run `git grep -n 'Social\\\(Bluesky\|Mastodon\|Lemmy\)\\' backend/src/Dto backend/src/Service/Library`. Expected: only the per-network `Connect*Request` DTOs (and any other request that carries one network's credentials; name them in the PR).
- [ ] **Step 2:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 3:** Scan today's `backend/var/log/dev-*.log` for new deprecations or errors.
- [ ] **Step 4:** Run `/simplify` on the branch.
- [ ] **Step 5:** Push, open the PR into `develop` with `Closes #2377`; the body records the two closed points (enum names, import order) with the reasons under Decisions. Merge when CI is green; verify #2377 closed.
