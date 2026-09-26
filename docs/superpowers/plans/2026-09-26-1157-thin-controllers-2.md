# Thin Controllers, Part B: Lookups, Security Decisions, Response Assembly and Commands (#1157) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1157. Every judgement-call item from the PR A plan's Appendix B leaves the controllers and the two commands:
- the owned lookups, which today repeat `findOneOwnedBy(...) ?? throw new NotFoundHttpException(...)` 15 times,
- the inline security decisions (refresh ownership, the test-digest gate, the OAuth callback),
- inline response assembly,
- the shared boilerplate (row enrichment ×6, `MeJson::profile` ×5, `AiSettingsJson::configuration(…, settingsFor(…)?->getId())` ×8, the entry-list query parameters ×3),
- the HTTP probing in `CheckCatalogUrlsCommand` and the file I/O and sharding in `ReaderAuditCommand`.

Three follow-ups from PR A's execution ride along:
- the admin category test proves that update and delete persist,
- `MailSendFailureRepository::add()` stops flushing,
- `ControllerMutatesNoEntityRule` closes its known blind spots and false positives (PR A ruling F6).

**Architecture:**
- **Owned lookups** become throwing repository methods (`getOneOwnedBy`, `getOneRowForUser`, `getOneSubscribedByUser`, `getOwned`), which follow the existing `CatalogFeedRepository::getById` pattern. They throw the existing `App\Repository\Exception\RecordNotFoundException`. `RequestProblems` already maps it to a `not_found` 404 that carries the message as `detail` (#1160). The 404s therefore gain a `detail` (D1).
- **Security decisions** move into services that throw typed exceptions:
  - `Service/Refresh/UserRefreshScope` decides which refresh a user may ask for.
  - `Service/Mail/Digest/TestDigestEligibility` holds the test-digest gate. `MailProblems` maps its exception to the same bare 403.
  - `Service/OAuth/OAuthCallback` holds the callback's state, provider and exchange checks. It throws `OAuthCallbackRefusedException` carrying an `OAuthCallbackFailure` reason, and the controller turns the reason into the same failure redirect.
- **Response assembly** moves into `src/Http` mappers, never into a service (#1158): `VersionJson`, `OnboardingJson`, `OpmlJson`, `AdminUserLimitsJson`, `SubscribeOutcomeJson`, `MeProfileJson`, `CatalogFaviconResponse`, plus new methods on `AdminCatalogJson`, `SubscriptionJson`, `SubscriptionCountsJson` and `AiSettingsJson`.
- **Shared boilerplate:**
  - `Repository/EntryListRowEnricher` composes the two batch loaders.
  - A `#[MapQueryString]` DTO, `Dto/Entry/EntryPageParameters`, carries the page parameters (D3).
- **Commands:**
  - `Service/Catalog/CatalogUrlChecker` takes its document from `BundledCatalog`.
  - `Service/ReaderAudit/AuditShard` and `Service/ReaderAudit/AuditFindingsFile` take over the sharding and the file I/O.
- **PR A follow-ups:**
  - `MailDeliveryHealth::recordFailure()` owns the flush of the failure log. The repository's `add()` only persists.
  - `ControllerMutatesNoEntityRule` recognises a Doctrine-mapped class by its `#[ORM\Entity]` or `#[ORM\Embeddable]` attribute, not by its namespace. It also sees first-class callables and static calls.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit 12 with DAMA DoctrineTestBundle (each test rolls back), PHPStan 2.2.5 at level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection 0.34.

**Spec:**
- GitHub issue #1157 (`gh issue view 1157`).
- The PR A plan, executed and merged as #1180 (883ac759): `git show origin/develop:docs/superpowers/plans/2026-09-26-1157-thin-controllers.md`. Its Appendix B is this plan's inventory, and its "Execution rulings" F1–F11 are facts this plan relies on.
- CLAUDE.md, "Controllers hold no private methods that carry responsibility".

## Status

| Task | State |
|---|---|
| Task 0: Preflight | ⬜ not started |
| Task 1: Tag, subscription and saved-search lookups throw `RecordNotFoundException` | ⬜ not started |
| Task 2: Entry and debug-log lookups | ⬜ not started |
| Task 3: `UserRefreshScope` (RefreshController) | ⬜ not started |
| Task 4: `TestDigestEligibility` (MeController gate) | ⬜ not started |
| Task 5: `MeProfileJson` (MeController ×5) | ⬜ not started |
| Task 6: `OAuthCallback` (OAuthController::callback) | ⬜ not started |
| Task 7: `EntryListRowEnricher` (×6) | ⬜ not started |
| Task 8: `EntryPageParameters` via `#[MapQueryString]` (D3) | ⬜ not started |
| Task 9: Subscription list, counts and candidates mappers | ⬜ not started |
| Task 10: `AiSettingsJson::configurationFor` | ⬜ not started |
| Task 11: `VersionJson`, `OnboardingJson`, `OpmlJson`; the OPML size guard moves into `OpmlImporter` | ⬜ not started |
| Task 12: Admin catalog and user-limit mappers, `BundledCatalog::summary()` | ⬜ not started |
| Task 13: Catalog favicon: `CatalogFaviconSource` + `CatalogFaviconResponse` | ⬜ not started |
| Task 14: `CatalogUrlChecker` (CheckCatalogUrlsCommand) | ⬜ not started |
| Task 15: `AuditShard` + `AuditFindingsFile` (ReaderAuditCommand) | ⬜ not started |
| Task 16: Admin category update and delete are proven to persist | ⬜ not started |
| Task 17: `MailDeliveryHealth` owns the failure log's flush | ⬜ not started |
| Task 18: `ControllerMutatesNoEntityRule` sees mapped classes, first-class callables and static calls | ⬜ not started |

## Scope

This PR closes #1157. PR A (#1180, `Refs #1157`, merged as 883ac759) moved persistence and entity mutation out of the controllers and added the two PHPStan rules. This PR covers every item in PR A's Appendix B, and three follow-ups from PR A's execution. The line numbers are develop's at 883ac759.

| Item | develop site | Task |
|---|---|---|
| `findOneOwnedBy(...) ?? throw new NotFoundHttpException` ×15 | `TagController` ×3, `SubscriptionController` ×3, `SavedSearchController` ×2, `SavedSearchEntriesController` ×2, `EntryController` ×2, `EntryCommentsController`, `EntryReaderController`, `RecommendationDebugLogController` | 1, 2 |
| `(int) $user->getId()` ×42 | already `$user->requireId()` since #1165 (D4) | none |
| Refresh ownership chain | `RefreshController:54-74` | 3 |
| Test-digest gate | `MeController:120-122` | 4 |
| `MeJson::profile(...)` ×5 | `MeController:51,65,79,90,104` | 5 |
| OAuth callback state and provider checks | `OAuthController::callback:147-202` | 6 |
| `savedSearchLoader->loadInto(categoryLoader->loadInto(...))` | `EntryController:100,115`, `EntrySearchController:39`, `SavedSearchEntriesController:62,92`, `ForYouFeedResponder:56` | 7 |
| cursor/limit/unread/order query parameters ×3 | `EntryController::list:58-61`, `SavedSearchEntriesController::list:47-50`, `::one:74-77` | 8 |
| `SubscriptionController::list` hand-built counts payload | `SubscriptionController:48-65` | 9 |
| `SubscriptionController::create` candidates payload | `SubscriptionController:89-101` | 9 |
| `AiSettingsJson::configuration(…, settingsFor($user)?->getId())` ×8 | `AiSettingsController:76,101,115,134,153,172,196,208` | 10 |
| `ConfigurationNotFoundException` catch ×10 | already `AiConfigurationForUser::require()` on develop | none |
| `VersionController` | `:23-35` | 11 |
| `OnboardingController::subscribe` | `:36-43` | 11 |
| `OpmlController::import` size guard + result | `:43-55` | 11 |
| `AdminCatalogImportController::describeBundled` | `:41-53` | 12 |
| `AdminUserLimitsController` same array twice | `:38-41`, `:50-53`, `:69` | 12 |
| `AdminCatalogController::list` / `warmFavicons` | `:41-50`, `:64-70` | 12 |
| `CatalogController::favicon` | `:48-63` | 13 |
| `CheckCatalogUrlsCommand` | whole `execute` + `assertServesFeed` | 14 |
| `ReaderAuditCommand::execute` file I/O and sharding | `:77`, `:87-98`, `shardOf`, `openOutput` | 15 |
| Category update and delete are checked by status code only; two mutants escape | `AdminCatalogCategoryController:56,64`; `tests/Controller/Admin/AdminCatalogControllerTest.php:118-130` | 16 |
| `add()` flushes the whole EntityManager | `MailSendFailureRepository:23-28`; `MailDeliveryHealth::recordFailure:26-32`; `docs/architecture.md:154-156` | 17 |
| The rule's blind spots and false positives (PR A ruling F6) | `ControllerMutatesNoEntityRule:28,45-49,106-109` | 18 |

The ThinControllerRule allow-list is empty on develop and stays empty. This PR adds no helper to any controller (D5).

## Rulings

Lars ruled on the draft's decisions on 2026-09-26. They are settled; do not re-open them during execution.

- **D1, CHANGED: the owned lookups reuse `RecordNotFoundException`.** No `OwnedRecordNotFoundException` is created, and `RequestProblems` and `ProblemContractTest` are untouched: `RequestProblems` already maps `RecordNotFoundException` to a 404 with the message as `detail` (#1160), and `ProblemContractTest`'s `record not found` yield already pins that. The consequence is a deliberate, additive wire change. Every 404 this PR moves now carries its message as `detail`:
  - `No such tag.`, `No such subscription.`, `No such saved search.`, `No such entry.` and `No such debug log entry.` from the owned lookups (Tasks 1, 2),
  - `No such subscription.` and `No such tag.` from the refresh endpoint (Task 3),
  - `No such feed.` from the catalog favicon (Task 13, through D2).

  Status, `type` and `title` do not change. No existing test asserted these bodies (each 404 test checks the status only), so one existing 404 test per message family gains a `detail` assertion that pins the new body. The frontend does not read `detail` on these endpoints; only the admin screens render it. The PR body lists the change.
- **D2, approved as drafted.** The favicon lookup is not an owned lookup. Under D1's reuse of `RecordNotFoundException`, the drafted consequence applies: Task 13 switches the favicon to the existing `CatalogFeedRepository::getById()`, so its 404 gains `"detail": "No such feed."`, and no `NotFoundHttpException` is left in `src/Controller`.
- **D3, approved: accept Task 8.** A malformed `limit` or `unread` on the three entry lists now answers `422 validation_error` naming the field, instead of `#[MapQueryParameter]`'s bare 404. The change is deliberate, is pinned by `EntryPageParametersTest`, and goes in the PR body. Well-formed requests do not change.
- **D4, approved as drafted: `$user->requireId()` stays in controllers.** #1165 removed every `(int) $user->getId()` cast, and `EntityIdCoercionRule` forbids new ones. A `#[CurrentUserId] int $userId` resolver would give most actions two ways to reach the same user. No resolver.
- **D5, approved as drafted: the ThinControllerRule allow-list mechanism stays.** It has been empty since #186, and CLAUDE.md documents it as the one permitted exception. The rule's `ALLOW_LIST` is not touched.
- **D6, approved as drafted: the test-digest gate gets its own service, `TestDigestEligibility`.** Folding it into `SendTestDigest::send()` would move it after the rate limiter, so a disabled-mail or unverified account would spend its `digest_test` budget on a 403.
- **D7, approved as drafted: `ForYouFeedResponder` joins the enricher (Task 7).** Task 0 Step 5 checks that #1162 has not reshaped it first.
- **Added: three PR A follow-ups (Tasks 16–18).**
  - Task 16: `AdminCatalogControllerTest` reloads the category after the PATCH and the DELETE, so the two escaped mutants at `AdminCatalogCategoryController:56,64` die.
  - Task 17: `MailSendFailureRepository::add()` only persists. `MailDeliveryHealth::recordFailure()`, which owns the unit of work, flushes. `docs/architecture.md` §7 stops naming it as "the one write left to fix".
  - Task 18: `ControllerMutatesNoEntityRule` decides "entity" by Doctrine mapping (`#[ORM\Entity]` or `#[ORM\Embeddable]`), not by the `App\Entity\` namespace. That stops the false positives on `App\Entity\Exception\*` and on the readonly value objects `EntryAttachment`, `EntryMedium` and `RecommendationRunProgress`, which carry neither attribute. It also catches the two blind spots: first-class callables (`$entity->setX(...)`, which PHPStan passes to rules only as `MethodCallableNode`) and static calls on a mapped class (`StaticCall`, and `StaticMethodCallableNode` for `Entity::create(...)`). An embeddable stays covered, because it is part of its entity's persisted state.

## PR A facts this plan relies on

PR A merged as #1180 (883ac759). What it left on develop, re-verified for this plan:
- `ControllerMutatesNoEntityRule` accepts `requireId()` through its own constant, `ID_READ = 'requireId'`, beside `QUERY_METHOD = '/^(get|is|has)[A-Z]/'`. This plan adds `requireId()` calls in controllers (Task 1, `SavedSearchEntriesController`), which that constant allows.
- Ruling F1: PHPStan 2.2.5 passes every `?->` call to a rule twice, once as `NullsafeMethodCall` and once as `MethodCall`. The rule handles `MethodCall` only, and Task 18 keeps it that way.
- Ruling F6: the rule's blind spots and false positives were deferred to this PR. Task 18 handles them.
- Ruling F8: where a controller method is rewritten, its docblock is trimmed to 3 lines or fewer. Docblocks on untouched methods stay.
- `ThinControllerRule` has `PERSISTENCE_TYPES` and `ALLOW_LIST = []`.
- PR A's services exist: `TagEditor`, `TagOrdering`, `SavedSearchEditor`, `SubscriptionEditor`, `AccountPreferencesWriter`, `PositionReorderer`. The before-blocks below are develop's text, in which the controllers already delegate to them:
  - `MeController`'s constructor is `preferences, accountDeleter, mail, registration, sendTestDigest, rateLimitGuard, rateLimiters, instanceTimezone`.
  - `SubscriptionController`'s constructor is `subscriptions, subscriptionRepo, tags, entryStates, ownedSubscriptions, bulkUpdater, editor`.
- No task here adds a private controller method, an `ObjectManager` parameter, a construction of a mapped class or a non-query call on one.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **No behaviour or wire-contract change, apart from D1 and D3.**
  - Every status code, `type`, `title`, header and redirect stays byte-identical. The one body change is D1's added `detail` on the moved 404s.
  - The existing controller tests are the contract net. They must pass. Only these edits under `tests/Controller/` are allowed:
    - one added `detail` assertion each in `Api/TagControllerTest`, `Api/SubscriptionControllerTest`, `Api/SavedSearchControllerTest`, `Api/EntryControllerTest`, `Api/RecommendationDebugLogControllerTest` and `Api/CatalogFaviconControllerTest`, and two in `Api/RefreshControllerTest` (D1),
    - the new file `Api/EntryPageParametersTest.php` (Task 8, D3),
    - the persistence assertions in `Admin/AdminCatalogControllerTest` (Task 16).
  - Every other controller test passes unedited: `SavedSearchEntriesControllerTest`, `SavedSearchSlugRoutingTest`, `EntryCommentsControllerTest`, `EntryReaderControllerTest`, `EntrySearchControllerTest`, `MeTest`, `MeControllerTest`, `MeDigestTestControllerTest`, `OAuthFlowTest`, `AiSettingsControllerTest`, `VersionControllerTest`, `OnboardingControllerTest`, `OpmlControllerTest`, `CatalogControllerTest`, `Admin/AdminCatalogImportControllerTest`, `Admin/AdminUserLimitsControllerTest`.
- **Clean Code (CLAUDE.md) is mandatory.**
  - Use `final readonly class` with constructor promotion.
  - Guard clauses, no boolean flag parameters, at most three parameters on a non-constructor method.
  - Tests read persisted ids with `requireId()`, because `EntityIdCoercionRule` also covers `tests/`. `RecommendationRunLog` has no `PersistedId`, so its tests keep `getId()` + `self::assertNotNull()`.
- **Comments:** one line at most, and only where a future reader would otherwise get the code wrong. A comment that moves with its code and does not clear that bar is deleted.
- **Presentation stays in `src/Http`** (#1158). No `*Json` shape, `Response` or `JsonResponse` enters `src/Service`.
- **Every touched `src` file must be PHPMD-clean** under `composer md`. Fix the design, never the threshold.
- **The ThinControllerRule allow-list stays empty.** Never allow-list a finding; fix the controller.
- **PHPStan at level max:** no new baseline entries, and no `@phpstan-ignore`.
- **Gates for every task:**
  - the task's own tests,
  - `bin/console cache:clear` before `composer stan` in any task that adds a service or changes a constructor,
  - `composer check`,
  - `composer md`.
- **Branch-wide gates (Finishing):** `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test`, `composer infection:diff`.
- **Commit format:** `refactor(#1157): …`, one commit per task; Task 16 changes only a test and uses `test(#1157): …`. Never commit to `develop`.
- **Branch:** `refactor/1157-thin-controllers-2`, cut from `origin/develop`.
- **Break-test restores are done by hand** with the Edit tool, never with `git checkout --`.

---

### Task 0: Preflight

**Files:** none changed.

- [ ] **Step 1: Check that the checkout is free.** Other sessions share this checkout.

Run: `git status --short && git branch --show-current`
Expected: a clean tree. If it is not clean, or another session's branch is checked out, stop and ask Lars. Do not stash, reset or check out over it.

- [ ] **Step 2: Confirm that PR A is on develop, and that #1157 is still open.**

Run: `git fetch origin develop && git merge-base --is-ancestor 883ac759 origin/develop && echo PR-A-MERGED && gh issue view 1157 --json state`
Expected: `PR-A-MERGED`, then `{"state":"OPEN"}`. If the ancestor check fails, stop: this plan starts from PR A's merge. If #1157 is already closed, stop and ask Lars.

- [ ] **Step 3: Confirm that PR A's rules are as this plan assumes.**

Run:
```bash
git grep -n "QUERY_METHOD = \|ID_READ = \|ENTITY_NAMESPACE_PREFIX = \|return CallLike::class" origin/develop -- backend/tests/PhpStan/ControllerMutatesNoEntityRule.php
git grep -n "PERSISTENCE_TYPES = \|ALLOW_LIST = " origin/develop -- backend/tests/PhpStan/ThinControllerRule.php
```
Expected:
- `QUERY_METHOD = '/^(get|is|has)[A-Z]/'`, `ID_READ = 'requireId'`, `ENTITY_NAMESPACE_PREFIX = 'App\\Entity\\'` and `return CallLike::class;` (the before-state of Task 18).
- `ThinControllerRule` has `PERSISTENCE_TYPES` and `ALLOW_LIST = []`.

If either rule differs, correct Task 18's before-blocks before it starts.

- [ ] **Step 4: Re-verify every before-block against develop.** For each file below, run `git show origin/develop:backend/<path>` and compare it with the before-blocks of the tasks named next to it. This plan's blocks were verified at 883ac759; any drift since then gets corrected in the plan before that task starts.
  - Controllers: `Api/TagController`, `Api/SubscriptionController`, `Api/SavedSearchController` (Task 1); `Api/SavedSearchEntriesController` (Tasks 1, 7, 8); `Api/EntryController` (Tasks 2, 7, 8); `Api/EntryCommentsController`, `Api/EntryReaderController`, `Api/RecommendationDebugLogController` (Task 2); `Api/RefreshController` (Task 3); `Api/MeController` (Tasks 4, 5); `Api/OAuthController` (Task 6); `Api/EntrySearchController` (Task 7); `Api/AiSettingsController` (Task 10); `Api/VersionController`, `Api/OnboardingController`, `Api/OpmlController` (Task 11); `Admin/AdminCatalogController`, `Admin/AdminCatalogImportController`, `Admin/AdminUserLimitsController` (Task 12); `Api/CatalogController` (Task 13).
  - Commands: `CheckCatalogUrlsCommand` (Task 14), `ReaderAuditCommand` (Task 15).
  - Others: `src/Http/Problem/MailProblems.php` and `tests/Http/Problem/ProblemContractTest.php` (Task 4); `src/Service/Recommendation/ForYouFeedResponder.php` (Task 7); `config/services.yaml`, the `catalog.rot_check.http_client` block (Task 14); `src/Repository/MailSendFailureRepository.php`, `src/Service/Mail/MailDeliveryHealth.php` and `docs/architecture.md` §7 (Task 17); `tests/PhpStan/ControllerMutatesNoEntityRule*.php`, its fixture file and `CLAUDE.md`'s rule paragraph (Task 18).
  - The existing controller tests that gain assertions: the eight files named under Global Constraints (seven for D1, plus `Admin/AdminCatalogControllerTest` for Task 16).

- [ ] **Step 5: Re-run the inventory sweeps.**

Run:
```bash
git grep -nE "NotFoundHttpException\(|AccessDeniedHttpException\(" origin/develop -- backend/src/Controller
git grep -nE "loadInto\(" origin/develop -- backend/src/Controller backend/src/Service
git grep -nE "MeJson::profile\(" origin/develop -- backend/src
git grep -nE "settingsFor\(\\\$user\)\?->getId\(\)" origin/develop -- backend/src/Controller
git grep -nE "\(int\) *\\\$[a-zA-Z]+->getId\(\)" origin/develop -- backend/src/Controller
git grep -n "flush()" origin/develop -- backend/src/Repository
git log --oneline -3 origin/develop -- backend/src/Service/Recommendation/ForYouFeedResponder.php
```
Expected:
- 18 `NotFoundHttpException(` sites: the 15 owned lookups, the 2 refresh sites and the favicon. 1 `AccessDeniedHttpException(` site, in `MeController`.
- 12 `loadInto(` lines, which are 6 nested pairs: 5 in controllers and 1 in `ForYouFeedResponder`.
- 5 `MeJson::profile(` sites, all in `MeController`.
- 9 `settingsFor($user)?->getId()` sites in `AiSettingsController`: the 8 of Task 10, and 1 in `list`.
- No `(int) …->getId()`.
- 1 `flush()` in `src/Repository`, in `MailSendFailureRepository::add()`.
- `ForYouFeedResponder`'s newest commit is still `17f8193d feat(#1118): …`.

If a new site has appeared, extend the matching task before implementing it. If #1162 has reshaped `ForYouFeedResponder`, drop its step from Task 7 and tell Lars (D7).

- [ ] **Step 6: Record a PHPMD baseline for every touched `src` file.**

Run:
```bash
composer md 2>&1 | grep -E 'Repository/(Tag|Subscription|SavedSearch|EntryList|RecommendationRunLog|CatalogFeed|MailSendFailure)Repository\.php|Http/(Problem/MailProblems|SubscriptionJson|SubscriptionCountsJson|AiSettingsJson|AdminCatalogJson)\.php|Controller/|Command/(CheckCatalogUrls|ReaderAudit)Command\.php|Recommendation/ForYouFeedResponder\.php|Opml/OpmlImporter\.php|Catalog/BundledCatalog\.php|Mail/MailDeliveryHealth\.php' || echo CLEAN
```
Expected: `CLEAN`. If any touched file already has a finding, stop and ask Lars. The standing rule makes the touching task fix it, and that is a design change this plan does not cover.

- [ ] **Step 7: Create the branch.**

Run: `git switch -c refactor/1157-thin-controllers-2 origin/develop`

---
### Task 1: Tag, subscription and saved-search lookups throw `RecordNotFoundException`

**Files:**
- Create: `tests/Repository/TagRepositoryTest.php`
- Create: `tests/Repository/SubscriptionRepositoryTest.php`
- Modify: `src/Repository/TagRepository.php` (imports, a method after `findOneOwnedBy`)
- Modify: `src/Repository/SubscriptionRepository.php` (imports, a method after `findOneOwnedBy`)
- Modify: `src/Repository/SavedSearchRepository.php` (imports, a method after `findOneOwnedBy`)
- Modify: `src/Service/Reader/MarkReadService.php` (one import, `requireSubscription`, `requireTag`)
- Modify: `src/Controller/Api/TagController.php` (one import, three lookups)
- Modify: `src/Controller/Api/SubscriptionController.php` (one import, three lookups)
- Modify: `src/Controller/Api/SavedSearchController.php` (one import, two lookups)
- Modify: `src/Controller/Api/SavedSearchEntriesController.php` (one import, two lookups)
- Test: `tests/Repository/SavedSearchRepositoryTest.php` (append two tests)
- Test: `tests/Controller/Api/TagControllerTest.php`, `SubscriptionControllerTest.php`, `SavedSearchControllerTest.php` (one `detail` assertion each, D1)

**Interfaces:**
- Consumes: the existing `App\Repository\Exception\RecordNotFoundException`, which `RequestProblems` maps to `ApiProblem::forStatus(404, $message)` (D1).
- Produces:
  - `TagRepository::getOneOwnedBy(int $id, int $userId): Tag`, which throws `RecordNotFoundException('No such tag.')`.
  - `SubscriptionRepository::getOneOwnedBy(int $id, int $userId): Subscription`, which throws `RecordNotFoundException('No such subscription.')`.
  - `SavedSearchRepository::getOneOwnedBy(int $id, int $userId): SavedSearch`, which throws `RecordNotFoundException('No such saved search.')`.
- `MarkReadService` already writes `findOneOwnedBy(...) ?? throw new RecordNotFoundException(...)` with these exact messages, twice. That makes the controllers the third and later copies, so it calls the new methods too (DRY).
- The nullable `findOneOwnedBy` methods stay public: the `getOneOwnedBy` methods and `FeedTagMove::ownedTagOrNull` (which maps a miss to `InvalidSelectionException`) read them, and `SavedSearchRepositoryTest` pins them.

- [ ] **Step 1: Write the failing tests.**

`tests/Repository/TagRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\TagRepository;
use App\Tests\DbTestCase;

final class TagRepositoryTest extends DbTestCase
{
    public function testGetOneOwnedByReturnsTheOwnersTag(): void
    {
        $owner = $this->user('tag-owner@example.com');
        $tag = $this->tag($owner);

        self::assertSame($tag, $this->repo()->getOneOwnedBy($tag->requireId(), $owner->requireId()));
    }

    public function testGetOneOwnedByRefusesAnotherUsersTag(): void
    {
        $tag = $this->tag($this->user('tag-owner@example.com'));
        $stranger = $this->user('tag-stranger@example.com');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such tag.');

        $this->repo()->getOneOwnedBy($tag->requireId(), $stranger->requireId());
    }

    private function repo(): TagRepository
    {
        $repo = $this->em->getRepository(Tag::class);
        self::assertInstanceOf(TagRepository::class, $repo);

        return $repo;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tag(User $owner): Tag
    {
        $tag = new Tag($owner, 'News');
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }
}
```

`tests/Repository/SubscriptionRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Tests\DbTestCase;

final class SubscriptionRepositoryTest extends DbTestCase
{
    public function testGetOneOwnedByReturnsTheOwnersSubscription(): void
    {
        $owner = $this->user('subscription-owner@example.com');
        $subscription = $this->subscription($owner);

        self::assertSame(
            $subscription,
            $this->repo()->getOneOwnedBy($subscription->requireId(), $owner->requireId()),
        );
    }

    public function testGetOneOwnedByRefusesAnotherUsersSubscription(): void
    {
        $subscription = $this->subscription($this->user('subscription-owner@example.com'));
        $stranger = $this->user('subscription-stranger@example.com');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such subscription.');

        $this->repo()->getOneOwnedBy($subscription->requireId(), $stranger->requireId());
    }

    private function repo(): SubscriptionRepository
    {
        $repo = $this->em->getRepository(Subscription::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repo);

        return $repo;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function subscription(User $owner): Subscription
    {
        $feed = new Feed('https://example.com/owned-lookup.xml');
        $this->em->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
```

`tests/Repository/SavedSearchRepositoryTest.php`: add the import `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\User;`. Then append two tests at the end of the class. Before:
```php
        self::assertNotNull($this->repo()->findOneOwnedBy($saved->requireId(), $owner->requireId()));
        self::assertNull($this->repo()->findOneOwnedBy($saved->requireId(), $stranger->requireId()));
    }
}
```
after:
```php
        self::assertNotNull($this->repo()->findOneOwnedBy($saved->requireId(), $owner->requireId()));
        self::assertNull($this->repo()->findOneOwnedBy($saved->requireId(), $stranger->requireId()));
    }

    public function testGetOneOwnedByReturnsTheOwnersSavedSearch(): void
    {
        $owner = new User('owner3@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($owner);
        $saved = new SavedSearch($owner, 'mine', false);
        $this->em->persist($saved);
        $this->em->flush();

        self::assertSame($saved, $this->repo()->getOneOwnedBy($saved->requireId(), $owner->requireId()));
    }

    public function testGetOneOwnedByRefusesAnotherUsersSavedSearch(): void
    {
        $owner = new User('owner4@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger4@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($owner);
        $this->em->persist($stranger);
        $saved = new SavedSearch($owner, 'mine', false);
        $this->em->persist($saved);
        $this->em->flush();

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such saved search.');

        $this->repo()->getOneOwnedBy($saved->requireId(), $stranger->requireId());
    }
}
```

Pin D1's body change in one existing 404 test per message. `tests/Controller/Api/TagControllerTest.php`, in `testDeleteAnotherUsersTagIs404`, before:
```php
        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
```
after:
```php
        $client->request('DELETE', '/api/tags/' . $tag->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
        self::assertStringContainsString('"detail":"No such tag."', (string) $client->getResponse()->getContent());
    }
```

`tests/Controller/Api/SubscriptionControllerTest.php`, in `testCannotUpdateAnotherUsersSubscription`, before:
```php
            content: json_encode(['customTitle' => 'hijacked', 'tagIds' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
```
after:
```php
            content: json_encode(['customTitle' => 'hijacked', 'tagIds' => []], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
        self::assertStringContainsString(
            '"detail":"No such subscription."',
            (string) $client->getResponse()->getContent(),
        );
    }
```

`tests/Controller/Api/SavedSearchControllerTest.php`, in `testDeleteAnotherUsersSavedSearchIs404`, before:
```php
        $client->request('DELETE', '/api/saved-searches/' . $saved->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
```
after:
```php
        $client->request('DELETE', '/api/saved-searches/' . $saved->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
        self::assertStringContainsString(
            '"detail":"No such saved search."',
            (string) $client->getResponse()->getContent(),
        );
    }
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Repository/TagRepositoryTest.php tests/Repository/SubscriptionRepositoryTest.php tests/Repository/SavedSearchRepositoryTest.php tests/Controller/Api/TagControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/SavedSearchControllerTest.php`
Expected: FAIL. `getOneOwnedBy` is undefined, and the three 404 bodies carry no `detail` yet (`NotFoundHttpException` maps to a bare `not_found`).

- [ ] **Step 3: Add the throwing lookups.**

`src/Repository/TagRepository.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\Tag;`. Then insert directly after the closing `}` of `findOneOwnedBy`:
```php

    public function getOneOwnedBy(int $id, int $userId): Tag
    {
        return $this->findOneOwnedBy($id, $userId) ?? throw new RecordNotFoundException('No such tag.');
    }
```

`src/Repository/SubscriptionRepository.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\Subscription;`. Then insert directly after the closing `}` of `findOneOwnedBy`:
```php

    public function getOneOwnedBy(int $id, int $userId): Subscription
    {
        return $this->findOneOwnedBy($id, $userId) ?? throw new RecordNotFoundException('No such subscription.');
    }
```

`src/Repository/SavedSearchRepository.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\SavedSearch;`. Then insert directly after the closing `}` of `findOneOwnedBy`:
```php

    public function getOneOwnedBy(int $id, int $userId): SavedSearch
    {
        return $this->findOneOwnedBy($id, $userId) ?? throw new RecordNotFoundException('No such saved search.');
    }
```

`src/Service/Reader/MarkReadService.php`: delete `use App\Repository\Exception\RecordNotFoundException;`. In `requireSubscription`, before:
```php
        return $this->subscriptions->findOneOwnedBy($id, $userId)
            ?? throw new RecordNotFoundException('No such subscription.');
    }
```
after:
```php
        return $this->subscriptions->getOneOwnedBy($id, $userId);
    }
```
In `requireTag`, before:
```php
        $tag = $this->tags->findOneOwnedBy($id, $userId)
            ?? throw new RecordNotFoundException('No such tag.');

        return $tag->requireId();
```
after:
```php
        return $this->tags->getOneOwnedBy($id, $userId)->requireId();
```

- [ ] **Step 4: Run the repository tests and check that they pass.**

Run: `php bin/phpunit tests/Repository/TagRepositoryTest.php tests/Repository/SubscriptionRepositoryTest.php tests/Repository/SavedSearchRepositoryTest.php tests/Service/Reader`
Expected: OK. `tests/Service/Reader` covers `MarkReadService`'s unknown-subscription and unknown-tag cases, which throw the same exception with the same message as before.

- [ ] **Step 5: Switch the four controllers over.**

`src/Controller/Api/TagController.php`: delete the import `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. Then replace **each of the three** occurrences, in `feedOrder`, `update` and `delete` (Edit with `replace_all: true`). Before:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');
```
after:
```php
        $tag = $this->tags->getOneOwnedBy($id, $user->requireId());
```

`src/Controller/Api/SubscriptionController.php`: delete the import `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. Then replace **both** occurrences, in `update` and `moveToTag` (`replace_all: true`). Before:
```php
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such subscription.');
```
after:
```php
        $sub = $this->subscriptionRepo->getOneOwnedBy($id, $user->requireId());
```
And in `delete`, before:
```php
        $subscription = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such subscription.');
```
after:
```php
        $subscription = $this->subscriptionRepo->getOneOwnedBy($id, $user->requireId());
```

`src/Controller/Api/SavedSearchController.php`: delete the import `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. In `update`, before:
```php
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such saved search.');
```
after:
```php
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);
```
In `delete`, before:
```php
        $savedSearch = $this->savedSearches->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such saved search.');
```
after:
```php
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $user->requireId());
```

`src/Controller/Api/SavedSearchEntriesController.php`: delete the import `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. In `one`, before:
```php
        $userId = $user->requireId();
        $this->savedSearches->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such saved search.');

        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: [$id],
```
after:
```php
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);

        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: [$savedSearch->requireId()],
```
In `markOneRead`, before:
```php
        $userId = $user->requireId();
        $this->savedSearches->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such saved search.');
        $this->markRead->markOne($user, $id, $request->until);
```
after:
```php
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $user->requireId());
        $this->markRead->markOne($user, $savedSearch->requireId(), $request->until);
```

- [ ] **Step 6: Run the contract net and check that it passes.**

Run: `php bin/phpunit tests/Controller/Api/TagControllerTest.php tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/MoveFeedToTagTest.php tests/Controller/Api/SavedSearchControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Controller/Api/SavedSearchSlugRoutingTest.php tests/Controller/Api/ReorderTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: OK. Every 404 case still answers 404 `not_found`, and the three pinned ones now carry their `detail`. `EntryControllerTest` covers `/api/entries/mark-read`, which reaches `MarkReadService`.

- [ ] **Step 7: Sweep.**

Run: `git grep -nE "findOneOwnedBy|NotFoundHttpException" -- src/Controller/Api/TagController.php src/Controller/Api/SubscriptionController.php src/Controller/Api/SavedSearchController.php src/Controller/Api/SavedSearchEntriesController.php src/Service/Reader/MarkReadService.php`
Expected: no output.

- [ ] **Step 8: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 9: Commit.**

```bash
git add src/Repository/TagRepository.php src/Repository/SubscriptionRepository.php src/Repository/SavedSearchRepository.php \
  src/Service/Reader/MarkReadService.php \
  src/Controller/Api/TagController.php src/Controller/Api/SubscriptionController.php \
  src/Controller/Api/SavedSearchController.php src/Controller/Api/SavedSearchEntriesController.php \
  tests/Repository/TagRepositoryTest.php tests/Repository/SubscriptionRepositoryTest.php \
  tests/Repository/SavedSearchRepositoryTest.php tests/Controller/Api/TagControllerTest.php \
  tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/SavedSearchControllerTest.php
git commit -m "refactor(#1157): owned tag, subscription and saved-search lookups throw RecordNotFoundException

The moved 404s now carry their message as detail (D1)."
```

---

### Task 2: Entry and debug-log lookups

**Files:**
- Modify: `src/Repository/EntryListRepository.php` (imports, two methods)
- Modify: `src/Repository/RecommendationRunLogRepository.php` (imports, one method)
- Modify: `src/Controller/Api/EntryController.php` (one import, `get`, `updateState`)
- Modify: `src/Controller/Api/EntryCommentsController.php` (one import, `comments`)
- Modify: `src/Controller/Api/EntryReaderController.php` (one import, `reader`)
- Modify: `src/Controller/Api/RecommendationDebugLogController.php` (one import, `entry`)
- Test: `tests/Repository/EntryListTest.php` (import, four tests), `tests/Repository/RecommendationRunLogRepositoryTest.php` (import, two tests)
- Test: `tests/Controller/Api/EntryControllerTest.php`, `RecommendationDebugLogControllerTest.php` (one `detail` assertion each, D1)

**Interfaces:**
- Consumes: the existing `RecordNotFoundException` (D1).
- Produces:
  - `EntryListRepository::getOneRowForUser(int $entryId, int $userId): EntryListRow`, which throws with `'No such entry.'`.
  - `EntryListRepository::getOneSubscribedByUser(int $entryId, int $userId): Entry`, which throws with `'No such entry.'`.
  - `RecommendationRunLogRepository::getOwned(int $id, User $user): RecommendationRunLog`, which throws with `'No such debug log entry.'`.

- [ ] **Step 1: Write the failing tests.**

`tests/Repository/EntryListTest.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Repository\EntryScopePredicates;`. Then insert the following directly before `    private function hidden(Entry $entry): EntryState`:
```php
    public function testGetOneRowForUserReturnsTheRowOfASubscribedEntry(): void
    {
        $entry = $this->entry('owned-row', '2026-07-02T00:00:00Z');

        $row = $this->repo()->getOneRowForUser($entry->requireId(), $this->user->requireId());

        self::assertSame($entry->requireId(), $row->entry->requireId());
    }

    public function testGetOneRowForUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void
    {
        $entry = $this->entryOfAnUnsubscribedFeed('foreign-row');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such entry.');

        $this->repo()->getOneRowForUser($entry->requireId(), $this->user->requireId());
    }

    public function testGetOneSubscribedByUserReturnsASubscribedEntry(): void
    {
        $entry = $this->entry('owned-entry', '2026-07-02T00:00:00Z');

        self::assertSame(
            $entry,
            $this->repo()->getOneSubscribedByUser($entry->requireId(), $this->user->requireId()),
        );
    }

    public function testGetOneSubscribedByUserRefusesAnEntryOfAFeedTheUserDoesNotSubscribeTo(): void
    {
        $entry = $this->entryOfAnUnsubscribedFeed('foreign-entry');

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such entry.');

        $this->repo()->getOneSubscribedByUser($entry->requireId(), $this->user->requireId());
    }

    private function entryOfAnUnsubscribedFeed(string $guid): Entry
    {
        $feed = new Feed('https://example.com/' . $guid . '.xml');
        $this->em->persist($feed);

        return $this->entryAt($guid, '2026-07-01T00:00:00Z', '2026-07-01T00:00:00Z', $feed);
    }

```

`tests/Repository/RecommendationRunLogRepositoryTest.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\User;`. Then, before:
```php
        self::assertSame($mine, $this->logs->findOwned($mineId, $this->user));
        self::assertNull($this->logs->findOwned($theirsId, $this->user));
    }
```
after:
```php
        self::assertSame($mine, $this->logs->findOwned($mineId, $this->user));
        self::assertNull($this->logs->findOwned($theirsId, $this->user));
    }

    public function testGetOwnedReturnsTheCallersRow(): void
    {
        $mine = $this->fixtures->log(
            $this->fixtures->createRun($this->user),
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'r',
        );
        $this->em->flush();
        $mineId = $mine->getId();
        self::assertNotNull($mineId);

        self::assertSame($mine, $this->logs->getOwned($mineId, $this->user));
    }

    public function testGetOwnedRefusesAnotherUsersRow(): void
    {
        $theirs = $this->fixtures->log(
            $this->fixtures->createRun($this->otherUser),
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'r',
        );
        $this->em->flush();
        $theirsId = $theirs->getId();
        self::assertNotNull($theirsId);

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such debug log entry.');

        $this->logs->getOwned($theirsId, $this->user);
    }
```

`tests/Controller/Api/EntryControllerTest.php`, in `testGetUnsubscribedEntryIs404`, before:
```php
        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseStatusCodeSame(404);
    }

    public function testGetMissingEntryIs404(): void
```
after:
```php
        $client->request('GET', "/api/entries/$entryId", server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such entry."', (string) $client->getResponse()->getContent());
    }

    public function testGetMissingEntryIs404(): void
```

`tests/Controller/Api/RecommendationDebugLogControllerTest.php`, at the end of `testDetailOfAnotherUsersRowIs404ProblemJson`, before:
```php
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
    }
}
```
after:
```php
        self::assertStringStartsWith(
            'application/problem+json',
            (string) $client->getResponse()->headers->get('Content-Type'),
        );
        self::assertStringContainsString(
            '"detail":"No such debug log entry."',
            (string) $client->getResponse()->getContent(),
        );
    }
}
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/RecommendationRunLogRepositoryTest.php tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php`
Expected: FAIL. `getOneRowForUser`, `getOneSubscribedByUser` and `getOwned` are undefined, and the two pinned 404 bodies carry no `detail` yet.

- [ ] **Step 3: Add the throwing lookups.**

`src/Repository/EntryListRepository.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\Subscription;`. Insert directly after the closing `}` of `oneRowForUser`:
```php

    public function getOneRowForUser(int $entryId, int $userId): EntryListRow
    {
        return $this->oneRowForUser($entryId, $userId) ?? throw new RecordNotFoundException('No such entry.');
    }
```
Insert directly after the closing `}` of `findOneSubscribedByUser`:
```php

    public function getOneSubscribedByUser(int $entryId, int $userId): Entry
    {
        return $this->findOneSubscribedByUser($entryId, $userId)
            ?? throw new RecordNotFoundException('No such entry.');
    }
```

`src/Repository/RecommendationRunLogRepository.php`: add `use App\Repository\Exception\RecordNotFoundException;` directly after `use App\Entity\User;`. Insert directly after the closing `}` of `findOwned`:
```php

    public function getOwned(int $id, User $user): RecommendationRunLog
    {
        return $this->findOwned($id, $user) ?? throw new RecordNotFoundException('No such debug log entry.');
    }
```

- [ ] **Step 4: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Repository/EntryListTest.php tests/Repository/RecommendationRunLogRepositoryTest.php`
Expected: OK.

- [ ] **Step 5: Switch the four controllers over.**

`src/Controller/Api/EntryController.php`: delete `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. In `get`, before:
```php
        $row = $this->entryList->oneRowForUser($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such entry.');
        $row = $this->savedSearchLoader->loadInto(
```
after:
```php
        $row = $this->entryList->getOneRowForUser($id, $user->requireId());
        $row = $this->savedSearchLoader->loadInto(
```
In `updateState`, before:
```php
        $row = $this->entryList->oneRowForUser($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such entry.');

        $state = $this->entryStateUpdater->apply($user, $row, $request);
```
after:
```php
        $row = $this->entryList->getOneRowForUser($id, $user->requireId());

        $state = $this->entryStateUpdater->apply($user, $row, $request);
```

`src/Controller/Api/EntryCommentsController.php`: delete `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. Before:
```php
        $entry = $this->entryList->findOneSubscribedByUser($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such entry.');
```
after:
```php
        $entry = $this->entryList->getOneSubscribedByUser($id, $user->requireId());
```

`src/Controller/Api/EntryReaderController.php`: delete `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. Before:
```php
        $entry = $this->entryList->findOneSubscribedByUser($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such entry.');
```
after:
```php
        $entry = $this->entryList->getOneSubscribedByUser($id, $user->requireId());
```
The two-line comment above it ("Ownership is checked BEFORE the limiter…") stays: the order is load-bearing.

`src/Controller/Api/RecommendationDebugLogController.php`: delete `use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;`. Before:
```php
    public function entry(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $log = $this->logs->findOwned($id, $user)
            ?? throw new NotFoundHttpException('No such debug log entry.');

        return new JsonResponse(RecommendationDebugLogJson::detail($log));
    }
```
after:
```php
    public function entry(int $id, #[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::detail($this->logs->getOwned($id, $user)));
    }
```

- [ ] **Step 6: Run the contract net.**

Run: `php bin/phpunit tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntryCommentsControllerTest.php tests/Controller/Api/EntryReaderControllerTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php`
Expected: OK, including the two new `detail` assertions. `EntryReaderControllerTest::testEntryOfAnotherUserIs404AndDoesNotCallExtractor` still proves the ownership check runs before the limiter and the extractor.

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md`
Expected: all green. If PHPMD reports `ExcessiveClassComplexity` on `EntryListRepository`, stop and ask Lars. Do not raise the threshold; #1169 is re-composing that class.

- [ ] **Step 8: Commit.**

```bash
git add src/Repository/EntryListRepository.php src/Repository/RecommendationRunLogRepository.php \
  src/Controller/Api/EntryController.php src/Controller/Api/EntryCommentsController.php \
  src/Controller/Api/EntryReaderController.php src/Controller/Api/RecommendationDebugLogController.php \
  tests/Repository/EntryListTest.php tests/Repository/RecommendationRunLogRepositoryTest.php \
  tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php
git commit -m "refactor(#1157): entry and debug-log lookups throw RecordNotFoundException"
```

---

### Task 3: `UserRefreshScope` (RefreshController)

**Files:**
- Create: `src/Service/Refresh/UserRefreshScope.php`
- Create: `tests/Service/Refresh/UserRefreshScopeTest.php`
- Modify: `src/Controller/Api/RefreshController.php` (whole file)
- Test: `tests/Controller/Api/RefreshControllerTest.php` (two `detail` assertions, D1)

**Interfaces:**
- Consumes: `TagRepository::getOneOwnedBy` (Task 1), the existing `RecordNotFoundException`, and the existing `SubscriptionRepository::existsForUserAndFeed(int, int): bool`.
- Produces: `UserRefreshScope::requestFor(int $userId, ?int $feedId, ?int $tagId): RefreshRequest`. The feed wins over the tag, as it does today.
  - It throws `RecordNotFoundException('No such subscription.')` for a feed the user does not subscribe to.
  - It throws `RecordNotFoundException('No such tag.')` for a tag the user does not own.
  - The budget is 25 seconds.

- [ ] **Step 1: Write the failing test.** `tests/Service/Refresh/UserRefreshScopeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Refresh\UserRefreshScope;
use App\Tests\DbTestCase;

final class UserRefreshScopeTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->user('refresh-scope@example.com');
        $this->feed = new Feed('https://example.com/refresh-scope.xml');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
    }

    public function testNoTargetRefreshesEveryFeedOfTheUser(): void
    {
        $request = $this->scope()->requestFor($this->user->requireId(), null, null);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertNull($request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testASubscribedFeedIsRefreshedAlone(): void
    {
        $request = $this->scope()->requestFor($this->user->requireId(), $this->feed->requireId(), null);

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertSame($this->feed->requireId(), $request->feedId);
        self::assertNull($request->tagId);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testAFeedTheUserDoesNotSubscribeToIsNotFound(): void
    {
        $other = new Feed('https://example.com/not-subscribed.xml');
        $this->em->persist($other);
        $this->em->flush();

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such subscription.');

        $this->scope()->requestFor($this->user->requireId(), $other->requireId(), null);
    }

    public function testAnOwnTagIsRefreshedAlone(): void
    {
        $tag = $this->tag($this->user);

        $request = $this->scope()->requestFor($this->user->requireId(), null, $tag->requireId());

        self::assertSame($this->user->requireId(), $request->userId);
        self::assertNull($request->feedId);
        self::assertSame($tag->requireId(), $request->tagId);
        self::assertSame(25, $request->budgetSeconds);
    }

    public function testAnotherUsersTagIsNotFound(): void
    {
        $tag = $this->tag($this->user('refresh-scope-stranger@example.com'));

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('No such tag.');

        $this->scope()->requestFor($this->user->requireId(), null, $tag->requireId());
    }

    public function testAFeedTakesPrecedenceOverATag(): void
    {
        $tag = $this->tag($this->user);

        $request = $this->scope()->requestFor($this->user->requireId(), $this->feed->requireId(), $tag->requireId());

        self::assertSame($this->feed->requireId(), $request->feedId);
        self::assertNull($request->tagId);
    }

    private function scope(): UserRefreshScope
    {
        $scope = self::getContainer()->get(UserRefreshScope::class);
        self::assertInstanceOf(UserRefreshScope::class, $scope);

        return $scope;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function tag(User $owner): Tag
    {
        $tag = new Tag($owner, 'News');
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }
}
```

`tests/Controller/Api/RefreshControllerTest.php`, in `testPerFeedRefreshOfANonSubscribedFeedIs404`, before:
```php
        $client->request('POST', '/api/refresh?feedId=' . $feed->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
    }
```
after:
```php
        $client->request('POST', '/api/refresh?feedId=' . $feed->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(
            '"detail":"No such subscription."',
            (string) $client->getResponse()->getContent(),
        );
    }
```
In `testTagRefreshOfAnUnknownTagIs404`, before:
```php
        $client->request('POST', '/api/refresh?tag=999999', server: $headers);
        self::assertResponseStatusCodeSame(404);
    }
```
after:
```php
        $client->request('POST', '/api/refresh?tag=999999', server: $headers);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such tag."', (string) $client->getResponse()->getContent());
    }
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Service/Refresh/UserRefreshScopeTest.php tests/Controller/Api/RefreshControllerTest.php`
Expected: FAIL. The class `UserRefreshScope` is not found, and the two pinned refresh 404s carry no `detail` yet.

- [ ] **Step 3: Write the service.** `src/Service/Refresh/UserRefreshScope.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\Exception\RecordNotFoundException;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;

/** A foreign or unknown feed or tag is a 404, not a 403, so the refresh endpoint never confirms that it exists. */
final readonly class UserRefreshScope
{
    /** Above BudgetedFeedQueue::SAFETY_MARGIN_SECONDS (10), so a call covers several feeds; below FastCGI limits. */
    private const int BUDGET_SECONDS = 25;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
    ) {
    }

    public function requestFor(int $userId, ?int $feedId, ?int $tagId): RefreshRequest
    {
        if (null !== $feedId) {
            return $this->forFeed($userId, $feedId);
        }

        if (null !== $tagId) {
            $tag = $this->tags->getOneOwnedBy($tagId, $userId);

            return RefreshRequest::forUserTag($userId, $tag->requireId(), self::BUDGET_SECONDS);
        }

        return RefreshRequest::forUser($userId, self::BUDGET_SECONDS);
    }

    private function forFeed(int $userId, int $feedId): RefreshRequest
    {
        if (!$this->subscriptions->existsForUserAndFeed($userId, $feedId)) {
            throw new RecordNotFoundException('No such subscription.');
        }

        return RefreshRequest::forUserFeed($userId, $feedId, self::BUDGET_SECONDS);
    }
}
```

- [ ] **Step 4: Run the test and check that it passes.**

Run: `php bin/phpunit tests/Service/Refresh/UserRefreshScopeTest.php`
Expected: OK (6 tests).

- [ ] **Step 5: Rewrite the controller.** `src/Controller/Api/RefreshController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RefreshJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Refresh\TrackedRefreshRunner;
use App\Service\Refresh\UserRefreshScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Runs one budgeted refresh slice over the caller's own feeds — or a single one
 * via `?feedId=` — and returns the tally as JSON. Always HTTP 200: the client
 * switches on the `status` field (busy → wait and retry; partial → keep
 * looping; completed → done; aborted → terminal error) and loops until
 * `remaining` reaches 0. `progress` is the run as a whole — every
 * slice of it — and is the only figure a client should render.
 */
final class RefreshController
{
    public function __construct(
        private readonly TrackedRefreshRunner $trackedRefreshRunner,
        private readonly UserRefreshScope $scope,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $refreshLimiter,
    ) {
    }

    #[Route('/api/refresh', name: 'api_refresh', methods: ['POST'])]
    public function __invoke(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $feedId = null,
        #[MapQueryParameter] ?int $tag = null,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->refreshLimiter, $user);

        $request = $this->scope->requestFor($user->requireId(), $feedId, $tag);

        return new JsonResponse(RefreshJson::slice($this->trackedRefreshRunner->run($request)));
    }
}
```
The class docblock is unchanged from develop. The rate limit still runs before the ownership check, as it does today.

- [ ] **Step 6: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/RefreshControllerTest.php tests/Service/Refresh/UserRefreshScopeTest.php`
Expected: OK. The three 404 cases in `RefreshControllerTest` still answer 404, and the two pinned ones carry their `detail`.

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/Refresh/UserRefreshScope.php src/Controller/Api/RefreshController.php \
  tests/Service/Refresh/UserRefreshScopeTest.php tests/Controller/Api/RefreshControllerTest.php
git commit -m "refactor(#1157): the refresh ownership decision moves into UserRefreshScope"
```

---
### Task 4: `TestDigestEligibility` (MeController gate)

**Files:**
- Create: `src/Service/Mail/Digest/Exception/TestDigestUnavailableException.php`
- Create: `src/Service/Mail/Digest/TestDigestEligibility.php`
- Create: `tests/Service/Mail/Digest/TestDigestEligibilityTest.php`
- Modify: `src/Http/Problem/MailProblems.php` (whole file)
- Modify: `src/Controller/Api/MeController.php` (imports, constructor, `sendTestDigest`)
- Test: `tests/Http/Problem/ProblemContractTest.php` (one import, one yield)

**Interfaces:**
- Produces:
  - `TestDigestEligibility::assertEligible(User $user): void`, which throws `TestDigestUnavailableException` unless mail is enabled for the instance and the user's address is verified.
  - `MailProblems` maps the exception to `ApiProblem::forStatus(403)`, the same body as today's `AccessDeniedHttpException`.
- The gate stays before the rate limiter (D6).

- [ ] **Step 1: Write the failing tests.**

`tests/Service/Mail/Digest/TestDigestEligibilityTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Digest\Exception\TestDigestUnavailableException;
use App\Service\Mail\Digest\TestDigestEligibility;
use App\Tests\DbTestCase;
use App\Tests\Support\EnablesMailInTests;

final class TestDigestEligibilityTest extends DbTestCase
{
    use EnablesMailInTests;

    public function testAVerifiedAddressOnAMailSendingInstanceIsEligible(): void
    {
        $this->seedEnabledMailInstance();

        $this->expectNotToPerformAssertions();

        $this->eligibility()->assertEligible($this->user(verified: true));
    }

    public function testAnUnverifiedAddressIsRefused(): void
    {
        $this->seedEnabledMailInstance();

        $this->expectException(TestDigestUnavailableException::class);

        $this->eligibility()->assertEligible($this->user(verified: false));
    }

    public function testAnInstanceThatSendsNoMailRefusesEvenAVerifiedAddress(): void
    {
        $this->expectException(TestDigestUnavailableException::class);

        $this->eligibility()->assertEligible($this->user(verified: true));
    }

    private function eligibility(): TestDigestEligibility
    {
        $eligibility = self::getContainer()->get(TestDigestEligibility::class);
        self::assertInstanceOf(TestDigestEligibility::class, $eligibility);

        return $eligibility;
    }

    private function user(bool $verified): User
    {
        $user = new User('test-digest@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        if ($verified) {
            $user->markEmailVerified(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        }

        return $user;
    }
}
```
The third test seeds no mail row on purpose. With the null fallback, mail derives to off, the same as `MeDigestTestControllerTest::testMailDisabledInstanceIsForbidden`.

`tests/Http/Problem/ProblemContractTest.php`: add `use App\Service\Mail\Digest\Exception\TestDigestUnavailableException;` directly before `use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;`. Then, before:
```php
        yield 'incomplete mail configuration' => [
            IncompleteMailConfigurationException::passwordMissing(),
```
after:
```php
        yield 'test digest unavailable, message withheld' => [
            new TestDigestUnavailableException(),
            ['type' => 'forbidden', 'title' => 'Forbidden', 'status' => 403],
        ];
        yield 'incomplete mail configuration' => [
            IncompleteMailConfigurationException::passwordMissing(),
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Service/Mail/Digest/TestDigestEligibilityTest.php tests/Http/Problem/ProblemContractTest.php`
Expected: FAIL. The classes do not exist.

- [ ] **Step 3: Write the exception, the service and the mapping.**

`src/Service/Mail/Digest/Exception/TestDigestUnavailableException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Exception;

final class TestDigestUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mail is unavailable for this account.');
    }
}
```

`src/Service/Mail/Digest/TestDigestEligibility.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Digest\Exception\TestDigestUnavailableException;
use App\Service\Mail\MailCapability;

/** A preview digest goes only where a real one could: mail on for this instance, and a verified address. */
final readonly class TestDigestEligibility
{
    public function __construct(private MailCapability $mail)
    {
    }

    public function assertEligible(User $user): void
    {
        if (!$this->mail->isEnabled() || !$user->isEmailVerified()) {
            throw new TestDigestUnavailableException();
        }
    }
}
```

`src/Http/Problem/MailProblems.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Service\Mail\Digest\Exception\TestDigestUnavailableException;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class MailProblems implements ExceptionProblems
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof IncompleteMailConfigurationException => new ResolvedProblem(new ApiProblem(
                'incomplete_mail_configuration',
                'Incomplete mail configuration',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            $exception instanceof TestDigestUnavailableException => new ResolvedProblem(
                ApiProblem::forStatus(Response::HTTP_FORBIDDEN),
            ),
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run the tests and check that they pass.**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Mail/Digest/TestDigestEligibilityTest.php tests/Http/Problem/ProblemContractTest.php`
Expected: OK.

- [ ] **Step 5: Switch `MeController` over.** The before-blocks are PR A's after-state.

Imports: delete `use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;`. Then, before:
```php
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\MailCapability;
```
after:
```php
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\Digest\TestDigestEligibility;
use App\Service\Mail\MailCapability;
```

Constructor, before:
```php
        private SendTestDigest $sendTestDigest,
        private RateLimitGuard $rateLimitGuard,
```
after:
```php
        private SendTestDigest $sendTestDigest,
        private TestDigestEligibility $testDigestEligibility,
        private RateLimitGuard $rateLimitGuard,
```

`sendTestDigest` body, before:
```php
        if (!$this->mail->isEnabled() || !$user->isEmailVerified()) {
            throw new AccessDeniedHttpException('Mail is unavailable for this account.');
        }

        $this->rateLimitGuard->enforceForUser($this->rateLimiters->digestTest, $user);
```
after:
```php
        $this->testDigestEligibility->assertEligible($user);
        $this->rateLimitGuard->enforceForUser($this->rateLimiters->digestTest, $user);
```
The action's docblock is trimmed under PR A ruling F8, because the method is rewritten. Its gate sentence now lives in `TestDigestEligibility`'s class comment. Before:
```php
    /**
     * Sends a one-off preview digest over the last `days` days, without moving
     * digestLastSentAt (#636) — SendTestDigest composes and sends but never
     * touches the schedule watermark, so this button can be pressed any number
     * of times without disturbing the real digest cadence. Gated the same way
     * as the real send: mail must be on for this instance and the address must
     * be verified, or there is nowhere trustworthy to send the preview to.
     */
```
after:
```php
    /**
     * A one-off preview digest over the last `days` days. SendTestDigest never moves digestLastSentAt, so the
     * button can be pressed any number of times without disturbing the real cadence (#636).
     */
```

- [ ] **Step 6: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/MeDigestTestControllerTest.php tests/Controller/Api/MeTest.php tests/Controller/Api/MeControllerTest.php`
Expected: OK. `testAnUnverifiedUserIsForbidden` and `testMailDisabledInstanceIsForbidden` still see a 403 `application/problem+json`, and `testTheSixthCallInTheWindowIsThrottled` still counts only eligible calls.

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md`
Expected: all green. `MeController` now takes nine constructor parameters, which is below PHPMD's `ExcessiveParameterList` threshold of 10. Task 5 brings it back to eight.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/Mail/Digest/Exception/TestDigestUnavailableException.php src/Service/Mail/Digest/TestDigestEligibility.php \
  src/Http/Problem/MailProblems.php src/Controller/Api/MeController.php \
  tests/Service/Mail/Digest/TestDigestEligibilityTest.php tests/Http/Problem/ProblemContractTest.php
git commit -m "refactor(#1157): the test-digest gate moves into TestDigestEligibility"
```

---

### Task 5: `MeProfileJson` (MeController ×5)

**Files:**
- Create: `src/Http/MeProfileJson.php`
- Create: `tests/Http/MeProfileJsonTest.php`
- Modify: `src/Controller/Api/MeController.php` (imports, constructor, five `return` lines)

**Interfaces:**
- Consumes: the existing `MeJson::profile(User, bool, string): array` and `MailCapability::isEnabled(): bool`.
- Produces: `App\Http\MeProfileJson::of(User $user): array<string, mixed>`, an injectable mapper that knows the instance's mail state and timezone.

- [ ] **Step 1: Write the failing test.** `tests/Http/MeProfileJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\MeJson;
use App\Http\MeProfileJson;
use App\Service\Mail\MailCapability;
use App\Tests\DbTestCase;
use App\Tests\Support\EnablesMailInTests;

final class MeProfileJsonTest extends DbTestCase
{
    use EnablesMailInTests;

    public function testAnInstanceThatSendsNoMailReportsMailOffAndItsTimezone(): void
    {
        $user = $this->user();

        self::assertSame(
            MeJson::profile($user, false, 'Europe/Berlin'),
            $this->profileJson('Europe/Berlin')->of($user),
        );
    }

    public function testAMailSendingInstanceReportsMailOn(): void
    {
        $this->seedEnabledMailInstance();
        $user = $this->user();

        self::assertSame(MeJson::profile($user, true, 'UTC'), $this->profileJson('UTC')->of($user));
    }

    private function profileJson(string $instanceTimezone): MeProfileJson
    {
        $mail = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mail);

        return new MeProfileJson($mail, $instanceTimezone);
    }

    private function user(): User
    {
        return new User('profile@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Http/MeProfileJsonTest.php`
Expected: FAIL, because the class `App\Http\MeProfileJson` is not found.

- [ ] **Step 3: Write the mapper.** `src/Http/MeProfileJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;
use App\Service\Mail\MailCapability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** MeJson::profile plus the two instance facts every /api/me answer carries: whether mail is on, and the timezone. */
final readonly class MeProfileJson
{
    public function __construct(
        private MailCapability $mail,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }

    /** @return array<string, mixed> */
    public function of(User $user): array
    {
        return MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone);
    }
}
```

- [ ] **Step 4: Run the test and check that it passes.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/MeProfileJsonTest.php`
Expected: OK (2 tests).

- [ ] **Step 5: Switch `MeController` over.** The before-blocks are the state after Task 4.

Imports: delete `use App\Service\Mail\MailCapability;` and `use Symfony\Component\DependencyInjection\Attribute\Autowire;`. Then, before:
```php
use App\Http\MeJson;
```
after:
```php
use App\Http\MeJson;
use App\Http\MeProfileJson;
```
`MeJson` stays imported, because the class docblock's `{@see MeJson}` still points readers at the shape's note.

Constructor, before:
```php
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private AccountDeleter $accountDeleter,
        private MailCapability $mail,
        private RegistrationService $registration,
        private SendTestDigest $sendTestDigest,
        private TestDigestEligibility $testDigestEligibility,
        private RateLimitGuard $rateLimitGuard,
        private MeRateLimiters $rateLimiters,
        #[Autowire('%env(string:APP_TIMEZONE)%')]
        private string $instanceTimezone,
    ) {
    }
```
after:
```php
    public function __construct(
        private AccountPreferencesWriter $preferences,
        private AccountDeleter $accountDeleter,
        private RegistrationService $registration,
        private SendTestDigest $sendTestDigest,
        private TestDigestEligibility $testDigestEligibility,
        private RateLimitGuard $rateLimitGuard,
        private MeRateLimiters $rateLimiters,
        private MeProfileJson $profile,
    ) {
    }
```

In `show`, `updateLocale`, `updatePreferences`, `updateMagazineStyle` and `updateDigest` (Edit with `replace_all: true`, five occurrences), before:
```php
        return new JsonResponse(MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone));
```
after:
```php
        return new JsonResponse($this->profile->of($user));
```

- [ ] **Step 6: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/MeTest.php tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeDigestControllerTest.php tests/Controller/Api/MagazineStyleControllerTest.php tests/Controller/Api/MeDigestTestControllerTest.php tests/Http/MeJsonTest.php`
Expected: OK.

- [ ] **Step 7: Run the gates and the sweep.**

Run: `composer check && composer md && git grep -n "MeJson::profile(" -- src`
Expected: all green. The grep lists only `src/Http/MeProfileJson.php`.

- [ ] **Step 8: Commit.**

```bash
git add src/Http/MeProfileJson.php src/Controller/Api/MeController.php tests/Http/MeProfileJsonTest.php
git commit -m "refactor(#1157): MeProfileJson assembles the /api/me profile in one place"
```

---

### Task 6: `OAuthCallback` (OAuthController::callback)

**Files:**
- Create: `src/Dto/OAuth/OAuthCallbackAttempt.php`
- Create: `src/Service/OAuth/OAuthCallbackFailure.php`
- Create: `src/Service/OAuth/Exception/OAuthCallbackRefusedException.php`
- Create: `src/Service/OAuth/OAuthCallback.php`
- Create: `tests/Service/OAuth/OAuthCallbackTest.php`
- Modify: `src/Controller/Api/OAuthController.php` (imports, constructor, `callback`)

**Interfaces:**
- Consumes (all existing):
  - `OAuthStateStore::start(string): OAuthStartState` and `consume(string, ?string): OAuthStartState`, which throws `InvalidOAuthStateException`.
  - `OAuthProviderRegistry::get(string): OAuthProviderInterface`.
  - `OAuthProviderInterface::exchangeCode(string, string, string): OAuthIdentity`, which throws `OAuthFailedException`.
  - `OAuthSignIn::issueLoginCode(OAuthIdentity, string): string`.
- Produces:
  - `enum App\Service\OAuth\OAuthCallbackFailure: string`, with the cases `AccessDenied = 'access_denied'`, `InvalidRequest = 'invalid_request'`, `InvalidState = 'invalid_state'` and `ExchangeFailed = 'exchange_failed'`. These are exactly the four reason literals the callback redirects with today.
  - `final class OAuthCallbackRefusedException extends OAuthException`, with `public readonly OAuthCallbackFailure $failure`.
  - `final readonly class App\Dto\OAuth\OAuthCallbackAttempt(string $provider, bool $declined, ?string $state, ?string $code, ?string $browserToken)`.
  - `OAuthCallback::complete(OAuthCallbackAttempt $attempt): string`, which returns the login code or throws `OAuthCallbackRefusedException`.
- The order of checks is unchanged:
  1. declined,
  2. missing state or code,
  3. state consumed (which burns it),
  4. provider match,
  5. code exchange (a failure is logged at `warning` with the same message and context),
  6. login code issued.

  `UnknownProviderException` from `providers->get()` still propagates, as it does today.

- [ ] **Step 1: Write the failing test.** `tests/Service/OAuth/OAuthCallbackTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Dto\OAuth\OAuthCallbackAttempt;
use App\Dto\OAuth\OAuthIdentity;
use App\Dto\OAuth\OAuthStartState;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\OAuthCallback;
use App\Service\OAuth\OAuthCallbackFailure;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Tests\DbTestCase;
use App\Tests\Support\FakeOAuthProvider;
use App\Tests\Support\RecordingLogger;

final class OAuthCallbackTest extends DbTestCase
{
    public function testADeclinedConsentScreenIsRefusedAsAccessDeniedWithoutContactingTheProvider(): void
    {
        $provider = $this->provider();
        $attempt = new OAuthCallbackAttempt('google', true, null, null, null);

        self::assertSame(OAuthCallbackFailure::AccessDenied, $this->refusalOf($this->callback($provider), $attempt));
        self::assertSame([], $provider->exchanges);
    }

    public function testAMissingCodeIsRefusedAsAnInvalidRequest(): void
    {
        $started = $this->stateStore()->start('google');
        $attempt = new OAuthCallbackAttempt('google', false, $started->state, null, $started->browserToken);

        self::assertSame(
            OAuthCallbackFailure::InvalidRequest,
            $this->refusalOf($this->callback($this->provider()), $attempt),
        );
    }

    public function testAMissingStateIsRefusedAsAnInvalidRequest(): void
    {
        $attempt = new OAuthCallbackAttempt('google', false, null, 'the-code', 'the-browser');

        self::assertSame(
            OAuthCallbackFailure::InvalidRequest,
            $this->refusalOf($this->callback($this->provider()), $attempt),
        );
    }

    public function testAnUnissuedStateIsRefusedAsInvalidState(): void
    {
        $provider = $this->provider();
        $attempt = new OAuthCallbackAttempt('google', false, 'never-issued', 'the-code', 'the-browser');

        self::assertSame(OAuthCallbackFailure::InvalidState, $this->refusalOf($this->callback($provider), $attempt));
        self::assertSame([], $provider->exchanges);
    }

    public function testAStateStartedForAnotherProviderIsRefusedAsInvalidState(): void
    {
        $provider = $this->provider();
        $started = $this->stateStore()->start('apple');
        $attempt = new OAuthCallbackAttempt('google', false, $started->state, 'the-code', $started->browserToken);

        self::assertSame(OAuthCallbackFailure::InvalidState, $this->refusalOf($this->callback($provider), $attempt));
        self::assertSame([], $provider->exchanges);
    }

    public function testAFailedExchangeIsRefusedAndLoggedWithItsDetail(): void
    {
        $logger = new RecordingLogger();
        $started = $this->stateStore()->start('google');

        $failure = $this->refusalOf(
            $this->callback($this->provider(failExchange: true), $logger),
            $this->attemptFor($started),
        );

        self::assertSame(OAuthCallbackFailure::ExchangeFailed, $failure);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('OAuth exchange failed', $logger->records[0]['message']);
        self::assertSame('google', $logger->records[0]['context']['provider']);
        self::assertSame('fake provider was told to fail', $logger->records[0]['context']['detail']);
    }

    public function testACompletedCallbackExchangesThisFlowsSecretsAndIssuesALoginCode(): void
    {
        $provider = $this->provider();
        $started = $this->stateStore()->start('google');

        $loginCode = $this->callback($provider)->complete($this->attemptFor($started));

        self::assertNotSame('', $loginCode);
        self::assertSame(
            [['code' => 'the-code', 'codeVerifier' => $started->codeVerifier, 'nonce' => $started->nonce]],
            $provider->exchanges,
        );
    }

    private function attemptFor(OAuthStartState $started): OAuthCallbackAttempt
    {
        return new OAuthCallbackAttempt('google', false, $started->state, 'the-code', $started->browserToken);
    }

    private function refusalOf(OAuthCallback $callback, OAuthCallbackAttempt $attempt): OAuthCallbackFailure
    {
        try {
            $callback->complete($attempt);
        } catch (OAuthCallbackRefusedException $refusal) {
            return $refusal->failure;
        }

        self::fail('The callback was not refused.');
    }

    private function callback(FakeOAuthProvider $provider, ?RecordingLogger $logger = null): OAuthCallback
    {
        $signIn = self::getContainer()->get(OAuthSignIn::class);
        self::assertInstanceOf(OAuthSignIn::class, $signIn);

        return new OAuthCallback(
            $this->stateStore(),
            new OAuthProviderRegistry([$provider]),
            $signIn,
            $logger ?? new RecordingLogger(),
        );
    }

    private function provider(bool $failExchange = false): FakeOAuthProvider
    {
        return new FakeOAuthProvider(
            new OAuthIdentity('google', 'sub-callback', 'callback@example.com', true),
            $failExchange,
        );
    }

    private function stateStore(): OAuthStateStore
    {
        $store = self::getContainer()->get(OAuthStateStore::class);
        self::assertInstanceOf(OAuthStateStore::class, $store);

        return $store;
    }
}
```
`provider(failExchange: true)` is a named argument on a test helper that mirrors `FakeOAuthProvider`'s own constructor. It is not a flag parameter on production code.

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/OAuth/OAuthCallbackTest.php`
Expected: FAIL. The classes do not exist.

- [ ] **Step 3: Write the value types.**

`src/Dto/OAuth/OAuthCallbackAttempt.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\OAuth;

/** What the provider's redirect brought back, as OAuthController::callback() read it off the request. */
final readonly class OAuthCallbackAttempt
{
    public function __construct(
        public string $provider,
        public bool $declined,
        public ?string $state,
        public ?string $code,
        public ?string $browserToken,
    ) {
    }
}
```

`src/Service/OAuth/OAuthCallbackFailure.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/** The reason code the SPA receives in the failure redirect; the values are wire contract. */
enum OAuthCallbackFailure: string
{
    case AccessDenied = 'access_denied';
    case InvalidRequest = 'invalid_request';
    case InvalidState = 'invalid_state';
    case ExchangeFailed = 'exchange_failed';
}
```

`src/Service/OAuth/Exception/OAuthCallbackRefusedException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth\Exception;

use App\Service\OAuth\OAuthCallbackFailure;

final class OAuthCallbackRefusedException extends OAuthException
{
    public function __construct(public readonly OAuthCallbackFailure $failure)
    {
        parent::__construct(\sprintf('The OAuth callback was refused: %s.', $failure->value));
    }
}
```

- [ ] **Step 4: Write the service.** `src/Service/OAuth/OAuthCallback.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Dto\OAuth\OAuthCallbackAttempt;
use App\Dto\OAuth\OAuthIdentity;
use App\Dto\OAuth\OAuthStartState;
use App\Service\OAuth\Exception\InvalidOAuthStateException;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\Exception\OAuthFailedException;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Random\RandomException;

final readonly class OAuthCallback
{
    public function __construct(
        private OAuthStateStore $stateStore,
        private OAuthProviderRegistry $providers,
        private OAuthSignIn $signIn,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OAuthCallbackRefusedException
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    public function complete(OAuthCallbackAttempt $attempt): string
    {
        if ($attempt->declined) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::AccessDenied);
        }

        $state = $attempt->state;
        $code = $attempt->code;
        if (null === $state || null === $code) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::InvalidRequest);
        }

        $started = $this->consume($state, $attempt);
        $identity = $this->exchange($attempt->provider, $code, $started);

        // consume() refuses a null token, so a matched state proves the cookie was present.
        \assert(null !== $attempt->browserToken);

        return $this->signIn->issueLoginCode($identity, $attempt->browserToken);
    }

    /** @throws InvalidArgumentException */
    private function consume(string $state, OAuthCallbackAttempt $attempt): OAuthStartState
    {
        try {
            $started = $this->stateStore->consume($state, $attempt->browserToken);
        } catch (InvalidOAuthStateException) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::InvalidState);
        }

        // A state replayed at another provider's callback is refused like a forged one.
        if ($started->provider !== $attempt->provider) {
            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::InvalidState);
        }

        return $started;
    }

    private function exchange(string $provider, string $code, OAuthStartState $started): OAuthIdentity
    {
        try {
            return $this->providers->get($provider)->exchangeCode($code, $started->codeVerifier, $started->nonce);
        } catch (OAuthFailedException $failure) {
            $this->logger->warning('OAuth exchange failed', [
                'provider' => $provider,
                'detail' => $failure->logDetail,
                'exception' => $failure->getPrevious(),
            ]);

            throw new OAuthCallbackRefusedException(OAuthCallbackFailure::ExchangeFailed);
        }
    }
}
```

- [ ] **Step 5: Run the test and check that it passes.**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/OAuth/OAuthCallbackTest.php`
Expected: OK (7 tests).

- [ ] **Step 6: Switch `OAuthController` over.**

Imports, before:
```php
use App\Dto\OAuth\OAuthExchangeRequest;
use App\Service\OAuth\Exception\InvalidOAuthStateException;
use App\Service\OAuth\Exception\OAuthFailedException;
use App\Service\OAuth\CallbackParameters;
use App\Service\OAuth\FlowCookie;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthRedirectFactory;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Service\RateLimit\RateLimitGuard;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Random\RandomException;
```
after:
```php
use App\Dto\OAuth\OAuthCallbackAttempt;
use App\Dto\OAuth\OAuthExchangeRequest;
use App\Service\OAuth\CallbackParameters;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\FlowCookie;
use App\Service\OAuth\OAuthCallback;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthRedirectFactory;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Service\RateLimit\RateLimitGuard;
use Psr\Cache\InvalidArgumentException;
use Random\RandomException;
```

Constructor, before:
```php
    public function __construct(
        private readonly OAuthProviderRegistry $providers,
        private readonly OAuthStateStore $stateStore,
        private readonly OAuthSignIn $signIn,
        private readonly LoggerInterface $logger,
        private readonly RateLimitGuard $rateLimitGuard,
```
after:
```php
    public function __construct(
        private readonly OAuthProviderRegistry $providers,
        private readonly OAuthStateStore $stateStore,
        private readonly OAuthSignIn $signIn,
        private readonly OAuthCallback $callback,
        private readonly RateLimitGuard $rateLimitGuard,
```

`callback`, before (from the docblock's opening line to the method's closing brace):
```php
    /**
     * Step 2: the provider sends the browser back. GET (Google, query string) and
     * POST (Apple, form body — requesting a scope makes Apple require
     * `response_mode=form_post`). Every failure leaves as a redirect to the SPA
     * with an error code, never problem+json: the caller is a browser following a
     * redirect chain, and a JSON body would strand it showing raw JSON.
     *
     * @throws InvalidArgumentException
     */
    #[Route(
        '/{provider}/callback',
        name: 'api_auth_oauth_callback',
        requirements: ['provider' => self::PROVIDER_PATTERN],
        methods: ['GET', 'POST'],
    )]
    public function callback(string $provider, Request $request): RedirectResponse
    {
        // Apple and Google both report a declined consent screen this way. It
        // is the single most common non-success outcome and is not an error.
        if (null !== CallbackParameters::read($request, 'error')) {
            return $this->oauthRedirect->failure('access_denied');
        }

        $state = CallbackParameters::read($request, 'state');
        $code = CallbackParameters::read($request, 'code');

        if (null === $state || null === $code) {
            return $this->oauthRedirect->failure('invalid_request');
        }

        // Read straight off the request; null when the browser sent none, which
        // the store treats as a failed binding, not a reason to skip the check.
        $cookie = $request->cookies->get(self::FLOW_COOKIE);
        $browserToken = \is_string($cookie) ? $cookie : null;
        try {
            $started = $this->stateStore->consume($state, $browserToken);
        } catch (InvalidOAuthStateException) {
            return $this->oauthRedirect->failure('invalid_state');
        }

        // A state replayed at another provider's callback is refused like a forged one.
        if ($started->provider !== $provider) {
            return $this->oauthRedirect->failure('invalid_state');
        }

        try {
            $identity = $this->providers->get($provider)
                ->exchangeCode($code, $started->codeVerifier, $started->nonce);
        } catch (OAuthFailedException $e) {
            // The detail is for us. The user gets a code they can quote.
            $this->logger->warning('OAuth exchange failed', [
                'provider' => $provider,
                'detail' => $e->logDetail,
                'exception' => $e->getPrevious(),
            ]);

            return $this->oauthRedirect->failure('exchange_failed');
        }

        // consume() refuses a null token, so reaching here proves the cookie was
        // present and matched. Restated for the type checker.
        \assert(null !== $browserToken);

        // NOT cleared here, unlike every failure exit: the code minted below is
        // bound to this value and the exchange needs it one hop later; clearing
        // now would make every sign-in fail like a bad code. exchange() clears it.
        // A suspended or pending user reaches here too and leaves with a working
        // code — see OAuthSignIn::issueLoginCode() for why the status gate sits at
        // the exchange.
        return $this->oauthRedirect->success($this->signIn->issueLoginCode($identity, $browserToken));
    }
```
after:
```php
    /**
     * Step 2: the provider sends the browser back, by GET (Google) or by POST (Apple's form_post). Every failure
     * is a redirect to the SPA with an error code, never problem+json: a browser mid-redirect would show raw JSON.
     *
     * @throws InvalidArgumentException
     * @throws RandomException
     */
    #[Route(
        '/{provider}/callback',
        name: 'api_auth_oauth_callback',
        requirements: ['provider' => self::PROVIDER_PATTERN],
        methods: ['GET', 'POST'],
    )]
    public function callback(string $provider, Request $request): RedirectResponse
    {
        $cookie = $request->cookies->get(self::FLOW_COOKIE);
        $attempt = new OAuthCallbackAttempt(
            provider: $provider,
            declined: null !== CallbackParameters::read($request, 'error'),
            state: CallbackParameters::read($request, 'state'),
            code: CallbackParameters::read($request, 'code'),
            browserToken: \is_string($cookie) ? $cookie : null,
        );

        try {
            // The success redirect leaves the flow cookie set: the exchange one hop later needs the binding.
            return $this->oauthRedirect->success($this->callback->complete($attempt));
        } catch (OAuthCallbackRefusedException $refusal) {
            return $this->oauthRedirect->failure($refusal->failure->value);
        }
    }
```
The action's docblock is trimmed to two prose lines under PR A ruling F8, because the method is rewritten. The class docblock stays as it is.

- [ ] **Step 7: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth`
Expected: OK. `OAuthFlowTest` swaps `OAuthProviderRegistry` before the first request, so `OAuthCallback` is built with the fake registry, exactly as the controller was.

- [ ] **Step 8: Run the gates.**

Run: `composer check && composer md`
Expected: all green. Also run `mcp__phpstorm__lint_files` on `src/Service/OAuth/OAuthCallback.php` and `src/Controller/Api/OAuthController.php`. There must be no unhandled-exception warning.

- [ ] **Step 9: Commit.**

```bash
git add src/Dto/OAuth/OAuthCallbackAttempt.php src/Service/OAuth/OAuthCallbackFailure.php \
  src/Service/OAuth/Exception/OAuthCallbackRefusedException.php src/Service/OAuth/OAuthCallback.php \
  src/Controller/Api/OAuthController.php tests/Service/OAuth/OAuthCallbackTest.php
git commit -m "refactor(#1157): the OAuth callback's state, provider and exchange checks move into OAuthCallback"
```

---
### Task 7: `EntryListRowEnricher` (×6)

**Files:**
- Create: `src/Repository/EntryListRowEnricher.php`
- Create: `tests/Repository/EntryListRowEnricherTest.php`
- Modify: `src/Controller/Api/EntryController.php` (imports, constructor, `list`, `get`)
- Modify: `src/Controller/Api/EntrySearchController.php` (whole file)
- Modify: `src/Controller/Api/SavedSearchEntriesController.php` (imports, constructor, `list`, `one`)
- Modify: `src/Service/Recommendation/ForYouFeedResponder.php` (imports, constructor, `enrichedRows`)
- Modify: `tests/Service/Recommendation/ForYouFeedResponderTest.php` (imports, `responder()`)

**Interfaces:**
- Consumes: the existing `EntryCategoryLoader::loadInto(list<EntryListRow>): list<EntryListRow>` and `SavedSearchMembershipLoader::loadInto(list<EntryListRow>, int): list<EntryListRow>`.
- Produces: `App\Repository\EntryListRowEnricher::enrich(list<EntryListRow> $rows, int $userId): list<EntryListRow>`. It loads the categories first and the saved searches second, the same order as all six call sites today.

- [ ] **Step 1: Write the failing test.** `tests/Repository/EntryListRowEnricherTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\User;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Tests\DbTestCase;

final class EntryListRowEnricherTest extends DbTestCase
{
    public function testARowGainsItsCategoriesAndItsOwnersSavedSearches(): void
    {
        $user = new User('enricher@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $entry = $this->entry();
        $politics = new Category('politics', '');
        $this->em->persist($politics);
        $this->em->persist(new EntryCategory($entry, $politics, 0, 'Politics'));
        $search = new SavedSearch($user, 'climate', false);
        $this->em->persist($search);
        $this->em->flush();
        $search->setSlug($search->requireId() . '-climate');
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));
        $this->em->flush();

        $rows = $this->enricher()->enrich([$this->row($entry)], $user->requireId());

        self::assertSame(['Politics'], $rows[0]->categories);
        self::assertSame([$search->requireId()], array_column($rows[0]->savedSearches, 'id'));
    }

    public function testNoRowsStayNoRows(): void
    {
        self::assertSame([], $this->enricher()->enrich([], 1));
    }

    private function enricher(): EntryListRowEnricher
    {
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);

        return $enricher;
    }

    private function entry(): Entry
    {
        $feed = new Feed('https://example.com/enricher-feed.xml');
        $this->em->persist($feed);
        $now = new \DateTimeImmutable('2026-07-02T00:00:00Z');
        $entry = new Entry($feed, 'enricher-guid', 'https://example.com/enricher-entry', 'Climate', $now, $now);
        $this->em->persist($entry);

        return $entry;
    }

    private function row(Entry $entry): EntryListRow
    {
        return new EntryListRow(
            $entry,
            new EntryListRowSubscription(1, 'S'),
            false,
            false,
            false,
            new EntryListRowViewState(false, null),
            null,
        );
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Repository/EntryListRowEnricherTest.php`
Expected: FAIL, because the class `EntryListRowEnricher` is not found.

- [ ] **Step 3: Write the enricher.** `src/Repository/EntryListRowEnricher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

/** The two batch loads every entry list runs over its page: category labels, then the owner's saved-search pills. */
final readonly class EntryListRowEnricher
{
    public function __construct(
        private EntryCategoryLoader $categories,
        private SavedSearchMembershipLoader $savedSearches,
    ) {
    }

    /**
     * @param list<EntryListRow> $rows
     *
     * @return list<EntryListRow>
     */
    public function enrich(array $rows, int $userId): array
    {
        return $this->savedSearches->loadInto($this->categories->loadInto($rows), $userId);
    }
}
```

- [ ] **Step 4: Run the test and check that it passes.**

Run: `bin/console cache:clear && php bin/phpunit tests/Repository/EntryListRowEnricherTest.php`
Expected: OK (2 tests).

- [ ] **Step 5: Switch `EntryController` over.** The before-blocks are the state after Task 2.

Imports, before:
```php
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryListRepository;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use App\Repository\ForYouFeedQuery;
use App\Repository\SavedSearchMembershipLoader;
```
after:
```php
use App\Repository\EntryListRepository;
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
use App\Repository\ForYouFeedQuery;
```

Constructor, before:
```php
        private EntryListRepository $entryList,
        private EntryCategoryLoader $categoryLoader,
        private SavedSearchMembershipLoader $savedSearchLoader,
        private EntryStateUpdater $entryStateUpdater,
```
after:
```php
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private EntryStateUpdater $entryStateUpdater,
```

`list`, before:
```php
        $rows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto($this->entryList->listForUser($query)),
            $user->requireId(),
        );
```
after:
```php
        $rows = $this->enricher->enrich($this->entryList->listForUser($query), $user->requireId());
```

`get`, before:
```php
        $row = $this->entryList->getOneRowForUser($id, $user->requireId());
        $row = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto([$row]),
            $user->requireId(),
        )[0];
```
after:
```php
        $row = $this->entryList->getOneRowForUser($id, $user->requireId());
        $row = $this->enricher->enrich([$row], $user->requireId())[0];
```

- [ ] **Step 6: Rewrite `EntrySearchController`.** `src/Controller/Api/EntrySearchController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Search\MarkSearchReadRequest;
use App\Entity\User;
use App\Http\SearchPage;
use App\Repository\EntryListRowEnricher;
use App\Service\Reader\SearchMarkReadService;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\EntrySearchRequestFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries/search')]
final readonly class EntrySearchController
{
    public function __construct(
        private EntrySearchInterface $search,
        private EntrySearchRequestFactory $requests,
        private EntryListRowEnricher $enricher,
        private SearchMarkReadService $searchMarkRead,
    ) {
    }

    #[Route('', name: 'api_entries_search', methods: ['GET'])]
    public function search(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $query = $this->requests->fromRequest($request, $user);
        $result = $this->search->search($query);
        $rows = $this->enricher->enrich($result->rows, $user->requireId());

        return new JsonResponse(SearchPage::of($result->withRows($rows), $query->limit));
    }

    #[Route('/mark-read', name: 'api_entries_search_mark_read', methods: ['POST'])]
    public function markRead(
        #[CurrentUser] User $user,
        #[MapRequestPayload] MarkSearchReadRequest $request,
    ): JsonResponse {
        $this->searchMarkRead->mark($user, $request->q, $request->until);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 7: Switch `SavedSearchEntriesController` over.** The before-blocks are the state after Task 1.

Imports, before:
```php
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchListQuery;
use App\Repository\SavedSearchMembershipLoader;
use App\Repository\SavedSearchRepository;
```
after:
```php
use App\Repository\EntryListRowEnricher;
use App\Repository\EntryQuery;
use App\Repository\SavedSearchListQuery;
use App\Repository\SavedSearchRepository;
```

Constructor, before:
```php
        private SavedSearchEntries $entries,
        private EntryCategoryLoader $categoryLoader,
        private SavedSearchMembershipLoader $savedSearchLoader,
        private SavedSearchMarkReadService $markRead,
```
after:
```php
        private SavedSearchEntries $entries,
        private EntryListRowEnricher $enricher,
        private SavedSearchMarkReadService $markRead,
```

In `list` and in `one` (Edit with `replace_all: true`, two occurrences), before:
```php
        $rows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto($result->rows),
            $userId,
        );
```
after:
```php
        $rows = $this->enricher->enrich($result->rows, $userId);
```

- [ ] **Step 8: Switch `ForYouFeedResponder` over.** Skip this step if Task 0 Step 5 found that #1162 had reshaped the class (D7).

Imports, before:
```php
use App\Repository\EntryCategoryLoader;
use App\Repository\EntryListRow;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
use App\Repository\SavedSearchMembershipLoader;
```
after:
```php
use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
```

Constructor, before:
```php
        private RecommendationSettingsResolver $settings,
        private EntryCategoryLoader $categoryLoader,
        private SavedSearchMembershipLoader $savedSearchLoader,
    ) {
```
after:
```php
        private RecommendationSettingsResolver $settings,
        private EntryListRowEnricher $enricher,
    ) {
```

`enrichedRows`, before:
```php
        $entryRows = $this->savedSearchLoader->loadInto(
            $this->categoryLoader->loadInto(
                array_map(static fn (RecommendationFeedRow $row): EntryListRow => $row->row, $rows),
            ),
            $userId,
        );
```
after:
```php
        $entryRows = $this->enricher->enrich(
            array_map(static fn (RecommendationFeedRow $row): EntryListRow => $row->row, $rows),
            $userId,
        );
```

`tests/Service/Recommendation/ForYouFeedResponderTest.php`: replace the two imports `use App\Repository\EntryCategoryLoader;` and `use App\Repository\SavedSearchMembershipLoader;` with a single `use App\Repository\EntryListRowEnricher;`, placed directly before `use App\Repository\ForYouFeedQuery;`. Then, in `responder()`, before:
```php
        $categoryLoader = self::getContainer()->get(EntryCategoryLoader::class);
        self::assertInstanceOf(EntryCategoryLoader::class, $categoryLoader);

        $savedSearchLoader = self::getContainer()->get(SavedSearchMembershipLoader::class);
        self::assertInstanceOf(SavedSearchMembershipLoader::class, $savedSearchLoader);

        return new ForYouFeedResponder(
            new RecommendationFeedPager($repository),
            $settings,
            $categoryLoader,
            $savedSearchLoader,
        );
```
after:
```php
        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);

        return new ForYouFeedResponder(
            new RecommendationFeedPager($repository),
            $settings,
            $enricher,
        );
```

- [ ] **Step 9: Run the contract net and the sweep.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntrySearchControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Controller/Api/SavedSearchSlugRoutingTest.php tests/Controller/Api/SavedSearchUnreadListMatchesBadgeTest.php tests/Service/Recommendation/ForYouFeedResponderTest.php && git grep -n "loadInto(" -- src/Controller src/Service`
Expected: OK, and the grep prints nothing. The only `loadInto(` callers left are inside `src/Repository/EntryListRowEnricher.php`.

- [ ] **Step 10: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 11: Commit.**

```bash
git add src/Repository/EntryListRowEnricher.php src/Controller/Api/EntryController.php \
  src/Controller/Api/EntrySearchController.php src/Controller/Api/SavedSearchEntriesController.php \
  src/Service/Recommendation/ForYouFeedResponder.php tests/Repository/EntryListRowEnricherTest.php \
  tests/Service/Recommendation/ForYouFeedResponderTest.php
git commit -m "refactor(#1157): EntryListRowEnricher replaces six copies of the two-loader chain"
```

---

### Task 8: `EntryPageParameters` via `#[MapQueryString]` (D3)

Lars accepted D3 (see Rulings). This task makes the one query-parameter wire change on purpose, and the PR body lists it.

**Files:**
- Create: `src/Dto/Entry/EntryPageParameters.php`
- Create: `tests/Controller/Api/EntryPageParametersTest.php`
- Modify: `src/Controller/Api/EntryController.php` (imports, `list`)
- Modify: `src/Controller/Api/SavedSearchEntriesController.php` (imports, `list`, `one`)

**Interfaces:**
- Produces: `final readonly class App\Dto\Entry\EntryPageParameters(?string $cursor = null, int $limit = EntryQuery::DEFAULT_LIMIT, bool $unread = false, ?string $order = null)`. It is mapped with `#[MapQueryString(validationFailedStatusCode: 422)]` and defaults to `new EntryPageParameters()` when the query string is empty.
- Behaviour: a well-formed request is unchanged. A malformed `limit` or `unread` now answers 422 `validation_error` with the field under `errors`, instead of a bare 404.

- [ ] **Step 1: Write the failing test.** `tests/Controller/Api/EntryPageParametersTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final class EntryPageParametersTest extends ApiTestCase
{
    public function testAMalformedLimitOnTheEntryListIsAValidationError(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-entries@example.com');

        $client->request('GET', '/api/entries?limit=abc', server: $this->authHeaderFor($user));

        $this->assertRejected($client, 422);
        self::assertSame('validation_error', $this->payload($client)['type']);
        self::assertIsArray($this->payload($client)['errors']);
        self::assertArrayHasKey('limit', $this->payload($client)['errors']);
    }

    public function testAMalformedLimitOnTheSavedSearchListIsAValidationError(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-saved@example.com');

        $client->request('GET', '/api/entries/saved-searches?limit=abc', server: $this->authHeaderFor($user));

        $this->assertRejected($client, 422);
        self::assertSame('validation_error', $this->payload($client)['type']);
        self::assertIsArray($this->payload($client)['errors']);
        self::assertArrayHasKey('limit', $this->payload($client)['errors']);
    }

    public function testWellFormedPageParametersStillPage(): void
    {
        $client = self::createClient();
        $user = $this->factory()->create('page-parameters-ok@example.com');

        $client->request('GET', '/api/entries?limit=2&unread=1&order=asc', server: $this->authHeaderFor($user));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload($client)['entries']);
    }

    /** @return array<string, string> */
    private function authHeaderFor(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Controller/Api/EntryPageParametersTest.php`
Expected: FAIL. The two malformed-limit tests see 404 (`#[MapQueryParameter]`'s default), not 422. The third test passes already. It pins that well-formed requests do not change.

- [ ] **Step 3: Write the DTO.** `src/Dto/Entry/EntryPageParameters.php`:
```php
<?php

declare(strict_types=1);

namespace App\Dto\Entry;

use App\Repository\EntryQuery;

/** The paging, unread and order parameters every entry list reads from its query string. */
final readonly class EntryPageParameters
{
    public function __construct(
        public ?string $cursor = null,
        public int $limit = EntryQuery::DEFAULT_LIMIT,
        public bool $unread = false,
        public ?string $order = null,
    ) {
    }
}
```

- [ ] **Step 4: Switch `EntryController::list` over.** The before-blocks are the state after Task 7.

Imports: add `use App\Dto\Entry\EntryPageParameters;` directly before `use App\Dto\Entry\MarkEntriesReadRequest;`. Add `use Symfony\Component\HttpKernel\Attribute\MapQueryString;` directly after `use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;`.

`list`, before (signature to `return`):
```php
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $view = null,
        #[MapQueryParameter] ?int $subscription = null,
        #[MapQueryParameter] ?int $tag = null,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
        #[MapQueryParameter] bool $unread = false,
        #[MapQueryParameter] ?string $order = null,
    ): JsonResponse {
```
after:
```php
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $view = null,
        #[MapQueryParameter] ?int $subscription = null,
        #[MapQueryParameter] ?int $tag = null,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        EntryPageParameters $page = new EntryPageParameters(),
    ): JsonResponse {
```
Before:
```php
        $listOrder = ListOrder::fromRequestValue($order);
```
after:
```php
        $listOrder = ListOrder::fromRequestValue($page->order);
```
Before:
```php
                new ForYouFeedQuery($user, $cursor, $limit, $unread),
```
after:
```php
                new ForYouFeedQuery($user, $page->cursor, $page->limit, $page->unread),
```
Before:
```php
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
            order: $listOrder,
```
after:
```php
            cursor: EntryCursor::fromRequestValue($page->cursor),
            limit: $page->limit,
            order: $listOrder,
```
`EntryQuery` stays imported (`new EntryQuery(...)`), and so does `MapQueryParameter` (`view`, `subscription`, `tag`).

- [ ] **Step 5: Switch `SavedSearchEntriesController` over.** The before-blocks are the state after Task 7.

Imports: add `use App\Dto\Entry\EntryPageParameters;` directly before `use App\Dto\Entry\MarkSavedSearchesReadRequest;`. Replace `use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;` with `use Symfony\Component\HttpKernel\Attribute\MapQueryString;`. Delete `use App\Repository\EntryQuery;`, which is no longer read.

`list`, before:
```php
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
        #[MapQueryParameter] bool $unread = false,
        #[MapQueryParameter] ?string $order = null,
    ): JsonResponse {
        $userId = $user->requireId();
        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: $this->savedSearches->idsForUser($userId),
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
            order: ListOrder::fromRequestValue($order),
        );
```
after:
```php
    public function list(
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        EntryPageParameters $page = new EntryPageParameters(),
    ): JsonResponse {
        $userId = $user->requireId();
        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: $this->savedSearches->idsForUser($userId),
            onlyUnread: $page->unread,
            cursor: EntryCursor::fromRequestValue($page->cursor),
            limit: $page->limit,
            order: ListOrder::fromRequestValue($page->order),
        );
```

`one`, before:
```php
    public function one(
        int $id,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $cursor = null,
        #[MapQueryParameter] int $limit = EntryQuery::DEFAULT_LIMIT,
        #[MapQueryParameter] bool $unread = false,
        #[MapQueryParameter] ?string $order = null,
    ): JsonResponse {
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);

        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: [$savedSearch->requireId()],
            onlyUnread: $unread,
            cursor: EntryCursor::fromRequestValue($cursor),
            limit: $limit,
            order: ListOrder::fromRequestValue($order),
        );
```
after:
```php
    public function one(
        int $id,
        #[CurrentUser] User $user,
        #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        EntryPageParameters $page = new EntryPageParameters(),
    ): JsonResponse {
        $userId = $user->requireId();
        $savedSearch = $this->savedSearches->getOneOwnedBy($id, $userId);

        $query = new SavedSearchListQuery(
            userId: $userId,
            savedSearchIds: [$savedSearch->requireId()],
            onlyUnread: $page->unread,
            cursor: EntryCursor::fromRequestValue($page->cursor),
            limit: $page->limit,
            order: ListOrder::fromRequestValue($page->order),
        );
```

- [ ] **Step 6: Run the new test and the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/EntryPageParametersTest.php tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Controller/Api/SavedSearchSlugRoutingTest.php tests/Controller/Api/SavedSearchUnreadListMatchesBadgeTest.php`
Expected: OK. Every existing paging, `unread=1`, `order=asc`, `order=sideways` (422 from `ListOrder`) and for-you case passes unchanged.

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add src/Dto/Entry/EntryPageParameters.php src/Controller/Api/EntryController.php \
  src/Controller/Api/SavedSearchEntriesController.php tests/Controller/Api/EntryPageParametersTest.php
git commit -m "refactor(#1157): entry lists read their page parameters through one #[MapQueryString] DTO

A malformed limit or unread now answers 422 validation_error naming the field,
instead of MapQueryParameter's bare 404 (D3)."
```

---
### Task 9: Subscription list, counts and candidates mappers

**Files:**
- Create: `src/Service/Subscription/SubscriptionTallies.php`
- Create: `src/Service/Subscription/SubscriptionTallyReader.php`
- Create: `src/Http/SubscribeOutcomeJson.php`
- Create: `tests/Service/Subscription/SubscriptionTallyReaderTest.php`
- Create: `tests/Http/SubscriptionJsonListTest.php`
- Create: `tests/Http/SubscribeOutcomeJsonTest.php`
- Modify: `src/Http/SubscriptionCountsJson.php` (whole file)
- Modify: `src/Http/SubscriptionJson.php` (imports, one method)
- Modify: `src/Controller/Api/SubscriptionController.php` (imports, constructor, `list`, `counts`, `create`)
- Modify: `tests/Http/SubscriptionCountsJsonTest.php` (whole file)

**Interfaces:**
- Consumes: the existing `EntryStateRepository::unreadCountsForUser(int): array<int, int>` and `stateCountsForUser(int): array{favorites: int, kept: int, viewed: int}`, and `SubscriptionRepository::entryCountsForUser(int): array<int, int>`.
- Produces:
  - `final readonly class App\Service\Subscription\SubscriptionTallies(array $unreadCounts, array $entryCounts, array $flags)`.
  - `SubscriptionTallyReader::forUser(int $userId): SubscriptionTallies`.
  - `SubscriptionCountsJson::from(SubscriptionTallies $tallies): array`. The signature changes, and the shape does not.
  - `SubscriptionCountsJson::surfaceTotals(SubscriptionTallies $tallies): array{favoritesCount: int, keptCount: int, viewedCount: int}`.
  - `SubscriptionJson::list(list<Subscription> $subscriptions, SubscriptionTallies $tallies): array<string, mixed>`, which returns the `GET /api/subscriptions` body.
  - `SubscribeOutcomeJson::candidates(SubscribeOutcome $outcome): array<string, mixed>`, which returns the candidates body of `POST /api/subscriptions`.

- [ ] **Step 1: Write the failing tests.**

`tests/Service/Subscription/SubscriptionTallyReaderTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Subscription\SubscriptionTallyReader;
use App\Tests\DbTestCase;

final class SubscriptionTallyReaderTest extends DbTestCase
{
    public function testReadsUnreadEntryAndSurfaceCountsForTheUser(): void
    {
        $when = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $user = new User('tallies@example.com', $when);
        $this->em->persist($user);
        $feed = new Feed('https://example.com/tallies.xml');
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, $when);
        $this->em->persist($subscription);
        $read = $this->entry($feed, 'read');
        $this->entry($feed, 'unread');
        $state = new EntryState($user, $read);
        $state->setIsHidden(true);
        $state->setIsFavorite(true);
        $this->em->persist($state);
        $this->em->flush();

        $reader = self::getContainer()->get(SubscriptionTallyReader::class);
        self::assertInstanceOf(SubscriptionTallyReader::class, $reader);
        $entryStates = $this->em->getRepository(EntryState::class);
        self::assertInstanceOf(EntryStateRepository::class, $entryStates);
        $tallies = $reader->forUser($user->requireId());

        self::assertSame($entryStates->unreadCountsForUser($user->requireId()), $tallies->unreadCounts);
        self::assertSame([$subscription->requireId() => 2], $tallies->entryCounts);
        self::assertSame(['favorites' => 1, 'kept' => 0, 'viewed' => 0], $tallies->flags);
        self::assertNotSame($tallies->entryCounts, $tallies->unreadCounts);
    }

    private function entry(Feed $feed, string $guid): Entry
    {
        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, $guid, null, $guid, $createdAt, $createdAt);
        $this->em->persist($entry);

        return $entry;
    }
}
```

`tests/Http/SubscriptionJsonListTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\SubscriptionJson;
use App\Service\Subscription\SubscriptionTallies;
use App\Tests\DbTestCase;

final class SubscriptionJsonListTest extends DbTestCase
{
    public function testEachSubscriptionCarriesItsCountsAndTheSurfaceTotalsFollow(): void
    {
        $user = new User('subscription-list@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $counted = $this->subscription($user, 'https://example.com/counted.xml');
        $silent = $this->subscription($user, 'https://example.com/silent.xml');
        $tallies = new SubscriptionTallies(
            [$counted->requireId() => 3],
            [$counted->requireId() => 40],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
        );

        $payload = SubscriptionJson::list([$counted, $silent], $tallies);

        self::assertSame(['subscriptions', 'favoritesCount', 'keptCount', 'viewedCount'], array_keys($payload));
        self::assertSame(
            [SubscriptionJson::one($counted, 3, 40), SubscriptionJson::one($silent)],
            $payload['subscriptions'],
        );
        self::assertSame(8, $payload['favoritesCount']);
        self::assertSame(2, $payload['keptCount']);
        self::assertSame(41, $payload['viewedCount']);
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
}
```

`tests/Http/SubscribeOutcomeJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SubscribeOutcomeJson;
use App\Service\Discovery\FeedCandidate;
use App\Service\Discovery\ScrapeFailureReason;
use App\Service\Subscription\SubscribeOutcome;
use PHPUnit\Framework\TestCase;

final class SubscribeOutcomeJsonTest extends TestCase
{
    public function testListsEveryCandidateWithoutAReasonWhenNoneWasGiven(): void
    {
        $outcome = SubscribeOutcome::candidates([
            new FeedCandidate('https://example.com/rss', 'Example', 'rss'),
            new FeedCandidate('https://example.com/atom', null, 'atom'),
        ]);

        self::assertSame(
            ['candidates' => [
                ['url' => 'https://example.com/rss', 'title' => 'Example', 'format' => 'rss'],
                ['url' => 'https://example.com/atom', 'title' => null, 'format' => 'atom'],
            ]],
            SubscribeOutcomeJson::candidates($outcome),
        );
    }

    public function testCarriesTheScrapeFailureReasonWhenThereIsOne(): void
    {
        $outcome = SubscribeOutcome::candidates([], ScrapeFailureReason::Blocked);

        self::assertSame(
            ['candidates' => [], 'scrapeFailureReason' => 'blocked'],
            SubscribeOutcomeJson::candidates($outcome),
        );
    }
}
```

`tests/Http/SubscriptionCountsJsonTest.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SubscriptionCountsJson;
use App\Service\Subscription\SubscriptionTallies;
use PHPUnit\Framework\TestCase;

final class SubscriptionCountsJsonTest extends TestCase
{
    public function testMapsUnreadAndEntryCountsAndSurfaceTotals(): void
    {
        $payload = SubscriptionCountsJson::from(new SubscriptionTallies(
            [12 => 3],
            [12 => 40, 8 => 5],
            ['favorites' => 8, 'kept' => 2, 'viewed' => 41],
        ));

        self::assertSame([
            'subscriptions' => [
                ['id' => 12, 'unreadCount' => 3, 'entryCount' => 40],
                ['id' => 8, 'unreadCount' => 0, 'entryCount' => 5],
            ],
            'favoritesCount' => 8,
            'keptCount' => 2,
            'viewedCount' => 41,
        ], $payload);
    }

    public function testAnEmptyAccountMapsToEmptySubscriptionsAndZeroTotals(): void
    {
        $payload = SubscriptionCountsJson::from(
            new SubscriptionTallies([], [], ['favorites' => 0, 'kept' => 0, 'viewed' => 0]),
        );

        self::assertSame([], $payload['subscriptions']);
        self::assertSame(0, $payload['favoritesCount']);
    }
}
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Service/Subscription/SubscriptionTallyReaderTest.php tests/Http/SubscriptionJsonListTest.php tests/Http/SubscribeOutcomeJsonTest.php tests/Http/SubscriptionCountsJsonTest.php`
Expected: FAIL. `SubscriptionTallies`, `SubscriptionTallyReader` and `SubscribeOutcomeJson` do not exist.

- [ ] **Step 3: Write the tallies and their reader.**

`src/Service/Subscription/SubscriptionTallies.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

final readonly class SubscriptionTallies
{
    /**
     * @param array<int, int>                               $unreadCounts subscription id => unread count, 0 absent
     * @param array<int, int>                               $entryCounts  subscription id => entries, read or not
     * @param array{favorites: int, kept: int, viewed: int} $flags
     */
    public function __construct(
        public array $unreadCounts,
        public array $entryCounts,
        public array $flags,
    ) {
    }
}
```

`src/Service/Subscription/SubscriptionTallyReader.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;

final readonly class SubscriptionTallyReader
{
    public function __construct(
        private EntryStateRepository $entryStates,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function forUser(int $userId): SubscriptionTallies
    {
        return new SubscriptionTallies(
            $this->entryStates->unreadCountsForUser($userId),
            $this->subscriptions->entryCountsForUser($userId),
            $this->entryStates->stateCountsForUser($userId),
        );
    }
}
```

- [ ] **Step 4: Write the mappers.**

`src/Http/SubscriptionCountsJson.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Subscription\SubscriptionTallies;

/**
 * The sidebar poll's cheap payload (#720): every subscription's unread count
 * plus the three surface totals, and nothing else. It replaces the 137 KB
 * bootstrap on a tick that only needs the numbers — no feeds, no tags, no
 * descriptions. A subscription absent from the list has no entries; the
 * client defaults it to zero against the list it already holds.
 */
final class SubscriptionCountsJson
{
    /**
     * @return array{
     *   subscriptions: list<array{id: int, unreadCount: int, entryCount: int}>,
     *   favoritesCount: int, keptCount: int, viewedCount: int
     * }
     */
    public static function from(SubscriptionTallies $tallies): array
    {
        $subscriptions = [];
        foreach ($tallies->entryCounts as $id => $entryCount) {
            $subscriptions[] = [
                'id' => $id,
                'unreadCount' => $tallies->unreadCounts[$id] ?? 0,
                'entryCount' => $entryCount,
            ];
        }

        return ['subscriptions' => $subscriptions, ...self::surfaceTotals($tallies)];
    }

    /** @return array{favoritesCount: int, keptCount: int, viewedCount: int} */
    public static function surfaceTotals(SubscriptionTallies $tallies): array
    {
        return [
            'favoritesCount' => $tallies->flags['favorites'],
            'keptCount' => $tallies->flags['kept'],
            'viewedCount' => $tallies->flags['viewed'],
        ];
    }
}
```
The class docblock is develop's, unchanged.

`src/Http/SubscriptionJson.php`: add `use App\Service\Subscription\SubscriptionTallies;` directly after `use App\Service\Text\PlainText;`. Then, before:
```php
    private const int DESCRIPTION_MAX = 1000;

    /**
     * The embedded tag's `position` is this feed's order WITHIN that tag (the
```
after:
```php
    private const int DESCRIPTION_MAX = 1000;

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array<string, mixed>
     */
    public static function list(array $subscriptions, SubscriptionTallies $tallies): array
    {
        return [
            'subscriptions' => array_map(
                static fn (Subscription $subscription): array => self::one(
                    $subscription,
                    $tallies->unreadCounts[$subscription->requireId()] ?? 0,
                    $tallies->entryCounts[$subscription->requireId()] ?? 0,
                ),
                $subscriptions,
            ),
            ...SubscriptionCountsJson::surfaceTotals($tallies),
        ];
    }

    /**
     * The embedded tag's `position` is this feed's order WITHIN that tag (the
```

`src/Http/SubscribeOutcomeJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Discovery\FeedCandidate;
use App\Service\Subscription\SubscribeOutcome;

final class SubscribeOutcomeJson
{
    /** @return array<string, mixed> */
    public static function candidates(SubscribeOutcome $outcome): array
    {
        $payload = [
            'candidates' => array_map(
                static fn (FeedCandidate $candidate): array => [
                    'url' => $candidate->url,
                    'title' => $candidate->title,
                    'format' => $candidate->format,
                ],
                $outcome->candidates,
            ),
        ];
        if (null !== $outcome->scrapeFailureReason) {
            $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason->value;
        }

        return $payload;
    }
}
```

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Subscription/SubscriptionTallyReaderTest.php tests/Http/SubscriptionJsonListTest.php tests/Http/SubscribeOutcomeJsonTest.php tests/Http/SubscriptionCountsJsonTest.php tests/Http/SubscriptionJsonTest.php`
Expected: OK.

- [ ] **Step 6: Switch `SubscriptionController` over.** The before-blocks are the state after Task 1.

Imports, before:
```php
use App\Http\SubscriptionCountsJson;
use App\Http\SubscriptionJson;
use App\Repository\EntryStateRepository;
use App\Repository\SubscriptionRepository;
```
after:
```php
use App\Http\SubscribeOutcomeJson;
use App\Http\SubscriptionCountsJson;
use App\Http\SubscriptionJson;
use App\Repository\SubscriptionRepository;
```
And add `use App\Service\Subscription\SubscriptionTallyReader;` directly after `use App\Service\Subscription\SubscriptionService;`.

Constructor, before:
```php
        private EntryStateRepository $entryStates,
```
after:
```php
        private SubscriptionTallyReader $tallies,
```

`list`, before:
```php
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $rows = $this->subscriptionRepo->findForUserWithTags($user->requireId());
        $counts = $this->entryStates->unreadCountsForUser($user->requireId());
        $entryCounts = $this->subscriptionRepo->entryCountsForUser($user->requireId());
        $flags = $this->entryStates->stateCountsForUser($user->requireId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn ($s) => SubscriptionJson::one(
                    $s,
                    $counts[$s->requireId()] ?? 0,
                    $entryCounts[$s->requireId()] ?? 0,
                ),
                $rows,
            ),
            'favoritesCount' => $flags['favorites'],
            'keptCount' => $flags['kept'],
            'viewedCount' => $flags['viewed'],
        ]);
    }
```
after:
```php
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(SubscriptionJson::list(
            $this->subscriptionRepo->findForUserWithTags($user->requireId()),
            $this->tallies->forUser($user->requireId()),
        ));
    }
```

`counts` body, before:
```php
        return new JsonResponse(SubscriptionCountsJson::from(
            $this->entryStates->unreadCountsForUser($user->requireId()),
            $this->subscriptionRepo->entryCountsForUser($user->requireId()),
            $this->entryStates->stateCountsForUser($user->requireId()),
        ));
```
after:
```php
        return new JsonResponse(SubscriptionCountsJson::from($this->tallies->forUser($user->requireId())));
```

`create`, before:
```php
        if (null === $outcome->subscription) {
            $payload = [
                'candidates' => array_map(
                    static fn ($c) => ['url' => $c->url, 'title' => $c->title, 'format' => $c->format],
                    $outcome->candidates,
                ),
            ];
            if (null !== $outcome->scrapeFailureReason) {
                $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason->value;
            }

            return new JsonResponse($payload);
        }
```
after:
```php
        if (null === $outcome->subscription) {
            return new JsonResponse(SubscribeOutcomeJson::candidates($outcome));
        }
```

- [ ] **Step 7: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/SubscriptionBulkTest.php tests/Controller/Api/MoveFeedToTagTest.php tests/Http`
Expected: OK.

- [ ] **Step 8: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 9: Commit.**

```bash
git add src/Service/Subscription/SubscriptionTallies.php src/Service/Subscription/SubscriptionTallyReader.php \
  src/Http/SubscriptionCountsJson.php src/Http/SubscriptionJson.php src/Http/SubscribeOutcomeJson.php \
  src/Controller/Api/SubscriptionController.php tests/Service/Subscription/SubscriptionTallyReaderTest.php \
  tests/Http/SubscriptionJsonListTest.php tests/Http/SubscribeOutcomeJsonTest.php tests/Http/SubscriptionCountsJsonTest.php
git commit -m "refactor(#1157): the subscription list, counts and candidates bodies move into Http mappers"
```

---

### Task 10: `AiSettingsJson::configurationFor`

**Files:**
- Modify: `src/Http/AiSettingsJson.php` (imports, one method)
- Modify: `src/Controller/Api/AiSettingsController.php` (eight `return` blocks)
- Test: `tests/Http/AiSettingsJsonTest.php` (append three tests)

**Interfaces:**
- Consumes: the existing `AiSettingsJson::configuration(AiProviderSettings, ?int): array` and `User::getActiveAiProviderSettings(): ?AiProviderSettings`. The latter is exactly what `AiProviderConfigurator::settingsFor(User)` returns.
- Produces: `AiSettingsJson::configurationFor(AiProviderSettings $settings, User $owner): array<string, mixed>`. It marks the row `active` when it is the owner's active configuration.

- [ ] **Step 1: Write the failing tests.** Append to `tests/Http/AiSettingsJsonTest.php`. Before (the end of the file):
```php
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $shape['models']);
        self::assertSame('Work OpenAI', $shape['name']);
        self::assertFalse($shape['ready']);
    }
}
```
after:
```php
        self::assertSame(['gpt-4o', 'gpt-4o-mini'], $shape['models']);
        self::assertSame('Work OpenAI', $shape['name']);
        self::assertFalse($shape['ready']);
    }

    public function testConfigurationForIsActiveWhenItIsTheOwnersActiveConfiguration(): void
    {
        $settings = $this->withId($this->settings(null), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $owner->setActiveAiProviderSettings($settings);

        self::assertTrue(AiSettingsJson::configurationFor($settings, $owner)['active']);
    }

    public function testConfigurationForIsNotActiveWhenTheOwnerHasAnotherOneActive(): void
    {
        $settings = $this->withId($this->settings(null), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));
        $owner->setActiveAiProviderSettings($this->withId($this->settings(null), 42));

        self::assertFalse(AiSettingsJson::configurationFor($settings, $owner)['active']);
    }

    public function testConfigurationForIsNotActiveWhenTheOwnerHasNoneActive(): void
    {
        $settings = $this->withId($this->settings('gpt-4o', 'Work OpenAI'), 7);
        $owner = new User('owner@example.test', new \DateTimeImmutable('2026-08-06 09:00:00'));

        self::assertSame(
            AiSettingsJson::configuration($settings, null),
            AiSettingsJson::configurationFor($settings, $owner),
        );
    }
}
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Http/AiSettingsJsonTest.php`
Expected: FAIL, because `configurationFor` is undefined.

- [ ] **Step 3: Add the method.** `src/Http/AiSettingsJson.php`: add `use App\Entity\User;` directly after `use App\Entity\AiProviderSettings;`. Insert directly after the closing `}` of `configuration()`:
```php

    /** @return array<string, mixed> */
    public static function configurationFor(AiProviderSettings $settings, User $owner): array
    {
        return self::configuration($settings, $owner->getActiveAiProviderSettings()?->getId());
    }
```

- [ ] **Step 4: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Http/AiSettingsJsonTest.php`
Expected: OK.

- [ ] **Step 5: Switch `AiSettingsController` over.**

In `duplicate`, before:
```php
            AiSettingsJson::configuration($copy, $this->configurator->settingsFor($user)?->getId()),
```
after:
```php
            AiSettingsJson::configurationFor($copy, $user),
```

In `saveModel`, `rename`, `setReasoning`, `setSlowModel`, `setBatchConcurrency`, `setMaxBatchSize` and `activate` (Edit with `replace_all: true`, seven occurrences), before:
```php
        return new JsonResponse(
            AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId()),
        );
```
after:
```php
        return new JsonResponse(AiSettingsJson::configurationFor($configuration, $user));
```
`list` keeps its single `settingsFor($user)?->getId()`, because it feeds `activeId` as well as each row.

- [ ] **Step 6: Run the contract net and the sweep.**

Run: `php bin/phpunit tests/Controller/Api/AiSettingsControllerTest.php && git grep -c "settingsFor(\$user)?->getId()" -- src/Controller/Api/AiSettingsController.php`
Expected: OK, and a count of `1` (in `list`).

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add src/Http/AiSettingsJson.php src/Controller/Api/AiSettingsController.php tests/Http/AiSettingsJsonTest.php
git commit -m "refactor(#1157): AiSettingsJson::configurationFor reads the owner's active configuration itself"
```

---

### Task 11: `VersionJson`, `OnboardingJson`, `OpmlJson`; the OPML size guard moves into `OpmlImporter`

**Files:**
- Create: `src/Http/VersionJson.php`, `src/Http/OnboardingJson.php`, `src/Http/OpmlJson.php`
- Create: `tests/Http/VersionJsonTest.php`, `tests/Http/OnboardingJsonTest.php`, `tests/Http/OpmlJsonTest.php`
- Modify: `src/Service/Opml/OpmlImporter.php` (imports, a constant, `import`)
- Modify: `src/Controller/Api/VersionController.php` (whole file)
- Modify: `src/Controller/Api/OnboardingController.php` (whole file)
- Modify: `src/Controller/Api/OpmlController.php` (whole file)
- Test: `tests/Service/Opml/OpmlImporterTest.php` (append three tests)

**Interfaces:**
- Consumes (all existing): `VersionReport`, `ReleaseVersion`, `LatestRelease`, `BulkSubscribeResult`, `OpmlImportResult`, `TagJson::one(Tag)`.
- Produces:
  - `VersionJson::of(VersionReport $report): array<string, mixed>`
  - `OnboardingJson::subscribed(BulkSubscribeResult $result): array<string, mixed>`
  - `OpmlJson::imported(OpmlImportResult $result): array{imported: int, alreadySubscribed: int, invalid: int, skippedOverLimit: int}`
  - `OpmlImporter::import()` throws `InvalidOpmlException('The OPML body is empty or larger than 1 MB.')` for an empty body or one over 1 048 576 bytes, before it parses anything. The guard stays out of `OpmlBodyReader`, because `CatalogDocument` shares that reader and has no such cap.

- [ ] **Step 1: Write the failing tests.**

`tests/Http/VersionJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\VersionJson;
use App\Service\Version\LatestRelease;
use App\Service\Version\ReleaseVersion;
use App\Service\Version\VersionReport;
use PHPUnit\Framework\TestCase;

final class VersionJsonTest extends TestCase
{
    public function testMapsTheRunningBuildAndTheLatestRelease(): void
    {
        $report = new VersionReport(
            new ReleaseVersion('v1.2.0', 'abc1234', '2026-09-01T10:00:00Z'),
            new LatestRelease('v1.3.0', 'https://example.com/releases/v1.3.0'),
            true,
        );

        self::assertSame([
            'version' => 'v1.2.0',
            'commit' => 'abc1234',
            'builtAt' => '2026-09-01T10:00:00Z',
            'latest' => ['version' => 'v1.3.0', 'notesUrl' => 'https://example.com/releases/v1.3.0'],
            'updateAvailable' => true,
        ], VersionJson::of($report));
    }

    public function testNoLatestReleaseMapsToNull(): void
    {
        $payload = VersionJson::of(new VersionReport(ReleaseVersion::development(), null, false));

        self::assertNull($payload['latest']);
        self::assertFalse($payload['updateAvailable']);
    }
}
```

`tests/Http/OnboardingJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\Tag;
use App\Entity\User;
use App\Http\OnboardingJson;
use App\Http\TagJson;
use App\Service\Subscription\BulkSubscribeResult;
use PHPUnit\Framework\TestCase;

final class OnboardingJsonTest extends TestCase
{
    public function testSkippedSumsEverySkipReasonAndTheCreatedTagsAreMapped(): void
    {
        $tag = new Tag(new User('onboarding@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z')), 'Science');
        $result = new BulkSubscribeResult(
            imported: 3,
            alreadySubscribed: 1,
            invalid: 2,
            skippedOverLimit: 4,
            tagsCreated: [$tag],
        );

        self::assertSame([
            'subscribed' => 3,
            'skipped' => 7,
            'skippedOverLimit' => 4,
            'tagsCreated' => [TagJson::one($tag)],
        ], OnboardingJson::subscribed($result));
    }
}
```

`tests/Http/OpmlJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\OpmlJson;
use App\Service\Opml\OpmlImportResult;
use PHPUnit\Framework\TestCase;

final class OpmlJsonTest extends TestCase
{
    public function testMapsEveryCount(): void
    {
        self::assertSame(
            ['imported' => 5, 'alreadySubscribed' => 2, 'invalid' => 1, 'skippedOverLimit' => 3],
            OpmlJson::imported(new OpmlImportResult(5, 2, 1, 3)),
        );
    }
}
```

`tests/Service/Opml/OpmlImporterTest.php`: append at the end of the class. Before:
```php
        self::assertSame($feedsBefore, $feedsAfter); // no orphan Feed rows created
    }
}
```
after:
```php
        self::assertSame($feedsBefore, $feedsAfter); // no orphan Feed rows created
    }

    public function testAnEmptyBodyIsRefusedBeforeParsing(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('The OPML body is empty or larger than 1 MB.');

        $this->importer()->import($this->user('empty-opml@example.com'), '');
    }

    public function testABodyOverOneMegabyteIsRefusedBeforeParsing(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('The OPML body is empty or larger than 1 MB.');

        $this->importer()->import($this->user('large-opml@example.com'), str_repeat('a', 1_048_577));
    }

    public function testABodyOfExactlyOneMegabyteReachesTheParser(): void
    {
        $this->expectException(InvalidOpmlException::class);
        $this->expectExceptionMessage('Not a well-formed OPML 2.0 document.');

        $this->importer()->import($this->user('limit-opml@example.com'), str_repeat('a', 1_048_576));
    }
}
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Http/VersionJsonTest.php tests/Http/OnboardingJsonTest.php tests/Http/OpmlJsonTest.php tests/Service/Opml/OpmlImporterTest.php`
Expected: FAIL. The three mappers do not exist. The empty and oversized bodies reach the parser, which reports `Not a well-formed OPML 2.0 document.`

- [ ] **Step 3: Write the mappers.**

`src/Http/VersionJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Version\VersionReport;

final class VersionJson
{
    /** @return array<string, mixed> */
    public static function of(VersionReport $report): array
    {
        $latest = $report->latest;

        return [
            'version' => $report->running->version,
            'commit' => $report->running->commit,
            'builtAt' => $report->running->builtAt,
            'latest' => null === $latest ? null : [
                'version' => $latest->version,
                'notesUrl' => $latest->notesUrl,
            ],
            'updateAvailable' => $report->updateAvailable,
        ];
    }
}
```

`src/Http/OnboardingJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Tag;
use App\Service\Subscription\BulkSubscribeResult;

final class OnboardingJson
{
    /** @return array<string, mixed> */
    public static function subscribed(BulkSubscribeResult $result): array
    {
        return [
            'subscribed' => $result->imported,
            'skipped' => $result->alreadySubscribed + $result->invalid + $result->skippedOverLimit,
            'skippedOverLimit' => $result->skippedOverLimit,
            'tagsCreated' => array_map(static fn (Tag $tag) => TagJson::one($tag), $result->tagsCreated),
        ];
    }
}
```

`src/Http/OpmlJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Opml\OpmlImportResult;

final class OpmlJson
{
    /** @return array{imported: int, alreadySubscribed: int, invalid: int, skippedOverLimit: int} */
    public static function imported(OpmlImportResult $result): array
    {
        return [
            'imported' => $result->imported,
            'alreadySubscribed' => $result->alreadySubscribed,
            'invalid' => $result->invalid,
            'skippedOverLimit' => $result->skippedOverLimit,
        ];
    }
}
```

- [ ] **Step 4: Move the size guard into `OpmlImporter`.** Add `use App\Service\Opml\Exception\InvalidOpmlException;` directly after `use App\Entity\User;`.

Before:
```php
final readonly class OpmlImporter
{
    public function __construct(
```
after:
```php
final readonly class OpmlImporter
{
    private const int MAX_BYTES = 1_048_576;

    public function __construct(
```
Before:
```php
    public function import(User $user, string $opml): OpmlImportResult
    {
        $body = $this->bodyReader->read($opml);
```
after:
```php
    public function import(User $user, string $opml): OpmlImportResult
    {
        if ($opml === '' || \strlen($opml) > self::MAX_BYTES) {
            throw new InvalidOpmlException('The OPML body is empty or larger than 1 MB.');
        }

        $body = $this->bodyReader->read($opml);
```

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Http/VersionJsonTest.php tests/Http/OnboardingJsonTest.php tests/Http/OpmlJsonTest.php tests/Service/Opml/OpmlImporterTest.php`
Expected: OK.

- [ ] **Step 6: Rewrite the three controllers.**

`src/Controller/Api/VersionController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\VersionJson;
use App\Service\Version\VersionReporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Which build the API is running, and whether a newer release exists upstream.
 * The SPA carries its own version baked in at build time and compares the two:
 * when they differ, the browser is holding a cached bundle from an earlier
 * release. `latest`/`updateAvailable` drive the sidebar's update badge; both
 * fall silent (null / false) whenever the upstream check has nothing to report.
 */
final class VersionController
{
    #[Route('/api/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(VersionReporter $reporter): JsonResponse
    {
        return new JsonResponse(VersionJson::of($reporter->report()));
    }
}
```

`src/Controller/Api/OnboardingController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Onboarding\OnboardingSubscribeRequest;
use App\Entity\User;
use App\Http\OnboardingJson;
use App\Service\Catalog\CatalogSubscriber;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/onboarding')]
final readonly class OnboardingController
{
    public function __construct(
        private CatalogSubscriber $subscriber,
    ) {
    }

    /**
     * Subscribes a picker selection and fetches nothing: the new feeds are due at once, and the frontend
     * triggers the sweep after navigating into the reader, so this returns promptly however many were picked.
     */
    #[Route('/subscribe', name: 'api_onboarding_subscribe', methods: ['POST'])]
    public function subscribe(
        #[CurrentUser] User $user,
        #[MapRequestPayload] OnboardingSubscribeRequest $request,
    ): JsonResponse {
        return new JsonResponse(OnboardingJson::subscribed(
            $this->subscriber->subscribe($user, $request->catalogFeedIds),
        ));
    }
}
```

`src/Controller/Api/OpmlController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\OpmlJson;
use App\Service\Opml\OpmlExporter;
use App\Service\Opml\OpmlImporter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/opml')]
final readonly class OpmlController
{
    public function __construct(
        private OpmlExporter $exporter,
        private OpmlImporter $importer,
    ) {
    }

    /**
     * @throws \DOMException
     */
    #[Route('/export', name: 'api_opml_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user): Response
    {
        $xml = $this->exporter->export($user);

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'text/x-opml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="feeds.opml"',
        ]);
    }

    #[Route('/import', name: 'api_opml_import', methods: ['POST'])]
    public function import(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(OpmlJson::imported($this->importer->import($user, $request->getContent())));
    }
}
```

- [ ] **Step 7: Run the contract net.**

Run: `php bin/phpunit tests/Controller/Api/VersionControllerTest.php tests/Controller/Api/OnboardingControllerTest.php tests/Controller/Api/OpmlControllerTest.php`
Expected: OK. `testImportRejectsEmptyBody` and `testImportRejectsOversizedBody` still get their 422.

- [ ] **Step 8: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 9: Commit.**

```bash
git add src/Http/VersionJson.php src/Http/OnboardingJson.php src/Http/OpmlJson.php src/Service/Opml/OpmlImporter.php \
  src/Controller/Api/VersionController.php src/Controller/Api/OnboardingController.php src/Controller/Api/OpmlController.php \
  tests/Http/VersionJsonTest.php tests/Http/OnboardingJsonTest.php tests/Http/OpmlJsonTest.php tests/Service/Opml/OpmlImporterTest.php
git commit -m "refactor(#1157): version, onboarding and OPML bodies move into Http mappers; OpmlImporter owns the size guard"
```

---
### Task 12: Admin catalog and user-limit mappers, `BundledCatalog::summary()`

**Files:**
- Create: `src/Service/Catalog/BundledCatalogSummary.php`
- Create: `src/Http/AdminUserLimitsJson.php`
- Create: `tests/Service/Catalog/BundledCatalogTest.php`
- Create: `tests/Http/AdminCatalogJsonTest.php`
- Create: `tests/Http/AdminUserLimitsJsonTest.php`
- Modify: `src/Service/Catalog/BundledCatalog.php` (one method)
- Modify: `src/Http/AdminCatalogJson.php` (imports, three methods)
- Modify: `src/Repository/CatalogFeedRepository.php` (one method)
- Modify: `src/Controller/Admin/AdminCatalogController.php` (whole file)
- Modify: `src/Controller/Admin/AdminCatalogImportController.php` (imports, `describeBundled`)
- Modify: `src/Controller/Admin/AdminUserLimitsController.php` (whole file)
- Test: `tests/Repository/CatalogFeedRepositoryTest.php` (append one test)

**Interfaces:**
- Consumes: the existing `BundledCatalog::document(): ParsedCatalog`, which throws `InvalidCatalogDocumentException`, and the existing `ParsedCatalog`, `CatalogWarmReport`, `CatalogCategoryRepository::findAllOrdered()`.
- Produces:
  - `BundledCatalogSummary` (`public bool $available, public int $categories, public int $feeds`), with `of(ParsedCatalog)` and `unavailable()`.
  - `BundledCatalog::summary(): BundledCatalogSummary`. A missing or corrupt document reads as unavailable.
  - `AdminCatalogJson::listing(list<CatalogCategory>, list<CatalogFeed>): array`, `AdminCatalogJson::warmReport(CatalogWarmReport): array` and `AdminCatalogJson::bundled(BundledCatalogSummary): array`.
  - `CatalogFeedRepository::findAllOrdered(): list<CatalogFeed>`, ordered by position, then title. It is the same order as the controller's `findBy([], ['position' => 'ASC', 'title' => 'ASC'])`.
  - `AdminUserLimitsJson::trial(User): array{status: string, trialEndsAt: string|null}` and `AdminUserLimitsJson::subscriptionLimit(User): array{maxSubscriptions: int|null}`.

- [ ] **Step 1: Write the failing tests.**

`tests/Service/Catalog/BundledCatalogTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BundledCatalogTest extends KernelTestCase
{
    public function testTheShippedDocumentIsSummarisedWithoutImportingIt(): void
    {
        $bundled = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $bundled);
        $document = $bundled->document();

        $summary = $bundled->summary();

        self::assertTrue($summary->available);
        self::assertSame(\count($document->categories), $summary->categories);
        self::assertSame($document->feedCount(), $summary->feeds);
        self::assertGreaterThan(0, $summary->feeds);
    }

    public function testAMissingDocumentSummarisesAsUnavailable(): void
    {
        $parser = self::getContainer()->get(CatalogDocument::class);
        self::assertInstanceOf(CatalogDocument::class, $parser);
        $bundled = new BundledCatalog($parser, sys_get_temp_dir() . '/no-catalog-' . bin2hex(random_bytes(6)));

        $summary = $bundled->summary();

        self::assertFalse($summary->available);
        self::assertSame(0, $summary->categories);
        self::assertSame(0, $summary->feeds);
    }
}
```

`tests/Http/AdminCatalogJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Http\AdminCatalogJson;
use App\Service\Catalog\BundledCatalogSummary;
use App\Service\Catalog\CatalogDocumentCategory;
use App\Service\Catalog\CatalogDocumentFeed;
use App\Service\Catalog\CatalogWarmReport;
use App\Service\Catalog\ParsedCatalog;
use PHPUnit\Framework\TestCase;

final class AdminCatalogJsonTest extends TestCase
{
    public function testTheListingMapsEveryCategoryAndEveryFeed(): void
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, 'The Verge', 'https://example.com/verge.xml');

        self::assertSame(
            ['categories' => [AdminCatalogJson::category($category)], 'feeds' => [AdminCatalogJson::feed($feed)]],
            AdminCatalogJson::listing([$category], [$feed]),
        );
    }

    public function testTheWarmReportMapsEveryCount(): void
    {
        self::assertSame(
            ['warmed' => 3, 'failed' => 1, 'remaining' => 7],
            AdminCatalogJson::warmReport(new CatalogWarmReport(3, 1, 7)),
        );
    }

    public function testAnAvailableBundledDocumentReportsItsSize(): void
    {
        $feed = new CatalogDocumentFeed('Feed', 'https://example.com/feed.xml', null, null, 'rss');
        $document = new ParsedCatalog([
            new CatalogDocumentCategory('a', 'A', 'memory', '#3b82f6', [$feed, $feed]),
            new CatalogDocumentCategory('b', 'B', 'memory', '#3b82f6', [$feed]),
        ]);

        self::assertSame(
            ['available' => true, 'categories' => 2, 'feeds' => 3],
            AdminCatalogJson::bundled(BundledCatalogSummary::of($document)),
        );
    }

    public function testAnUnavailableBundledDocumentReportsZeroes(): void
    {
        self::assertSame(
            ['available' => false, 'categories' => 0, 'feeds' => 0],
            AdminCatalogJson::bundled(BundledCatalogSummary::unavailable()),
        );
    }
}
```

`tests/Http/AdminUserLimitsJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Http\AdminUserLimitsJson;
use PHPUnit\Framework\TestCase;

final class AdminUserLimitsJsonTest extends TestCase
{
    public function testATrialReportsTheStatusAndItsEnd(): void
    {
        $user = $this->user();
        $user->setStatus(UserStatus::Active);
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'));

        self::assertSame(
            ['status' => 'active', 'trialEndsAt' => '2026-10-01T00:00:00+00:00'],
            AdminUserLimitsJson::trial($user),
        );
    }

    public function testNoTrialReportsANullEnd(): void
    {
        self::assertSame(
            ['status' => 'pending_verification', 'trialEndsAt' => null],
            AdminUserLimitsJson::trial($this->user()),
        );
    }

    public function testTheSubscriptionLimitIsReported(): void
    {
        $user = $this->user();
        $user->setMaxSubscriptions(25);

        self::assertSame(['maxSubscriptions' => 25], AdminUserLimitsJson::subscriptionLimit($user));
    }

    private function user(): User
    {
        return new User('limits@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
```

`tests/Repository/CatalogFeedRepositoryTest.php`: append at the end of the class. Before:
```php
        $rows = $repository->findNeedingFavicon($staleBefore, $retryBefore, 2);

        self::assertCount(2, $rows);
    }
}
```
after:
```php
        $rows = $repository->findNeedingFavicon($staleBefore, $retryBefore, 2);

        self::assertCount(2, $rows);
    }

    public function testFindAllOrderedSortsByPositionThenTitleAndKeepsDisabledFeeds(): void
    {
        $category = new CatalogCategory('ordering', 'Ordering', 'sort', '#6b7280');
        $bravo = new CatalogFeed($category, 'Bravo', 'https://example.com/bravo.xml');
        $bravo->setPosition(1);
        $alpha = new CatalogFeed($category, 'Alpha', 'https://example.com/alpha.xml');
        $alpha->setPosition(1);
        $zulu = new CatalogFeed($category, 'Zulu', 'https://example.com/zulu.xml');
        $zulu->setPosition(0);
        $zulu->setEnabled(false);
        $this->em->persist($category);
        foreach ([$bravo, $alpha, $zulu] as $feed) {
            $this->em->persist($feed);
        }
        $this->em->flush();

        $repository = self::getContainer()->get(CatalogFeedRepository::class);
        self::assertInstanceOf(CatalogFeedRepository::class, $repository);
        $mine = array_filter(
            $repository->findAllOrdered(),
            static fn (CatalogFeed $feed): bool => $feed->getCategory() === $category,
        );

        self::assertSame(
            ['Zulu', 'Alpha', 'Bravo'],
            array_values(array_map(static fn (CatalogFeed $feed): string => $feed->getTitle(), $mine)),
        );
    }
}
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Service/Catalog/BundledCatalogTest.php tests/Http/AdminCatalogJsonTest.php tests/Http/AdminUserLimitsJsonTest.php tests/Repository/CatalogFeedRepositoryTest.php`
Expected: FAIL. `summary`, `BundledCatalogSummary`, the three `AdminCatalogJson` methods, `AdminUserLimitsJson` and `findAllOrdered` do not exist.

- [ ] **Step 3: Write the summary, and add `summary()`.**

`src/Service/Catalog/BundledCatalogSummary.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class BundledCatalogSummary
{
    private function __construct(
        public bool $available,
        public int $categories,
        public int $feeds,
    ) {
    }

    public static function of(ParsedCatalog $document): self
    {
        return new self(true, \count($document->categories), $document->feedCount());
    }

    public static function unavailable(): self
    {
        return new self(false, 0, 0);
    }
}
```

`src/Service/Catalog/BundledCatalog.php`: insert directly after the closing `}` of `document()`:
```php

    public function summary(): BundledCatalogSummary
    {
        try {
            return BundledCatalogSummary::of($this->document());
        } catch (InvalidCatalogDocumentException) {
            // Missing or corrupt reads as unavailable, not a 500: the admin can still upload a file.
            return BundledCatalogSummary::unavailable();
        }
    }
```
`InvalidCatalogDocumentException` is already imported there.

- [ ] **Step 4: Write the mappers and the repository method.**

`src/Http/AdminCatalogJson.php`: add `use App\Service\Catalog\BundledCatalogSummary;` directly before `use App\Service\Catalog\CatalogImportResult;`, and `use App\Service\Catalog\CatalogWarmReport;` directly after it. Insert these methods directly after the closing `}` of `importResult()`:
```php

    /**
     * @param list<CatalogCategory> $categories
     * @param list<CatalogFeed>     $feeds
     *
     * @return array{categories: list<array<string, mixed>>, feeds: list<array<string, mixed>>}
     */
    public static function listing(array $categories, array $feeds): array
    {
        return [
            'categories' => array_map(self::category(...), $categories),
            'feeds' => array_map(self::feed(...), $feeds),
        ];
    }

    /** @return array{warmed: int, failed: int, remaining: int} */
    public static function warmReport(CatalogWarmReport $report): array
    {
        return [
            'warmed' => $report->warmed,
            'failed' => $report->failed,
            'remaining' => $report->remaining,
        ];
    }

    /** @return array{available: bool, categories: int, feeds: int} */
    public static function bundled(BundledCatalogSummary $summary): array
    {
        return [
            'available' => $summary->available,
            'categories' => $summary->categories,
            'feeds' => $summary->feeds,
        ];
    }
```

`src/Http/AdminUserLimitsJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;

final class AdminUserLimitsJson
{
    /** @return array{status: string, trialEndsAt: string|null} */
    public static function trial(User $user): array
    {
        return [
            'status' => $user->getStatus()->value,
            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array{maxSubscriptions: int|null} */
    public static function subscriptionLimit(User $user): array
    {
        return ['maxSubscriptions' => $user->getMaxSubscriptions()];
    }
}
```

`src/Repository/CatalogFeedRepository.php`: insert directly after the closing `}` of `getById()`:
```php

    /** @return list<CatalogFeed> every catalog feed in admin order, enabled or not */
    public function findAllOrdered(): array
    {
        /** @var list<CatalogFeed> $rows */
        $rows = $this->createQueryBuilder('f')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.title', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Catalog/BundledCatalogTest.php tests/Http/AdminCatalogJsonTest.php tests/Http/AdminUserLimitsJsonTest.php tests/Repository/CatalogFeedRepositoryTest.php`
Expected: OK.

- [ ] **Step 6: Switch the three admin controllers over.**

`src/Controller/Admin/AdminCatalogController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalog-wide administration: the full listing and the budgeted favicon warm.
 * Per-resource CRUD lives in AdminCatalogCategoryController and
 * AdminCatalogFeedController.
 *
 * Access is enforced by ROLE_ADMIN on ^/api/admin/ in the firewall, consistent
 * with AdminUserController.
 */
#[Route('/api/admin/catalog')]
final class AdminCatalogController
{
    /** Comfortably inside any sane PHP max_execution_time, and long enough that
     *  111 icons take a handful of polls rather than dozens. */
    private const int WARM_BUDGET_SECONDS = 15;

    public function __construct(
        private readonly CatalogCategoryRepository $categories,
        private readonly CatalogFeedRepository $feeds,
        private readonly CatalogFaviconWarmer $warmer,
    ) {
    }

    #[Route('', name: 'api_admin_catalog_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::listing(
            $this->categories->findAllOrdered(),
            $this->feeds->findAllOrdered(),
        ));
    }

    /**
     * One budgeted slice of favicon warming, polled until `remaining` is 0, as /api/refresh is. It makes icons a
     * property of the app: an install that never runs a console command still gets them.
     */
    #[Route('/favicons/warm', name: 'api_admin_catalog_warm_favicons', methods: ['POST'])]
    public function warmFavicons(): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::warmReport($this->warmer->warm(self::WARM_BUDGET_SECONDS)));
    }
}
```
The class docblock and the constant's docblock are develop's, unchanged. `warmFavicons`' docblock is trimmed to two lines under PR A ruling F8, because the method is rewritten.

`src/Controller/Admin/AdminCatalogImportController.php`: delete `use App\Service\Catalog\Exception\InvalidCatalogDocumentException;`. Then, before:
```php
    public function describeBundled(): JsonResponse
    {
        try {
            $document = $this->bundled->document();
        } catch (InvalidCatalogDocumentException) {
            // Missing or corrupt: report it as unavailable rather than 500. The
            // admin can still upload a file, which is the more useful answer.
            return new JsonResponse(['available' => false, 'categories' => 0, 'feeds' => 0]);
        }

        return new JsonResponse([
            'available' => true,
            'categories' => \count($document->categories),
            'feeds' => $document->feedCount(),
        ]);
    }
```
after:
```php
    public function describeBundled(): JsonResponse
    {
        return new JsonResponse(AdminCatalogJson::bundled($this->bundled->summary()));
    }
```

`src/Controller/Admin/AdminUserLimitsController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\SetSubscriptionLimitRequest;
use App\Dto\Admin\StartTrialRequest;
use App\Http\AdminUserLimitsJson;
use App\Repository\UserRepository;
use App\Service\Admin\UserLimits;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin's per-account limit controls: start or clear a trial, and set or
 * clear the per-user subscription cap. Split out of AdminUserController so
 * that controller's constructor does not grow past PHPStorm/PHPMD's
 * ExcessiveParameterList threshold — these three actions need only the two
 * collaborators below. Access is enforced by ROLE_ADMIN on ^/api/admin/ in
 * security.yaml, the same as every other controller under that prefix.
 */
#[Route('/api/admin/users')]
final readonly class AdminUserLimitsController
{
    public function __construct(
        private UserRepository $users,
        private UserLimits $userLimits,
    ) {
    }

    #[Route('/{id}/trial', name: 'api_admin_users_start_trial', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function startTrial(int $id, #[MapRequestPayload] StartTrialRequest $request): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->startTrial($user, $request->days);

        return new JsonResponse(AdminUserLimitsJson::trial($user));
    }

    #[Route('/{id}/trial', name: 'api_admin_users_clear_trial', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function clearTrial(int $id): JsonResponse
    {
        $user = $this->users->getById($id);
        $this->userLimits->clearTrial($user);

        return new JsonResponse(AdminUserLimitsJson::trial($user));
    }

    #[Route(
        '/{id}/subscription-limit',
        name: 'api_admin_users_set_subscription_limit',
        methods: ['PUT'],
        requirements: ['id' => '\d+'],
    )]
    public function setSubscriptionLimit(
        int $id,
        #[MapRequestPayload] SetSubscriptionLimitRequest $request,
    ): JsonResponse {
        $user = $this->users->getById($id);
        $this->userLimits->setSubscriptionLimit($user, $request->maxSubscriptions);

        return new JsonResponse(AdminUserLimitsJson::subscriptionLimit($user));
    }
}
```

- [ ] **Step 7: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Admin/AdminCatalogControllerTest.php tests/Controller/Admin/AdminCatalogImportControllerTest.php tests/Controller/Admin/AdminUserLimitsControllerTest.php tests/Command/ImportCatalogCommandTest.php`
Expected: OK.

- [ ] **Step 8: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 9: Commit.**

```bash
git add src/Service/Catalog/BundledCatalogSummary.php src/Service/Catalog/BundledCatalog.php src/Http/AdminCatalogJson.php \
  src/Http/AdminUserLimitsJson.php src/Repository/CatalogFeedRepository.php src/Controller/Admin/AdminCatalogController.php \
  src/Controller/Admin/AdminCatalogImportController.php src/Controller/Admin/AdminUserLimitsController.php \
  tests/Service/Catalog/BundledCatalogTest.php tests/Http/AdminCatalogJsonTest.php tests/Http/AdminUserLimitsJsonTest.php \
  tests/Repository/CatalogFeedRepositoryTest.php
git commit -m "refactor(#1157): admin catalog and user-limit bodies move into Http mappers; BundledCatalog summarises itself"
```

---

### Task 13: Catalog favicon: `CatalogFaviconSource` + `CatalogFaviconResponse`

**Files:**
- Create: `src/Service/Catalog/CatalogFavicon.php`
- Create: `src/Service/Catalog/CatalogFaviconSource.php`
- Create: `src/Http/CatalogFaviconResponse.php`
- Create: `tests/Service/Catalog/CatalogFaviconSourceTest.php`
- Create: `tests/Http/CatalogFaviconResponseTest.php`
- Modify: `src/Controller/Api/CatalogController.php` (whole file)
- Test: `tests/Controller/Api/CatalogFaviconControllerTest.php` (one `detail` assertion, D1/D2)

**Interfaces:**
- Consumes: the existing `MonogramFavicon::render(CatalogFeed): string`, `MonogramFavicon::CONTENT_TYPE`, `CatalogFeed::getFaviconBytes()` / `getFaviconContentType()`, and `CatalogFeedRepository::getById(int): CatalogFeed`, which throws `RecordNotFoundException('No such feed.')`.
- Produces:
  - `final readonly class CatalogFavicon(public string $bytes, public string $contentType)`.
  - `CatalogFaviconSource::imageFor(CatalogFeed $feed): CatalogFavicon`, which returns the cached bytes, or the monogram when either the bytes or the type is missing.
  - `CatalogFaviconResponse::of(CatalogFavicon $favicon): Response`, which returns a 200 with the content type, an ETag of `md5(bytes)`, `public` and `max-age=86400`.
- The lookup becomes `CatalogFeedRepository::getById()` (D2 under D1). The unknown-feed 404 gains `"detail": "No such feed."`, and no `NotFoundHttpException` is left in `src/Controller`.

- [ ] **Step 1: Write the failing tests.**

`tests/Service/Catalog/CatalogFaviconSourceTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Catalog\CatalogFaviconSource;
use App\Service\Catalog\MonogramFavicon;
use PHPUnit\Framework\TestCase;

final class CatalogFaviconSourceTest extends TestCase
{
    public function testACachedIconIsServedAsStored(): void
    {
        $feed = $this->feed();
        $feed->storeFavicon(
            'https://example.com/favicon.png',
            'png-bytes',
            'image/png',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );

        $favicon = (new CatalogFaviconSource(new MonogramFavicon()))->imageFor($feed);

        self::assertSame('png-bytes', $favicon->bytes);
        self::assertSame('image/png', $favicon->contentType);
    }

    public function testAFeedWithoutACachedIconGetsTheMonogram(): void
    {
        $feed = $this->feed();
        $monogram = new MonogramFavicon();

        $favicon = (new CatalogFaviconSource($monogram))->imageFor($feed);

        self::assertSame($monogram->render($feed), $favicon->bytes);
        self::assertSame(MonogramFavicon::CONTENT_TYPE, $favicon->contentType);
    }

    private function feed(): CatalogFeed
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        return new CatalogFeed($category, 'The Verge', 'https://example.com/feed.xml');
    }
}
```

`tests/Http/CatalogFaviconResponseTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\CatalogFaviconResponse;
use App\Service\Catalog\CatalogFavicon;
use PHPUnit\Framework\TestCase;

final class CatalogFaviconResponseTest extends TestCase
{
    public function testServesTheBytesWithTheirTypeAnETagAndADayOfPublicCaching(): void
    {
        $response = CatalogFaviconResponse::of(new CatalogFavicon('icon-bytes', 'image/png'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('icon-bytes', $response->getContent());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('"' . md5('icon-bytes') . '"', $response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('86400', $response->headers->getCacheControlDirective('max-age'));
    }
}
```

`tests/Controller/Api/CatalogFaviconControllerTest.php`, in `testAnUnknownFeedIs404`, before:
```php
        $client->request('GET', '/api/catalog/feeds/999999/favicon', server: $headers);

        self::assertResponseStatusCodeSame(404);
    }
```
after:
```php
        $client->request('GET', '/api/catalog/feeds/999999/favicon', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such feed."', (string) $client->getResponse()->getContent());
    }
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFaviconSourceTest.php tests/Http/CatalogFaviconResponseTest.php tests/Controller/Api/CatalogFaviconControllerTest.php`
Expected: FAIL. The classes do not exist, and the unknown-feed 404 carries no `detail` yet.

- [ ] **Step 3: Write the value, the source and the response.**

`src/Service/Catalog/CatalogFavicon.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogFavicon
{
    public function __construct(
        public string $bytes,
        public string $contentType,
    ) {
    }
}
```

`src/Service/Catalog/CatalogFaviconSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;

/** Never fetches: a missing icon is a normal state that app:catalog:warm-favicons fills at deploy time. */
final readonly class CatalogFaviconSource
{
    public function __construct(private MonogramFavicon $monogram)
    {
    }

    public function imageFor(CatalogFeed $feed): CatalogFavicon
    {
        $bytes = $feed->getFaviconBytes();
        $contentType = $feed->getFaviconContentType();

        if (null === $bytes || null === $contentType) {
            return new CatalogFavicon($this->monogram->render($feed), MonogramFavicon::CONTENT_TYPE);
        }

        return new CatalogFavicon($bytes, $contentType);
    }
}
```

`src/Http/CatalogFaviconResponse.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Catalog\CatalogFavicon;
use Symfony\Component\HttpFoundation\Response;

final class CatalogFaviconResponse
{
    /** A day is safe: the URL is per feed id, and the ETag changes whenever the bytes do. */
    private const int MAX_AGE_SECONDS = 86400;

    public static function of(CatalogFavicon $favicon): Response
    {
        $response = new Response($favicon->bytes, Response::HTTP_OK, ['Content-Type' => $favicon->contentType]);
        $response->setEtag(md5($favicon->bytes));
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
```

- [ ] **Step 4: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFaviconSourceTest.php tests/Http/CatalogFaviconResponseTest.php`
Expected: OK.

- [ ] **Step 5: Rewrite the controller.** `src/Controller/Api/CatalogController.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\CatalogFaviconResponse;
use App\Http\CatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Repository\FeedRepository;
use App\Service\Catalog\CatalogFaviconSource;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/catalog')]
final readonly class CatalogController
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private FeedRepository $feeds,
        private CatalogFeedRepository $catalogFeeds,
        private CatalogFaviconSource $favicons,
    ) {
    }

    #[Route('', name: 'api_catalog_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(CatalogJson::many(
            $this->categories->findEnabledWithFeeds(),
            $this->feeds->subscribedUrlSetForUser($user->requireId()),
        ));
    }

    #[Route('/feeds/{id}/favicon', name: 'api_catalog_favicon', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function favicon(int $id): Response
    {
        return CatalogFaviconResponse::of($this->favicons->imageFor($this->catalogFeeds->getById($id)));
    }
}
```
The action's old docblock is split between two homes. "NEVER fetches" is now `CatalogFaviconSource`'s class comment, and the max-age reasoning is on `CatalogFaviconResponse::MAX_AGE_SECONDS`.

- [ ] **Step 6: Run the contract net.**

Run: `bin/console cache:clear && php bin/phpunit tests/Controller/Api/CatalogFaviconControllerTest.php tests/Controller/Api/CatalogControllerTest.php tests/Service/Catalog/MonogramFaviconTest.php`
Expected: OK. This covers the cached bytes with an ETag, the monogram fallback, the unknown feed 404 with its new `detail`, and access without authentication.

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/Catalog/CatalogFavicon.php src/Service/Catalog/CatalogFaviconSource.php src/Http/CatalogFaviconResponse.php \
  src/Controller/Api/CatalogController.php tests/Service/Catalog/CatalogFaviconSourceTest.php tests/Http/CatalogFaviconResponseTest.php \
  tests/Controller/Api/CatalogFaviconControllerTest.php
git commit -m "refactor(#1157): the favicon's bytes-or-monogram choice and its cache headers leave CatalogController"
```

---

### Task 14: `CatalogUrlChecker` (CheckCatalogUrlsCommand)

**Files:**
- Create: `src/Service/Catalog/CatalogUrlChecker.php`
- Create: `src/Service/Catalog/CatalogUrlReport.php`
- Create: `src/Service/Catalog/BrokenCatalogUrl.php`
- Create: `tests/Service/Catalog/CatalogUrlCheckerTest.php`
- Modify: `src/Command/CheckCatalogUrlsCommand.php` (whole file)
- Modify: `config/services.yaml` (the `catalog.rot_check.http_client` block)
- Modify: `tests/Service/Fetch/OutboundUserAgentWiringTest.php` (one import, one yield)

**Interfaces:**
- Consumes (all existing): `BundledCatalog::document(): ParsedCatalog`, `ProxyEgressResolver::resolve(): ?ProxyConfig`, `EgressOptions::proxied(ProxyConfig): array`, `BrokenCatalogUrlException`, and the `$userAgent` bind (`%outbound_user_agent%`).
- Produces:
  - `CatalogUrlChecker::check(?int $limit): CatalogUrlReport`. `null` checks every feed.
  - `CatalogUrlReport` (`public int $checked`, `public list<BrokenCatalogUrl> $broken`, `isHealthy(): bool`).
  - `BrokenCatalogUrl` (`public string $title, public string $url, public string $reason`).
- Behaviour:
  - The command's output and exit codes are unchanged.
  - The document now comes from `BundledCatalog`. That is the same file (`%kernel.project_dir%/resources/catalog/catalog.opml` equals `dirname(__DIR__, 2)` from `src/Command`), and a missing file now fails with `InvalidCatalogDocumentException` instead of a PHP warning.

- [ ] **Step 1: Write the failing test.** `tests/Service/Catalog/CatalogUrlCheckerTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\BundledCatalog;
use App\Service\Catalog\CatalogUrlChecker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogUrlCheckerTest extends KernelTestCase
{
    private const string FEED_BODY = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel>'
        . '</rss>';

    public function testAUrlThatAnswersWithAnErrorStatusIsReportedBroken(): void
    {
        $checker = $this->checker(new MockHttpClient(
            static fn (): MockResponse => new MockResponse('', ['http_code' => 404]),
        ));
        $firstFeed = $this->bundled()->document()->categories[0]->feeds[0];

        $report = $checker->check(1);

        self::assertSame(1, $report->checked);
        self::assertFalse($report->isHealthy());
        self::assertCount(1, $report->broken);
        self::assertSame($firstFeed->title, $report->broken[0]->title);
        self::assertSame($firstFeed->url, $report->broken[0]->url);
        self::assertSame('HTTP 404', $report->broken[0]->reason);
    }

    public function testUrlsThatServeAFeedLeaveTheReportHealthy(): void
    {
        $checker = $this->checker(new MockHttpClient(static fn (): MockResponse => new MockResponse(self::FEED_BODY)));

        $report = $checker->check(2);

        self::assertSame(2, $report->checked);
        self::assertSame([], $report->broken);
        self::assertTrue($report->isHealthy());
    }

    public function testNoLimitChecksTheWholeShippedCatalog(): void
    {
        $checker = $this->checker(new MockHttpClient(static fn (): MockResponse => new MockResponse(self::FEED_BODY)));

        $report = $checker->check(null);

        self::assertSame($this->bundled()->document()->feedCount(), $report->checked);
    }

    private function checker(MockHttpClient $client): CatalogUrlChecker
    {
        self::getContainer()->set('catalog.rot_check.http_client', $client);
        $checker = self::getContainer()->get(CatalogUrlChecker::class);
        self::assertInstanceOf(CatalogUrlChecker::class, $checker);

        return $checker;
    }

    private function bundled(): BundledCatalog
    {
        $bundled = self::getContainer()->get(BundledCatalog::class);
        self::assertInstanceOf(BundledCatalog::class, $bundled);

        return $bundled;
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Catalog/CatalogUrlCheckerTest.php`
Expected: FAIL, because the class `CatalogUrlChecker` is not found.

- [ ] **Step 3: Write the report types.**

`src/Service/Catalog/BrokenCatalogUrl.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class BrokenCatalogUrl
{
    public function __construct(
        public string $title,
        public string $url,
        public string $reason,
    ) {
    }
}
```

`src/Service/Catalog/CatalogUrlReport.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

final readonly class CatalogUrlReport
{
    /** @param list<BrokenCatalogUrl> $broken */
    public function __construct(
        public int $checked,
        public array $broken,
    ) {
    }

    public function isHealthy(): bool
    {
        return [] === $this->broken;
    }
}
```

- [ ] **Step 4: Write the checker.** The request and the feed sniffing are copied verbatim from the command's `assertServesFeed()`. `src/Service/Catalog/CatalogUrlChecker.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Service\Catalog\Exception\BrokenCatalogUrlException;
use App\Service\Fetch\EgressOptions;
use App\Service\Fetch\ProxyConfig;
use App\Service\Fetch\ProxyEgressResolver;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class CatalogUrlChecker
{
    private const int TIMEOUT_SECONDS = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private BundledCatalog $bundled,
        private string $userAgent,
        private ProxyEgressResolver $proxyEgressResolver,
    ) {
    }

    public function check(?int $limit): CatalogUrlReport
    {
        $feeds = $this->feedsToCheck($limit);
        // Once per sweep: the instance proxy cannot change mid-run, and each read costs a row lookup and a decryption.
        $proxy = $this->proxyEgressResolver->resolve();

        $broken = [];
        foreach ($feeds as $feed) {
            try {
                $this->assertServesFeed($feed->url, $proxy);
            } catch (BrokenCatalogUrlException $failure) {
                $broken[] = new BrokenCatalogUrl($feed->title, $feed->url, $failure->getMessage());
            }
        }

        return new CatalogUrlReport(\count($feeds), $broken);
    }

    /** @return list<CatalogDocumentFeed> */
    private function feedsToCheck(?int $limit): array
    {
        $feeds = [];
        foreach ($this->bundled->document()->categories as $category) {
            foreach ($category->feeds as $feed) {
                $feeds[] = $feed;
            }
        }

        return null === $limit ? $feeds : \array_slice($feeds, 0, $limit);
    }

    /** @throws BrokenCatalogUrlException */
    private function assertServesFeed(string $url, ?ProxyConfig $proxy): void
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                // The fetcher's agent: a publisher tolerating an unknown checker must not pass for healthy.
                'headers' => ['User-Agent' => $this->userAgent],
                ...(null !== $proxy ? EgressOptions::proxied($proxy) : []),
            ]);
            $status = $response->getStatusCode();
            $head = 200 === $status ? mb_substr($response->getContent(), 0, 2048) : '';
        } catch (ExceptionInterface $e) {
            throw new BrokenCatalogUrlException($e->getMessage(), 0, $e);
        }

        if (200 !== $status) {
            throw new BrokenCatalogUrlException('HTTP ' . $status);
        }
        if (!str_contains($head, '<rss') && !str_contains($head, '<feed') && !str_contains($head, '<rdf:RDF')) {
            throw new BrokenCatalogUrlException('not a feed document');
        }
    }
}
```

- [ ] **Step 5: Rewire the HTTP client.** `config/services.yaml`, before:
```yaml
    # A child of the framework's http_client so the rot-check command holds its
    # own injectable client id. The point of the separate id is the test: it can
    # `set('catalog.rot_check.http_client', $mockClient)` to swap in a
    # MockHttpClient without touching the app-wide client every other fetch uses.
    catalog.rot_check.http_client:
        parent: 'http_client'

    App\Command\CheckCatalogUrlsCommand:
        arguments:
            $httpClient: '@catalog.rot_check.http_client'
```
after:
```yaml
    # Its own id, so a test can swap in a MockHttpClient for the catalog URL checker without touching the app-wide client.
    catalog.rot_check.http_client:
        parent: 'http_client'

    App\Service\Catalog\CatalogUrlChecker:
        arguments:
            $httpClient: '@catalog.rot_check.http_client'
```

- [ ] **Step 6: Run the test and check that it passes.**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Catalog/CatalogUrlCheckerTest.php`
Expected: OK (3 tests).

- [ ] **Step 7: Rewrite the command.** `src/Command/CheckCatalogUrlsCommand.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Catalog\BrokenCatalogUrl;
use App\Service\Catalog\CatalogUrlChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches every URL in resources/catalog/catalog.opml and reports the ones that
 * no longer serve a feed. Reads the SHIPPED DOCUMENT, not the database: this
 * checks what we hand a new install, which is the thing that rots unnoticed.
 *
 * Run on a schedule, never as a PR gate — 111 publisher domains produce enough
 * rate limits, bot blocks and transient outages to make a merge check useless.
 */
#[AsCommand(
    name: 'app:catalog:check-urls',
    description: 'Verify every catalog URL still serves a feed',
)]
final class CheckCatalogUrlsCommand extends Command
{
    public function __construct(private readonly CatalogUrlChecker $checker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Check at most this many URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->checker->check($this->limit($input));

        if ($report->isHealthy()) {
            $io->success(\sprintf('All %d catalog URLs still serve a feed.', $report->checked));

            return Command::SUCCESS;
        }

        $io->error(\sprintf('%d of %d catalog URLs need attention:', \count($report->broken), $report->checked));
        $io->listing(array_map(
            static fn (BrokenCatalogUrl $broken): string
                => \sprintf('%s (%s): %s', $broken->title, $broken->url, $broken->reason),
            $report->broken,
        ));

        return Command::FAILURE;
    }

    private function limit(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
```
The class docblock is develop's, unchanged.

- [ ] **Step 8: Repoint the User-Agent wiring test.** `tests/Service/Fetch/OutboundUserAgentWiringTest.php`: replace `use App\Command\CheckCatalogUrlsCommand;` with `use App\Service\Catalog\CatalogUrlChecker;`. Then, before:
```php
        yield 'catalog rot check' => [CheckCatalogUrlsCommand::class, 'userAgent'];
```
after:
```php
        yield 'catalog rot check' => [CatalogUrlChecker::class, 'userAgent'];
```

- [ ] **Step 9: Run the command tests and the wiring test.**

Run: `bin/console cache:clear && php bin/phpunit tests/Command/CheckCatalogUrlsCommandTest.php tests/Command/CheckCatalogUrlsCommandProxyTest.php tests/Service/Fetch/OutboundUserAgentWiringTest.php tests/Service/Catalog/CatalogUrlCheckerTest.php`
Expected: OK. The proxy test still sees `socks5://proxy.example:1080` and a 20-second timeout, and the command test still sees `not a feed` and `Could not resolve host` in the output.

- [ ] **Step 10: Run the gates.**

Run: `composer check && composer md`
Expected: all green.

- [ ] **Step 11: Commit.**

```bash
git add src/Service/Catalog/CatalogUrlChecker.php src/Service/Catalog/CatalogUrlReport.php src/Service/Catalog/BrokenCatalogUrl.php \
  src/Command/CheckCatalogUrlsCommand.php config/services.yaml tests/Service/Catalog/CatalogUrlCheckerTest.php \
  tests/Service/Fetch/OutboundUserAgentWiringTest.php
git commit -m "refactor(#1157): CatalogUrlChecker does the rot check's fetching and reads the document from BundledCatalog"
```

---

### Task 15: `AuditShard` + `AuditFindingsFile` (ReaderAuditCommand)

**Files:**
- Create: `src/Service/ReaderAudit/AuditShard.php`
- Create: `src/Service/ReaderAudit/AuditFindingsFile.php`
- Create: `src/Service/ReaderAudit/Exception/UnwritableFindingsFileException.php`
- Create: `tests/Service/ReaderAudit/AuditShardTest.php`
- Create: `tests/Service/ReaderAudit/AuditFindingsFileTest.php`
- Modify: `src/Command/ReaderAuditCommand.php` (whole file)

**Interfaces:**
- Consumes (existing): `SampledEntry`, and `AuditFinding::toArray(): array<string, mixed>`.
- Produces:
  - `final readonly class AuditShard(int $index, int $count)`, with `pick(list<SampledEntry> $sample): list<SampledEntry>`. A count of 1 or less keeps the whole sample. Otherwise it keeps every entry whose position modulo `count` equals `index`.
  - `AuditFindingsFile::create(string $path): self`. It creates a missing directory (0775, recursive), truncates the file, and throws `UnwritableFindingsFileException('Cannot write <path>.')` when `fopen` fails.
  - `AuditFindingsFile::append(AuditFinding): void` writes one JSON line with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`.
  - `AuditFindingsFile::close(): void`.

- [ ] **Step 1: Write the failing tests.**

`tests/Service/ReaderAudit/AuditShardTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\AuditShard;
use App\Service\ReaderAudit\SampledEntry;
use PHPUnit\Framework\TestCase;

final class AuditShardTest extends TestCase
{
    public function testASingleShardKeepsTheWholeSample(): void
    {
        $sample = $this->sample(5);

        self::assertSame($sample, (new AuditShard(0, 1))->pick($sample));
    }

    public function testNoShardCountKeepsTheWholeSample(): void
    {
        $sample = $this->sample(5);

        self::assertSame($sample, (new AuditShard(0, 0))->pick($sample));
    }

    public function testAShardKeepsEveryEntryAtItsOwnOffset(): void
    {
        $sample = $this->sample(5);

        self::assertSame([$sample[1], $sample[3]], (new AuditShard(1, 2))->pick($sample));
        self::assertSame([$sample[0], $sample[3]], (new AuditShard(0, 3))->pick($sample));
    }

    /** @return list<SampledEntry> */
    private function sample(int $size): array
    {
        return array_map(
            static fn (int $entryId): SampledEntry => new SampledEntry(
                $entryId,
                1,
                1,
                'Feed',
                'Title ' . $entryId,
                'https://example.com/' . $entryId,
                null,
                false,
            ),
            range(0, $size - 1),
        );
    }
}
```

`tests/Service/ReaderAudit/AuditFindingsFileTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\AuditFinding;
use App\Service\ReaderAudit\AuditFindingsFile;
use PHPUnit\Framework\TestCase;

final class AuditFindingsFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/audit-findings-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path())) {
            unlink($this->path());
        }
        if (is_dir($this->directory . '/nested')) {
            rmdir($this->directory . '/nested');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testWritesOneJsonLinePerFindingIntoADirectoryItCreates(): void
    {
        $first = $this->finding(1, 'Café');
        $second = $this->finding(2, 'A/B testing');

        $file = AuditFindingsFile::create($this->path());
        $file->append($first);
        $file->append($second);
        $file->close();

        $written = (string) file_get_contents($this->path());
        self::assertSame(
            json_encode($first->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n"
            . json_encode($second->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n",
            $written,
        );
        self::assertStringContainsString('"title":"Café"', $written);
        self::assertStringContainsString('"title":"A/B testing"', $written);
    }

    public function testCreatingTheFileAgainStartsItEmpty(): void
    {
        $earlier = AuditFindingsFile::create($this->path());
        $earlier->append($this->finding(1, 'Earlier run'));
        $earlier->close();

        AuditFindingsFile::create($this->path())->close();

        self::assertSame('', file_get_contents($this->path()));
    }

    private function path(): string
    {
        return $this->directory . '/nested/findings.jsonl';
    }

    private function finding(int $entryId, string $title): AuditFinding
    {
        return new AuditFinding(
            $entryId,
            10,
            'Feed',
            $title,
            'https://example.com/source',
            'http://localhost:4200/reader/1',
            true,
            [],
            [],
        );
    }
}
```

- [ ] **Step 2: Run the tests and check that they fail.**

Run: `php bin/phpunit tests/Service/ReaderAudit/AuditShardTest.php tests/Service/ReaderAudit/AuditFindingsFileTest.php`
Expected: FAIL. The classes do not exist.

- [ ] **Step 3: Write the shard, the file and the exception.**

`src/Service/ReaderAudit/AuditShard.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/** Every shard draws the same sample; each keeps every count-th entry, so parallel shards cover it once. */
final readonly class AuditShard
{
    public function __construct(
        private int $index,
        private int $count,
    ) {
    }

    /**
     * @param list<SampledEntry> $sample
     *
     * @return list<SampledEntry>
     */
    public function pick(array $sample): array
    {
        if ($this->count <= 1) {
            return $sample;
        }

        $mine = [];
        foreach ($sample as $position => $entry) {
            if ($position % $this->count === $this->index) {
                $mine[] = $entry;
            }
        }

        return $mine;
    }
}
```

`src/Service/ReaderAudit/Exception/UnwritableFindingsFileException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit\Exception;

final class UnwritableFindingsFileException extends \RuntimeException
{
}
```

`src/Service/ReaderAudit/AuditFindingsFile.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\ReaderAudit\Exception\UnwritableFindingsFileException;

final readonly class AuditFindingsFile
{
    /** @var resource */
    private mixed $handle;

    /** @param resource $handle */
    private function __construct(mixed $handle)
    {
        $this->handle = $handle;
    }

    public static function create(string $path): self
    {
        $directory = \dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new UnwritableFindingsFileException(\sprintf('Cannot write %s.', $path));
        }

        return new self($handle);
    }

    public function append(AuditFinding $finding): void
    {
        $line = json_encode($finding->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        fwrite($this->handle, $line . "\n");
    }

    public function close(): void
    {
        fclose($this->handle);
    }
}
```
The handle is a non-promoted property so that its `@var resource` reaches PHPStan at `fwrite` and `fclose`. `mixed` is the only native type a resource can have.

- [ ] **Step 4: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/ReaderAudit/AuditShardTest.php tests/Service/ReaderAudit/AuditFindingsFileTest.php`
Expected: OK (5 tests).

- [ ] **Step 5: Rewrite the command.** `src/Command/ReaderAuditCommand.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReaderAudit\AuditFindingsFile;
use App\Service\ReaderAudit\AuditSample;
use App\Service\ReaderAudit\AuditSampler;
use App\Service\ReaderAudit\AuditShard;
use App\Service\ReaderAudit\AuditUserResolver;
use App\Service\ReaderAudit\ReaderAuditRunner;
use App\Service\ReaderAudit\ReaderLink;
use App\Service\ReaderAudit\SampledEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the reader's extract-and-clean pipeline over a stratified sample of the
 * articles a user is subscribed to, and writes one JSON line per article with
 * the markers that say the cleaning probably went wrong. `app:reader:audit:report`
 * turns those lines into the ranked, clickable list.
 *
 * Split into sweep and report on purpose: the sweep is a thousand outbound page
 * fetches and takes minutes, the report is instant and gets re-run every time a
 * threshold or a phrase is questioned. The sweep also shards — the same seed
 * draws the same sample in every shard, so `--shards=8 --shard=0..7` in parallel
 * covers the sample once with no coordination.
 *
 * Never a CI gate: publisher outages, bot walls and rate limits make the result
 * a survey, not a verdict. Same reasoning as app:catalog:check-urls.
 */
#[AsCommand(
    name: 'app:reader:audit',
    description: 'Sweep subscribed articles through the reader pipeline and record bad-cleanup markers',
)]
final class ReaderAuditCommand extends Command
{
    private const int DEFAULT_LIMIT = 1000;
    private const int DEFAULT_PER_FEED = 8;
    private const int DEFAULT_SEED = 20260831;
    private const string DEFAULT_BASE_URL = 'http://localhost:4200';

    public function __construct(
        private readonly AuditUserResolver $users,
        private readonly AuditSampler $sampler,
        private readonly ReaderAuditRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $required = InputOption::VALUE_REQUIRED;

        $this
            ->addOption('user', null, $required, 'Account id or email; defaults to the widest subscriber')
            ->addOption('limit', null, $required, 'Articles to audit in total', (string) self::DEFAULT_LIMIT)
            ->addOption('per-feed', null, $required, 'Cap per feed', (string) self::DEFAULT_PER_FEED)
            ->addOption('seed', null, $required, 'Sample seed; shards share it', (string) self::DEFAULT_SEED)
            ->addOption('before', null, $required, 'Sample entries stored before this instant; shards share it')
            ->addOption('entries', null, $required, 'Audit these entry ids instead of drawing a sample')
            ->addOption('shards', null, $required, 'Split the sample into this many runs', '1')
            ->addOption('shard', null, $required, 'Which shard this process runs, from 0', '0')
            ->addOption('base-url', null, $required, 'SPA origin the report links to', self::DEFAULT_BASE_URL)
            ->addOption('out', null, $required, 'JSONL file to write', 'var/reader-audit/findings.jsonl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userId = $this->users->resolve($this->option($input, 'user'));
        $sample = $this->articlesToAudit($input, $userId);
        $mine = (new AuditShard($this->number($input, 'shard'), $this->number($input, 'shards')))->pick($sample);

        $io->text(\sprintf(
            'user %d — %d articles sampled over %d feeds, %d in this shard',
            $userId,
            \count($sample),
            \count(array_unique(array_map(static fn (SampledEntry $e): int => $e->feedId, $sample))),
            \count($mine),
        ));

        $file = AuditFindingsFile::create((string) $this->option($input, 'out'));
        $link = new ReaderLink((string) $this->option($input, 'base-url'));

        $io->progressStart(\count($mine));
        $flagged = 0;
        foreach ($this->runner->run($mine, $link) as $finding) {
            $file->append($finding);
            $flagged += $finding->markers === [] ? 0 : 1;
            $io->progressAdvance();
        }
        $io->progressFinish();
        $file->close();

        $io->success(\sprintf('%d of %d audited articles carry at least one marker.', $flagged, \count($mine)));

        return Command::SUCCESS;
    }

    /** @return list<SampledEntry> */
    private function articlesToAudit(InputInterface $input, int $userId): array
    {
        $named = $this->option($input, 'entries');
        if ($named !== null) {
            return $this->sampler->pick(array_map(intval(...), explode(',', $named)), $userId);
        }

        return $this->sampler->sample(new AuditSample(
            $userId,
            $this->number($input, 'limit'),
            $this->number($input, 'per-feed'),
            $this->number($input, 'seed'),
            new \DateTimeImmutable($this->option($input, 'before') ?? 'now'),
        ));
    }

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function number(InputInterface $input, string $name): int
    {
        return (int) ($this->option($input, $name) ?? '0');
    }
}
```
Everything except `execute`'s shard and file lines, the two new imports and the deleted `shardOf()`/`openOutput()` is develop's text, unchanged. If a later commit changed an `AuditSampler` or `AuditUserResolver` call here, Task 0 Step 4 has already brought this block up to date.

- [ ] **Step 6: Smoke the command.** No test drives it, so run it once against the dev database with a tiny sample:

Run: `bin/console app:reader:audit --limit=2 --per-feed=1 --out=var/reader-audit/smoke.jsonl && wc -l var/reader-audit/smoke.jsonl && rm var/reader-audit/smoke.jsonl`
Expected: the `user … articles sampled` line and a success line. The file holds as many lines as the shard size. If the dev database holds no subscriptions, `NoAuditUserException` is the expected answer; report that rather than seeding data.

- [ ] **Step 7: Run the gates.**

Run: `composer check && composer md && php bin/phpunit tests/Service/ReaderAudit`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/ReaderAudit/AuditShard.php src/Service/ReaderAudit/AuditFindingsFile.php \
  src/Service/ReaderAudit/Exception/UnwritableFindingsFileException.php src/Command/ReaderAuditCommand.php \
  tests/Service/ReaderAudit/AuditShardTest.php tests/Service/ReaderAudit/AuditFindingsFileTest.php
git commit -m "refactor(#1157): the reader audit's sharding and findings file leave the command"
```

---

### Task 16: Admin category update and delete are proven to persist

**Files:**
- Modify: `tests/Controller/Admin/AdminCatalogControllerTest.php` (one helper, `testAdminCanCreateUpdateAndDeleteACategory`)

**Interfaces:**
- Consumes: the existing `CatalogCategory::getName()`, `CatalogCategory::isEnabled()` and `EntityManagerInterface::find()`.
- No production code changes. Today the test checks only status codes. So removing `$this->editor->update($category, $request)` (`AdminCatalogCategoryController:56`) or `$this->editor->delete(...)` (`:64`) still passes, and those two Infection mutants escape. After this task, each of them fails the test.

- [ ] **Step 1: Make the test read the database back.**

Add this helper directly after the closing `}` of `responseBody()`:
```php

    private function reloadedCategory(int $id): ?CatalogCategory
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();

        return $em->find(CatalogCategory::class, $id);
    }
```
`clear()` is what makes it a reload: the test container's EntityManager is the one the request just used, and without the clear `find()` would answer from its identity map.

In `testAdminCanCreateUpdateAndDeleteACategory`, before:
```php
                ['key' => 'technology', 'name' => 'Tech', 'icon' => 'memory', 'color' => '#3b82f6', 'enabled' => false],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();

        $client->request('DELETE', '/api/admin/catalog/categories/' . $id, server: $headers);
        self::assertResponseStatusCodeSame(204);
    }
```
after:
```php
                ['key' => 'technology', 'name' => 'Tech', 'icon' => 'memory', 'color' => '#3b82f6', 'enabled' => false],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();
        $updated = $this->reloadedCategory($id);
        self::assertNotNull($updated);
        self::assertSame('Tech', $updated->getName());
        self::assertFalse($updated->isEnabled());

        $client->request('DELETE', '/api/admin/catalog/categories/' . $id, server: $headers);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->reloadedCategory($id));
    }
```

- [ ] **Step 2: Run the test and check that it passes.**

Run: `php bin/phpunit tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: OK. The test pins behaviour that already works, so it passes at once. Step 3 proves that it guards anything.

- [ ] **Step 3: Prove that the two mutants now die.**

Run: `OTEL_PHP_DISABLED_INSTRUMENTATIONS=all vendor/bin/infection --filter=src/Controller/Admin/AdminCatalogCategoryController.php --threads=max --show-mutations`
Expected: no escaped mutant on lines 56 or 64 of `AdminCatalogCategoryController.php` in the summary or in `var/infection.log`. The file is not in this PR's diff, so `composer infection:diff` would not re-check it. If either mutant still escapes, the test does not yet observe the write: stop and report, rather than widening the assertion list by guesswork.

- [ ] **Step 4: Run the gates.**

Run: `composer check`
Expected: all green. No `src` file changed, so `composer md` has nothing new to say.

- [ ] **Step 5: Commit.**

```bash
git add tests/Controller/Admin/AdminCatalogControllerTest.php
git commit -m "test(#1157): admin category update and delete are read back from the database

Status codes alone let both editor calls in AdminCatalogCategoryController be
deleted without a failing test; two Infection mutants escaped there."
```

---

### Task 17: `MailDeliveryHealth` owns the failure log's flush

**Files:**
- Modify: `src/Repository/MailSendFailureRepository.php` (`add`)
- Modify: `src/Service/Mail/MailDeliveryHealth.php` (imports, constructor, `recordFailure`)
- Modify: `tests/Repository/MailSendFailureRepositoryTest.php` (one new test; the others flush explicitly)
- Modify: `docs/architecture.md` (§7, the "No hidden side effects" bullet)

**Interfaces:**
- `MailSendFailureRepository::add(MailSendFailure): void` only persists. It no longer runs a whole-EntityManager `flush()` that would commit someone else's pending changes.
- `MailDeliveryHealth::recordFailure(MailKind, string, string): void` is the unit of work: it persists the row, flushes, and then prunes. The flush must come before the prune, because `pruneToRetention()` selects committed rows through DQL, which never auto-flushes.
- `MailDeliveryHealthTest` needs no edit, and it already pins both facts:
  - `testRecordFailurePersistsAViewableRow` reads through `recent()` (DQL), so it fails without the flush.
  - `testRecordFailureKeepsTheLogWithinRetention` records 51 failures and expects 50 rows. It fails if the flush moves after the prune.

- [ ] **Step 1: Write the failing test.** `tests/Repository/MailSendFailureRepositoryTest.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Repository\MailSendFailureRepository;
use App\Tests\DbTestCase;

final class MailSendFailureRepositoryTest extends DbTestCase
{
    private MailSendFailureRepository $failures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
    }

    public function testAddOnlyPersistsAndLeavesTheFlushToTheCaller(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));

        self::assertSame(0, $this->failures->countAll());

        $this->em->flush();

        self::assertSame(1, $this->failures->countAll());
    }

    public function testRecentReturnsTheStoredFailuresNewestFirst(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));
        $this->failures->add($this->failure('b@example.test', '2026-09-06T11:00:00Z'));
        $this->em->flush();

        $recent = $this->failures->recent(10);

        self::assertCount(2, $recent);
        self::assertSame('b@example.test', $recent[0]->getRecipient());
        self::assertSame('a@example.test', $recent[1]->getRecipient());
        self::assertSame(2, $this->failures->countAll());
    }

    public function testDeleteAllClearsTheTable(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));
        $this->em->flush();

        $this->failures->deleteAll();

        self::assertSame(0, $this->failures->countAll());
        self::assertSame([], $this->failures->recent(10));
    }

    public function testAddKeepsEveryRowItWrites(): void
    {
        $this->storeFailures(MailSendFailureRepository::RETENTION + 1);

        self::assertSame(MailSendFailureRepository::RETENTION + 1, $this->failures->countAll());
    }

    public function testPruneToRetentionKeepsTheNewest(): void
    {
        $this->storeFailures(MailSendFailureRepository::RETENTION + 5);

        $this->failures->pruneToRetention();

        self::assertSame(MailSendFailureRepository::RETENTION, $this->failures->countAll());
        self::assertSame(
            'user' . (MailSendFailureRepository::RETENTION + 4) . '@example.test',
            $this->failures->recent(1)[0]->getRecipient(),
        );
        $retained = $this->failures->recent(MailSendFailureRepository::RETENTION);
        self::assertSame('user5@example.test', $retained[MailSendFailureRepository::RETENTION - 1]->getRecipient());
    }

    private function storeFailures(int $count): void
    {
        for ($minute = 0; $minute < $count; ++$minute) {
            $stamp = sprintf('2026-09-06T10:%02d:00Z', $minute);
            $this->failures->add($this->failure("user{$minute}@example.test", $stamp));
        }
        $this->em->flush();
    }

    private function failure(string $recipient, string $createdAt): MailSendFailure
    {
        return new MailSendFailure(
            MailKind::Digest,
            $recipient,
            'SMTP transport failed',
            new \DateTimeImmutable($createdAt),
        );
    }
}
```
`fillFailures` is renamed `storeFailures`, because it now flushes as well. `testAddPersistsAndRecentReturnsNewestFirst` becomes `testRecentReturnsTheStoredFailuresNewestFirst`, because persisting is no longer `add()`'s whole story.

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Repository/MailSendFailureRepositoryTest.php`
Expected: FAIL. In `testAddOnlyPersistsAndLeavesTheFlushToTheCaller`, `add()` still flushes, so the first count is 1, not 0. The other four pass.

- [ ] **Step 3: Move the flush.**

`src/Repository/MailSendFailureRepository.php`, before:
```php
    public function add(MailSendFailure $failure): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($failure);
        $manager->flush();
    }
```
after:
```php
    public function add(MailSendFailure $failure): void
    {
        $this->getEntityManager()->persist($failure);
    }
```

`src/Service/Mail/MailDeliveryHealth.php`: add `use Doctrine\ORM\EntityManagerInterface;` directly after `use App\Service\Clock\NaiveUtcClock;`. Then, before:
```php
    public function __construct(
        private MailSendFailureRepository $failures,
        private NaiveUtcClock $clock,
    ) {
    }

    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        $occurredAt = $this->clock->now();

        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $occurredAt));
        $this->failures->pruneToRetention();
    }
```
after:
```php
    public function __construct(
        private MailSendFailureRepository $failures,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function recordFailure(MailKind $kind, string $recipient, string $error): void
    {
        $this->failures->add(new MailSendFailure($kind, $recipient, $error, $this->clock->now()));
        $this->entityManager->flush();
        $this->failures->pruneToRetention();
    }
```

- [ ] **Step 4: Run the tests and check that they pass.**

Run: `bin/console cache:clear && php bin/phpunit tests/Repository/MailSendFailureRepositoryTest.php tests/Service/Mail/MailDeliveryHealthTest.php tests/EventListener/DeferredMailFlushHealthTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Controller/Admin/AdminMailErrorsControllerTest.php`
Expected: OK. Every production caller reaches the repository through `MailDeliveryHealth`, so these files cover all of them.

- [ ] **Step 5: Update the architecture note.** `docs/architecture.md`, §7, before:
```markdown
- **No hidden side effects.** A write method does what its name says. `add()` does not prune, and should not need a
  whole-EntityManager `flush()` that commits someone else's pending changes — `MailSendFailureRepository::add()` still
  does, and is the one write left to fix.
```
after:
```markdown
- **No hidden side effects.** A write method does what its name says. `add()` persists: it neither prunes nor runs a
  whole-EntityManager `flush()` that would commit someone else's pending changes. The service that owns the unit of
  work flushes, as `MailDeliveryHealth::recordFailure()` does for the mail-failure log.
```

- [ ] **Step 6: Sweep and run the gates.**

Run: `git grep -n "flush()" -- src/Repository; composer check && composer md`
Expected: the grep prints nothing, and the gates are green.

- [ ] **Step 7: Commit.**

```bash
git add src/Repository/MailSendFailureRepository.php src/Service/Mail/MailDeliveryHealth.php \
  tests/Repository/MailSendFailureRepositoryTest.php ../docs/architecture.md
git commit -m "refactor(#1157): MailDeliveryHealth flushes the failure log; the repository only persists"
```

---

### Task 18: `ControllerMutatesNoEntityRule` sees mapped classes, first-class callables and static calls

**Files:**
- Modify: `tests/PhpStan/ControllerMutatesNoEntityRule.php` (whole file)
- Modify: `tests/PhpStan/ControllerMutatesNoEntityRuleTest.php` (whole file)
- Modify: `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php` (whole file)
- Modify: `CLAUDE.md` (the rule's sentence under "Enforced mechanically")

**Interfaces:**
- **What counts as an entity.** A class Doctrine maps, `#[ORM\Entity]` or `#[ORM\Embeddable]`, read through `ClassReflection::getAttributes()`. The `App\Entity\` prefix no longer decides it.
  - Newly out of scope, and so no longer false positives: `App\Entity\Exception\UnpersistedEntityException`, the readonly value objects `EntryAttachment`, `EntryMedium` and `RecommendationRunProgress`, `MailKind` (an enum), `PersistedId` (a trait) and `Positioned` (an interface). None of them carries either attribute.
  - Still in scope: every entity, and the twelve embeddables (`AccountLimits`, `FetchSchedule`, `EntryMedia`, the `Run*` value parts and so on). An embeddable is mutable, persisted state of its entity.
- **What is caught.**
  - `New_`: constructing a mapped class, as before.
  - `MethodCall`: a non-query call on a mapped receiver, as before. F1 still holds: a `?->` call is caught through its `MethodCall` pass, so `NullsafeMethodCall` stays unhandled.
  - New, `MethodCallableNode`: `$entity->setX(...)`. PHPStan 2.2.5 turns every first-class callable into its `*CallableNode` before any rule sees it (`NodeScopeResolver::processExprNode`), so a `Rule<CallLike>` never saw these.
  - New, `StaticCall` and `StaticMethodCallableNode`: any static call on a mapped class, whatever its name. On an entity, a static method is either a named constructor or a shared helper, and a controller needs neither.
- The node type widens from `CallLike` to `Expr`, because the two `*CallableNode` classes extend `Expr` and are not `CallLike`.
- The message and identifier of the construction and mutation errors are unchanged. The static-call error is new: `A controller calls <Class>::<method>(), a static method of an entity. <advice>`, identifier `simpleFeedReader.thinController.entity`.
- develop has no first-class callable and no static call on an entity class in `src/Controller`, so the widened rule adds no finding at HEAD. Step 5 proves that, and Step 6 proves that the new branches bite.

- [ ] **Step 1: Write the failing test.**

`tests/PhpStan/data/controller-mutates-no-entity-fixtures.php`, whole file after:
```php
<?php

declare(strict_types=1);

// Fixtures for ControllerMutatesNoEntityRuleTest, analysed only by that RuleTestCase (see excludePaths in
// phpstan.dist.neon). The namespaces deliberately do not match the path, hence the PSR-4 suppressions.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    use Doctrine\ORM\Mapping as ORM;

    #[ORM\Entity]
    class Widget
    {
        private string $label = '';

        public static function named(string $label): self
        {
            $widget = new self();
            $widget->setLabel($label);

            return $widget;
        }

        public function getLabel(): string
        {
            return $this->label;
        }

        public function isVisible(): bool
        {
            return '' !== $this->label;
        }

        public function requireId(): int
        {
            return 1;
        }

        public function setLabel(string $label): void
        {
            $this->label = $label;
        }

        public function rename(string $label): void
        {
            $this->label = $label;
        }
    }

    #[ORM\Embeddable]
    class Dimensions
    {
        private int $width = 0;

        public function getWidth(): int
        {
            return $this->width;
        }

        public function setWidth(int $width): void
        {
            $this->width = $width;
        }
    }

    final readonly class Coordinates
    {
        public function __construct(public int $x = 0)
        {
        }

        public static function origin(): self
        {
            return new self();
        }

        public function withX(int $x): self
        {
            return new self($x);
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures\Exception {
    final class WidgetGoneException extends \RuntimeException
    {
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Controller\Fixtures\Mutation {
    use App\Entity\Fixtures\Coordinates;
    use App\Entity\Fixtures\Dimensions;
    use App\Entity\Fixtures\Exception\WidgetGoneException;
    use App\Entity\Fixtures\Widget;

    final class MutatingController
    {
        public function create(): Widget
        {
            return new Widget();
        }

        public function update(Widget $widget, ?Widget $maybe): string
        {
            $widget->setLabel('new');
            $widget->rename('newer');
            $maybe?->setLabel('nullsafe');

            return $widget->getLabel() . ($widget->isVisible() ? 'shown' : 'hidden');
        }

        public function inClosure(Widget $widget): void
        {
            (static fn (Widget $inner) => $inner->setLabel('closure'))($widget);
        }

        public function readsTheId(Widget $widget): int
        {
            return $widget->requireId();
        }

        public function nonEntity(\ArrayObject $bag): \ArrayObject
        {
            $bag->setFlags(0);

            return new \ArrayObject();
        }

        /** @return list<\Closure> */
        public function firstClassCallables(Widget $widget): array
        {
            return [
                $widget->setLabel(...),
                $widget->getLabel(...),
                Widget::named(...),
            ];
        }

        public function staticCall(): Widget
        {
            return Widget::named('static');
        }

        public function embeddable(Dimensions $dimensions): int
        {
            $dimensions->setWidth(3);

            return $dimensions->getWidth();
        }

        public function valueObjectsAndExceptions(int $x): Coordinates
        {
            if (0 > $x) {
                throw new WidgetGoneException();
            }

            return (new Coordinates($x))->withX(Coordinates::origin()->x);
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures\Mutation {
    use App\Entity\Fixtures\Widget;

    final class WidgetService
    {
        public function create(): Widget
        {
            $widget = new Widget();
            $widget->setLabel('services may');

            return Widget::named($widget->getLabel());
        }
    }
}
```

`tests/PhpStan/ControllerMutatesNoEntityRuleTest.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ControllerMutatesNoEntityRule>
 */
final class ControllerMutatesNoEntityRuleTest extends RuleTestCase
{
    private const string WIDGET = 'App\Entity\Fixtures\Widget';
    private const string DIMENSIONS = 'App\Entity\Fixtures\Dimensions';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    protected function getRule(): Rule
    {
        return new ControllerMutatesNoEntityRule();
    }

    public function testItFlagsBuildingCallingStaticallyAndChangingAMappedClassInAController(): void
    {
        $this->analyse(
            [__DIR__ . '/data/controller-mutates-no-entity-fixtures.php'],
            [
                [$this->constructionMessage(), 105],
                [$this->mutationMessage(self::WIDGET, 'setLabel'), 110],
                [$this->mutationMessage(self::WIDGET, 'rename'), 111],
                [$this->mutationMessage(self::WIDGET, 'setLabel'), 112],
                [$this->mutationMessage(self::WIDGET, 'setLabel'), 119],
                [$this->mutationMessage(self::WIDGET, 'setLabel'), 138],
                [$this->staticCallMessage('named'), 140],
                [$this->staticCallMessage('named'), 146],
                [$this->mutationMessage(self::DIMENSIONS, 'setWidth'), 151],
                // Not reported: queries and requireId() (114, 124, 139, 153), an unmapped \ArrayObject (129, 131),
                // an exception (159) and a readonly value object (162) that live under App\Entity but are not mapped.
            ],
        );
    }

    private function constructionMessage(): string
    {
        return sprintf('A controller constructs the entity %s. %s', self::WIDGET, self::ADVICE);
    }

    private function mutationMessage(string $class, string $method): string
    {
        return sprintf('A controller calls %s::%s(), which changes an entity. %s', $class, $method, self::ADVICE);
    }

    private function staticCallMessage(string $method): string
    {
        return sprintf(
            'A controller calls %s::%s(), a static method of an entity. %s',
            self::WIDGET,
            $method,
            self::ADVICE,
        );
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/PhpStan/ControllerMutatesNoEntityRuleTest.php`
Expected: FAIL, in both directions:
- The expected errors on lines 138, 140 and 146 are missing. These are the blind spots.
- Unexpected errors appear on lines 159 and 162. `WidgetGoneException` and `Coordinates` sit under `App\Entity\`, and those are the false positives.

- [ ] **Step 3: Rewrite the rule.** `tests/PhpStan/ControllerMutatesNoEntityRule.php`, whole file after:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use Doctrine\ORM\Mapping\Embeddable;
use Doctrine\ORM\Mapping\Entity;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MethodCallableNode;
use PHPStan\Node\StaticMethodCallableNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Thin-controller rule, expression half (#1157): a controller neither builds, statically calls nor changes a class
 * Doctrine maps. A ?-> call also arrives as a MethodCall (F1); a first-class callable arrives only as a *CallableNode.
 *
 * @implements Rule<Expr>
 */
final readonly class ControllerMutatesNoEntityRule implements Rule
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';
    private const array MAPPING_ATTRIBUTES = [Entity::class, Embeddable::class];
    private const string QUERY_METHOD = '/^(get|is|has)[A-Z]/';
    private const string ID_READ = 'requireId';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::isInController($scope)) {
            return [];
        }

        return match (true) {
            $node instanceof New_ => self::constructionErrors($node->class, $scope),
            $node instanceof StaticCall => self::staticCallErrors($node->class, $node->name, $scope),
            $node instanceof StaticMethodCallableNode
                => self::staticCallErrors($node->getClass(), $node->getName(), $scope),
            $node instanceof MethodCall => self::mutationErrors($node->var, $node->name, $scope),
            $node instanceof MethodCallableNode => self::mutationErrors($node->getVar(), $node->getName(), $scope),
            default => [],
        };
    }

    private static function isInController(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();

        return null !== $classReflection
            && str_starts_with($classReflection->getName(), self::CONTROLLER_NAMESPACE_PREFIX);
    }

    /** @return list<IdentifierRuleError> */
    private static function constructionErrors(Node $class, Scope $scope): array
    {
        $mapped = self::mappedClassNamed($class, $scope);
        if (null === $mapped) {
            return [];
        }

        return [self::error(sprintf('A controller constructs the entity %s. %s', $mapped, self::ADVICE))];
    }

    /** @return list<IdentifierRuleError> */
    private static function staticCallErrors(Node $class, Node $method, Scope $scope): array
    {
        $mapped = self::mappedClassNamed($class, $scope);
        if (null === $mapped || !$method instanceof Identifier) {
            return [];
        }

        return [self::error(sprintf(
            'A controller calls %s::%s(), a static method of an entity. %s',
            $mapped,
            $method->name,
            self::ADVICE,
        ))];
    }

    /** @return list<IdentifierRuleError> */
    private static function mutationErrors(Expr $receiver, Node $method, Scope $scope): array
    {
        if (!$method instanceof Identifier || self::isQuery($method->name)) {
            return [];
        }

        $receiverType = $scope->getType($receiver);
        $mapped = self::mappedClassesOf($receiverType);
        if ([] === $mapped) {
            return [];
        }

        return [self::error(sprintf(
            'A controller calls %s::%s(), which changes an entity. %s',
            self::declaringClassName($scope, $receiverType, $method->name) ?? $mapped[0],
            $method->name,
            self::ADVICE,
        ))];
    }

    private static function declaringClassName(Scope $scope, Type $receiverType, string $methodName): ?string
    {
        return $scope->getMethodReflection($receiverType, $methodName)?->getDeclaringClass()->getName();
    }

    private static function isQuery(string $methodName): bool
    {
        return self::ID_READ === $methodName || 1 === preg_match(self::QUERY_METHOD, $methodName);
    }

    private static function mappedClassNamed(Node $class, Scope $scope): ?string
    {
        if (!$class instanceof Name) {
            return null;
        }

        return self::mappedClassesOf($scope->resolveTypeByName($class))[0] ?? null;
    }

    /** @return list<string> */
    private static function mappedClassesOf(Type $type): array
    {
        $mapped = array_filter($type->getObjectClassReflections(), self::isMapped(...));

        return array_values(array_map(static fn (ClassReflection $class): string => $class->getName(), $mapped));
    }

    private static function isMapped(ClassReflection $class): bool
    {
        foreach ($class->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), self::MAPPING_ATTRIBUTES, true)) {
                return true;
            }
        }

        return false;
    }

    private static function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('simpleFeedReader.thinController.entity')
            ->build();
    }
}
```

- [ ] **Step 4: Run the test and check that it passes.**

Run: `php bin/phpunit tests/PhpStan/ControllerMutatesNoEntityRuleTest.php tests/PhpStan/ThinControllerRuleTest.php`
Expected: OK. If line 112 is reported twice, PHPStan has changed its F1 behaviour: stop and report it, and do not add a `NullsafeMethodCall` arm.

- [ ] **Step 5: Run PHPStan over the whole tree.**

Run: `vendor/bin/phpstan clear-result-cache && composer stan`
Expected: green, with no new finding in `src/Controller`.

- [ ] **Step 6: Break test.** Prove that the new branches bite on real classes and that the value-object exclusion holds. In `src/Controller/Api/TagController.php`, add `use App\Entity\EntryMedium;` directly after `use App\Entity\User;`. Then insert these lines in `update`, directly after `$this->editor->update($tag, $request);`:
```php
        $rename = $tag->setName(...);
        $normalised = User::normalizeEmail('break-test@example.test');
        $medium = new EntryMedium('https://example.com/break-test.png', 'image');
```
Run: `vendor/bin/phpstan clear-result-cache && composer stan`
Expected: exactly two errors, both identified `simpleFeedReader.thinController.entity`:
- `A controller calls App\Entity\Tag::setName(), which changes an entity. …`
- `A controller calls App\Entity\User::normalizeEmail(), a static method of an entity. …`

There is no error for `EntryMedium`. The old rule would have reported its construction.

Restore by hand with the Edit tool: delete the three lines and the `EntryMedium` import. Then run `vendor/bin/phpstan clear-result-cache && composer stan` again. Expected: green. Also run `git diff --stat -- src/Controller/Api/TagController.php`. Expected: only Task 1's change to that file.

- [ ] **Step 7: Update CLAUDE.md.** Before:
```markdown
  helpers lives in the rule and only ever shrinks. Its sibling
  **`ControllerMutatesNoEntityRule`** rejects entity construction and any call on
  an entity other than `get*`/`is*`/`has*` and `requireId()` inside a controller.
```
after:
```markdown
  helpers lives in the rule and only ever shrinks. Its sibling
  **`ControllerMutatesNoEntityRule`** rejects, inside a controller, constructing a
  class Doctrine maps (`#[ORM\Entity]` or `#[ORM\Embeddable]`), calling it
  statically, and calling, or taking as a first-class callable, any of its
  methods other than `get*`/`is*`/`has*` and `requireId()`.
```

- [ ] **Step 8: Run the gates.**

Run: `composer check`
Expected: all green. The rule lives in `tests/`, so `composer md` does not cover it.

- [ ] **Step 9: Commit.**

```bash
git add tests/PhpStan/ControllerMutatesNoEntityRule.php tests/PhpStan/ControllerMutatesNoEntityRuleTest.php \
  tests/PhpStan/data/controller-mutates-no-entity-fixtures.php ../CLAUDE.md
git commit -m "refactor(#1157): ControllerMutatesNoEntityRule keys on Doctrine mapping and sees callables and static calls

A class under App\Entity that Doctrine does not map (an exception, a readonly
value object) is no longer an entity to the rule. First-class callables reach
rules only as *CallableNode, and static calls were never checked (PR A F6)."
```

---

## Finishing

1. **Run the branch-wide sweeps.** Each line has its expected output.
   ```bash
   git grep -nE "NotFoundHttpException|AccessDeniedHttpException" -- src/Controller   # nothing
   git grep -nE "findOneOwnedBy|findOwned\(|findOneSubscribedByUser|oneRowForUser" -- src/Controller src/Service/Reader/MarkReadService.php   # nothing
   git grep -nE "loadInto\(" -- src/Controller src/Service        # nothing
   git grep -nE "MeJson::profile\(" -- src                        # only src/Http/MeProfileJson.php
   git grep -nE "private (static )?function" -- src/Controller    # nothing
   git grep -n "flush()" -- src/Repository                        # nothing
   git grep -n "ALLOW_LIST = \[\]" -- tests/PhpStan/ThinControllerRule.php   # still empty
   git grep -n "OwnedRecordNotFoundException" -- src tests        # nothing (D1)
   ```
2. **Run the gates, all green:**
   - `composer check`
   - `composer md`
   - `php bin/phpunit` (SQLite)
   - `docker compose exec php composer test` (MySQL). First confirm that the php container runs this checkout's code; see the "check the container is current" rule.
   - `composer infection:diff`. Every new file is untracked until committed, so run it after the last commit.

   Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. It must show no new deprecations or errors.
3. **Run the SDD final whole-branch review.** It is not optional. Ask the reviewer to attack these points:
   - **Did any response change beyond D1 and D3?**
     - `git diff origin/develop --stat -- tests/Controller` must list exactly these files: the seven D1 files (`Api/TagControllerTest`, `Api/SubscriptionControllerTest`, `Api/SavedSearchControllerTest`, `Api/EntryControllerTest`, `Api/RecommendationDebugLogControllerTest`, `Api/RefreshControllerTest`, `Api/CatalogFaviconControllerTest`), the new `Api/EntryPageParametersTest.php` (D3) and `Admin/AdminCatalogControllerTest` (Task 16). Each D1 file may only gain `detail` assertions.
     - Every 404 that used to be a `NotFoundHttpException` keeps its status, `type` and `title`, and now carries its message as `detail` (D1). Nothing else in those bodies changed.
     - The test-digest 403 is still a detail-less `forbidden`.
   - **Did any order change?** Check each of these:
     - Test-digest gate before the limiter.
     - Refresh limiter before the ownership check.
     - Reader ownership before the reader limiter.
     - Comments ownership before the comments limiter.
     - OAuth: declined, then missing params, then state consume, then provider match, then exchange.
     - Mail-failure log: persist, then flush, then prune.
   - **Did anything cross the in-flight boundaries?**
     - No `*Json`, `Response` or `JsonResponse` in `src/Service` (#1158).
     - No QueryBuilder outside `src/Repository` (#1170).
     - No `flush()` in `src/Repository` (docs/architecture.md §7).
     - `requireId()` in every new test.
   - **Do the rules hold?** `composer stan` is green with PR A's two rules, Task 18's widened `ControllerMutatesNoEntityRule` and an empty `ThinControllerRule::ALLOW_LIST`.
4. **Run `/simplify`** over the branch diff. If it changed anything, re-run the gates from step 2.
5. **Open the PR against `develop`** with this body:

   ```markdown
   Closes #1157

   Part B of #1157. Part A (#1180) took persistence and entity mutation out of the controllers.

   - **Owned lookups.** `getOneOwnedBy` / `getOneRowForUser` / `getOneSubscribedByUser` / `getOwned` on the
     repositories replace the 15 inline `?? throw new NotFoundHttpException(...)`. They throw the existing
     `RecordNotFoundException`, and `MarkReadService` now uses the same methods instead of its own two copies.
   - **Security decisions.** `UserRefreshScope` (refresh ownership), `TestDigestEligibility` (the mail/verified gate,
     still ahead of the limiter) and `OAuthCallback` (state, provider and exchange checks) own the decisions. The
     controllers keep the HTTP around them.
   - **Response assembly.** `VersionJson`, `OnboardingJson`, `OpmlJson`, `AdminUserLimitsJson`, `SubscribeOutcomeJson`,
     `MeProfileJson`, `CatalogFaviconResponse`, and new methods on `AdminCatalogJson`, `SubscriptionJson`,
     `SubscriptionCountsJson` and `AiSettingsJson`. Nothing presentational moved into a service (#1158).
   - **Shared boilerplate.** `EntryListRowEnricher` replaces six copies of the two-loader chain. `EntryPageParameters`
     (`#[MapQueryString]`) replaces the page parameters that three actions re-read.
   - **Commands.** `CatalogUrlChecker` does the rot check and takes its document from `BundledCatalog`.
     `AuditShard` and `AuditFindingsFile` take the reader audit's sharding and file I/O.
   - **Follow-ups from part A.** The admin category test reads update and delete back from the database, which kills
     two escaped mutants. `MailSendFailureRepository::add()` only persists, and `MailDeliveryHealth` owns the flush.
     `ControllerMutatesNoEntityRule` keys on Doctrine mapping (`#[ORM\Entity]` / `#[ORM\Embeddable]`) instead of
     the `App\Entity` namespace, and it now also catches first-class callables and static calls.

   `(int) $user->getId()` was already gone (#1165), and `$user->requireId()` stays as the cast-free id read. The
   ThinControllerRule allow-list stays empty.

   **Two deliberate wire changes:**
   - **404s now carry `detail`.** Status, `type` and `title` are unchanged, but each moved 404 now has its message
     as `detail`: `No such tag.`, `No such subscription.`, `No such saved search.`, `No such entry.`,
     `No such debug log entry.`, and `No such feed.` on the catalog favicon. This is the body `RecordNotFoundException`
     already produces elsewhere. One existing 404 test per message pins it. The SPA does not read `detail` on these
     endpoints.
   - **A malformed page parameter answers 422.** A malformed `limit`/`unread` on `/api/entries` and
     `/api/entries/saved-searches[/{id}]` now answers `422 validation_error` naming the field, instead of
     `#[MapQueryParameter]`'s bare 404. `EntryPageParametersTest` pins it.
   ```
   The body names #1157 as the only issue it closes. It mentions #1158, #1165 and #1180 as references only. Do not add a closing keyword for any of them.
6. **Merge when CI is green** (the planner's instruction for this plan).
   - Arm a Monitor that polls `gh pr checks <pr>` until every check has passed. Then run `gh pr merge <pr> --merge`.
   - Never use `gh pr merge --auto`: it merges immediately on this repository.
   - If a check fails, stop and report. If phptramp fails, look at `composer show larspohlmann/phptramp` before you blame the diff.
   - After the merge, confirm with `gh issue view 1157 --json state` that #1157 closed. Do not close it by hand.
