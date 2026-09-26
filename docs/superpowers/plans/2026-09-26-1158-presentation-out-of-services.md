# Presentation Out of Services and Repositories (#1158) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No class in `App\Service`, `App\Repository`, `App\Entity`, `App\Enum`, `App\Exception` or the new `App\Pagination` references `App\Http\*` or any Symfony HTTP class, and `DomainKnowsNoHttpRule` enforces that everywhere, not only in `*\Exception` namespaces.

**Architecture:** Three moves, each behaviour-neutral:
- **Domain rules and value types leave `App\Http`.** "Is AI ready" becomes `Service/Ai/AiReadiness`. The two keyset cursors and `MalformedCursorException` move together to a new top-level `App\Pagination` namespace.
- **HTTP helpers leave `App\Service`.** Classes whose whole job is a Symfony `Request`, `Response` or `Cookie` move into `App\Http` (`MaintenanceTokenGuard`, `FlowCookie`, `OAuthRedirectFactory`, `CallbackParameters`, `BackupDownloadResponseFactory`, `EntrySearchRequestFactory`). Where a service needs one HTTP fact, it gets a scalar (`RateLimitGuard` takes the client IP) or a consumer-owned interface with an `App\Http` implementation (`ServingHost`, `StatusReasonPhrases`).
- **Services return typed results; controllers call the `Http/*Json` mapper.** The ten services that returned JSON arrays now return small `final readonly` values (`ForYouFeedPage`, `RecommendationRunStatus`, `RunHistoryOverview`, …). The mapper takes that value; the controller wires the two together.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit 12 (DAMA DoctrineTestBundle), PHPStan 2.x at level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection 0.34.

**Spec:** GitHub issue #1158 (`gh issue view 1158`). Related:
- #1160 (merged) created `DomainKnowsNoHttpRule` and the `*Problems` mappers.
- #1165 (merged) added `requireId()`; `EntityIdCoercionRule` also runs over `tests/`.
- #1170 (merged, a14c66c8) moved query code into repositories. #1157 (merged in two PRs, 883ac759 and 2b08b681) moved controller work into `Service/*` editors and `src/Http` mappers. This plan is written against develop at 2b08b681; see "Merged dependencies".
- #1159 (open) splits the admin settings services from runtime config. The boundary is stated under "Open dependency".
- #1182 is the follow-up for the three leak families this plan leaves (Appendix C).

## Status

| Task | PR | State |
|---|---|---|
| Task 0: Preflight | 1 | ✅ done |
| Task 1: `AiReadiness` replaces `AiSettingsJson::isReady()` | 1 | ✅ done |
| Task 2: Cursors and `MalformedCursorException` move to `App\Pagination` | 1 | ✅ done |
| Task 3: OAuth HTTP helpers move to `App\Http\OAuth` | 1 | ✅ done |
| Task 4: `MaintenanceTokenGuard`, `BackupDownloadResponseFactory`, `EntrySearchRequestFactory` move to `App\Http` | 1 | ✅ done |
| Task 5: `RateLimitGuard::enforceForClient()` takes the client IP | 1 | ✅ done |
| Task 6: `ServingHost` becomes an interface; `App\Http\RequestServingHost` implements it | 1 | ✅ done |
| Task 7: `HtmlPageFetcher` asks `StatusReasonPhrases` instead of `Response` | 1 | ✅ done |
| Task 8: Rule, step 1: Symfony HTTP forbidden in every domain namespace | 1 | ✅ done |
| Task 9: `ForYouFeed` returns `ForYouFeedPage` | 2 | ✅ done |
| Task 10: `RecommendationRunStatusResolver` returns `RecommendationRunStatus` | 2 | ✅ done |
| Task 11: `RecommendationRunHistory` returns `RunHistoryOverview` / `RunHistoryMonthPage` | 2 | ✅ done |
| Task 12: `RecommendationDebugLogLoader` returns `RecommendationDebugLog` | 2 | ✅ done |
| Task 13: `ReadingActivityCounter` returns `ReadingActivity` | 2 | ✅ done |
| Task 14: `PasskeyListing` returns `AccountPasskeys` | 2 | ✅ done |
| Task 15: `MailDeliveryHealth::recentFailures()` | 2 | ✅ done |
| Task 16: Settings overviews (Grafana, Mail, Proxy) — #1159 boundary | 2 | ✅ done |
| Task 17: Rule, step 2: `App\Http` forbidden in services too; CLAUDE.md | 2 | ✅ done |

## Scope and PR split

The issue names six families of leak. This plan removes the three that `DomainKnowsNoHttpRule` can gate, and turns the rule on for all of them:

1. `isReady` living in a JSON mapper.
2. Services returning JSON shapes (the `App\Http` imports in `src/Service`).
3. Repositories and services importing the cursors from `App\Http`, plus every Symfony `HttpFoundation` import in domain code.

The other three need judgement and a namespace decision no rule can gate yet: request DTOs taken by services, `toArray()` on service values, and the shared value types and enums. They belong to #1182 (Appendix C).

Two PRs (R1), each about 35 files and green on its own:
- **PR 1 (Tasks 0–8), `Refs #1158`.** isReady, cursors, every `HttpFoundation` import, rule step 1. After it, the rule forbids Symfony HTTP everywhere in domain code, and `App\Http` everywhere except outside exception namespaces in `App\Service`. #1158 stays open.
- **PR 2 (Tasks 9–17), `Closes #1158`.** The ten JSON-returning services, rule step 2 (the `App\Service` carve-out goes), CLAUDE.md.

## Inventory (develop @ `2b08b681`, 2026-09-26)

Paths are relative to `backend/`. Sweeps:
`git grep -nE '^use (App\\Http\\|Symfony\\Component\\HttpFoundation\\|Symfony\\Component\\HttpKernel\\Exception\\|Symfony\\Component\\Security\\Core\\Exception\\AccessDenied)' origin/develop -- backend/src/Service backend/src/Repository backend/src/Entity backend/src/Enum backend/src/Exception`
plus a fully-qualified-name sweep and a class-name-in-string sweep, both empty. #1157 and #1170 added no site: the 34 `use` lines below are develop's whole list at 2b08b681. #1157's new `Service/OAuth/OAuthCallback` takes an `OAuthCallbackAttempt` DTO and never touches `CallbackParameters`, `FlowCookie` or `OAuthRedirectFactory`, so Task 3's moves leave it alone. A same-namespace reference has no `use` line, so each moved class was also swept by name: only the files listed in Tasks 2–4 name them.

**A. `isReady` in a JSON mapper** — target `Service/Ai/AiReadiness::of(?AiProviderSettings): bool` (Task 1)

| Site | Today |
|---|---|
| `src/Http/AiSettingsJson.php:94-108` | the definition |
| `src/Http/AiSettingsJson.php:43` | `'ready' => self::isReady($settings)` |
| `src/Http/MeJson.php:48` | `'ready' => AiSettingsJson::isReady($aiSettings)` |
| `src/Service/Recommendation/DueRecommendationRunFinder.php:9, :53` | import; scheduler guard |
| `src/Service/Recommendation/RecommendationRunStarter.php:9, :44, :88` | import; `start()` and `resume()` guards |

**B. Cursors in `App\Http`** — target `App\Pagination\{EntryCursor, RecommendationCursor, Exception\MalformedCursorException}` (Task 2)

| Site | Import |
|---|---|
| `src/Repository/AbstractEntryProjectionRepository.php:10` | `App\Http\EntryCursor` |
| `src/Repository/EntryQuery.php:8` | `App\Http\EntryCursor` |
| `src/Repository/EntrySearchQuery.php:8` | `App\Http\EntryCursor` |
| `src/Repository/SavedSearchListQuery.php:8` | `App\Http\EntryCursor` |
| `src/Repository/RecommendationItemRepository.php:12` | `App\Http\RecommendationCursor` |
| `src/Service/Recommendation/RecommendationFeedPager.php:7-8` | `MalformedCursorException`, `RecommendationCursor` (the pager catches the exception and treats a garbled For You cursor as none — kept) |
| `src/Service/Search/Index/IndexSearch.php:8` | `App\Http\EntryCursor` |
| `src/Service/Search/EntrySearchRequestFactory.php:10` | `App\Http\EntryCursor` (the class itself moves to `App\Http` in Task 4) |
| `src/Http/EntryPage.php:89` | same-namespace use today; gains an import |
| `src/Controller/Api/EntryController.php:15`, `src/Controller/Api/SavedSearchEntriesController.php:11` | `App\Http\EntryCursor` (both now read it from #1157's `EntryPageParameters` DTO) |

**C. Symfony `HttpFoundation` in domain code** (12 imports in 9 files)

| Site | Uses | Target (task) |
|---|---|---|
| `src/Service/Maintenance/MaintenanceTokenGuard.php:8-10` | `JsonResponse`, `Request`, `Response` | move to `App\Http\MaintenanceTokenGuard` (4) |
| `src/Service/OAuth/FlowCookie.php:8-9` | `Cookie`, `Response` | move to `App\Http\OAuth\FlowCookie` (3) |
| `src/Service/OAuth/OAuthRedirectFactory.php:8` | `RedirectResponse` | move to `App\Http\OAuth\OAuthRedirectFactory` (3) |
| `src/Service/OAuth/CallbackParameters.php:7` | `Request` | move to `App\Http\OAuth\CallbackParameters` (3) |
| `src/Service/Backup/BackupDownloadResponseFactory.php:9` | `StreamedResponse` | move to `App\Http\BackupDownloadResponseFactory` (4) |
| `src/Service/Search/EntrySearchRequestFactory.php:13` | `Request` | move to `App\Http\EntrySearchRequestFactory` (4) |
| `src/Service/RateLimit/RateLimitGuard.php:10, :51-54` | `Request` for `getClientIp()` | take `?string $clientIp` (5) |
| `src/Service/Settings/ServingHost.php:7` | `RequestStack` | interface; `App\Http\RequestServingHost` implements it (6) |
| `src/Service/Reader/HtmlPageFetcher.php:14, :138` | `Response::$statusTexts` | `Service/Reader/StatusReasonPhrases`, implemented by `App\Http\SymfonyStatusReasonPhrases` (7) |

**D. Services returning `App\Http` shapes** (17 `App\Http` imports in `src/Service`, 5 more in `src/Repository` from B — 22 presentation imports in all)

| Service (develop lines) | Mapper it calls | Target (task) |
|---|---|---|
| `Recommendation/ForYouFeedResponder.php:7-8, :32-45` | `RecommendationFeedJson::page`, `FeedAnnotationVisibility` | `ForYouFeed::page(): ForYouFeedPage`; `FeedAnnotationVisibility` moves to `Service/Recommendation` (9) |
| `Recommendation/RecommendationRunStatusPayload.php:8, :30-38` | `RecommendationRunStatusJson::report` | `RecommendationRunStatusResolver::forReport(): RecommendationRunStatus` (10) |
| `Recommendation/RecommendationRunHistoryView.php:8, :22-23, :42-59` | `RecommendationRunHistoryJson::overview/monthPage` | `RecommendationRunHistory` returning `RunHistoryOverview` / `RunHistoryMonthPage` (11) |
| `Recommendation/RecommendationDebugLogView.php:9, :42-59` | `RecommendationDebugLogJson::list` | `RecommendationDebugLogLoader::forUser(): RecommendationDebugLog` (12) |
| `Reading/ReadingActivityView.php:8, :23, :39-54` | `ReadingActivityJson::of` | `ReadingActivityCounter::daily(): ReadingActivity` (13) |
| `Passkey/PasskeyListing.php:8, :16, :30-35` | `PasskeyJson::listing` | `PasskeyListing::forUser(): AccountPasskeys` (14) |
| `Mail/MailDeliveryHealth.php:9, :40-46` | `MailDeliveryHealthJson::view` | `recentFailures(): list<MailSendFailure>` (15) |
| `Grafana/GrafanaSettings.php:9, :46-50` | `Admin\GrafanaSettingsJson::from` | `overview(): GrafanaSettingsOverview` (16) |
| `Mail/Settings/MailSettings.php:10, :23, :36-44` | `Admin\MailSettingsJson::from` | `overview(): MailSettingsOverview` (16) |
| `Proxy/ProxySettings.php:10, :30-45` | `Admin\ProxySettingsJson::from` | `stored(): ?ProxyServerSettings` (16) |
| `Recommendation/DueRecommendationRunFinder.php:9`, `RecommendationRunStarter.php:9` | `AiSettingsJson::isReady` | see A (1) |
| `Recommendation/RecommendationFeedPager.php:7-8`, `Search/Index/IndexSearch.php:8`, `Search/EntrySearchRequestFactory.php:10` | cursors | see B (2) |

`Security\Core\Exception\AccessDeniedException` and `HttpFoundation\Exception\BadRequestException`: no domain file uses either today. Task 8 closes the gap so none can.

## Rulings

Lars ruled on the draft's decisions on 2026-09-26. They are settled; do not reopen them during execution.

- **R1 (D1), two PRs: approved.** PR 1 (Tasks 0–8) says `Refs #1158` and carries no closing keyword (`close`, `closes`, `closed`, `fix`, `fixes`, `fixed`, `resolve`, `resolves`, `resolved`) anywhere in its title, body or commit messages, not even in prose. After PR 1 merges, #1158 must still be OPEN (Finishing, PR 1, step 6). PR 2 (Tasks 9–17) says `Closes #1158`, and that is its only closing keyword.
- **R2 (D2), CHANGED: the cursors live in a new top-level `App\Pagination` namespace**, not `App\Domain`. `EntryCursor` and `RecommendationCursor` move to `src/Pagination/`, `MalformedCursorException` to `src/Pagination/Exception/`, and their tests to `tests/Pagination/`. `DomainKnowsNoHttpRule` covers `App\Pagination\`, and CLAUDE.md's layout table names `backend/src/Pagination/**`. No `App\Domain` namespace is created; the home of the other shared value types is #1182's decision.
- **R3 (D3), ban all of `Symfony\Component\HttpFoundation\` in domain code: approved.** Plus `HttpKernel\Exception\*` (already banned) and `Security\Core\Exception\AccessDeniedException`. Tasks 3–7 remove every current use.
- **R4 (D4), `HtmlPageFetcher` asks a consumer-owned `Service/Reader/StatusReasonPhrases`**, implemented in `App\Http` over `Response::$statusTexts`: approved.
- **R5 (D5), `ServingHost` becomes an interface with the same FQCN**, implemented by `App\Http\RequestServingHost`: approved.
- **R6 (D6), #1159 owns the admin/runtime settings split and the `*SettingsRequest` DTOs: approved.** This plan moves only the JSON call out of the three settings services (Task 16). If #1159 lands first, Task 16 is skipped and PR 2's body says so.
- **R7 (D7), a static `AiReadiness::of(?AiProviderSettings)` in `Service/Ai`: approved.**
- **R8 (D8), the five renames: approved.** `ForYouFeedResponder` → `ForYouFeed`, `RecommendationRunStatusPayload` → `RecommendationRunStatusResolver`, `RecommendationRunHistoryView` → `RecommendationRunHistory`, `RecommendationDebugLogView` → `RecommendationDebugLogLoader`, `ReadingActivityView` → `ReadingActivityCounter`. `PasskeyListing` keeps its name.
- **R9 (D9), the follow-up issue exists: #1182.** Nothing is opened during execution. PR 2's body carries the line `Follow-up: #1182`.
- **R10 (D10), the two CLAUDE.md edits (Task 17, Step 8): approved as drafted**, with `Domain` read as `Pagination` per R2.

## Merged dependencies

Every before-block below is develop's text at 2b08b681.

- **#1170** (a14c66c8): `MailSendFailureRepository` and `MailDeliveryHealthTest` changed, not the lines Task 15 edits. `QueriesLiveInRepositoriesRule` guards every `App\` class outside `App\Repository`, `App\Doctrine` and `App\Tests`, so it covers `App\Pagination` too; the cursors build no query. This plan adds no query code: every new service method calls an existing repository method.
- **#1157 PR A** (883ac759) and **PR B** (2b08b681), including both plans' "Execution rulings":
  - `EntryController::list` reads its page parameters from the `#[MapQueryString] EntryPageParameters $page` DTO (PR B Task 8), so the for-you branch Task 9 edits reads `$page->cursor`, `$page->limit` and `$page->unread`. `EntryController` also moved to `EntryListRowEnricher`, which shifted its import and constructor lines by one.
  - `ForYouFeedResponder` takes `EntryListRowEnricher` instead of the two batch loaders (PR B Task 7 and its ruling D7). Task 9's `ForYouFeed` and its test keep the enricher.
  - `AiSettingsJson` gained `configurationFor()` and a `use App\Entity\User;` line (PR B Task 10), which moves `isReady()` to lines 94–108. `MeJson` is unchanged; `MeProfileJson::of()` wraps it.
  - `RecommendationDebugLogController::entry()` now calls `getOwned()` (PR B Task 2); the lines Task 12 edits moved up by one.
  - `MailDeliveryHealth::recordFailure()` owns the flush (PR B Task 17), which added the `EntityManagerInterface` import; `view()` is now lines 40–46.
  - `OAuthController` imports `OAuthCallbackAttempt`, `OAuthCallbackRefusedException` and `OAuthCallback` (PR B Task 6), and its `start()` rate-limit call is at line 184.
  - `EntrySearchController` takes `EntryListRowEnricher` instead of the two loaders.
  - `ControllerMutatesNoEntityRule` and its method-callable and static-callable variants: on a Doctrine-mapped class a controller may call only `get*`, `is*`, `has*` and `requireId()`, may not construct one, and may not call a static method that returns one. Every controller line this plan writes calls services, `src/Http` mappers, `Request` and non-mapped value constructors (`ForYouFeedQuery`, `ViewerTimeZone::of`, `MonthWindow::of`, `RecommendationRunReport::none`) only.
  - `ThinControllerRule`: no private or protected controller helper and no `ObjectManager`/`ManagerRegistry` parameter. This plan adds neither; the allow-list stays empty.
- **#1165** (fcc1e6b8): `requireId()` comes from the `PersistedId` trait. `EntityIdCoercionRule` runs over `src` and `tests`.

## Open dependency

- **#1159 (R6):** this plan changes `GrafanaSettings::view()`, `MailSettings::view()` and `ProxySettings::view()` into typed reads and nothing else in those classes. `update(*SettingsRequest)`, the runtime getters, the missing interfaces and the non-`final` classes stay for #1159. If #1159 lands first, Task 9, Step 0 finds no `App\Http` import in those three files: mark Task 16 skipped and say so in PR 2's body.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **Line numbers** in a before-block's label are the file's lines before that task touches it: develop's at 2b08b681, or, where the label says so, the lines an earlier task left. Edits within one step are listed top to bottom, so apply them bottom to top or match on the before text.
- **No wire-contract change.** Every JSON body, status code and header stays byte-identical. The controller and e2e tests are the contract net: no file under `tests/Controller/` or `tests/E2e/` is edited, and every one must pass unchanged. Mapper tests under `tests/Http/` may change how they build the mapper's input, never weaken what they assert. (The three `isReady` tests move to `AiReadinessTest`; Task 11 compares `latest` with the mapped page instead of the raw array.)
- **Moves are moves.** Relocate a class with `git mv`, then change only the namespace, imports and references the move needs, so git records a rename. Moved docblocks stay verbatim unless they name the old location.
- **Clean Code (CLAUDE.md) is mandatory.** `final readonly class` with constructor promotion for every new class; guard clauses; no boolean flag parameters; at most three method parameters (value-object constructors excepted).
- **Comments:** one line at most in new code, and only where a future reader would otherwise get the code wrong.
- **Every touched `src` file must be PHPMD-clean** under `composer md`, not merely free of new findings.
- **PHPStan level max:** no baseline entries, no `@phpstan-ignore`. `DomainKnowsNoHttpRule` gets no allow-list.
- **Tests use `requireId()`** whenever they need an entity's id, never `getId()` and never `(int) getId()`; `EntityIdCoercionRule` runs over `tests/` too.
- **Controllers stay inside #1157's rules.** New or changed controller code calls only `get*`, `is*`, `has*` and `requireId()` on a Doctrine-mapped class, constructs none, and adds no private or protected method (`ControllerMutatesNoEntityRule` and its two callable variants, `ThinControllerRule`).
- **Per-task gates:** the task's tests, then `composer check` and `composer md`. Run `bin/console cache:clear` before `composer stan` in any task that adds, renames or moves a service, so the dev container XML PHPStan reads knows it.
- **Branch-wide gates (Finishing):** `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test`, `composer infection:diff`.
- **Commit format:** `refactor(#1158): …`, one commit per task. Never commit to `develop`. No commit message on either branch uses a closing keyword (R1); the commit messages below contain none.
- **Branches:** PR 1 on `refactor/1158-presentation-out-of-services`, cut from `origin/develop`. PR 2 on `refactor/1158-presentation-out-of-services-2`, cut from `origin/develop` after PR 1 has merged.

## File Structure

New files:

| File | Responsibility |
|---|---|
| `src/Service/Ai/AiReadiness.php` | The one definition of "this account's AI is ready" |
| `src/Pagination/EntryCursor.php`, `RecommendationCursor.php`, `Exception/MalformedCursorException.php` | Moved from `src/Http`: keyset cursors and their decode failure |
| `src/Http/OAuth/FlowCookie.php`, `OAuthRedirectFactory.php`, `CallbackParameters.php` | Moved from `src/Service/OAuth`: the OAuth flow's cookie, redirects and callback reads |
| `src/Http/MaintenanceTokenGuard.php` | Moved from `src/Service/Maintenance` |
| `src/Http/BackupDownloadResponseFactory.php` | Moved from `src/Service/Backup` |
| `src/Http/EntrySearchRequestFactory.php` | Moved from `src/Service/Search` |
| `src/Http/RequestServingHost.php` | The `RequestStack` implementation of `Service\Settings\ServingHost` |
| `src/Service/Reader/StatusReasonPhrases.php`, `src/Http/SymfonyStatusReasonPhrases.php` | The reason phrase `HtmlPageFetcher` needs, and its Symfony-backed implementation |
| `src/Service/Recommendation/ForYouFeedPage.php` | One annotated page of the for-you feed |
| `src/Service/Recommendation/RecommendationRunStatus.php` | A run report plus the three facts its status response carries |
| `src/Service/Recommendation/RunHistoryOverview.php`, `RunHistoryMonthPage.php` | The run-history card and one month page |
| `src/Service/Recommendation/RecommendationDebugLog.php` | The debug panel's rows, streaming text and run choice |
| `src/Service/Reading/ReadingActivity.php` | The reading window's dates, per-day counts and top feeds |
| `src/Service/Passkey/AccountPasskeys.php` | An account's passkeys plus the relying party id and shared handle |
| `src/Service/Grafana/GrafanaSettingsOverview.php`, `src/Service/Mail/Settings/MailSettingsOverview.php` | What the admin screens show |
| `tests/Service/Ai/AiReadinessTest.php`, `tests/Http/SymfonyStatusReasonPhrasesTest.php` | Tests for the two new behaviours |
| `tests/Http/RecommendationDebugLogJsonTest.php`, `tests/Http/PasskeyJsonTest.php` | Mapper tests for the two mappers that had none |

Renamed files (`git mv`, then the content given in the task): `ForYouFeedResponder` → `ForYouFeed`, `RecommendationRunStatusPayload` → `RecommendationRunStatusResolver`, `RecommendationRunHistoryView` → `RecommendationRunHistory`, `RecommendationDebugLogView` → `RecommendationDebugLogLoader`, `ReadingActivityView` → `ReadingActivityCounter`, `src/Http/FeedAnnotationVisibility.php` → `src/Service/Recommendation/FeedAnnotationVisibility.php`, and the tests that move with their classes.

Modified: the mappers named in inventory D, the controllers that call them, `src/Service/Settings/ServingHost.php`, `src/Service/RateLimit/RateLimitGuard.php`, `src/Service/Reader/HtmlPageFetcher.php`, `config/services.yaml`, `tests/PhpStan/DomainKnowsNoHttpRule.php` with its test and fixtures, and `CLAUDE.md`.

---

### Task 0: Preflight

**Files:** none changed.

- [ ] **Step 1: Check that the checkout is free.** Another session may be mid-edit.

Run: `git status --short && git branch --show-current`
Expected: a clean tree. If it is not clean, or another session's branch is checked out, stop and ask Lars. Do not stash, reset or check out over it.

- [ ] **Step 2: Confirm develop contains #1157 and #1170, and both issues are closed.**

Run:
```bash
git fetch origin develop
git merge-base --is-ancestor 2b08b681 origin/develop && echo CONTAINS-2b08b681
gh issue view 1170 --json state -q .state
gh issue view 1157 --json state -q .state
gh issue view 1158 --json state -q .state
```
Expected: `CONTAINS-2b08b681`, then `CLOSED`, `CLOSED`, `OPEN`. If the ancestry check prints nothing or either dependency is open, stop: this plan's before-blocks are develop's text at 2b08b681.

- [ ] **Step 3: List the files that changed since this plan was verified.**

Run:
```bash
git diff --stat 2b08b681 origin/develop -- \
  src/Http/AiSettingsJson.php src/Http/MeJson.php src/Http/EntryPage.php src/Http/EntryCursor.php \
  src/Http/RecommendationCursor.php src/Http/Exception src/Http/RecommendationFeedJson.php \
  src/Http/FeedAnnotationVisibility.php src/Http/RecommendationRunStatusJson.php \
  src/Http/RecommendationRunHistoryJson.php src/Http/RecommendationDebugLogJson.php \
  src/Http/ReadingActivityJson.php src/Http/PasskeyJson.php src/Http/MailDeliveryHealthJson.php src/Http/Admin \
  src/Service/Recommendation src/Service/Search src/Service/OAuth src/Service/Maintenance src/Service/Backup \
  src/Service/RateLimit src/Service/Settings src/Service/Reader/HtmlPageFetcher.php src/Service/Reading \
  src/Service/Passkey src/Service/Mail src/Service/Grafana src/Service/Proxy src/Service/Ai \
  src/Repository/AbstractEntryProjectionRepository.php src/Repository/EntryQuery.php \
  src/Repository/EntrySearchQuery.php src/Repository/SavedSearchListQuery.php \
  src/Repository/RecommendationItemRepository.php src/Controller config/services.yaml \
  tests/Http tests/Service tests/Repository tests/PhpStan/DomainKnowsNoHttpRule.php \
  tests/PhpStan/DomainKnowsNoHttpRuleTest.php tests/PhpStan/data/domain-knows-no-http-fixtures.php \
  ../CLAUDE.md
```
Expected: no output, since every before-block below was verified against 2b08b681. If files are listed, another branch landed since: for each listed file this plan edits, run `git show origin/develop:backend/<path>` and compare it with the before-blocks of the task that edits it. Where develop differs, take develop's text, keep this plan's after-code, names and signatures, and note the drift for the reviewer. Stop and ask Lars if a target class or method no longer exists.

- [ ] **Step 4: Re-run the inventory sweeps.**

Run:
```bash
git grep -nE '^use (App\\Http\\|Symfony\\Component\\HttpFoundation\\|Symfony\\Component\\HttpKernel\\Exception\\|Symfony\\Component\\Security\\Core\\Exception\\AccessDenied)' \
  origin/develop -- src/Service src/Repository src/Entity src/Enum src/Exception
git grep -nE 'App\\Http\\|HttpFoundation|HttpKernel\\Exception|AccessDeniedException' origin/develop -- \
  src/Service src/Repository src/Entity src/Enum src/Exception | grep -v ':use '
```
Expected: the first command prints the 34 `use` lines of inventory tables B, C and D (the 5 repository lines of B, the 12 `HttpFoundation` lines of C and the 17 `App\Http` lines in `src/Service` of D); the second prints nothing. A new site means another branch added one: add it to the task that owns its module (same pattern as its neighbours) and tell the reviewer.

- [ ] **Step 5: Record the PHPMD baseline for the `src` files this plan touches.**

Run: `composer md 2>&1 | grep -E 'Http/(AiSettingsJson|MeJson|EntryPage|RecommendationFeedJson|RecommendationRunStatusJson|RecommendationRunHistoryJson|RecommendationDebugLogJson|ReadingActivityJson|PasskeyJson|MailDeliveryHealthJson|Admin/)|Service/(Recommendation/(DueRecommendationRunFinder|RecommendationRunStarter|RecommendationFeedPager|ForYouFeedResponder|RecommendationRunStatusPayload|RecommendationRunHistoryView|RecommendationDebugLogView)|Search/|OAuth/(FlowCookie|OAuthRedirectFactory|CallbackParameters)|Maintenance/MaintenanceTokenGuard|Backup/BackupDownloadResponseFactory|RateLimit/RateLimitGuard|Settings/ServingHost|Reader/HtmlPageFetcher|Reading/|Passkey/PasskeyListing|Mail/MailDeliveryHealth|Mail/Settings/MailSettings|Grafana/GrafanaSettings|Proxy/ProxySettings)|Repository/(AbstractEntryProjectionRepository|EntryQuery|EntrySearchQuery|SavedSearchListQuery|RecommendationItemRepository)|Controller/' || echo CLEAN`
Expected: `CLEAN`. If a file already has a finding, stop and ask Lars: the standing rule makes the task that touches it fix the design, which this plan does not cover.

- [ ] **Step 6: Create the branch.**

Run: `git switch -c refactor/1158-presentation-out-of-services origin/develop && bin/console cache:warmup`

---

### Task 1: `AiReadiness` replaces `AiSettingsJson::isReady()`

**Files:**
- Create: `src/Service/Ai/AiReadiness.php`
- Create: `tests/Service/Ai/AiReadinessTest.php`
- Modify: `src/Http/AiSettingsJson.php:7-13, :43, :91-108`
- Modify: `src/Http/MeJson.php:7, :48`
- Modify: `src/Service/Recommendation/DueRecommendationRunFinder.php:9-12, :53`
- Modify: `src/Service/Recommendation/RecommendationRunStarter.php:9-13, :44, :88`
- Modify: `tests/Http/AiSettingsJsonTest.php:48-61` (three tests move to the new test)

**Interfaces:**
- Produces: `App\Service\Ai\AiReadiness::of(?AiProviderSettings $settings): bool` (static, pure).
- Consumes: `AiProviderSettings::hasModel(): bool`, `AiProviderConfigurator::settingsFor(User): ?AiProviderSettings`.

- [ ] **Step 1: Write the failing test** (`tests/Service/Ai/AiReadinessTest.php`). These are the three `isReady` tests from `AiSettingsJsonTest`, pointed at the new home.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiReadiness;
use App\Service\Crypto\SealedSecret;
use PHPUnit\Framework\TestCase;

final class AiReadinessTest extends TestCase
{
    public function testARowWithoutAModelIsNotReady(): void
    {
        self::assertFalse(AiReadiness::of($this->settings(null)));
    }

    public function testARowWithAModelIsReady(): void
    {
        self::assertTrue(AiReadiness::of($this->settings('gpt-4o')));
    }

    public function testNoRowIsNotReady(): void
    {
        self::assertFalse(AiReadiness::of(null));
    }

    private function settings(?string $model): AiProviderSettings
    {
        $settings = new AiProviderSettings(
            new User('readiness@example.test', new \DateTimeImmutable('2026-08-06 09:00:00')),
            null,
            'https://api.example.test/v1',
            new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1),
            'abcd',
            new \DateTimeImmutable('2026-08-06 09:30:00'),
        );

        if (null !== $model) {
            $settings->chooseModel($model, new \DateTimeImmutable('2026-08-06 10:00:00'), null);
        }

        return $settings;
    }
}
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Service/Ai/AiReadinessTest.php`
Expected: FAIL, `Class "App\Service\Ai\AiReadiness" not found`.

- [ ] **Step 3: Create `src/Service/Ai/AiReadiness.php`.** The body is `AiSettingsJson::isReady()` unchanged; its docblock shrinks to the one fact a reader could get wrong.

```php
<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;

final readonly class AiReadiness
{
    /** No verifiedAt term: chooseModel() is the only writer of `model` and stamps verifiedAt in the same call. */
    public static function of(?AiProviderSettings $settings): bool
    {
        return null !== $settings && $settings->hasModel();
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run it to see it pass.**

Run: `php bin/phpunit tests/Service/Ai/AiReadinessTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Point the two services at it.**

`src/Service/Recommendation/DueRecommendationRunFinder.php`, before (lines 9-12):
```php
use App\Http\AiSettingsJson;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
```
after:
```php
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\AiReadiness;
```
Before (line 53):
```php
        if (!AiSettingsJson::isReady($this->configurator->settingsFor($user))) {
```
after:
```php
        if (!AiReadiness::of($this->configurator->settingsFor($user))) {
```

`src/Service/Recommendation/RecommendationRunStarter.php`, before (lines 9-13):
```php
use App\Http\AiSettingsJson;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\Exception\AiNotConfiguredException;
```
after:
```php
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Ai\AiReadiness;
use App\Service\Ai\Exception\AiNotConfiguredException;
```
Before (line 44, and the identical line 88):
```php
        if (!AiSettingsJson::isReady($this->configurator->settingsFor($user))) {
```
after (both lines):
```php
        if (!AiReadiness::of($this->configurator->settingsFor($user))) {
```

- [ ] **Step 6: Point the two mappers at it, and delete the old definition.**

`src/Http/AiSettingsJson.php`, before (lines 7-13):
```php
use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Recommendation\RecommendationPackingSettings;

/**
 * The client's view of the account's AI provider configurations, and the ONE
 * definition of "ready".
```
after:
```php
use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Service\Ai\AiReadiness;
use App\Service\Recommendation\RecommendationPackingSettings;

/**
 * The client's view of the account's AI provider configurations.
```
Before (line 43):
```php
            'ready' => self::isReady($settings),
```
after:
```php
            'ready' => AiReadiness::of($settings),
```
Before (lines 91-108, the end of `models()` through the end of `isReady()`; line 109 is the class's closing brace):
```php
        return ['models' => $models];
    }

    /**
     * The one definition of "ready", named so the other responses that report
     * it — MeJson — reach a method instead of an array key no static analysis
     * can follow.
     *
     * No verifiedAt term: AiProviderSettings::chooseModel() is the only writer
     * of `model` and stamps verifiedAt in the same call, while
     * replaceConnection() clears `model` again. A row with a model is therefore
     * always a verified row, so testing both would be a term that can never be
     * false.
     */
    public static function isReady(?AiProviderSettings $settings): bool
    {
        return null !== $settings && $settings->hasModel();
    }
```
after:
```php
        return ['models' => $models];
    }
```

`src/Http/MeJson.php`, before (line 7):
```php
use App\Entity\User;
```
after:
```php
use App\Entity\User;
use App\Service\Ai\AiReadiness;
```
Before (line 48):
```php
                'ready' => AiSettingsJson::isReady($aiSettings),
```
after:
```php
                'ready' => AiReadiness::of($aiSettings),
```

`tests/Http/AiSettingsJsonTest.php`, delete lines 48-62 (the three moved tests and the blank line after them). Before:
```php
    public function testARowWithoutAModelIsNotReady(): void
    {
        self::assertFalse(AiSettingsJson::isReady($this->settings(null)));
    }

    public function testARowWithAModelIsReady(): void
    {
        self::assertTrue(AiSettingsJson::isReady($this->settings('gpt-4o')));
    }

    public function testNoRowIsNotReady(): void
    {
        self::assertFalse(AiSettingsJson::isReady(null));
    }

    public function testConfigurationCarriesTheRowsOwnShape(): void
```
after:
```php
    public function testConfigurationCarriesTheRowsOwnShape(): void
```

- [ ] **Step 7: Confirm nothing else calls the old method.**

Run: `grep -rn 'isReady' src tests`
Expected: no output.

- [ ] **Step 8: Run the affected tests and the gates.**

Run: `php bin/phpunit tests/Service/Ai tests/Http tests/Service/Recommendation tests/Controller/Api/MeTest.php tests/Controller/Api/AiSettingsControllerTest.php && composer check && composer md`
Expected: all green. `AiSettingsControllerTest` pins `ready` on the configurations (lines 162, 197, 520) and `ai.ready` on `/api/me` (line 202).

- [ ] **Step 9: Commit.**

```bash
git add src/Service/Ai/AiReadiness.php tests/Service/Ai/AiReadinessTest.php src/Http/AiSettingsJson.php src/Http/MeJson.php \
  src/Service/Recommendation/DueRecommendationRunFinder.php src/Service/Recommendation/RecommendationRunStarter.php \
  tests/Http/AiSettingsJsonTest.php
git commit -m "refactor(#1158): AI readiness is a Service/Ai rule, not a JSON mapper method"
```

---

### Task 2: Cursors and `MalformedCursorException` move to `App\Pagination`

**Files:**
- Move: `src/Http/EntryCursor.php` → `src/Pagination/EntryCursor.php`
- Move: `src/Http/RecommendationCursor.php` → `src/Pagination/RecommendationCursor.php`
- Move: `src/Http/Exception/MalformedCursorException.php` → `src/Pagination/Exception/MalformedCursorException.php`
- Move: `tests/Http/EntryCursorTest.php` → `tests/Pagination/EntryCursorTest.php`
- Move: `tests/Http/RecommendationCursorTest.php` → `tests/Pagination/RecommendationCursorTest.php`
- Modify (one `use` line each): `src/Controller/Api/EntryController.php:15`, `src/Controller/Api/SavedSearchEntriesController.php:11`, `src/Repository/AbstractEntryProjectionRepository.php:10`, `src/Repository/EntryQuery.php:8`, `src/Repository/EntrySearchQuery.php:8`, `src/Repository/RecommendationItemRepository.php:12`, `src/Repository/SavedSearchListQuery.php:8`, `src/Service/Recommendation/RecommendationFeedPager.php:7-8`, `src/Service/Search/EntrySearchRequestFactory.php:10`, `src/Service/Search/Index/IndexSearch.php:8`, `tests/Http/EntryPageTest.php:10`, `tests/Http/SavedSearchPageTest.php:9`, `tests/Http/SearchPageTest.php:9`, `tests/Repository/EntryListTest.php:14`, `tests/Repository/EntrySearchTest.php:13`, `tests/Repository/RecommendationFeedTest.php:14`, `tests/Repository/SavedSearchMembershipReadsTest.php:14`, `tests/Service/Search/EntrySearchRequestFactoryTest.php:10`, `tests/Service/Search/Index/MeilisearchIndexTest.php:8`, `tests/Service/Search/IndexedEntrySearchTest.php:13`
- Modify: `src/Http/EntryPage.php:7` (adds the import it never needed in the same namespace)

**Interfaces:**
- Produces: `App\Pagination\EntryCursor`, `App\Pagination\RecommendationCursor`, `App\Pagination\Exception\MalformedCursorException`, with every member unchanged (`fromRequestValue`, `inclusiveUpperBound`, `encode`, `decode`; `$sortInstant`, `$id`; `$runId`, `$position`).
- Kept on purpose: `RecommendationFeedPager::cursorOf()` still catches `MalformedCursorException` and returns null, so a garbled For You cursor restarts the feed. `EntryCursor::fromRequestValue()` still turns it into the 422 `ValidationException`.
- `src/Pagination` needs no configuration: `App\` autoloads from `src/`, `services.yaml` registers `../src/` whole, and PHPStan, PHPMD, phptramp and Infection all read `src`.

- [ ] **Step 1: Move the two cursor tests first, so they fail.**

Run:
```bash
mkdir -p tests/Pagination
git mv tests/Http/EntryCursorTest.php tests/Pagination/EntryCursorTest.php
git mv tests/Http/RecommendationCursorTest.php tests/Pagination/RecommendationCursorTest.php
perl -pi -e 's/^namespace App\\Tests\\Http;$/namespace App\\Tests\\Pagination;/' \
  tests/Pagination/EntryCursorTest.php tests/Pagination/RecommendationCursorTest.php
perl -pi -e 's/^use App\\Http\\EntryCursor;$/use App\\Pagination\\EntryCursor;/; s/^use App\\Http\\RecommendationCursor;$/use App\\Pagination\\RecommendationCursor;/; s/^use App\\Http\\Exception\\MalformedCursorException;$/use App\\Pagination\\Exception\\MalformedCursorException;/' \
  tests/Pagination/EntryCursorTest.php tests/Pagination/RecommendationCursorTest.php
```
`tests/Pagination/EntryCursorTest.php`, before (lines 5-10):
```php
namespace App\Tests\Http;

use App\Http\EntryCursor;
use App\Http\Exception\MalformedCursorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
```
after:
```php
namespace App\Tests\Pagination;

use App\Pagination\EntryCursor;
use App\Pagination\Exception\MalformedCursorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
```
`tests/Pagination/RecommendationCursorTest.php`, before (lines 5-9):
```php
namespace App\Tests\Http;

use App\Http\Exception\MalformedCursorException;
use App\Http\RecommendationCursor;
use PHPUnit\Framework\TestCase;
```
after:
```php
namespace App\Tests\Pagination;

use App\Pagination\Exception\MalformedCursorException;
use App\Pagination\RecommendationCursor;
use PHPUnit\Framework\TestCase;
```

- [ ] **Step 2: Run them to see them fail.**

Run: `php bin/phpunit tests/Pagination`
Expected: FAIL, `Class "App\Pagination\EntryCursor" not found` (and the same for `RecommendationCursor`).

- [ ] **Step 3: Move the three classes and fix their namespaces.**

Run:
```bash
mkdir -p src/Pagination/Exception
git mv src/Http/EntryCursor.php src/Pagination/EntryCursor.php
git mv src/Http/RecommendationCursor.php src/Pagination/RecommendationCursor.php
git mv src/Http/Exception/MalformedCursorException.php src/Pagination/Exception/MalformedCursorException.php
perl -pi -e 's/^namespace App\\Http;$/namespace App\\Pagination;/' \
  src/Pagination/EntryCursor.php src/Pagination/RecommendationCursor.php
perl -pi -e 's/^namespace App\\Http\\Exception;$/namespace App\\Pagination\\Exception;/' \
  src/Pagination/Exception/MalformedCursorException.php
perl -pi -e 's/^use App\\Http\\Exception\\MalformedCursorException;$/use App\\Pagination\\Exception\\MalformedCursorException;/' \
  src/Pagination/EntryCursor.php src/Pagination/RecommendationCursor.php
```
`src/Pagination/EntryCursor.php`, before (lines 5-8):
```php
namespace App\Http;

use App\Exception\ValidationException;
use App\Http\Exception\MalformedCursorException;
```
after:
```php
namespace App\Pagination;

use App\Exception\ValidationException;
use App\Pagination\Exception\MalformedCursorException;
```
`src/Pagination/RecommendationCursor.php`, before (lines 5-7):
```php
namespace App\Http;

use App\Http\Exception\MalformedCursorException;
```
after:
```php
namespace App\Pagination;

use App\Pagination\Exception\MalformedCursorException;
```
`src/Pagination/Exception/MalformedCursorException.php`, before (line 5):
```php
namespace App\Http\Exception;
```
after:
```php
namespace App\Pagination\Exception;
```

- [ ] **Step 4: Run the cursor tests to see them pass.**

Run: `php bin/phpunit tests/Pagination`
Expected: PASS.

- [ ] **Step 5: Repoint every importer.** Each file has exactly the `use` line(s) named in **Files**; the replacement keeps the line where it is.

Run:
```bash
perl -pi -e 's/^use App\\Http\\EntryCursor;$/use App\\Pagination\\EntryCursor;/; s/^use App\\Http\\RecommendationCursor;$/use App\\Pagination\\RecommendationCursor;/; s/^use App\\Http\\Exception\\MalformedCursorException;$/use App\\Pagination\\Exception\\MalformedCursorException;/' \
  src/Controller/Api/EntryController.php src/Controller/Api/SavedSearchEntriesController.php \
  src/Repository/AbstractEntryProjectionRepository.php src/Repository/EntryQuery.php src/Repository/EntrySearchQuery.php \
  src/Repository/RecommendationItemRepository.php src/Repository/SavedSearchListQuery.php \
  src/Service/Recommendation/RecommendationFeedPager.php src/Service/Search/EntrySearchRequestFactory.php \
  src/Service/Search/Index/IndexSearch.php \
  tests/Http/EntryPageTest.php tests/Http/SavedSearchPageTest.php tests/Http/SearchPageTest.php \
  tests/Repository/EntryListTest.php tests/Repository/EntrySearchTest.php tests/Repository/RecommendationFeedTest.php \
  tests/Repository/SavedSearchMembershipReadsTest.php tests/Service/Search/EntrySearchRequestFactoryTest.php \
  tests/Service/Search/Index/MeilisearchIndexTest.php tests/Service/Search/IndexedEntrySearchTest.php
git grep -c '^use App\\Pagination\\' -- src tests | wc -l
```
Expected count: `24` files (the 20 listed here plus the two moved tests and the two moved cursors).

`src/Service/Recommendation/RecommendationFeedPager.php`, before (lines 7-8):
```php
use App\Http\Exception\MalformedCursorException;
use App\Http\RecommendationCursor;
```
after:
```php
use App\Pagination\Exception\MalformedCursorException;
use App\Pagination\RecommendationCursor;
```
`src/Repository/RecommendationItemRepository.php`, before (line 12):
```php
use App\Http\RecommendationCursor;
```
after:
```php
use App\Pagination\RecommendationCursor;
```
`tests/Repository/RecommendationFeedTest.php`, before (line 14):
```php
use App\Http\RecommendationCursor;
```
after:
```php
use App\Pagination\RecommendationCursor;
```
Each of the other seventeen files (`EntryController.php:15`, `SavedSearchEntriesController.php:11` and the rest of the **Files** list), before:
```php
use App\Http\EntryCursor;
```
after:
```php
use App\Pagination\EntryCursor;
```

- [ ] **Step 6: Give `EntryPage` the import it needs now that the cursor left its namespace.**

`src/Http/EntryPage.php`, before (lines 5-9):
```php
namespace App\Http;

use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
```
after:
```php
namespace App\Http;

use App\Pagination\EntryCursor;
use App\Repository\EntryListRow;
use App\Repository\EntryListSort;
use App\Repository\EntryQuery;
```

- [ ] **Step 7: Confirm no reference to the old names is left.**

Run: `grep -rnE 'App\\Http\\(EntryCursor|RecommendationCursor|Exception\\MalformedCursorException)' src tests config; ls src/Http/Exception 2>/dev/null`
Expected: no output from either (the moved exception was the directory's only file).

- [ ] **Step 8: Run the affected tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Pagination tests/Http tests/Repository tests/Service/Search tests/Service/Recommendation tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntrySearchControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php && composer check && composer md`
Expected: all green. `EntryControllerTest::testForYouViewWithAMalformedCursorDegradesToTheFirstPageInsteadOfErroring` and `EntrySearchControllerTest::testMalformedCursorIsAValidationError` pin both leniency rules.

- [ ] **Step 9: Commit.**

```bash
git add -A src/Pagination src/Http tests/Pagination tests/Http src/Controller/Api/EntryController.php \
  src/Controller/Api/SavedSearchEntriesController.php src/Repository src/Service/Recommendation/RecommendationFeedPager.php \
  src/Service/Search tests/Repository tests/Service/Search
git commit -m "refactor(#1158): pagination cursors live in App\Pagination, not the HTTP layer"
```

---

### Task 3: OAuth HTTP helpers move to `App\Http\OAuth`

**Files:**
- Move: `src/Service/OAuth/FlowCookie.php` → `src/Http/OAuth/FlowCookie.php`
- Move: `src/Service/OAuth/OAuthRedirectFactory.php` → `src/Http/OAuth/OAuthRedirectFactory.php`
- Move: `src/Service/OAuth/CallbackParameters.php` → `src/Http/OAuth/CallbackParameters.php`
- Move: `tests/Service/OAuth/FlowCookieTest.php` → `tests/Http/OAuth/FlowCookieTest.php`
- Modify: `src/Controller/Api/OAuthController.php:7-17`

**Interfaces:**
- Produces: `App\Http\OAuth\FlowCookie` (`NAME`, `issue(string): Cookie`, `clearFrom(Response): Response`), `App\Http\OAuth\OAuthRedirectFactory` (`success(string)`, `failure(string)`), `App\Http\OAuth\CallbackParameters::read(Request, string): ?string`. Members unchanged.
- Consumes: `App\Service\OAuth\OAuthStateStore::LIFETIME_SECONDS`, `App\Service\OAuth\LoginCodeStore::LIFETIME_SECONDS` (now imported, formerly same-namespace).

- [ ] **Step 1: Move the test first, so it fails.**

Run:
```bash
mkdir -p tests/Http/OAuth
git mv tests/Service/OAuth/FlowCookieTest.php tests/Http/OAuth/FlowCookieTest.php
```
`tests/Http/OAuth/FlowCookieTest.php`, before (lines 5-7):
```php
namespace App\Tests\Service\OAuth;

use App\Service\OAuth\FlowCookie;
```
after:
```php
namespace App\Tests\Http\OAuth;

use App\Http\OAuth\FlowCookie;
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/OAuth/FlowCookieTest.php`
Expected: FAIL, `Class "App\Http\OAuth\FlowCookie" not found`.

- [ ] **Step 3: Move the three classes.**

Run:
```bash
mkdir -p src/Http/OAuth
git mv src/Service/OAuth/FlowCookie.php src/Http/OAuth/FlowCookie.php
git mv src/Service/OAuth/OAuthRedirectFactory.php src/Http/OAuth/OAuthRedirectFactory.php
git mv src/Service/OAuth/CallbackParameters.php src/Http/OAuth/CallbackParameters.php
```
`src/Http/OAuth/FlowCookie.php`, before (lines 5-9):
```php
namespace App\Service\OAuth;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
```
after:
```php
namespace App\Http\OAuth;

use App\Service\OAuth\LoginCodeStore;
use App\Service\OAuth\OAuthStateStore;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
```
`src/Http/OAuth/OAuthRedirectFactory.php`, before (line 5):
```php
namespace App\Service\OAuth;
```
after:
```php
namespace App\Http\OAuth;
```
`src/Http/OAuth/CallbackParameters.php`, before (line 5):
```php
namespace App\Service\OAuth;
```
after:
```php
namespace App\Http\OAuth;
```

- [ ] **Step 4: Repoint the controller.**

`src/Controller/Api/OAuthController.php`, before (lines 7-17):
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
```
after:
```php
use App\Dto\OAuth\OAuthCallbackAttempt;
use App\Dto\OAuth\OAuthExchangeRequest;
use App\Http\OAuth\CallbackParameters;
use App\Http\OAuth\FlowCookie;
use App\Http\OAuth\OAuthRedirectFactory;
use App\Service\OAuth\Exception\OAuthCallbackRefusedException;
use App\Service\OAuth\OAuthCallback;
use App\Service\OAuth\OAuthProviderRegistry;
use App\Service\OAuth\OAuthSignIn;
use App\Service\OAuth\OAuthStateStore;
use App\Service\RateLimit\RateLimitGuard;
```
`OAuthController`'s `FLOW_COOKIE = FlowCookie::NAME` (line 64), its constructor's `FlowCookie` and `OAuthRedirectFactory` (lines 79-80) and the three `CallbackParameters::read()` calls in `callback()` (lines 150-152) resolve through these imports and need no edit.

- [ ] **Step 5: Confirm nothing references the old names.**

Run: `grep -rnE 'Service\\OAuth\\(FlowCookie|OAuthRedirectFactory|CallbackParameters)' src tests config`
Expected: no output.

- [ ] **Step 6: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/OAuth tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth && composer check && composer md`
Expected: all green. `OAuthFlowTest` is the contract: the cookie attributes, the clears on every failure exit and the redirects.

- [ ] **Step 7: Commit.**

```bash
git add -A src/Http/OAuth src/Service/OAuth tests/Http/OAuth tests/Service/OAuth src/Controller/Api/OAuthController.php
git commit -m "refactor(#1158): the OAuth flow cookie, redirects and callback reader live in App\Http"
```

---

### Task 4: `MaintenanceTokenGuard`, `BackupDownloadResponseFactory`, `EntrySearchRequestFactory` move to `App\Http`

**Files:**
- Move: `src/Service/Maintenance/MaintenanceTokenGuard.php` → `src/Http/MaintenanceTokenGuard.php`
- Move: `src/Service/Backup/BackupDownloadResponseFactory.php` → `src/Http/BackupDownloadResponseFactory.php`
- Move: `src/Service/Search/EntrySearchRequestFactory.php` → `src/Http/EntrySearchRequestFactory.php`
- Move: `tests/Service/Maintenance/MaintenanceTokenGuardTest.php` → `tests/Http/MaintenanceTokenGuardTest.php`
- Move: `tests/Service/Backup/BackupDownloadResponseFactoryTest.php` → `tests/Http/BackupDownloadResponseFactoryTest.php`
- Move: `tests/Service/Search/EntrySearchRequestFactoryTest.php` → `tests/Http/EntrySearchRequestFactoryTest.php`
- Modify: `src/Controller/MaintenanceController.php:7-8`, `src/Controller/Api/AccountBackupController.php:8-13`, `src/Controller/Api/EntrySearchController.php:9-13`

**Interfaces:**
- Produces: `App\Http\MaintenanceTokenGuard` (`isAuthorized(Request): bool`, `rejectionResponse(Request): ?JsonResponse`), `App\Http\BackupDownloadResponseFactory::stream(string, \Generator): StreamedResponse`, `App\Http\EntrySearchRequestFactory::fromRequest(Request, User): EntrySearchQuery`. Members unchanged.
- Consumes: `App\Pagination\EntryCursor` (Task 2), `App\Service\Search\SearchTerms`, `App\Service\Backup\{BackupFilename, BackupPart}` (formerly same-namespace, now imported).

- [ ] **Step 1: Move the three tests first, so they fail.**

Run:
```bash
git mv tests/Service/Maintenance/MaintenanceTokenGuardTest.php tests/Http/MaintenanceTokenGuardTest.php
git mv tests/Service/Backup/BackupDownloadResponseFactoryTest.php tests/Http/BackupDownloadResponseFactoryTest.php
git mv tests/Service/Search/EntrySearchRequestFactoryTest.php tests/Http/EntrySearchRequestFactoryTest.php
```
`tests/Http/MaintenanceTokenGuardTest.php`, before (lines 5-7):
```php
namespace App\Tests\Service\Maintenance;

use App\Service\Maintenance\MaintenanceTokenGuard;
```
after:
```php
namespace App\Tests\Http;

use App\Http\MaintenanceTokenGuard;
```
`tests/Http/BackupDownloadResponseFactoryTest.php`, before (lines 5-8):
```php
namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupDownloadResponseFactory;
use App\Service\Backup\BackupPart;
```
after:
```php
namespace App\Tests\Http;

use App\Http\BackupDownloadResponseFactory;
use App\Service\Backup\BackupPart;
```
`tests/Http/EntrySearchRequestFactoryTest.php`, before (lines 5-14, as Task 2 left them):
```php
namespace App\Tests\Service\Search;

use App\Entity\User;
use App\Enum\ListOrder;
use App\Exception\ValidationException;
use App\Pagination\EntryCursor;
use App\Repository\EntryQuery;
use App\Service\Search\EntrySearchRequestFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
```
after:
```php
namespace App\Tests\Http;

use App\Entity\User;
use App\Enum\ListOrder;
use App\Exception\ValidationException;
use App\Http\EntrySearchRequestFactory;
use App\Pagination\EntryCursor;
use App\Repository\EntryQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
```

- [ ] **Step 2: Run them to see them fail.**

Run: `php bin/phpunit tests/Http/MaintenanceTokenGuardTest.php tests/Http/BackupDownloadResponseFactoryTest.php tests/Http/EntrySearchRequestFactoryTest.php`
Expected: FAIL, class not found for each of the three.

- [ ] **Step 3: Move the three classes.**

Run:
```bash
git mv src/Service/Maintenance/MaintenanceTokenGuard.php src/Http/MaintenanceTokenGuard.php
git mv src/Service/Backup/BackupDownloadResponseFactory.php src/Http/BackupDownloadResponseFactory.php
git mv src/Service/Search/EntrySearchRequestFactory.php src/Http/EntrySearchRequestFactory.php
```
`src/Http/MaintenanceTokenGuard.php`, before (line 5):
```php
namespace App\Service\Maintenance;
```
after:
```php
namespace App\Http;
```
`src/Http/BackupDownloadResponseFactory.php`, before (lines 5-9):
```php
namespace App\Service\Backup;

use App\Service\Version\ReleaseVersionReader;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
```
after:
```php
namespace App\Http;

use App\Service\Backup\BackupFilename;
use App\Service\Backup\BackupPart;
use App\Service\Version\ReleaseVersionReader;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
```
`src/Http/EntrySearchRequestFactory.php`, before (lines 5-13, as Task 2 left them):
```php
namespace App\Service\Search;

use App\Entity\User;
use App\Enum\ListOrder;
use App\Exception\ValidationException;
use App\Pagination\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\EntrySearchQuery;
use Symfony\Component\HttpFoundation\Request;
```
after:
```php
namespace App\Http;

use App\Entity\User;
use App\Enum\ListOrder;
use App\Exception\ValidationException;
use App\Pagination\EntryCursor;
use App\Repository\EntryQuery;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;
use Symfony\Component\HttpFoundation\Request;
```

- [ ] **Step 4: Repoint the three controllers.**

`src/Controller/MaintenanceController.php`, before (lines 7-8):
```php
use App\Service\Maintenance\MaintenanceTick;
use App\Service\Maintenance\MaintenanceTokenGuard;
```
after:
```php
use App\Http\MaintenanceTokenGuard;
use App\Service\Maintenance\MaintenanceTick;
```
`src/Controller/Api/AccountBackupController.php`, before (lines 8-13):
```php
use App\Http\RestorePreviewJson;
use App\Http\RestoreResultJson;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\BackupDownloadResponseFactory;
use App\Service\Backup\EntryPartRestorer;
```
after:
```php
use App\Http\BackupDownloadResponseFactory;
use App\Http\RestorePreviewJson;
use App\Http\RestoreResultJson;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\AccountRestorer;
use App\Service\Backup\EntryPartRestorer;
```
`src/Controller/Api/EntrySearchController.php`, before (lines 9-13):
```php
use App\Http\SearchPage;
use App\Repository\EntryListRowEnricher;
use App\Service\Reader\SearchMarkReadService;
use App\Service\Search\EntrySearchInterface;
use App\Service\Search\EntrySearchRequestFactory;
```
after:
```php
use App\Http\EntrySearchRequestFactory;
use App\Http\SearchPage;
use App\Repository\EntryListRowEnricher;
use App\Service\Reader\SearchMarkReadService;
use App\Service\Search\EntrySearchInterface;
```

- [ ] **Step 5: Confirm nothing references the old names.**

Run: `grep -rnE 'Service\\(Maintenance\\MaintenanceTokenGuard|Backup\\BackupDownloadResponseFactory|Search\\EntrySearchRequestFactory)' src tests config`
Expected: no output.

- [ ] **Step 6: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http tests/Controller/MaintenanceControllerTest.php tests/Controller/Api/AccountBackupControllerTest.php tests/Controller/Api/EntrySearchControllerTest.php tests/Controller/Api/EntrySearchMarkReadTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add -A src/Http src/Service/Maintenance src/Service/Backup src/Service/Search tests/Http tests/Service/Maintenance \
  tests/Service/Backup tests/Service/Search src/Controller/MaintenanceController.php \
  src/Controller/Api/AccountBackupController.php src/Controller/Api/EntrySearchController.php
git commit -m "refactor(#1158): request and response helpers leave App\Service for App\Http"
```

---

### Task 5: `RateLimitGuard::enforceForClient()` takes the client IP

**Files:**
- Modify: `src/Service/RateLimit/RateLimitGuard.php:10, :38-54`
- Modify: `tests/Service/RateLimit/RateLimitGuardTest.php:12, :28, :38, :52, :74-78`
- Modify (one line each): `src/Controller/Api/AuthController.php:71, :107`, `src/Controller/Api/ClientErrorController.php:33`, `src/Controller/Api/OAuthController.php:184`, `src/Controller/Api/PasskeyController.php:99`, `src/Controller/Api/SetupController.php:49`

**Interfaces:**
- Produces: `RateLimitGuard::enforceForClient(RateLimiterFactoryInterface $limiter, ?string $clientIp): void`. `enforceForUser()` is unchanged.

- [ ] **Step 1: Write the failing test change.** The guard now receives the IP the controller read, so the test passes it directly.

`tests/Service/RateLimit/RateLimitGuardTest.php`, before (line 12):
```php
use Symfony\Component\HttpFoundation\Request;
```
after: the line is deleted.

Before (lines 28, 38 and 52, the same call three times):
```php
        $this->guard()->enforceForClient($factory, $this->requestFrom('203.0.113.7'));
```
```php
            $this->guard()->enforceForClient($factory, $this->requestFrom('203.0.113.7'));
```
```php
            $this->guard()->enforceForClient($factory, $this->requestFrom('203.0.113.7'));
```
after (same three lines):
```php
        $this->guard()->enforceForClient($factory, '203.0.113.7');
```
```php
            $this->guard()->enforceForClient($factory, '203.0.113.7');
```
```php
            $this->guard()->enforceForClient($factory, '203.0.113.7');
```
Before (lines 74-78, the helper, now unused):
```php

    private function requestFrom(string $clientIp): Request
    {
        return Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => $clientIp]);
    }
```
after: deleted.

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Service/RateLimit/RateLimitGuardTest.php`
Expected: FAIL with a `TypeError`: argument #2 (`$request`) must be of type `Request`, string given.

- [ ] **Step 3: Change the guard.**

`src/Service/RateLimit/RateLimitGuard.php`, before (line 10):
```php
use Symfony\Component\HttpFoundation\Request;
```
after: the line is deleted.

Before (lines 38-54):
```php
    /**
     * Caps an anonymous endpoint per client IP.
     *
     * getClientIp() returns REMOTE_ADDR unless the request came from a trusted
     * proxy, and nothing configures trusted_proxies yet — the safe default,
     * since a spoofed X-Forwarded-For cannot buy a fresh budget. But the day
     * this app sits behind a CDN or reverse proxy, every request wears the
     * proxy's address and all callers share one bucket, unless
     * framework.trusted_proxies is set at the same time.
     *
     * A null IP (possible for non-HTTP-ish transports) collapses every such
     * caller into one shared bucket — fails closed, the right direction.
     */
    public function enforceForClient(RateLimiterFactoryInterface $limiter, Request $request): void
    {
        $this->enforce($limiter->create($request->getClientIp()));
    }
```
after:
```php
    /**
     * Caps an anonymous endpoint per client IP, as Request::getClientIp() reports it.
     *
     * getClientIp() returns REMOTE_ADDR unless the request came from a trusted
     * proxy, and nothing configures trusted_proxies yet — the safe default,
     * since a spoofed X-Forwarded-For cannot buy a fresh budget. But the day
     * this app sits behind a CDN or reverse proxy, every request wears the
     * proxy's address and all callers share one bucket, unless
     * framework.trusted_proxies is set at the same time.
     *
     * A null IP (possible for non-HTTP-ish transports) collapses every such
     * caller into one shared bucket — fails closed, the right direction.
     */
    public function enforceForClient(RateLimiterFactoryInterface $limiter, ?string $clientIp): void
    {
        $this->enforce($limiter->create($clientIp));
    }
```

- [ ] **Step 4: Run it to see it pass.**

Run: `php bin/phpunit tests/Service/RateLimit/RateLimitGuardTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Update the six callers.** Each controller keeps its `Request` parameter and now reads the IP itself.

`src/Controller/Api/AuthController.php`, before (line 71):
```php
        $this->rateLimitGuard->enforceForClient($this->registrationLimiter, $httpRequest);
```
after:
```php
        $this->rateLimitGuard->enforceForClient($this->registrationLimiter, $httpRequest->getClientIp());
```
Before (line 107):
```php
        $this->rateLimitGuard->enforceForClient($this->passwordResetRequestLimiter, $httpRequest);
```
after:
```php
        $this->rateLimitGuard->enforceForClient($this->passwordResetRequestLimiter, $httpRequest->getClientIp());
```
`src/Controller/Api/ClientErrorController.php`, before (line 33):
```php
        $this->rateLimitGuard->enforceForClient($this->clientErrorsLimiter, $httpRequest);
```
after:
```php
        $this->rateLimitGuard->enforceForClient($this->clientErrorsLimiter, $httpRequest->getClientIp());
```
`src/Controller/Api/OAuthController.php`, before (line 184):
```php
        $this->rateLimitGuard->enforceForClient($this->oauthStartLimiter, $request);
```
after:
```php
        $this->rateLimitGuard->enforceForClient($this->oauthStartLimiter, $request->getClientIp());
```
`src/Controller/Api/PasskeyController.php`, before (line 99):
```php
        $this->rateLimitGuard->enforceForClient($this->passkeyChallengeLimiter, $request);
```
after:
```php
        $this->rateLimitGuard->enforceForClient($this->passkeyChallengeLimiter, $request->getClientIp());
```
`src/Controller/Api/SetupController.php`, before (line 49):
```php
        $this->rateLimitGuard->enforceForClient($this->setupLimiter, $httpRequest);
```
after:
```php
        $this->rateLimitGuard->enforceForClient($this->setupLimiter, $httpRequest->getClientIp());
```

- [ ] **Step 6: Confirm every caller passes the IP.**

Run: `grep -rn 'enforceForClient(' src | grep -v 'getClientIp()' | grep -v 'function enforceForClient'`
Expected: no output.

- [ ] **Step 7: Run the tests and the gates.**

Run: `php bin/phpunit tests/Service/RateLimit tests/Controller/Api && composer check && composer md`
Expected: all green. The 429 contract tests (registration, password reset, OAuth start, passkey options, setup, client errors) must pass unchanged.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/RateLimit/RateLimitGuard.php tests/Service/RateLimit/RateLimitGuardTest.php \
  src/Controller/Api/AuthController.php src/Controller/Api/ClientErrorController.php src/Controller/Api/OAuthController.php \
  src/Controller/Api/PasskeyController.php src/Controller/Api/SetupController.php
git commit -m "refactor(#1158): the client rate limit takes the IP, not the Symfony request"
```

---

### Task 6: `ServingHost` becomes an interface; `App\Http\RequestServingHost` implements it

**Files:**
- Modify (whole file): `src/Service/Settings/ServingHost.php`
- Create: `src/Http/RequestServingHost.php`
- Modify: `config/services.yaml:125` (one alias line after it)
- Modify: `tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php:7-15, :86, :104`
- Modify: `tests/Service/Settings/RelyingPartyChangeTest.php:7-17, :106`

**Interfaces:**
- Produces: `interface App\Service\Settings\ServingHost { public function get(): string; }` — same FQCN and method, so `ConfiguredPasskeyRelyingParty` and `RelyingPartyChange` (same namespace, no import) change nothing.
- Produces: `App\Http\RequestServingHost implements ServingHost`, constructor `(RequestStack $requests, PublicBaseUrl $publicBaseUrl)`.

- [ ] **Step 1: Point the two tests at the implementation, so they fail.**

`tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php`, before (lines 7-15):
```php
use App\Service\Settings\ConfiguredPasskeyRelyingParty;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Service\Settings\ServingHost;
use App\Tests\Support\FixedPublicBaseUrl;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
```
after:
```php
use App\Http\RequestServingHost;
use App\Service\Settings\ConfiguredPasskeyRelyingParty;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\InstanceSettings;
use App\Service\Settings\InstanceSettingsUpdate;
use App\Tests\Support\FixedPublicBaseUrl;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
```
Before (line 86):
```php
            new ServingHost(new RequestStack(), new FixedPublicBaseUrl(self::EMAIL_LINK_URL)),
```
after:
```php
            new RequestServingHost(new RequestStack(), new FixedPublicBaseUrl(self::EMAIL_LINK_URL)),
```
Before (line 104):
```php
            new ServingHost($requests, new FixedPublicBaseUrl($emailLinkUrl)),
```
after:
```php
            new RequestServingHost($requests, new FixedPublicBaseUrl($emailLinkUrl)),
```
`tests/Service/Settings/RelyingPartyChangeTest.php`, before (lines 7-17):
```php
use App\Dto\Admin\InstanceSettingsRequest;
use App\Exception\ValidationException;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\RelyingPartyChange;
use App\Service\Settings\RelyingPartyIdRule;
use App\Service\Settings\ServingHost;
use Symfony\Component\HttpFoundation\RequestStack;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
```
after:
```php
use App\Dto\Admin\InstanceSettingsRequest;
use App\Exception\ValidationException;
use App\Http\RequestServingHost;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\EffectivePasskeyRelyingPartyId;
use App\Service\Settings\PasskeyRelyingParty;
use App\Service\Settings\RelyingPartyChange;
use App\Service\Settings\RelyingPartyIdRule;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
```
Before (line 106):
```php
            new ServingHost(new RequestStack(), new FixedPublicBaseUrl($publicBaseUrl)),
```
after:
```php
            new RequestServingHost(new RequestStack(), new FixedPublicBaseUrl($publicBaseUrl)),
```

- [ ] **Step 2: Run them to see them fail.**

Run: `php bin/phpunit tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php tests/Service/Settings/RelyingPartyChangeTest.php`
Expected: FAIL, `Class "App\Http\RequestServingHost" not found`.

- [ ] **Step 3: Split the class.** Replace the whole of `src/Service/Settings/ServingHost.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Settings;

interface ServingHost
{
    public function get(): string;
}
```
Create `src/Http/RequestServingHost.php` (the old class body, verbatim):
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Settings\PublicBaseUrl;
use App\Service\Settings\ServingHost;
use Symfony\Component\HttpFoundation\RequestStack;

/** A proxy that rewrites Host rather than passing it through needs
 *  SYMFONY_TRUSTED_PROXIES set for this to see the real one. */
final readonly class RequestServingHost implements ServingHost
{
    public function __construct(
        private RequestStack $requests,
        private PublicBaseUrl $publicBaseUrl,
    ) {
    }

    public function get(): string
    {
        $request = $this->requests->getMainRequest();
        if (null !== $request) {
            return $request->getHost();
        }

        $host = parse_url($this->publicBaseUrl->get(), PHP_URL_HOST);

        return \is_string($host) ? $host : '';
    }
}
```

- [ ] **Step 4: Alias the interface explicitly**, as `services.yaml` does for its other single-implementation interfaces.

`config/services.yaml`, before (line 125):
```yaml
    App\Service\Reader\ArticleExtractorInterface: '@App\Service\Reader\ArticleExtractor'
```
after:
```yaml
    App\Service\Reader\ArticleExtractorInterface: '@App\Service\Reader\ArticleExtractor'
    App\Service\Settings\ServingHost: '@App\Http\RequestServingHost'
```

- [ ] **Step 5: Run the tests to see them pass, then the passkey and admin-settings contract.**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Settings tests/Controller/Admin/AdminSettingsControllerTest.php tests/Service/Passkey`
Expected: all green, including `testPasskeyRpIdEffectiveReflectsTheStoredOverrideOrTheServingHost`.

- [ ] **Step 6: Run the gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Settings/ServingHost.php src/Http/RequestServingHost.php config/services.yaml \
  tests/Service/Settings/ConfiguredPasskeyRelyingPartyTest.php tests/Service/Settings/RelyingPartyChangeTest.php
git commit -m "refactor(#1158): ServingHost is an interface; the RequestStack read lives in App\Http"
```

---

### Task 7: `HtmlPageFetcher` asks `StatusReasonPhrases` instead of `Response`

**Files:**
- Create: `src/Service/Reader/StatusReasonPhrases.php`
- Create: `src/Http/SymfonyStatusReasonPhrases.php`
- Create: `tests/Http/SymfonyStatusReasonPhrasesTest.php`
- Modify: `src/Service/Reader/HtmlPageFetcher.php:14, :34-40, :82, :134-141`
- Modify: `config/services.yaml` (one alias line after Task 6's)
- Modify: `tests/Service/Reader/HtmlPageFetcherTest.php:7, :43-51`, and one new test after line 127
- Modify: `tests/Service/Reader/ArticleExtractorTest.php:9, :108, :493`

**Interfaces:**
- Produces: `interface App\Service\Reader\StatusReasonPhrases { public function of(int $status): string; }` — the standard phrase, or `''` when the code has none.
- Produces: `App\Http\SymfonyStatusReasonPhrases implements StatusReasonPhrases`, no constructor arguments.
- Changes: `HtmlPageFetcher::__construct(RedirectFollower, MetaRefreshTarget, LandingChallenge, string $userAgent, StatusReasonPhrases $reasonPhrases)`.

- [ ] **Step 1: Write the failing tests.** `tests/Http/SymfonyStatusReasonPhrasesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\SymfonyStatusReasonPhrases;
use PHPUnit\Framework\TestCase;

final class SymfonyStatusReasonPhrasesTest extends TestCase
{
    public function testAKnownStatusHasItsStandardPhrase(): void
    {
        self::assertSame('Forbidden', new SymfonyStatusReasonPhrases()->of(403));
    }

    public function testAStatusWithoutAStandardPhraseHasNone(): void
    {
        self::assertSame('', new SymfonyStatusReasonPhrases()->of(499));
    }
}
```
And in `tests/Service/Reader/HtmlPageFetcherTest.php`, insert after line 127 (the end of `testNon2xxWithAnEmptyBodyReportsOnlyTheStatus`):
```php

    public function testNon2xxWithoutAStandardPhraseReportsTheBareCode(): void
    {
        $fetcher = $this->fetcher([new MockResponse("   \n  ", ['http_code' => 499])]);

        try {
            $fetcher->fetch('https://example.com/closed');
            self::fail('expected a PageFetchException');
        } catch (PageFetchException $failure) {
            self::assertSame('HTTP 499', $failure->getMessage());
        }
    }
```

- [ ] **Step 2: Run them to see the new-class test fail.**

Run: `php bin/phpunit tests/Http/SymfonyStatusReasonPhrasesTest.php tests/Service/Reader/HtmlPageFetcherTest.php`
Expected: `SymfonyStatusReasonPhrasesTest` FAILS with class not found. The new `HtmlPageFetcherTest` case already passes on the old code; it pins the bare-code branch the refactor moves.

- [ ] **Step 3: Create the interface and its implementation.**

`src/Service/Reader/StatusReasonPhrases.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

interface StatusReasonPhrases
{
    /** The standard reason phrase for an HTTP status code, or '' for a code without one. */
    public function of(int $status): string;
}
```
`src/Http/SymfonyStatusReasonPhrases.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reader\StatusReasonPhrases;
use Symfony\Component\HttpFoundation\Response;

final readonly class SymfonyStatusReasonPhrases implements StatusReasonPhrases
{
    public function of(int $status): string
    {
        return Response::$statusTexts[$status] ?? '';
    }
}
```

- [ ] **Step 4: Inject it into `HtmlPageFetcher`.**

`src/Service/Reader/HtmlPageFetcher.php`, before (line 14):
```php
use Symfony\Component\HttpFoundation\Response;
```
after: the line is deleted.

Before (lines 34-40):
```php
    public function __construct(
        private RedirectFollower $redirects,
        private MetaRefreshTarget $metaRefresh,
        private LandingChallenge $challenge,
        private string $userAgent,
    ) {
    }
```
after:
```php
    public function __construct(
        private RedirectFollower $redirects,
        private MetaRefreshTarget $metaRefresh,
        private LandingChallenge $challenge,
        private string $userAgent,
        private StatusReasonPhrases $reasonPhrases,
    ) {
    }
```
Before (line 82):
```php
            $status = self::describeStatus($landed->status);
```
after:
```php
            $status = $this->describeStatus($landed->status);
```
Before (lines 134-141):
```php
    /** The status line as a reader would read it — the code with its standard
     *  reason phrase ("HTTP 403 Forbidden"), or the bare code for an unknown one. */
    private static function describeStatus(int $status): string
    {
        $phrase = Response::$statusTexts[$status] ?? '';

        return $phrase === '' ? sprintf('HTTP %d', $status) : sprintf('HTTP %d %s', $status, $phrase);
    }
```
after:
```php
    /** The status line as a reader would read it — the code with its standard
     *  reason phrase ("HTTP 403 Forbidden"), or the bare code for an unknown one. */
    private function describeStatus(int $status): string
    {
        $phrase = $this->reasonPhrases->of($status);

        return $phrase === '' ? sprintf('HTTP %d', $status) : sprintf('HTTP %d %s', $status, $phrase);
    }
```

- [ ] **Step 5: Alias the interface.**

`config/services.yaml`, before (Task 6's line):
```yaml
    App\Service\Settings\ServingHost: '@App\Http\RequestServingHost'
```
after:
```yaml
    App\Service\Settings\ServingHost: '@App\Http\RequestServingHost'
    App\Service\Reader\StatusReasonPhrases: '@App\Http\SymfonyStatusReasonPhrases'
```

- [ ] **Step 6: Give the three test constructions the new argument.**

`tests/Service/Reader/HtmlPageFetcherTest.php`, before (line 7):
```php
use App\Service\Fetch\DnsResolverInterface;
```
after:
```php
use App\Http\SymfonyStatusReasonPhrases;
use App\Service\Fetch\DnsResolverInterface;
```
Before (lines 43-51):
```php
        return new HtmlPageFetcher(
            new RedirectFollower(
                new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
                new UrlGuard($resolver, new IpValidator()),
            ),
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
        );
```
after:
```php
        return new HtmlPageFetcher(
            new RedirectFollower(
                new FailoverRequestSender(new MockHttpClient($responses), $this->noProxyResolver()),
                new UrlGuard($resolver, new IpValidator()),
            ),
            new MetaRefreshTarget(),
            new LandingChallenge(),
            'TestAgent/1.0',
            new SymfonyStatusReasonPhrases(),
        );
```
`tests/Service/Reader/ArticleExtractorTest.php`, before (line 9):
```php
use App\Entity\Feed;
```
after:
```php
use App\Entity\Feed;
use App\Http\SymfonyStatusReasonPhrases;
```
Before (line 108, and the identical line 493):
```php
            new HtmlPageFetcher($redirects, new MetaRefreshTarget(), new LandingChallenge(), 'TestAgent/1.0'),
```
after (both sites):
```php
            new HtmlPageFetcher(
                $redirects,
                new MetaRefreshTarget(),
                new LandingChallenge(),
                'TestAgent/1.0',
                new SymfonyStatusReasonPhrases(),
            ),
```

- [ ] **Step 7: Confirm every construction was updated.**

Run: `grep -rn 'new HtmlPageFetcher(' src tests | wc -l && grep -rn 'SymfonyStatusReasonPhrases()' tests | wc -l`
Expected: `3` and `5` (three constructions; two uses in `SymfonyStatusReasonPhrasesTest`).

- [ ] **Step 8: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/SymfonyStatusReasonPhrasesTest.php tests/Service/Reader tests/Service/Fetch/OutboundUserAgentWiringTest.php tests/Service/Tracing && composer check && composer md`
Expected: all green.

- [ ] **Step 9: Commit.**

```bash
git add src/Service/Reader/StatusReasonPhrases.php src/Http/SymfonyStatusReasonPhrases.php src/Service/Reader/HtmlPageFetcher.php \
  config/services.yaml tests/Http/SymfonyStatusReasonPhrasesTest.php tests/Service/Reader/HtmlPageFetcherTest.php \
  tests/Service/Reader/ArticleExtractorTest.php
git commit -m "refactor(#1158): the reader fetcher asks for reason phrases instead of reading Symfony's Response"
```

---

### Task 8: Rule, step 1: Symfony HTTP forbidden in every domain namespace

**Files:**
- Modify (whole file): `tests/PhpStan/DomainKnowsNoHttpRule.php`
- Modify (whole file): `tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
- Modify (whole file): `tests/PhpStan/data/domain-knows-no-http-fixtures.php`

**Interfaces:**
- Produces: the rule now covers `App\Pagination\` as well as `App\Service\`, `App\Repository\`, `App\Entity\`, `App\Enum\`, `App\Exception\`. Everywhere in those it forbids `Symfony\Component\HttpFoundation\*` (which includes `HttpFoundation\Exception\BadRequestException`), `Symfony\Component\HttpKernel\Exception\*` and `Symfony\Component\Security\Core\Exception\AccessDeniedException`. It forbids `App\Http\*` everywhere except outside exception namespaces in `App\Service`, the carve-out Task 17 deletes.
- Produces: class names inside string literals count, with one leading backslash trimmed.
- Produces: the message `Domain code must not know HTTP: <namespace> references <class>. Return a typed value or throw a typed exception, and let src/Http shape it (#1158).` Identifier unchanged: `simpleFeedReader.domainKnowsNoHttp`.
- `phpstan.dist.neon` needs no change: the constructor still takes only `NodeFinder`.

- [ ] **Step 1: Replace the fixtures.** Line numbers matter: the test asserts them. `tests/PhpStan/data/domain-knows-no-http-fixtures.php`:

```php
<?php

declare(strict_types=1);

// Fixtures for DomainKnowsNoHttpRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use App\Http\RecommendationFeedJson;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class KnowsHttp
    {
        public function fail(): never
        {
            throw new NotFoundHttpException();
        }

        public function statusText(): string
        {
            return Response::$statusTexts[404];
        }

        public function clientIp(Request $request): ?string
        {
            return $request->getClientIp();
        }

        public function mapper(): string
        {
            return RecommendationFeedJson::class;
        }

        public function mapperByName(): string
        {
            return 'App\Http\RecommendationFeedJson';
        }

        public function responseByName(): string
        {
            return '\Symfony\Component\HttpFoundation\Response';
        }
    }
}

namespace App\Service\Fixtures\Exception {
    use App\Http\Problem\ApiProblem;
    use Symfony\Component\HttpFoundation\Exception\BadRequestException;
    use Symfony\Component\Security\Core\Exception\AccessDeniedException;

    final class KnowsItsProblem extends \RuntimeException
    {
        public function problem(): ?ApiProblem
        {
            return null;
        }

        public function badRequest(): BadRequestException
        {
            return new BadRequestException();
        }

        public function denied(): AccessDeniedException
        {
            return new AccessDeniedException();
        }
    }
}

namespace App\Repository\Fixtures {
    final class ThrowsInlineHttp
    {
        public function fail(): never
        {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException();
        }

        public function cursor(): string
        {
            return \App\Http\EntryCursor::class;
        }
    }
}

namespace App\Pagination\Fixtures {
    use Symfony\Component\HttpFoundation\Cookie;

    final class BakesCookies
    {
        public function cookie(): Cookie
        {
            return Cookie::create('name');
        }
    }
}

namespace App\Http\Fixtures {
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    final class HttpLayer
    {
        public function fail(): never
        {
            throw new NotFoundHttpException('App\Http\Anything');
        }

        public function status(): int
        {
            return Response::HTTP_OK;
        }
    }
}

namespace App\Service\Fixtures\Clean {
    final class NamesNoHttp
    {
        public function label(): string
        {
            return 'App\HttpClientSettings is not the HTTP layer';
        }
    }
}
```

Line map the test relies on: 9 `use App\Http\RecommendationFeedJson`, 10 `use …Request`, 11 `use …Response`, 12 `use …NotFoundHttpException`, 18 `throw new NotFoundHttpException`, 23 `Response::$statusTexts`, 26 `Request $request`, 33 `RecommendationFeedJson::class`, 38 the `App\Http\…` string, 43 the `\Symfony\…\Response` string, 49-51 the three exception-namespace `use` lines, 55 `?ApiProblem`, 60 and 62 `BadRequestException`, 65 and 67 `AccessDeniedException`, 77 `BadRequestHttpException`, 82 `\App\Http\EntryCursor::class`, 88, 92 and 94 `Cookie`. Before Step 3, check them: `grep -n 'RecommendationFeedJson\|Request\|Response\|NotFound\|ApiProblem\|BadRequest\|AccessDenied\|EntryCursor\|Cookie' tests/PhpStan/data/domain-knows-no-http-fixtures.php`.

- [ ] **Step 2: Replace the test.** `tests/PhpStan/DomainKnowsNoHttpRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DomainKnowsNoHttpRule> */
final class DomainKnowsNoHttpRuleTest extends RuleTestCase
{
    private const string SERVICE = 'App\Service\Fixtures';
    private const string SERVICE_EXCEPTION = 'App\Service\Fixtures\Exception';
    private const string REPOSITORY = 'App\Repository\Fixtures';
    private const string PAGINATION = 'App\Pagination\Fixtures';
    private const string FOUNDATION = 'Symfony\Component\HttpFoundation\\';
    private const string HTTP_KERNEL = 'Symfony\Component\HttpKernel\Exception\\';
    private const string ACCESS_DENIED = 'Symfony\Component\Security\Core\Exception\AccessDeniedException';
    private const string API_PROBLEM = 'App\Http\Problem\ApiProblem';

    protected function getRule(): Rule
    {
        return new DomainKnowsNoHttpRule(new NodeFinder());
    }

    public function testItReportsSymfonyHttpEverywhereAndTheHttpLayerOutsideServiceMappers(): void
    {
        $this->analyse(
            [__DIR__ . '/data/domain-knows-no-http-fixtures.php'],
            [
                [self::message(self::SERVICE, self::FOUNDATION . 'Request'), 10],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 11],
                [self::message(self::SERVICE, self::HTTP_KERNEL . 'NotFoundHttpException'), 12],
                [self::message(self::SERVICE, self::HTTP_KERNEL . 'NotFoundHttpException'), 18],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 23],
                [self::message(self::SERVICE, self::FOUNDATION . 'Request'), 26],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 43],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 49],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 50],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 51],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 55],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 60],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 62],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 65],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 67],
                [self::message(self::REPOSITORY, self::HTTP_KERNEL . 'BadRequestHttpException'), 77],
                [self::message(self::REPOSITORY, 'App\Http\EntryCursor'), 82],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 88],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 92],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 94],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).',
            $namespaceName,
            $reference,
        );
    }
}
```
Lines 9, 33 and 38 (`App\Http` in a non-exception `App\Service` namespace) are deliberately absent: that is the carve-out. Task 17 adds them.

- [ ] **Step 3: Run it to see it fail.**

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: FAIL. The old rule reports the old message, misses every `Request`, `Cookie`, `BadRequestException`, `AccessDeniedException` and string reference, and ignores `App\Pagination`.

- [ ] **Step 4: Replace the rule.** `tests/PhpStan/DomainKnowsNoHttpRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code returns typed values and throws typed exceptions; src/Http shapes and maps them (#1158).
 * Class names inside strings count too, so a string-built reference cannot slip past.
 *
 * @implements Rule<FileNode>
 */
final readonly class DomainKnowsNoHttpRule implements Rule
{
    private const array DOMAIN_NAMESPACES = [
        'App\\Pagination\\',
        'App\\Service\\',
        'App\\Repository\\',
        'App\\Entity\\',
        'App\\Enum\\',
        'App\\Exception\\',
    ];

    private const array SYMFONY_HTTP_PREFIXES = [
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\Exception\\',
    ];

    private const array SYMFONY_HTTP_CLASSES = [
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
    ];

    private const string HTTP_LAYER = 'App\\Http\\';
    private const string SERVICES = 'App\\Service\\';

    public function __construct(private NodeFinder $finder)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->finder->findInstanceOf($node->getNodes(), Namespace_::class) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!self::isDomain($namespaceName)) {
            return [];
        }

        $errors = [];
        foreach ($this->references($namespace) as [$reference, $line]) {
            if (self::isForbidden($reference, $namespaceName)) {
                $errors[] = self::error($namespaceName, $reference, $line);
            }
        }

        return $errors;
    }

    /** @return list<array{string, int}> every class name mentioned, in code or in a string, with its line */
    private function references(Namespace_ $namespace): array
    {
        $references = [];
        foreach ($this->finder->findInstanceOf($namespace->stmts, Name::class) as $name) {
            $references[] = [$name->toString(), $name->getStartLine()];
        }
        foreach ($this->finder->findInstanceOf($namespace->stmts, String_::class) as $string) {
            $references[] = [ltrim($string->value, '\\'), $string->getStartLine()];
        }

        return $references;
    }

    private static function isDomain(string $namespaceName): bool
    {
        return self::startsWithAny($namespaceName . '\\', self::DOMAIN_NAMESPACES);
    }

    private static function isForbidden(string $reference, string $namespaceName): bool
    {
        if (self::isSymfonyHttp($reference)) {
            return true;
        }

        return str_starts_with($reference, self::HTTP_LAYER) && !self::stillShapesJson($namespaceName);
    }

    private static function isSymfonyHttp(string $reference): bool
    {
        return \in_array($reference, self::SYMFONY_HTTP_CLASSES, true)
            || self::startsWithAny($reference, self::SYMFONY_HTTP_PREFIXES);
    }

    /** Until #1158's second PR, services outside exception namespaces may still call App\Http mappers. */
    private static function stillShapesJson(string $namespaceName): bool
    {
        return str_starts_with($namespaceName . '\\', self::SERVICES)
            && !\in_array('Exception', explode('\\', $namespaceName), true);
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $subject, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function error(string $namespaceName, string $reference, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).',
            $namespaceName,
            $reference,
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($line)
            ->build();
    }
}
```

- [ ] **Step 5: Run the rule test to see it pass.**

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: PASS. If only the order differs from the expected list, the fixture lines moved: recheck Step 1's line map rather than reordering the expectations.

- [ ] **Step 6: Run the rule over the tree.**

Run: `bin/console cache:clear && composer stan`
Expected: green. Any `simpleFeedReader.domainKnowsNoHttp` error is a site Tasks 1-7 missed: fix it in the owning module, never by allow-listing.

- [ ] **Step 7: Break what the rule guards, then restore it by hand.** Add the line `use Symfony\Component\HttpFoundation\Request;` below the `namespace` line of `src/Service/Ai/AiReadiness.php`, run `composer stan`, and confirm exactly one `simpleFeedReader.domainKnowsNoHttp` error naming `App\Service\Ai` and `Symfony\Component\HttpFoundation\Request`. Delete the line again with the editor (not `git checkout --`), then run `git diff --exit-code src/Service/Ai/AiReadiness.php` (expected: exit 0, no output) and `composer stan` (expected: green).

- [ ] **Step 8: Run the gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 9: Commit.**

```bash
git add tests/PhpStan/DomainKnowsNoHttpRule.php tests/PhpStan/DomainKnowsNoHttpRuleTest.php \
  tests/PhpStan/data/domain-knows-no-http-fixtures.php
git commit -m "refactor(#1158): DomainKnowsNoHttpRule forbids Symfony HTTP in all domain code, strings included"
```

- [ ] **Step 10: Finish PR 1.** Follow **Finishing → PR 1** below before starting Task 9.

---

### Task 9: `ForYouFeed` returns `ForYouFeedPage`

PR 2 starts here. Step 0 cuts its branch.

**Files:**
- Move: `src/Service/Recommendation/ForYouFeedResponder.php` → `src/Service/Recommendation/ForYouFeed.php` (whole file given below)
- Move: `src/Http/FeedAnnotationVisibility.php` → `src/Service/Recommendation/FeedAnnotationVisibility.php` (namespace line only)
- Create: `src/Service/Recommendation/ForYouFeedPage.php`
- Modify: `src/Http/RecommendationFeedJson.php:5-29`
- Modify: `src/Controller/Api/EntryController.php:15-18, :27, :45, :81-85`
- Move: `tests/Service/Recommendation/ForYouFeedResponderTest.php` → `tests/Service/Recommendation/ForYouFeedTest.php` (whole file given below)
- Modify: `tests/Http/RecommendationFeedJsonTest.php:9-15` and its five `page(` calls
- Modify: `tests/Service/Tracing/TracedServiceMethodsTest.php:18, :38`

**Interfaces:**
- Produces: `App\Service\Recommendation\ForYouFeed::page(ForYouFeedQuery $query): ForYouFeedPage` (still `#[WithSpan]`).
- Produces: `ForYouFeedPage` with `public array $rows` (`list<RecommendationFeedRow>`), `public ?string $nextCursor`, `public FeedAnnotationVisibility $visibility`.
- Produces: `App\Service\Recommendation\FeedAnnotationVisibility` (moved; `public bool $showExplanation`).
- Produces: `RecommendationFeedJson::page(ForYouFeedPage $page): array` (same output).

- [ ] **Step 0: Cut PR 2's branch.** PR 1 must have merged, and #1158 must still be open (Finishing, PR 1, step 6).

Run:
```bash
git status --short
git fetch origin develop
gh issue view 1158 --json state -q .state
git log --format='%h %s' 2b08b681..origin/develop -- \
  src/Service/Recommendation/ForYouFeedResponder.php src/Service/Recommendation/RecommendationRunStatusPayload.php \
  src/Service/Recommendation/RecommendationRunHistoryView.php src/Service/Recommendation/RecommendationDebugLogView.php \
  src/Service/Reading src/Service/Passkey/PasskeyListing.php src/Service/Mail/MailDeliveryHealth.php \
  src/Service/Grafana/GrafanaSettings.php src/Service/Mail/Settings/MailSettings.php src/Service/Proxy/ProxySettings.php \
  src/Http/RecommendationFeedJson.php src/Http/FeedAnnotationVisibility.php src/Http/RecommendationRunStatusJson.php \
  src/Http/RecommendationRunHistoryJson.php src/Http/RecommendationDebugLogJson.php src/Http/ReadingActivityJson.php \
  src/Http/PasskeyJson.php src/Http/Admin src/Controller tests/Http tests/Service/Recommendation \
  tests/Service/Tracing tests/Service/Mail tests/Service/Grafana tests/Service/Proxy tests/PhpStan ../CLAUDE.md
git switch -c refactor/1158-presentation-out-of-services-2 origin/develop && bin/console cache:warmup
git grep -n '^use App\\Http\\' -- src/Service src/Repository src/Entity src/Enum src/Exception src/Pagination
```
Expected: a clean tree; `OPEN`; a log that lists only PR 1's `refactor(#1158): …` commits and its merge; a new branch; and exactly these 11 `use` lines: `Grafana/GrafanaSettings.php:9`, `Mail/MailDeliveryHealth.php:9`, `Mail/Settings/MailSettings.php:10`, `Passkey/PasskeyListing.php:8`, `Proxy/ProxySettings.php:10`, `Reading/ReadingActivityView.php:8`, `Recommendation/ForYouFeedResponder.php:7-8`, `Recommendation/RecommendationDebugLogView.php:9`, `Recommendation/RecommendationRunHistoryView.php:8`, `Recommendation/RecommendationRunStatusPayload.php:8`, all under `src/Service/`. If another commit shows in the log, compare the files it touched with this plan's before-blocks as Task 0, Step 3 describes. If `GrafanaSettings`, `MailSettings` and `ProxySettings` are missing from the sweep, #1159 landed first: skip Task 16 (R6).

- [ ] **Step 1: Point the mapper test at the new input, so it fails.**

`tests/Http/RecommendationFeedJsonTest.php`, before (lines 9-15):
```php
use App\Http\FeedAnnotationVisibility;
use App\Http\RecommendationFeedJson;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\RecommendationFeedRow;
use PHPUnit\Framework\TestCase;
```
after:
```php
use App\Http\RecommendationFeedJson;
use App\Repository\EntryListRow;
use App\Repository\EntryListRowSubscription;
use App\Repository\EntryListRowViewState;
use App\Repository\RecommendationFeedRow;
use App\Service\Recommendation\FeedAnnotationVisibility;
use App\Service\Recommendation\ForYouFeedPage;
use PHPUnit\Framework\TestCase;
```
Wrap each of the five calls' arguments in a `ForYouFeedPage`:
```bash
perl -0pi -e 's/RecommendationFeedJson::page\(\n(.*?)\n        \);/RecommendationFeedJson::page(new ForYouFeedPage(\n$1\n        ));/gs' tests/Http/RecommendationFeedJsonTest.php
grep -c 'RecommendationFeedJson::page(new ForYouFeedPage($' tests/Http/RecommendationFeedJsonTest.php
```
Expected count: `5`. Each call now reads, for example (lines 21-25):
```php
        $result = RecommendationFeedJson::page(new ForYouFeedPage(
            [$this->row()],
            null,
            new FeedAnnotationVisibility(showExplanation: true),
        ));
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/RecommendationFeedJsonTest.php`
Expected: FAIL, class `App\Service\Recommendation\FeedAnnotationVisibility` (or `ForYouFeedPage`) not found.

- [ ] **Step 3: Move `FeedAnnotationVisibility` and create `ForYouFeedPage`.**

Run: `git mv src/Http/FeedAnnotationVisibility.php src/Service/Recommendation/FeedAnnotationVisibility.php`

`src/Service/Recommendation/FeedAnnotationVisibility.php`, before (line 5):
```php
namespace App\Http;
```
after:
```php
namespace App\Service\Recommendation;
```
Create `src/Service/Recommendation/ForYouFeedPage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationFeedRow;

final readonly class ForYouFeedPage
{
    /** @param list<RecommendationFeedRow> $rows */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
        public FeedAnnotationVisibility $visibility,
    ) {
    }
}
```

- [ ] **Step 4: Make the mapper take the page.**

`src/Http/RecommendationFeedJson.php`, before (lines 5-29):
```php
namespace App\Http;

use App\Repository\RecommendationFeedRow;

final class RecommendationFeedJson
{
    /**
     * A page of the for-you feed. Each entry carries `runId` and
     * `runGeneratedAt` unconditionally — the run-boundary divider is a
     * normal-user feature (#348) — then `recommendationReason` and
     * `recommendationScore` together, iff the reader asked to see why an
     * article was picked (#576; see FeedAnnotationVisibility for why the two
     * travel as one).
     *
     * @param list<RecommendationFeedRow> $rows
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function page(array $rows, ?string $nextCursor, FeedAnnotationVisibility $visibility): array
    {
        return [
            'entries' => self::entries($rows, $visibility),
            'nextCursor' => $nextCursor,
        ];
    }
```
after:
```php
namespace App\Http;

use App\Repository\RecommendationFeedRow;
use App\Service\Recommendation\FeedAnnotationVisibility;
use App\Service\Recommendation\ForYouFeedPage;

final class RecommendationFeedJson
{
    /**
     * A page of the for-you feed. Each entry carries `runId` and
     * `runGeneratedAt` unconditionally — the run-boundary divider is a
     * normal-user feature (#348) — then `recommendationReason` and
     * `recommendationScore` together, iff the reader asked to see why an
     * article was picked (#576; see FeedAnnotationVisibility for why the two
     * travel as one).
     *
     * @return array{entries: list<array<string, mixed>>, nextCursor: string|null}
     */
    public static function page(ForYouFeedPage $page): array
    {
        return [
            'entries' => self::entries($page->rows, $page->visibility),
            'nextCursor' => $page->nextCursor,
        ];
    }
```
The private `entries()` below is unchanged.

- [ ] **Step 5: Run the mapper test to see it pass.**

Run: `php bin/phpunit tests/Http/RecommendationFeedJsonTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Rename the service and return the page.**

Run: `git mv src/Service/Recommendation/ForYouFeedResponder.php src/Service/Recommendation/ForYouFeed.php`, then replace the whole file with:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\EntryListRow;
use App\Repository\EntryListRowEnricher;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationFeedRow;
use OpenTelemetry\API\Instrumentation\WithSpan;

/**
 * A page of the user's for-you feed (#321), enriched like every entry list, with the annotations the
 * reader's "show reasons" preference allows. Debug is deliberately not consulted here: it keeps the
 * per-run call logs, not a second way into the feed's annotations (#576).
 */
final readonly class ForYouFeed
{
    public function __construct(
        private RecommendationFeedPager $pager,
        private RecommendationSettingsResolver $settings,
        private EntryListRowEnricher $enricher,
    ) {
    }

    #[WithSpan]
    public function page(ForYouFeedQuery $query): ForYouFeedPage
    {
        $page = $this->pager->page($query);

        $visibility = new FeedAnnotationVisibility(
            showExplanation: $this->settings->forUser($query->user)->showReasons,
        );

        return new ForYouFeedPage(
            $this->enrichedRows($page->rows, $query->userId()),
            $page->nextCursor,
            $visibility,
        );
    }

    /**
     * @param list<RecommendationFeedRow> $rows
     *
     * @return list<RecommendationFeedRow>
     */
    private function enrichedRows(array $rows, int $userId): array
    {
        $entryRows = $this->enricher->enrich(
            array_map(static fn (RecommendationFeedRow $row): EntryListRow => $row->row, $rows),
            $userId,
        );

        return array_map(
            static fn (RecommendationFeedRow $row, EntryListRow $entryRow) => $row->withRow($entryRow),
            $rows,
            $entryRows,
        );
    }
}
```

- [ ] **Step 7: Rename the service test.** It keeps its assertions on the wire shape by composing the service with the mapper, exactly as the controller does. Its saved-search test reads the id with `requireId()` (Global Constraints).

Run: `git mv tests/Service/Recommendation/ForYouFeedResponderTest.php tests/Service/Recommendation/ForYouFeedTest.php`, then replace the whole file with:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Category;
use App\Entity\Entry;
use App\Entity\EntryCategory;
use App\Entity\Feed;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\SavedSearch;
use App\Entity\SavedSearchEntry;
use App\Entity\Subscription;
use App\Entity\User;
use App\Http\RecommendationFeedJson;
use App\Repository\EntryListRowEnricher;
use App\Repository\ForYouFeedQuery;
use App\Repository\RecommendationItemRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\ForYouFeed;
use App\Service\Recommendation\RecommendationFeedPager;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;

final class ForYouFeedTest extends DbTestCase
{
    private User $user;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);

        $this->user = new User('for-you-responder@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $feed = new Feed('https://example.com/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $run = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-07T09:00:00Z'));
        $run->snapshot([[1]]);
        $run->complete(new \DateTimeImmutable('2026-08-07T09:05:00Z'));
        $this->em->persist($run);

        $createdAt = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, 'g1', null, 'Title g1', $createdAt, $createdAt);
        $this->em->persist($entry);
        $this->em->persist(new RecommendationItem($run, $entry, 1, 'reason g1', 88));
        $this->em->flush();
    }

    public function testOmitsBothAnnotationsWhenShowReasonsIsOff(): void
    {
        $first = $this->firstEntry();

        self::assertArrayNotHasKey('recommendationReason', $first);
        self::assertArrayNotHasKey('recommendationScore', $first);
    }

    public function testShowsTheReasonAndItsScoreWhenShowReasonsIsOn(): void
    {
        $this->fixtures->showReasonsEnabledSettings($this->user);

        $first = $this->firstEntry();

        self::assertSame('reason g1', $first['recommendationReason']);
        self::assertSame(88, $first['recommendationScore']);
    }

    /** Debug keeps the per-run call logs and nothing else — it is not a second
     *  way to reveal what the reader asked to keep hidden (#576). */
    public function testDebugAloneRevealsNeitherAnnotation(): void
    {
        $this->fixtures->debugEnabledSettings($this->user);

        $first = $this->firstEntry();

        self::assertArrayNotHasKey('recommendationReason', $first);
        self::assertArrayNotHasKey('recommendationScore', $first);
    }

    /** Debug does not take anything away either: with reasons on, the pair is
     *  shown whether or not the reader is also collecting call logs. */
    public function testDebugDoesNotChangeWhatShowReasonsReveals(): void
    {
        $this->fixtures->showReasonsAndDebugEnabledSettings($this->user);

        $first = $this->firstEntry();

        self::assertSame('reason g1', $first['recommendationReason']);
        self::assertSame(88, $first['recommendationScore']);
    }

    public function testEntryCategoriesAreEnrichedOnTheForYouFeed(): void
    {
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['title' => 'Title g1']);
        self::assertInstanceOf(Entry::class, $entry);
        $category = new Category('world', '');
        $this->em->persist($category);
        $this->em->persist(new EntryCategory($entry, $category, 0, 'World'));
        $this->em->flush();

        $first = $this->firstEntry();

        self::assertSame(['World'], $first['categories']);
    }

    public function testEntrySavedSearchesAreEnrichedOnTheForYouFeed(): void
    {
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['title' => 'Title g1']);
        self::assertInstanceOf(Entry::class, $entry);
        $search = new SavedSearch($this->user, 'title', false);
        $this->em->persist($search);
        $this->em->flush();
        $search->setSlug($search->requireId() . '-title');
        $this->em->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-08-07T09:00:00Z')));
        $this->em->flush();

        $first = $this->firstEntry();

        self::assertSame(
            [['id' => $search->requireId(), 'slug' => $search->getSlug(), 'term' => $search->getTerm()]],
            $first['savedSearches'],
        );
    }

    /** @return array<string, mixed> */
    private function firstEntry(): array
    {
        $page = $this->forYouFeed()->page(new ForYouFeedQuery($this->user, null, 50));

        return RecommendationFeedJson::page($page)['entries'][0];
    }

    private function forYouFeed(): ForYouFeed
    {
        $repository = $this->em->getRepository(RecommendationItem::class);
        self::assertInstanceOf(RecommendationItemRepository::class, $repository);

        $settings = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $settings);

        $enricher = self::getContainer()->get(EntryListRowEnricher::class);
        self::assertInstanceOf(EntryListRowEnricher::class, $enricher);

        return new ForYouFeed(
            new RecommendationFeedPager($repository),
            $settings,
            $enricher,
        );
    }
}
```
Against develop's `ForYouFeedResponderTest`, only the class names, the `RecommendationFeedJson` import, `firstEntry()` (it maps the typed page, so the two `assertIsArray()` narrowings and their docblock go) and the two `getId()` → `requireId()` reads in the saved-search test change.

- [ ] **Step 8: Wire the controller and the tracing test.**

`src/Controller/Api/EntryController.php`, before (lines 15-18; line 15 as Task 2 left it):
```php
use App\Pagination\EntryCursor;
use App\Http\EntryJson;
use App\Http\EntryPage;
use App\Http\EntryStateJson;
```
after (the cursor import moves to its sorted place):
```php
use App\Http\EntryJson;
use App\Http\EntryPage;
use App\Http\EntryStateJson;
use App\Http\RecommendationFeedJson;
use App\Pagination\EntryCursor;
```
Before (line 27):
```php
use App\Service\Recommendation\ForYouFeedResponder;
```
after:
```php
use App\Service\Recommendation\ForYouFeed;
```
Before (line 45):
```php
        private ForYouFeedResponder $forYouFeed,
```
after:
```php
        private ForYouFeed $forYouFeed,
```
Before (lines 81-85, the for-you branch of `list()`, which reads #1157's `EntryPageParameters $page`):
```php
        if ($view === 'for-you') {
            return new JsonResponse($this->forYouFeed->page(
                new ForYouFeedQuery($user, $page->cursor, $page->limit, $page->unread),
            ));
        }
```
after:
```php
        if ($view === 'for-you') {
            return new JsonResponse(RecommendationFeedJson::page($this->forYouFeed->page(
                new ForYouFeedQuery($user, $page->cursor, $page->limit, $page->unread),
            )));
        }
```
`tests/Service/Tracing/TracedServiceMethodsTest.php`, before (line 18):
```php
use App\Service\Recommendation\ForYouFeedResponder;
```
after:
```php
use App\Service\Recommendation\ForYouFeed;
```
Before (line 38):
```php
        yield 'entries list, for-you responder' => [ForYouFeedResponder::class, 'page'];
```
after:
```php
        yield 'entries list, for-you feed' => [ForYouFeed::class, 'page'];
```

- [ ] **Step 9: Confirm the old names are gone.**

Run: `grep -rn 'ForYouFeedResponder\|App\\Http\\FeedAnnotationVisibility' src tests config`
Expected: no output.

- [ ] **Step 10: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/RecommendationFeedJsonTest.php tests/Service/Recommendation tests/Service/Tracing tests/Controller/Api/EntryControllerTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 11: Commit.**

```bash
git add -A src/Service/Recommendation src/Http/RecommendationFeedJson.php src/Http/FeedAnnotationVisibility.php \
  src/Controller/Api/EntryController.php tests/Http/RecommendationFeedJsonTest.php tests/Service/Recommendation \
  tests/Service/Tracing/TracedServiceMethodsTest.php
git commit -m "refactor(#1158): the for-you feed returns a typed page; the controller maps it"
```

---

### Task 10: `RecommendationRunStatusResolver` returns `RecommendationRunStatus`

**Files:**
- Create: `src/Service/Recommendation/RecommendationRunStatus.php`
- Move: `src/Service/Recommendation/RecommendationRunStatusPayload.php` → `src/Service/Recommendation/RecommendationRunStatusResolver.php` (whole file given below)
- Modify (whole file): `src/Http/RecommendationRunStatusJson.php`
- Modify (whole file): `src/Controller/Api/RecommendationRunController.php`
- Modify (whole file): `tests/Http/RecommendationRunStatusJsonTest.php`
- Modify: `tests/Service/Tracing/TracedServiceMethodsTest.php:21, :56`

**Interfaces:**
- Produces: `RecommendationRunStatus(RecommendationRunReport $report, RecommendationForYouSummary $forYou, \DateTimeImmutable $observedAt, ?int $etaSeconds)`.
- Produces: `RecommendationRunStatusResolver::forReport(RecommendationRunReport $report, User $user): RecommendationRunStatus` (still `#[WithSpan]`).
- Produces: `RecommendationRunStatusJson::report(RecommendationRunStatus $status): array` (same output; `elapsedSeconds` is computed from `observedAt`, the resolver's clock reading).

- [ ] **Step 1: Rewrite the mapper test against the new input, so it fails.** Replace the whole of `tests/Http/RecommendationRunStatusJsonTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Http\RecommendationRunStatusJson;
use App\Service\Recommendation\RecommendationForYouSummary;
use App\Service\Recommendation\RecommendationRunReport;
use App\Service\Recommendation\RecommendationRunStatus;
use PHPUnit\Framework\TestCase;

final class RecommendationRunStatusJsonTest extends TestCase
{
    public function testElapsedSecondsIsWholeSecondsSinceStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));

        $json = RecommendationRunStatusJson::report(
            new RecommendationRunStatus($report, $this->emptySummary(), $startedAt->modify('+90 seconds'), null),
        );

        self::assertSame(90, $json['elapsedSeconds']);
    }

    public function testEtaSecondsEchoesTheEstimatePassedIn(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            42,
        ));

        self::assertSame(42, $json['etaSeconds']);
    }

    public function testReportsWhetherTheFirstBatchHasStarted(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-09T10:00:00'));
        $run->snapshot([[1]]);
        $run->markFirstBatchStarted();

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::fromRun($run),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            42,
        ));

        self::assertTrue($json['firstBatchStarted']);
    }

    public function testTreatsCompletedBatchesAsAStartedFirstBatchForExistingRuns(): void
    {
        $run = new RecommendationRun($this->user(), new \DateTimeImmutable('2026-08-09T10:00:00'));
        $run->snapshot([[1]]);
        $run->recordBatchWinners([]);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::fromRun($run),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            42,
        ));

        self::assertTrue($json['firstBatchStarted']);
    }

    public function testElapsedSecondsClampsToZeroWhenTheClockIsBehindStartedAt(): void
    {
        $startedAt = new \DateTimeImmutable('2026-08-09T10:00:00');
        $report = RecommendationRunReport::fromRun(new RecommendationRun($this->user(), $startedAt));

        $json = RecommendationRunStatusJson::report(
            new RecommendationRunStatus($report, $this->emptySummary(), $startedAt->modify('-5 seconds'), null),
        );

        self::assertSame(0, $json['elapsedSeconds']);
    }

    public function testElapsedSecondsIsNullWhenThereIsNoRun(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::none(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertNull($json['elapsedSeconds']);
    }

    public function testForYouCarriesTheNewestCompletedRunId(): void
    {
        $summary = new RecommendationForYouSummary(4, 9, new \DateTimeImmutable('2026-08-09T10:00:00Z'), 42);

        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::none(),
            $summary,
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        $forYou = $json['forYou'];
        self::assertIsArray($forYou);
        self::assertSame(42, $forYou['newestRunId']);
    }

    private function user(): User
    {
        return new User('eta@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function emptySummary(): RecommendationForYouSummary
    {
        return new RecommendationForYouSummary(0, 0, null, null);
    }
}
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php`
Expected: FAIL, `Class "App\Service\Recommendation\RecommendationRunStatus" not found`.

- [ ] **Step 3: Create the value.** `src/Service/Recommendation/RecommendationRunStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

final readonly class RecommendationRunStatus
{
    public function __construct(
        public RecommendationRunReport $report,
        public RecommendationForYouSummary $forYou,
        public \DateTimeImmutable $observedAt,
        public ?int $etaSeconds,
    ) {
    }
}
```

- [ ] **Step 4: Make the mapper take it.** Replace the whole of `src/Http/RecommendationRunStatusJson.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\RecommendationRunStatus;

/**
 * The wire shape every /api/recommendations/runs* action returns: the run
 * report, the run's live `elapsedSeconds` (computed on the server's own clock —
 * the client never subtracts timestamps across machines), the phase-weighted
 * `etaSeconds` (null when there is no estimate yet, #638), and the for-you
 * summary.
 */
final class RecommendationRunStatusJson
{
    /** @return array<string, mixed> */
    public static function report(RecommendationRunStatus $status): array
    {
        $summary = $status->forYou;

        return $status->report->toArray() + [
            'elapsedSeconds' => $status->report->elapsedSecondsAt($status->observedAt),
            'etaSeconds' => $status->etaSeconds,
            'forYou' => [
                // The count of unread surviving picks (#724); the field name
                // stays `itemCount` for wire compatibility.
                'itemCount' => $summary->itemCount,
                'totalCount' => $summary->totalCount,
                'generatedAt' => $summary->generatedAt?->format(\DateTimeInterface::ATOM),
                'newestRunId' => $summary->newestRunId,
            ],
        ];
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 5: Run the mapper test to see it pass.**

Run: `php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Rename the service and return the value.**

Run: `git mv src/Service/Recommendation/RecommendationRunStatusPayload.php src/Service/Recommendation/RecommendationRunStatusResolver.php`, then replace the whole file with:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use OpenTelemetry\API\Instrumentation\WithSpan;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sources the three facts every recommendation-run response carries beside the report — the for-you
 * summary, the clock reading and the phase-weighted ETA — so no controller gathers them itself (#638).
 */
final readonly class RecommendationRunStatusResolver
{
    public function __construct(
        private RecommendationForYouSummaryProvider $forYouSummaries,
        private RecommendationEtaEstimator $etaEstimator,
        private ClockInterface $clock,
    ) {
    }

    #[WithSpan]
    public function forReport(RecommendationRunReport $report, User $user): RecommendationRunStatus
    {
        return new RecommendationRunStatus(
            $report,
            $this->forYouSummaries->forUser($user),
            $this->clock->now(),
            $this->etaEstimator->estimateSeconds($report, $user),
        );
    }
}
```

- [ ] **Step 7: Wire the controller.** Replace the whole of `src/Controller/Api/RecommendationRunController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationRunStatusJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Recommendation\RecommendationPollDriver;
use App\Service\Recommendation\RecommendationRunCanceller;
use App\Service\Recommendation\RecommendationRunPurger;
use App\Service\Recommendation\RecommendationRunReport;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationRunStatusResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The poll loop the client drives. `current` is a plain read with no limiter. Starting a run commits outbound
 * spend; ticking is the progress loop and must stay generous enough never to throttle a long run (#308).
 */
#[Route('/api/recommendations/runs')]
final readonly class RecommendationRunController
{
    public function __construct(
        private RecommendationRunStarter $starter,
        private RecommendationPollDriver $pollDriver,
        private RecommendationRunPurger $purger,
        private RecommendationRunCanceller $canceller,
        private RecommendationRunStatusResolver $status,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $aiRecommendationsLimiter,
        private RateLimiterFactoryInterface $aiRecommendationStartsLimiter,
    ) {
    }

    #[Route('', name: 'api_recommendations_start', methods: ['POST'])]
    public function start(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        return new JsonResponse(RecommendationRunStatusJson::report(
            $this->status->forReport($this->starter->start($user), $user),
        ));
    }

    /** Resumes the latest failed run; it shares the start limiter because it commits the same outbound spend. */
    #[Route('/resume', name: 'api_recommendations_resume', methods: ['POST'])]
    public function resume(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationStartsLimiter, $user);

        return new JsonResponse(RecommendationRunStatusJson::report(
            $this->status->forReport($this->starter->resume($user), $user),
        ));
    }

    #[Route('/tick', name: 'api_recommendations_tick', methods: ['POST'])]
    public function tick(#[CurrentUser] User $user): JsonResponse
    {
        $this->rateLimitGuard->enforceForUser($this->aiRecommendationsLimiter, $user);

        return new JsonResponse(RecommendationRunStatusJson::report(
            $this->status->forReport($this->pollDriver->poll($user), $user),
        ));
    }

    #[Route('/current', name: 'api_recommendations_current', methods: ['GET'])]
    public function current(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationRunStatusJson::report(
            $this->status->forReport($this->pollDriver->current($user), $user),
        ));
    }

    /** No limiter: stopping only reduces work, and throttling the way out of a spending run is backwards. */
    #[Route('/stop', name: 'api_recommendations_stop', methods: ['POST'])]
    public function stop(#[CurrentUser] User $user): JsonResponse
    {
        $this->canceller->cancel($user);

        return new JsonResponse(RecommendationRunStatusJson::report(
            $this->status->forReport($this->pollDriver->current($user), $user),
        ));
    }

    #[Route('', name: 'api_recommendations_purge', methods: ['DELETE'])]
    public function purge(#[CurrentUser] User $user): JsonResponse
    {
        $this->purger->purge($user);

        return new JsonResponse(RecommendationRunStatusJson::report(
            $this->status->forReport(RecommendationRunReport::none(), $user),
        ));
    }
}
```

`tests/Service/Tracing/TracedServiceMethodsTest.php`, before (line 21):
```php
use App\Service\Recommendation\RecommendationRunStatusPayload;
```
after:
```php
use App\Service\Recommendation\RecommendationRunStatusResolver;
```
Before (line 56):
```php
        yield 'recommendations current, payload' => [RecommendationRunStatusPayload::class, 'forReport'];
```
after:
```php
        yield 'recommendations current, status' => [RecommendationRunStatusResolver::class, 'forReport'];
```

- [ ] **Step 8: Confirm the old name is gone.**

Run: `grep -rn 'RecommendationRunStatusPayload\|statusPayload' src tests config`
Expected: no output.

- [ ] **Step 9: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php tests/Service/Tracing tests/Controller/Api/RecommendationRunControllerTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 10: Commit.**

```bash
git add -A src/Service/Recommendation src/Http/RecommendationRunStatusJson.php src/Controller/Api/RecommendationRunController.php \
  tests/Http/RecommendationRunStatusJsonTest.php tests/Service/Tracing/TracedServiceMethodsTest.php
git commit -m "refactor(#1158): run status is a typed value; the controller maps it"
```

---

### Task 11: `RecommendationRunHistory` returns `RunHistoryOverview` / `RunHistoryMonthPage`

**Files:**
- Create: `src/Service/Recommendation/RunHistoryMonthPage.php`, `src/Service/Recommendation/RunHistoryOverview.php`
- Move: `src/Service/Recommendation/RecommendationRunHistoryView.php` → `src/Service/Recommendation/RecommendationRunHistory.php` (whole file given below)
- Modify: `src/Http/RecommendationRunHistoryJson.php:5-76`
- Modify (whole file): `src/Controller/Api/RecommendationRunHistoryController.php`
- Modify: `tests/Http/RecommendationRunHistoryJsonTest.php:7-18`, every `monthPage(` / `overview(` call (lines 22, 39, 58, 71, 85, 94, 109, 120, 127, 137, 145-165, 170), plus a private helper

**Interfaces:**
- Produces: `RunHistoryMonthPage(string $month, array $rows /* list<HistoryRow> */, ?int $nextCursor)`.
- Produces: `RunHistoryOverview(?int $totalCostNanoCredits, array $months /* list<HistoryMonth> */, ?RunHistoryMonthPage $latest)`.
- Produces: `RecommendationRunHistory::overview(User, ViewerTimeZone): RunHistoryOverview` and `::month(User, MonthWindow, ?int $beforeRunId): RunHistoryMonthPage`.
- Produces: `RecommendationRunHistoryJson::overview(RunHistoryOverview): array` (`OverviewPayload`) and `::monthPage(RunHistoryMonthPage): array` (`MonthPagePayload`), same output.

- [ ] **Step 1: Point the mapper test at the new inputs, so it fails.** A test-private `monthPage()` helper builds the value and calls the mapper, so the ten month-page call sites change only their receiver and every line stays shorter than before.

`tests/Http/RecommendationRunHistoryJsonTest.php`, before (lines 7-18):
```php
use App\Entity\RecommendationRun;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\HistoryMonth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 */
#[CoversClass(RecommendationRunHistoryJson::class)]
final class RecommendationRunHistoryJsonTest extends TestCase
```
after:
```php
use App\Entity\RecommendationRun;
use App\Http\RecommendationRunHistoryJson;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\HistoryMonth;
use App\Service\Recommendation\RunHistoryMonthPage;
use App\Service\Recommendation\RunHistoryOverview;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 * @phpstan-import-type MonthPagePayload from RecommendationRunHistoryJson
 */
#[CoversClass(RecommendationRunHistoryJson::class)]
final class RecommendationRunHistoryJsonTest extends TestCase
```
The overview test, before (old lines 145-154):
```php
        $latest = RecommendationRunHistoryJson::monthPage('2026-08', [$this->completedRow()], 361);

        $payload = RecommendationRunHistoryJson::overview(
            918_200_000,
            [
                new HistoryMonth('2026-08', 47, 2_431_200_000),
                new HistoryMonth('2026-07', 3, 100_000),
            ],
            $latest,
        );
```
after:
```php
        $latest = new RunHistoryMonthPage('2026-08', [$this->completedRow()], 361);

        $payload = RecommendationRunHistoryJson::overview(new RunHistoryOverview(
            918_200_000,
            [
                new HistoryMonth('2026-08', 47, 2_431_200_000),
                new HistoryMonth('2026-07', 3, 100_000),
            ],
            $latest,
        ));
```
Before (old line 165):
```php
        self::assertSame($latest, $payload['latest']);
```
after:
```php
        self::assertSame(RecommendationRunHistoryJson::monthPage($latest), $payload['latest']);
```
Before (old line 170):
```php
        $payload = RecommendationRunHistoryJson::overview(null, [], null);
```
after:
```php
        $payload = RecommendationRunHistoryJson::overview(new RunHistoryOverview(null, [], null));
```
Then every remaining `$payload = RecommendationRunHistoryJson::monthPage(` (old lines 22, 39, 58, 71, 85, 94, 109, 120, 127, 137) calls the helper instead:
```bash
perl -pi -e 's/^(\s+\$payload = )RecommendationRunHistoryJson::monthPage\(/$1self::monthPage(/' \
  tests/Http/RecommendationRunHistoryJsonTest.php
grep -c 'self::monthPage(' tests/Http/RecommendationRunHistoryJsonTest.php
```
Expected count: `10`. For example old line 22, before:
```php
        $payload = RecommendationRunHistoryJson::monthPage('2026-08', [$this->completedRow()], null);
```
after:
```php
        $payload = self::monthPage('2026-08', [$this->completedRow()], null);
```
Add the helper just above the `/** @return HistoryRow */` docblock of `completedRow()` (old line 177):
```php
    /**
     * @param list<HistoryRow> $rows
     *
     * @return MonthPagePayload
     */
    private static function monthPage(string $month, array $rows, ?int $nextCursor): array
    {
        return RecommendationRunHistoryJson::monthPage(new RunHistoryMonthPage($month, $rows, $nextCursor));
    }

```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/RecommendationRunHistoryJsonTest.php`
Expected: FAIL, `Class "App\Service\Recommendation\RunHistoryMonthPage" not found`.

- [ ] **Step 3: Create the two values.**

`src/Service/Recommendation/RunHistoryMonthPage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Repository\RecommendationRunHistoryRepository;

/**
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 */
final readonly class RunHistoryMonthPage
{
    /** @param list<HistoryRow> $rows already truncated to the page size */
    public function __construct(
        public string $month,
        public array $rows,
        public ?int $nextCursor,
    ) {
    }
}
```
`src/Service/Recommendation/RunHistoryOverview.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

final readonly class RunHistoryOverview
{
    /** @param list<HistoryMonth> $months newest first */
    public function __construct(
        public ?int $totalCostNanoCredits,
        public array $months,
        public ?RunHistoryMonthPage $latest,
    ) {
    }
}
```

- [ ] **Step 4: Make the mapper take them.**

`src/Http/RecommendationRunHistoryJson.php`, before (lines 5-10):
```php
namespace App\Http;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\HistoryMonth;
```
after:
```php
namespace App\Http;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunHistoryRepository;
use App\Service\Recommendation\HistoryMonth;
use App\Service\Recommendation\RunHistoryMonthPage;
use App\Service\Recommendation\RunHistoryOverview;
```
Before (lines 20-30; the paragraph about exporting types for the service goes, since the service no longer returns them):
```php
 * `durationSeconds` is computed here, not left to the client (the rule
 * RecommendationRunStatusJson follows) — the client never subtracts timestamps
 * across machines. `status` goes out as the raw wire vocabulary, untranslated,
 * the same convention the #309 debug log records.
 *
 * The two named shapes below are exported so RecommendationRunHistoryView can
 * declare return types against them instead of a bare `array`: a key renamed
 * here without a matching update there is a level-max PHPStan error at the
 * call site, not a silent wire break the client discovers.
 *
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
```
after:
```php
 * `durationSeconds` is computed here, not left to the client (the rule
 * RecommendationRunStatusJson follows) — the client never subtracts timestamps
 * across machines. `status` goes out as the raw wire vocabulary, untranslated,
 * the same convention the #309 debug log records.
 *
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
```
Before (lines 44-76):
```php
    /**
     * @param list<HistoryMonth> $months newest first
     * @param ?MonthPagePayload $latest the newest month's own monthPage(),
     *                                   or null for an account that has
     *                                   never run
     *
     * @return OverviewPayload
     */
    public static function overview(?int $totalCostNanoCredits, array $months, ?array $latest): array
    {
        return [
            // The account's whole spend, not the sum of the page above it. A
            // total that silently means "of the last fifty" is a wrong number,
            // not a cheaper one.
            'totalCostNanoCredits' => $totalCostNanoCredits,
            'months' => array_map(self::monthSummary(...), $months),
            'latest' => $latest,
        ];
    }

    /**
     * @param list<HistoryRow> $rows already truncated to the page size
     *
     * @return MonthPagePayload
     */
    public static function monthPage(string $month, array $rows, ?int $nextCursor): array
    {
        return [
            'month' => $month,
            'runs' => array_map(self::row(...), $rows),
            'nextCursor' => $nextCursor,
        ];
    }
```
after:
```php
    /** @return OverviewPayload */
    public static function overview(RunHistoryOverview $overview): array
    {
        return [
            // The account's whole spend, not the sum of the page above it. A
            // total that silently means "of the last fifty" is a wrong number,
            // not a cheaper one.
            'totalCostNanoCredits' => $overview->totalCostNanoCredits,
            'months' => array_map(self::monthSummary(...), $overview->months),
            'latest' => null === $overview->latest ? null : self::monthPage($overview->latest),
        ];
    }

    /** @return MonthPagePayload */
    public static function monthPage(RunHistoryMonthPage $page): array
    {
        return [
            'month' => $page->month,
            'runs' => array_map(self::row(...), $page->rows),
            'nextCursor' => $page->nextCursor,
        ];
    }
```
The private helpers below (`monthSummary`, `row`, `completionOf`, `durationSeconds`, `costNanoCredits`) are unchanged.

- [ ] **Step 5: Run the mapper test to see it pass.**

Run: `php bin/phpunit tests/Http/RecommendationRunHistoryJsonTest.php`
Expected: PASS.

- [ ] **Step 6: Rename the service and return the values.**

Run: `git mv src/Service/Recommendation/RecommendationRunHistoryView.php src/Service/Recommendation/RecommendationRunHistory.php`, then replace the whole file with:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\User;
use App\Repository\RecommendationRunHistoryRepository;

/**
 * The run history (#409): the overview card and the month pages it expands into. The limit-plus-one
 * truncation lives once, in truncate(): an earlier split across controller and mapper was rejected.
 *
 * @phpstan-import-type HistoryRow from RecommendationRunHistoryRepository
 */
final readonly class RecommendationRunHistory
{
    public function __construct(
        private RecommendationRunHistoryRepository $runs,
        private HistoryMonthSummariser $summariser,
    ) {
    }

    /**
     * The all-time total is summed by the database over the same rows
     * spendTimeline() returns. The duplication is deliberate: the timeline may
     * gain a cap one day, and the SUM keeps the account total honest when it
     * does. Deriving the total from the timeline would silently reduce it to
     * "the total of what the timeline still covers".
     */
    public function overview(User $user, ViewerTimeZone $viewer): RunHistoryOverview
    {
        $months = $this->summariser->summarise($this->runs->spendTimeline($user), $viewer);

        return new RunHistoryOverview(
            $this->runs->totalCostNanoCredits($user),
            $months,
            $this->latestMonthPage($user, $viewer, $months[0] ?? null),
        );
    }

    public function month(User $user, MonthWindow $window, ?int $beforeRunId): RunHistoryMonthPage
    {
        [$rows, $nextCursor] = $this->truncate($this->runs->pageForMonth($user, $window, $beforeRunId));

        return new RunHistoryMonthPage($window->month, $rows, $nextCursor);
    }

    /**
     * The overview's `latest`: the first page of the newest month that has a
     * run in it, not the calendar month the server clock reads. Null when the
     * account has never run, since there is then no month to open.
     */
    private function latestMonthPage(
        User $user,
        ViewerTimeZone $viewer,
        ?HistoryMonth $newestMonth,
    ): ?RunHistoryMonthPage {
        if (null === $newestMonth) {
            return null;
        }

        return $this->month($user, MonthWindow::of($newestMonth->month, $viewer), null);
    }

    /**
     * Splits the repository's HISTORY_LIMIT + 1 rows into the page the wire
     * shape keeps and the cursor that says whether another one exists. The
     * extra row is read purely as a yes/no signal and never shown, so it is
     * dropped here rather than passed on for a caller to remember to trim.
     *
     * @param list<HistoryRow> $rows
     *
     * @return array{0: list<HistoryRow>, 1: ?int}
     */
    private function truncate(array $rows): array
    {
        if (\count($rows) <= RecommendationRunHistoryRepository::HISTORY_LIMIT) {
            return [$rows, null];
        }

        $kept = \array_slice($rows, 0, RecommendationRunHistoryRepository::HISTORY_LIMIT);

        return [$kept, $kept[array_key_last($kept)]['id']];
    }
}
```

- [ ] **Step 7: Wire the controller.** Replace the whole of `src/Controller/Api/RecommendationRunHistoryController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationRunHistoryJson;
use App\Service\Recommendation\MonthWindow;
use App\Service\Recommendation\RecommendationRunHistory;
use App\Service\Recommendation\ViewerTimeZone;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * What every for-you run has cost this account (#409): an overview with the
 * all-time total, one summary per calendar month and the newest month's own
 * runs, plus a route to page further into any other month.
 *
 * Read-only and cheap — scoped, indexed queries against one user — so it
 * carries no rate limiter, the same call the #309 debug log endpoint makes.
 * Ownership is enforced in the repository: every query filters on the
 * authenticated user, and there is no id in the route to forge.
 *
 * Its own controller rather than a seventh action on RecommendationRunController:
 * that class is about driving a run, and reading a spending record is not that.
 */
#[Route('/api/recommendations/runs/history')]
final readonly class RecommendationRunHistoryController
{
    public function __construct(private RecommendationRunHistory $history)
    {
    }

    #[Route('', name: 'api_recommendations_run_history', methods: ['GET'])]
    public function overview(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RecommendationRunHistoryJson::overview($this->history->overview(
            $user,
            ViewerTimeZone::of($request->query->get('tz')),
        )));
    }

    #[Route(
        '/{month}',
        name: 'api_recommendations_run_history_month',
        requirements: ['month' => '\d{4}-(?:0[1-9]|1[0-2])'],
        methods: ['GET'],
    )]
    public function month(string $month, #[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(RecommendationRunHistoryJson::monthPage($this->history->month(
            $user,
            MonthWindow::of($month, ViewerTimeZone::of($request->query->get('tz'))),
            $request->query->getInt('before') ?: null,
        )));
    }
}
```

- [ ] **Step 8: Confirm the old names are gone.**

Run: `grep -rn 'RecommendationRunHistoryView' src tests config; grep -rn 'MonthPagePayload from\|OverviewPayload from' src`
Expected: no output from either. (`tests/Http/RecommendationRunHistoryJsonTest.php` imports `MonthPagePayload` on purpose, for its helper, so the second sweep reads `src` only.)

- [ ] **Step 9: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/RecommendationRunHistoryJsonTest.php tests/Controller/Api/RecommendationRunHistoryControllerTest.php tests/Repository/RecommendationRunHistoryRepositoryTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 10: Commit.**

```bash
git add -A src/Service/Recommendation src/Http/RecommendationRunHistoryJson.php \
  src/Controller/Api/RecommendationRunHistoryController.php tests/Http/RecommendationRunHistoryJsonTest.php
git commit -m "refactor(#1158): run history returns typed pages; the controller maps them"
```

---

### Task 12: `RecommendationDebugLogLoader` returns `RecommendationDebugLog`

**Files:**
- Create: `src/Service/Recommendation/RecommendationDebugLog.php`
- Create: `tests/Http/RecommendationDebugLogJsonTest.php`
- Move: `src/Service/Recommendation/RecommendationDebugLogView.php` → `src/Service/Recommendation/RecommendationDebugLogLoader.php` (whole file given below)
- Modify: `src/Http/RecommendationDebugLogJson.php:5-41`
- Modify: `src/Controller/Api/RecommendationDebugLogController.php:10, :26, :38`

**Interfaces:**
- Produces: `RecommendationDebugLog(array $rows /* list<DebugLogRow> */, array $streamingTextById /* array<int, string> */, ?RecommendationRun $selectedRun, array $retainedRuns /* list<RecommendationRun> */)` with `static empty(): self`, and the exported `@phpstan-type DebugLogRow`.
- Produces: `RecommendationDebugLogLoader::forUser(User $user, int $requestedRunId): RecommendationDebugLog`.
- Produces: `RecommendationDebugLogJson::list(RecommendationDebugLog $log): array` (same output). `detail()` is unchanged.

- [ ] **Step 1: Write the failing mapper test.** `tests/Http/RecommendationDebugLogJsonTest.php` (there is none today; the empty case needs no database):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RecommendationDebugLogJson;
use App\Service\Recommendation\RecommendationDebugLog;
use PHPUnit\Framework\TestCase;

final class RecommendationDebugLogJsonTest extends TestCase
{
    public function testAnAccountWithNoRetainedRunGetsAnEmptyPanel(): void
    {
        self::assertSame(
            ['entries' => [], 'run' => null, 'runs' => []],
            RecommendationDebugLogJson::list(RecommendationDebugLog::empty()),
        );
    }

    public function testEachRowCarriesItsStreamingTextOrNull(): void
    {
        $log = new RecommendationDebugLog(
            [self::row(7), self::row(8)],
            [8 => 'partial answer'],
            null,
            [],
        );

        $entries = RecommendationDebugLogJson::list($log)['entries'];

        self::assertNull($entries[0]['streamingText']);
        self::assertSame('partial answer', $entries[1]['streamingText']);
        self::assertSame(7, $entries[0]['id']);
    }

    /**
     * @return array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
     *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}
     */
    private static function row(int $id): array
    {
        return [
            'id' => $id,
            'runId' => 1,
            'phase' => 'batch',
            'batchNumber' => 1,
            'attempt' => 1,
            'verdict' => null,
            'requestBytes' => 10,
            'responseBytes' => 20,
            'wireBytes' => 30,
            'createdAt' => '2026-08-09T10:00:00+00:00',
            'finishedAt' => null,
            'errorDetail' => null,
            'finishReason' => null,
        ];
    }
}
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/RecommendationDebugLogJsonTest.php`
Expected: FAIL, `Class "App\Service\Recommendation\RecommendationDebugLog" not found`.

- [ ] **Step 3: Create the value.** `src/Service/Recommendation/RecommendationDebugLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;

/**
 * @phpstan-type DebugLogRow array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
 *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
 *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}
 */
final readonly class RecommendationDebugLog
{
    /**
     * @param list<DebugLogRow>       $rows
     * @param array<int, string>      $streamingTextById
     * @param list<RecommendationRun> $retainedRuns newest first, the runs the panel may switch to
     */
    public function __construct(
        public array $rows,
        public array $streamingTextById,
        public ?RecommendationRun $selectedRun,
        public array $retainedRuns,
    ) {
    }

    public static function empty(): self
    {
        return new self([], [], null, []);
    }
}
```

- [ ] **Step 4: Make the mapper take it.**

`src/Http/RecommendationDebugLogJson.php`, before (lines 5-41):
```php
namespace App\Http;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;

/**
 * Response shapes for the recommendation debug log (#309). The list shape is
 * poll-cheap by construction: bodies never ride along, only sizes — except
 * the one call still streaming, whose growing text IS the live view.
 */
final class RecommendationDebugLogJson
{
    /**
     * @param list<array{id: int, runId: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int, wireBytes: int,
     *     createdAt: string, finishedAt: ?string, errorDetail: ?string, finishReason: ?string}> $rows
     * @param array<int, string>       $streamingTextById
     * @param list<RecommendationRun>  $retainedRuns newest first, the runs the panel may switch to
     *
     * @return array{entries: list<array<string, mixed>>, run: ?array<string, mixed>,
     *     runs: list<array<string, mixed>>}
     */
    public static function list(
        array $rows,
        array $streamingTextById,
        ?RecommendationRun $run,
        array $retainedRuns,
    ): array {
        return [
            'entries' => array_map(
                static fn (array $row): array => [...$row, 'streamingText' => $streamingTextById[$row['id']] ?? null],
                $rows,
            ),
            'run' => null === $run ? null : self::run($run),
            'runs' => array_map(self::choice(...), $retainedRuns),
        ];
    }
```
after:
```php
namespace App\Http;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Service\Recommendation\RecommendationDebugLog;

/**
 * Response shapes for the recommendation debug log (#309). The list shape is
 * poll-cheap by construction: bodies never ride along, only sizes — except
 * the one call still streaming, whose growing text IS the live view.
 */
final class RecommendationDebugLogJson
{
    /**
     * @return array{entries: list<array<string, mixed>>, run: ?array<string, mixed>,
     *     runs: list<array<string, mixed>>}
     */
    public static function list(RecommendationDebugLog $log): array
    {
        $streamingTextById = $log->streamingTextById;

        return [
            'entries' => array_map(
                static fn (array $row): array => [...$row, 'streamingText' => $streamingTextById[$row['id']] ?? null],
                $log->rows,
            ),
            'run' => null === $log->selectedRun ? null : self::run($log->selectedRun),
            'runs' => array_map(self::choice(...), $log->retainedRuns),
        ];
    }
```
`choice()`, `run()` and `detail()` below are unchanged (`RecommendationRun` is still imported for them).

- [ ] **Step 5: Run the mapper test to see it pass.**

Run: `php bin/phpunit tests/Http/RecommendationDebugLogJsonTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Rename the service and return the value.**

Run: `git mv src/Service/Recommendation/RecommendationDebugLogView.php src/Service/Recommendation/RecommendationDebugLogLoader.php`, then replace the whole file with:
```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Repository\RecommendationRunRepository;

/**
 * What the debug panel shows: the runs it may switch between, and the rows of the one it is looking at.
 * One run at a time on purpose: the panel polls every two seconds mid-run, and shipping all ten retained
 * runs' rows on every poll costs ten times as much for nine runs nobody is reading.
 */
final readonly class RecommendationDebugLogLoader
{
    public function __construct(
        private RecommendationRunLogRepository $logs,
        private RecommendationRunRepository $runs,
    ) {
    }

    /**
     * @param int $requestedRunId any id outside the retention window selects
     *                            the newest run instead — including the 0 an
     *                            absent query parameter reads as, and a
     *                            selection the window has since dropped. A
     *                            stale pick lands on something real rather
     *                            than on an empty panel
     */
    public function forUser(User $user, int $requestedRunId): RecommendationDebugLog
    {
        $runs = $this->runs->findNewestForUser($user, RunLogRetention::RUNS);
        $selected = self::select($runs, $requestedRunId);

        if (null === $selected) {
            return RecommendationDebugLog::empty();
        }

        $selectedId = $selected->requireId();

        return new RecommendationDebugLog(
            $this->logs->listForRun($user, $selectedId),
            $this->logs->streamingTextForRun($user, $selectedId),
            $selected,
            $runs,
        );
    }

    /** @param list<RecommendationRun> $runs newest first */
    private static function select(array $runs, int $requestedRunId): ?RecommendationRun
    {
        foreach ($runs as $run) {
            if ($run->getId() === $requestedRunId) {
                return $run;
            }
        }

        return $runs[0] ?? null;
    }
}
```

- [ ] **Step 7: Wire the controller.**

`src/Controller/Api/RecommendationDebugLogController.php`, before (line 10):
```php
use App\Service\Recommendation\RecommendationDebugLogView;
```
after:
```php
use App\Service\Recommendation\RecommendationDebugLogLoader;
```
Before (line 26):
```php
        private RecommendationDebugLogView $view,
```
after:
```php
        private RecommendationDebugLogLoader $debugLogs,
```
Before (line 38):
```php
        return new JsonResponse($this->view->forUser($user, $request->query->getInt('run')));
```
after:
```php
        return new JsonResponse(RecommendationDebugLogJson::list(
            $this->debugLogs->forUser($user, $request->query->getInt('run')),
        ));
```
(`App\Http\RecommendationDebugLogJson` is already imported at line 8 for `detail()`.)

- [ ] **Step 8: Confirm the old name is gone.**

Run: `grep -rn 'RecommendationDebugLogView' src tests config`
Expected: no output.

- [ ] **Step 9: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/RecommendationDebugLogJsonTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 10: Commit.**

```bash
git add -A src/Service/Recommendation src/Http/RecommendationDebugLogJson.php \
  src/Controller/Api/RecommendationDebugLogController.php tests/Http/RecommendationDebugLogJsonTest.php
git commit -m "refactor(#1158): the debug log loader returns a typed log; the controller maps it"
```

---

### Task 13: `ReadingActivityCounter` returns `ReadingActivity`

**Files:**
- Create: `src/Service/Reading/ReadingActivity.php`
- Move: `src/Service/Reading/ReadingActivityView.php` → `src/Service/Reading/ReadingActivityCounter.php` (whole file given below)
- Modify (whole file): `src/Http/ReadingActivityJson.php`
- Modify (whole file): `src/Controller/Api/ReadingActivityController.php`
- Modify: `tests/Http/ReadingActivityJsonTest.php:7, :18-22, :41`

**Interfaces:**
- Produces: `ReadingActivity(array $localDates /* list<string> */, array $countsByDay /* array<string, int> */, array $topFeedsByRead /* list<array{feedId: int, readCount: int}> */)`.
- Produces: `ReadingActivityCounter::daily(User $user, ViewerTimeZone $viewer): ReadingActivity`.
- Produces: `ReadingActivityJson::of(ReadingActivity $activity): array` (`ReadingActivityPayload`, same output).

- [ ] **Step 1: Point the mapper test at the new input, so it fails.**

`tests/Http/ReadingActivityJsonTest.php`, before (line 7):
```php
use App\Http\ReadingActivityJson;
```
after:
```php
use App\Http\ReadingActivityJson;
use App\Service\Reading\ReadingActivity;
```
Before (lines 18-22):
```php
        $payload = ReadingActivityJson::of(
            ['2026-09-05', '2026-09-06', '2026-09-07'],
            ['2026-09-06' => 3, '2026-09-07' => 1],
            [['feedId' => 7, 'readCount' => 42], ['feedId' => 3, 'readCount' => 10]],
        );
```
after:
```php
        $payload = ReadingActivityJson::of(new ReadingActivity(
            ['2026-09-05', '2026-09-06', '2026-09-07'],
            ['2026-09-06' => 3, '2026-09-07' => 1],
            [['feedId' => 7, 'readCount' => 42], ['feedId' => 3, 'readCount' => 10]],
        ));
```
Before (line 41):
```php
        $payload = ReadingActivityJson::of(['2026-09-06', '2026-09-07'], [], []);
```
after:
```php
        $payload = ReadingActivityJson::of(new ReadingActivity(['2026-09-06', '2026-09-07'], [], []));
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/ReadingActivityJsonTest.php`
Expected: FAIL, `Class "App\Service\Reading\ReadingActivity" not found`.

- [ ] **Step 3: Create the value.** `src/Service/Reading/ReadingActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

final readonly class ReadingActivity
{
    /**
     * @param list<string>                             $localDates     oldest first, one 'Y-m-d' per day
     * @param array<string, int>                       $countsByDay    local 'Y-m-d' => articles opened
     * @param list<array{feedId: int, readCount: int}> $topFeedsByRead busiest feed first
     */
    public function __construct(
        public array $localDates,
        public array $countsByDay,
        public array $topFeedsByRead,
    ) {
    }
}
```

- [ ] **Step 4: Make the mapper take it.** Replace the whole of `src/Http/ReadingActivityJson.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Reading\ReadingActivity;

/**
 * The wire shape of the reading-activity chart (#896): one entry per day of the
 * window in order, each carrying the day and how many articles the account
 * opened on it, plus the window's total. Quiet days are present with a count of
 * zero so the client draws a continuous axis rather than skipping gaps.
 *
 * @phpstan-type ReadingActivityPayload array{
 *     days: list<array{date: string, count: int}>,
 *     total: int,
 *     topFeedsByRead: list<array{feedId: int, readCount: int}>,
 * }
 */
final class ReadingActivityJson
{
    /** @return ReadingActivityPayload */
    public static function of(ReadingActivity $activity): array
    {
        $days = [];
        $total = 0;
        foreach ($activity->localDates as $date) {
            $count = $activity->countsByDay[$date] ?? 0;
            $days[] = ['date' => $date, 'count' => $count];
            $total += $count;
        }

        return ['days' => $days, 'total' => $total, 'topFeedsByRead' => $activity->topFeedsByRead];
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 5: Run the mapper test to see it pass.**

Run: `php bin/phpunit tests/Http/ReadingActivityJsonTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Rename the service and return the value.**

Run: `git mv src/Service/Reading/ReadingActivityView.php src/Service/Reading/ReadingActivityCounter.php`, then replace the whole file with:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reading;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Service\Recommendation\ViewerTimeZone;
use Psr\Clock\ClockInterface;

/**
 * How many articles the account opened on each of the last WINDOW_DAYS days, in the viewer's own timezone
 * (#896). Bucketed in PHP, not the database: `viewedAt` is naive UTC and the buckets are cut in the viewer's
 * zone, which no portable DQL expression can shift before grouping.
 */
final readonly class ReadingActivityCounter
{
    private const int WINDOW_DAYS = 30;
    private const int TOP_FEEDS = 5;

    public function __construct(
        private EntryStateRepository $states,
        private ClockInterface $clock,
    ) {
    }

    public function daily(User $user, ViewerTimeZone $viewer): ReadingActivity
    {
        $window = ReadingWindow::lastDays(self::WINDOW_DAYS, $viewer, $this->nowUtc());
        $userId = $user->requireId();

        $countsByDay = $this->countByLocalDay(
            $this->states->viewedAtSince($userId, $window->sinceUtc),
            $viewer,
        );

        return new ReadingActivity(
            $window->localDates,
            $countsByDay,
            $this->states->readCountsByFeed($userId, self::TOP_FEEDS),
        );
    }

    /**
     * @param list<\DateTimeImmutable> $viewedAt
     *
     * @return array<string, int> local 'Y-m-d' => articles opened
     */
    private function countByLocalDay(array $viewedAt, ViewerTimeZone $viewer): array
    {
        $countsByDay = [];
        foreach ($viewedAt as $instant) {
            $day = $instant->setTimezone($viewer->zone)->format('Y-m-d');
            $countsByDay[$day] = ($countsByDay[$day] ?? 0) + 1;
        }

        return $countsByDay;
    }

    private function nowUtc(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())
            ->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

- [ ] **Step 7: Wire the controller.** Replace the whole of `src/Controller/Api/ReadingActivityController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReadingActivityJson;
use App\Service\Reading\ReadingActivityCounter;
use App\Service\Recommendation\ViewerTimeZone;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * How much the account has read lately (#896): one count per day for the last
 * thirty days, bucketed in the viewer's timezone, for the About page's reading
 * chart.
 *
 * Read-only and cheap — one scoped, bounded query against the current user — so
 * it carries no rate limiter, the same call RecommendationRunHistoryController
 * makes. Ownership is enforced in the service and repository: every query filters
 * on the authenticated user, and there is no id in the route to forge.
 */
#[Route('/api/reading')]
final readonly class ReadingActivityController
{
    public function __construct(private ReadingActivityCounter $activity)
    {
    }

    #[Route('/activity', name: 'api_reading_activity', methods: ['GET'])]
    public function activity(#[CurrentUser] User $user, Request $request): JsonResponse
    {
        return new JsonResponse(ReadingActivityJson::of($this->activity->daily(
            $user,
            ViewerTimeZone::of($request->query->get('tz')),
        )));
    }
}
```

- [ ] **Step 8: Confirm the old name is gone.**

Run: `grep -rn 'ReadingActivityView' src tests config`
Expected: no output.

- [ ] **Step 9: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/ReadingActivityJsonTest.php tests/Controller/Api/ReadingActivityControllerTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 10: Commit.**

```bash
git add -A src/Service/Reading src/Http/ReadingActivityJson.php src/Controller/Api/ReadingActivityController.php \
  tests/Http/ReadingActivityJsonTest.php
git commit -m "refactor(#1158): reading activity is a typed value; the controller maps it"
```

---

### Task 14: `PasskeyListing` returns `AccountPasskeys`

**Files:**
- Create: `src/Service/Passkey/AccountPasskeys.php`
- Create: `tests/Http/PasskeyJsonTest.php`
- Modify (whole file): `src/Service/Passkey/PasskeyListing.php`
- Modify: `src/Http/PasskeyJson.php:5-46`
- Modify: `src/Controller/Api/PasskeyController.php:8, :131, :139`

**Interfaces:**
- Produces: `AccountPasskeys(string $relyingPartyId, ?string $userHandle, array $passkeys /* list<UserPasskey> */)`.
- Produces: `PasskeyListing::forUser(User $user): AccountPasskeys`.
- Produces: `PasskeyJson::listing(AccountPasskeys $account): array` (`PasskeyListingBody`, same output).

- [ ] **Step 1: Write the failing mapper test.** `tests/Http/PasskeyJsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\PasskeyJson;
use App\Service\Passkey\AccountPasskeys;
use PHPUnit\Framework\TestCase;

final class PasskeyJsonTest extends TestCase
{
    public function testAnAccountWithoutPasskeysStillNamesItsRelyingParty(): void
    {
        self::assertSame(
            ['rpId' => 'reader.example.test', 'userHandle' => null, 'acceptedCredentialIds' => [], 'passkeys' => []],
            PasskeyJson::listing(new AccountPasskeys('reader.example.test', null, [])),
        );
    }
}
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Http/PasskeyJsonTest.php`
Expected: FAIL, `Class "App\Service\Passkey\AccountPasskeys" not found`.

- [ ] **Step 3: Create the value.** `src/Service/Passkey/AccountPasskeys.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\UserPasskey;

final readonly class AccountPasskeys
{
    /** @param list<UserPasskey> $passkeys */
    public function __construct(
        public string $relyingPartyId,
        public ?string $userHandle,
        public array $passkeys,
    ) {
    }
}
```

- [ ] **Step 4: Make the mapper take it.**

`src/Http/PasskeyJson.php`, before (lines 5-46):
```php
namespace App\Http;

use App\Entity\UserPasskey;

/**
 * The passkey listing body (#624): the rows plus the three values the WebAuthn
 * Signal API needs (#727), which `register/options` already discloses to the
 * same authenticated user.
 *
 * The two options factories already return their body in its final wire
 * shape — the identical `{options, handle}` for both ceremonies — so they
 * need no mapper here and their controller actions hand the array straight
 * to the response.
 *
 * @phpstan-type PasskeyRow array{id: ?int, label: string, createdAt: string, lastUsedAt: ?string}
 * @phpstan-type PasskeyListingBody array{
 *     rpId: string, userHandle: ?string, acceptedCredentialIds: list<string>, passkeys: list<PasskeyRow>,
 * }
 */
final readonly class PasskeyJson
{
    /**
     * `acceptedCredentialIds` is ONE flat authoritative list the client hands
     * to the browser unchanged: a rebuilt or shortened list deletes valid
     * credentials. The handle comes from PasskeyCredentials::sharedHandle().
     *
     * @param list<UserPasskey> $passkeys
     *
     * @return PasskeyListingBody
     */
    public static function listing(string $relyingPartyId, ?string $userHandle, array $passkeys): array
    {
        return [
            'rpId' => $relyingPartyId,
            'userHandle' => $userHandle,
            'acceptedCredentialIds' => array_map(
                static fn (UserPasskey $passkey): string => $passkey->getCredentialId(),
                $passkeys,
            ),
            'passkeys' => array_map(self::passkey(...), $passkeys),
        ];
    }
```
after:
```php
namespace App\Http;

use App\Entity\UserPasskey;
use App\Service\Passkey\AccountPasskeys;

/**
 * The passkey listing body (#624): the rows plus the three values the WebAuthn
 * Signal API needs (#727), which `register/options` already discloses to the
 * same authenticated user.
 *
 * The two options factories already return their body in its final wire
 * shape — the identical `{options, handle}` for both ceremonies — so they
 * need no mapper here and their controller actions hand the array straight
 * to the response.
 *
 * @phpstan-type PasskeyRow array{id: ?int, label: string, createdAt: string, lastUsedAt: ?string}
 * @phpstan-type PasskeyListingBody array{
 *     rpId: string, userHandle: ?string, acceptedCredentialIds: list<string>, passkeys: list<PasskeyRow>,
 * }
 */
final readonly class PasskeyJson
{
    /**
     * `acceptedCredentialIds` is ONE flat authoritative list the client hands
     * to the browser unchanged: a rebuilt or shortened list deletes valid
     * credentials. The handle comes from PasskeyCredentials::sharedHandle().
     *
     * @return PasskeyListingBody
     */
    public static function listing(AccountPasskeys $account): array
    {
        return [
            'rpId' => $account->relyingPartyId,
            'userHandle' => $account->userHandle,
            'acceptedCredentialIds' => array_map(
                static fn (UserPasskey $passkey): string => $passkey->getCredentialId(),
                $account->passkeys,
            ),
            'passkeys' => array_map(self::passkey(...), $account->passkeys),
        ];
    }
```
The private `passkey()` below is unchanged.

- [ ] **Step 5: Run the mapper test to see it pass.**

Run: `php bin/phpunit tests/Http/PasskeyJsonTest.php`
Expected: PASS.

- [ ] **Step 6: Return the value from the service.** Replace the whole of `src/Service/Passkey/PasskeyListing.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey;

use App\Entity\User;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\PasskeyRelyingParty;

/** One account's passkeys, with the relying party id and shared handle the WebAuthn Signal API needs (#727). */
final readonly class PasskeyListing
{
    public function __construct(
        private UserPasskeyRepository $passkeys,
        private PasskeyCredentials $credentials,
        private PasskeyRelyingParty $relyingParty,
    ) {
    }

    public function forUser(User $user): AccountPasskeys
    {
        $rows = $this->passkeys->findForUser($user);

        return new AccountPasskeys($this->relyingParty->id(), $this->credentials->sharedHandle($rows), $rows);
    }
}
```

- [ ] **Step 7: Wire the controller.**

`src/Controller/Api/PasskeyController.php`, before (line 8):
```php
use App\Entity\User;
```
after:
```php
use App\Entity\User;
use App\Http\PasskeyJson;
```
Before (line 131):
```php
        return new JsonResponse($this->listing->forUser($user), Response::HTTP_CREATED);
```
after:
```php
        return new JsonResponse(PasskeyJson::listing($this->listing->forUser($user)), Response::HTTP_CREATED);
```
Before (line 139):
```php
        return new JsonResponse($this->listing->forUser($user));
```
after:
```php
        return new JsonResponse(PasskeyJson::listing($this->listing->forUser($user)));
```

- [ ] **Step 8: Confirm no service imports the passkey mapper.**

Run: `grep -rn 'PasskeyListingBody from' src tests`
Expected: no output.

- [ ] **Step 9: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/PasskeyJsonTest.php tests/Controller/Api/PasskeyListTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Service/Passkey && composer check && composer md`
Expected: all green.

- [ ] **Step 10: Commit.**

```bash
git add src/Service/Passkey/AccountPasskeys.php src/Service/Passkey/PasskeyListing.php src/Http/PasskeyJson.php \
  src/Controller/Api/PasskeyController.php tests/Http/PasskeyJsonTest.php
git commit -m "refactor(#1158): the passkey listing returns a typed value; the controller maps it"
```

---

### Task 15: `MailDeliveryHealth::recentFailures()`

**Files:**
- Modify: `src/Service/Mail/MailDeliveryHealth.php:7-11, :40-46`
- Modify: `tests/Service/Mail/MailDeliveryHealthTest.php:28-50`
- Modify: `src/Controller/Admin/AdminMailController.php:7-10, :54-58`

**Interfaces:**
- Produces: `MailDeliveryHealth::recentFailures(): array` (`list<MailSendFailure>`), replacing `view()`. `recordFailure()` / `recordSuccess()` are unchanged. `MailDeliveryHealthJson::view(list<MailSendFailure>)` is unchanged.

- [ ] **Step 1: Write the failing test change.** The service test asserts on the rows; the wire shape stays pinned by `AdminMailErrorsControllerTest`.

`tests/Service/Mail/MailDeliveryHealthTest.php`, before (lines 28-50):
```php
    public function testRecordFailurePersistsAViewableRow(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $view = $this->health->view();

        self::assertCount(1, $view['failures']);
        self::assertSame('digest', $view['failures'][0]['kind']);
        self::assertSame('reader@example.test', $view['failures'][0]['recipient']);
        self::assertSame('SMTP is down', $view['failures'][0]['error']);
        self::assertNotEmpty($view['failures'][0]['at']);
    }

    public function testRecordSuccessClearsEveryStoredFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');
        $this->health->recordFailure(MailKind::Account, 'new@example.test', 'relay refused');

        $this->health->recordSuccess();

        self::assertSame([], $this->health->view()['failures']);
        self::assertSame(0, $this->failures->countAll());
    }
```
after:
```php
    public function testRecordFailurePersistsARecentFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $failures = $this->health->recentFailures();

        self::assertCount(1, $failures);
        self::assertSame(MailKind::Digest, $failures[0]->getKind());
        self::assertSame('reader@example.test', $failures[0]->getRecipient());
        self::assertSame('SMTP is down', $failures[0]->getErrorDetail());
    }

    public function testRecordSuccessClearsEveryStoredFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');
        $this->health->recordFailure(MailKind::Account, 'new@example.test', 'relay refused');

        $this->health->recordSuccess();

        self::assertSame([], $this->health->recentFailures());
        self::assertSame(0, $this->failures->countAll());
    }
```

- [ ] **Step 2: Run it to see it fail.**

Run: `php bin/phpunit tests/Service/Mail/MailDeliveryHealthTest.php`
Expected: FAIL, `Call to undefined method App\Service\Mail\MailDeliveryHealth::recentFailures()`.

- [ ] **Step 3: Change the service.**

`src/Service/Mail/MailDeliveryHealth.php`, before (lines 7-11):
```php
use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Http\MailDeliveryHealthJson;
use App\Repository\MailSendFailureRepository;
use App\Service\Clock\NaiveUtcClock;
```
after:
```php
use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Repository\MailSendFailureRepository;
use App\Service\Clock\NaiveUtcClock;
```
Before (lines 40-46, after `recordSuccess()`):
```php
    /**
     * @return array{failures: list<array{kind: string, recipient: string, error: string, at: string}>}
     */
    public function view(): array
    {
        return MailDeliveryHealthJson::view($this->failures->recent(MailSendFailureRepository::RETENTION));
    }
```
after:
```php
    /** @return list<MailSendFailure> newest first */
    public function recentFailures(): array
    {
        return $this->failures->recent(MailSendFailureRepository::RETENTION);
    }
```

- [ ] **Step 4: Run it to see it pass.**

Run: `php bin/phpunit tests/Service/Mail/MailDeliveryHealthTest.php`
Expected: PASS.

- [ ] **Step 5: Wire the controller.**

`src/Controller/Admin/AdminMailController.php`, before (lines 7-10):
```php
use App\Dto\Admin\MailSettingsRequest;
use App\Service\Mail\MailDeliveryHealth;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
```
after:
```php
use App\Dto\Admin\MailSettingsRequest;
use App\Http\MailDeliveryHealthJson;
use App\Service\Mail\MailDeliveryHealth;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
```
Before (lines 54-58):
```php
    #[Route('/errors', name: 'api_admin_mail_errors', methods: ['GET'])]
    public function errors(MailDeliveryHealth $health): JsonResponse
    {
        return new JsonResponse($health->view());
    }
```
after:
```php
    #[Route('/errors', name: 'api_admin_mail_errors', methods: ['GET'])]
    public function errors(MailDeliveryHealth $health): JsonResponse
    {
        return new JsonResponse(MailDeliveryHealthJson::view($health->recentFailures()));
    }
```

- [ ] **Step 6: Run the tests and the gates.**

Run: `php bin/phpunit tests/Service/Mail tests/Controller/Admin/AdminMailErrorsControllerTest.php tests/Controller/Admin/AdminMailControllerTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Mail/MailDeliveryHealth.php tests/Service/Mail/MailDeliveryHealthTest.php \
  src/Controller/Admin/AdminMailController.php
git commit -m "refactor(#1158): mail delivery health returns its failures; the controller maps them"
```

---

### Task 16: Settings overviews (Grafana, Mail, Proxy) — #1159 boundary

Skip this task if Task 9, Step 0 found no `App\Http` import in the three settings services (#1159 landed first, R6); say so in PR 2's body.

**Files:**
- Create: `src/Service/Grafana/GrafanaSettingsOverview.php`, `src/Service/Mail/Settings/MailSettingsOverview.php`
- Modify: `src/Service/Grafana/GrafanaSettings.php:7-13, :46-50`
- Modify: `src/Service/Mail/Settings/MailSettings.php:7-15, :17-24, :36-44`
- Modify: `src/Service/Proxy/ProxySettings.php:7-14, :30-45`
- Modify: `src/Http/Admin/GrafanaSettingsJson.php:7-8, :38-46, :64`
- Modify: `src/Http/Admin/MailSettingsJson.php:7-9, :26-29`
- Modify (whole file): `src/Controller/Admin/AdminGrafanaController.php`, `src/Controller/Admin/AdminMailController.php`, `src/Controller/Admin/AdminProxyController.php`
- Modify: `tests/Http/Admin/GrafanaSettingsJsonTest.php` (import, five `from(` calls, one helper), `tests/Http/Admin/MailSettingsJsonTest.php` (import, three `from(` calls)
- Modify: `tests/Service/Grafana/GrafanaSettingsTest.php` (import, five `view()` calls), `tests/Service/Mail/Settings/MailSettingsTest.php` (import, eleven), `tests/Service/Proxy/ProxySettingsTest.php` (import, five)

**Interfaces:**
- Produces: `GrafanaSettingsOverview(?GrafanaSettingsEntity $settings, GrafanaEnvDefaults $defaults, bool $profilerAvailable)`; `GrafanaSettings::overview(): GrafanaSettingsOverview` replaces `view()`.
- Produces: `MailSettingsOverview(?MailServerSettings $saved, MailConnection $fallback, ?ProxyConfig $proxy)`; `MailSettings::overview(): MailSettingsOverview` replaces `view()`.
- Produces: `ProxySettings::stored(): ?ProxyServerSettings` replaces `view()`; `ProxySettingsJson::from(?ProxyServerSettings)` is unchanged.
- Produces: `GrafanaSettingsJson::from(GrafanaSettingsOverview)`, `MailSettingsJson::from(MailSettingsOverview)`, same output.
- Left for #1159: `update(*SettingsRequest)`, the runtime getters, the non-`final` classes and their test seams.

- [ ] **Step 1: Point the two mapper tests at the new inputs, so they fail.**

`tests/Http/Admin/GrafanaSettingsJsonTest.php`, before (line 11):
```php
use App\Service\Grafana\GrafanaEnvDefaults;
```
after:
```php
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsOverview;
```
Route the five calls (lines 18, 40, 52, 67, 79) through a helper:
```bash
perl -pi -e 's/GrafanaSettingsJson::from\(/self::payloadOf(/' tests/Http/Admin/GrafanaSettingsJsonTest.php
grep -c 'self::payloadOf(' tests/Http/Admin/GrafanaSettingsJsonTest.php
```
Expected count: `5`. For example line 18, before:
```php
        $payload = GrafanaSettingsJson::from(null, $this->defaults(), false);
```
after:
```php
        $payload = self::payloadOf(null, $this->defaults(), false);
```
Add the helper after `defaults()`, before the class's closing brace (old line 95):
```php

    /** @return array<string, mixed> */
    private static function payloadOf(
        ?GrafanaSettings $settings,
        GrafanaEnvDefaults $defaults,
        bool $profilerAvailable,
    ): array {
        return GrafanaSettingsJson::from(new GrafanaSettingsOverview($settings, $defaults, $profilerAvailable));
    }
```
`tests/Http/Admin/MailSettingsJsonTest.php`, before (line 13):
```php
use App\Service\Mail\Settings\MailConnection;
```
after:
```php
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettingsOverview;
```
Wrap the three calls (lines 36, 44, 60):
```bash
perl -pi -e 's/MailSettingsJson::from\(([^()]*)\)/MailSettingsJson::from(new MailSettingsOverview($1))/' tests/Http/Admin/MailSettingsJsonTest.php
grep -c 'MailSettingsJson::from(new MailSettingsOverview(' tests/Http/Admin/MailSettingsJsonTest.php
```
Expected count: `3`. Line 36, before:
```php
        ], MailSettingsJson::from(null, $fallback, null));
```
after:
```php
        ], MailSettingsJson::from(new MailSettingsOverview(null, $fallback, null)));
```

- [ ] **Step 2: Run them to see them fail.**

Run: `php bin/phpunit tests/Http/Admin`
Expected: FAIL, `GrafanaSettingsOverview` and `MailSettingsOverview` not found.

- [ ] **Step 3: Create the two values.**

`src/Service/Grafana/GrafanaSettingsOverview.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;

final readonly class GrafanaSettingsOverview
{
    public function __construct(
        public ?GrafanaSettingsEntity $settings,
        public GrafanaEnvDefaults $defaults,
        public bool $profilerAvailable,
    ) {
    }
}
```
`src/Service/Mail/Settings/MailSettingsOverview.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Service\Fetch\ProxyConfig;

final readonly class MailSettingsOverview
{
    public function __construct(
        public ?MailServerSettings $saved,
        public MailConnection $fallback,
        public ?ProxyConfig $proxy,
    ) {
    }
}
```

- [ ] **Step 4: Make the two mappers take them.**

`src/Http/Admin/GrafanaSettingsJson.php`, before (lines 7-8):
```php
use App\Entity\GrafanaSettings;
use App\Service\Grafana\GrafanaEnvDefaults;
```
after:
```php
use App\Entity\GrafanaSettings;
use App\Service\Grafana\GrafanaSettingsOverview;
```
Before (lines 38-44):
```php
    public static function from(
        ?GrafanaSettings $settings,
        GrafanaEnvDefaults $defaults,
        bool $profilerAvailable,
    ): array {
        $settings ??= new GrafanaSettings();
        $lokiOverride = $settings->getLokiPushUrlOverride();
```
after:
```php
    public static function from(GrafanaSettingsOverview $overview): array
    {
        $settings = $overview->settings ?? new GrafanaSettings();
        $defaults = $overview->defaults;
        $lokiOverride = $settings->getLokiPushUrlOverride();
```
Before (line 64):
```php
            'profilerAvailable' => $profilerAvailable,
```
after:
```php
            'profilerAvailable' => $overview->profilerAvailable,
```
`src/Http/Admin/MailSettingsJson.php`, before (lines 7-9):
```php
use App\Entity\MailServerSettings;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\MailConnection;
```
after:
```php
use App\Service\Mail\Settings\MailSettingsOverview;
```
Before (lines 26-29):
```php
    /** @return MailSettingsPayload */
    public static function from(?MailServerSettings $settings, MailConnection $fallback, ?ProxyConfig $proxy): array
    {
        $connection = $settings?->connection() ?? $fallback;
```
after:
```php
    /** @return MailSettingsPayload */
    public static function from(MailSettingsOverview $overview): array
    {
        $settings = $overview->saved;
        $fallback = $overview->fallback;
        $proxy = $overview->proxy;
        $connection = $settings?->connection() ?? $fallback;
```

- [ ] **Step 5: Run the mapper tests to see them pass.**

Run: `php bin/phpunit tests/Http/Admin`
Expected: PASS.

- [ ] **Step 6: Point the three service tests at the typed reads, so they fail.**

`tests/Service/Grafana/GrafanaSettingsTest.php`, before (line 9):
```php
use App\Repository\GrafanaSettingsRepository;
```
after:
```php
use App\Http\Admin\GrafanaSettingsJson;
use App\Repository\GrafanaSettingsRepository;
```
`tests/Service/Mail/Settings/MailSettingsTest.php`, before (line 10):
```php
use App\Repository\MailServerSettingsRepository;
```
after:
```php
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
```
`tests/Service/Proxy/ProxySettingsTest.php`, before (line 10):
```php
use App\Repository\ProxyServerSettingsRepository;
```
after:
```php
use App\Http\Admin\ProxySettingsJson;
use App\Repository\ProxyServerSettingsRepository;
```
Then compose each read with its mapper, exactly as the controllers will:
```bash
perl -pi -e 's/\$settings->view\(\)/GrafanaSettingsJson::from(\$settings->overview())/g' tests/Service/Grafana/GrafanaSettingsTest.php
perl -pi -e 's/\$this->settings\(\)->view\(\)/MailSettingsJson::from(\$this->settings()->overview())/g' tests/Service/Mail/Settings/MailSettingsTest.php
perl -pi -e 's/\$settings->view\(\)/ProxySettingsJson::from(\$settings->stored())/g' tests/Service/Proxy/ProxySettingsTest.php
grep -c 'GrafanaSettingsJson::from($settings->overview())' tests/Service/Grafana/GrafanaSettingsTest.php
grep -c 'MailSettingsJson::from($this->settings()->overview())' tests/Service/Mail/Settings/MailSettingsTest.php
grep -c 'ProxySettingsJson::from($settings->stored())' tests/Service/Proxy/ProxySettingsTest.php
grep -c 'view()' tests/Service/Grafana/GrafanaSettingsTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Proxy/ProxySettingsTest.php
```
Expected counts: `5`, `11`, `5`, then `0` for each file. For example `GrafanaSettingsTest.php:62`, before:
```php
        self::assertTrue($settings->view()['hasToken']);
```
after:
```php
        self::assertTrue(GrafanaSettingsJson::from($settings->overview())['hasToken']);
```
and `MailSettingsTest.php:208`, before:
```php
        self::assertSame('smtp.moved.test', $this->settings()->view()['host']);
```
after:
```php
        self::assertSame('smtp.moved.test', MailSettingsJson::from($this->settings()->overview())['host']);
```

- [ ] **Step 7: Run them to see them fail.**

Run: `php bin/phpunit tests/Service/Grafana/GrafanaSettingsTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Proxy/ProxySettingsTest.php`
Expected: FAIL, `Call to undefined method …::overview()` / `::stored()`.

- [ ] **Step 8: Replace the three `view()` methods.**

`src/Service/Grafana/GrafanaSettings.php`, before (lines 7-13):
```php
use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Http\Admin\GrafanaSettingsJson;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;
```
Before (lines 46-50):
```php
    /** @return array<string, mixed> */
    public function view(): array
    {
        return GrafanaSettingsJson::from($this->settings(), $this->defaults, $this->sampler->isAvailable());
    }
```
after:
```php
    public function overview(): GrafanaSettingsOverview
    {
        return new GrafanaSettingsOverview($this->settings(), $this->defaults, $this->sampler->isAvailable());
    }
```
`src/Service/Mail/Settings/MailSettings.php`, before (lines 7-15):
```php
use App\Dto\Admin\MailSettingsRequest;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Dto\Admin\MailSettingsRequest;
use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;
```
Before (lines 17-24):
```php
/**
 * Reads and writes the instance-wide mail row, defaulting to "not configured"
 * when no row exists. The rest of the app depends on this, never on the entity
 * directly, so "no row yet", the sealing, and the DB-or-env resolution all live
 * in one place.
 *
 * @phpstan-import-type MailSettingsPayload from MailSettingsJson
 */
```
after:
```php
/**
 * Reads and writes the instance-wide mail row, defaulting to "not configured"
 * when no row exists. The rest of the app depends on this, never on the entity
 * directly, so "no row yet", the sealing, and the DB-or-env resolution all live
 * in one place.
 */
```
Before (lines 36-44):
```php
    /** @return MailSettingsPayload */
    public function view(): array
    {
        return MailSettingsJson::from(
            $this->repository->findSingleton(),
            $this->fallback->connection(),
            $this->proxySettings->configuredProxy(),
        );
    }
```
after:
```php
    public function overview(): MailSettingsOverview
    {
        return new MailSettingsOverview(
            $this->repository->findSingleton(),
            $this->fallback->connection(),
            $this->proxySettings->configuredProxy(),
        );
    }
```
`src/Service/Proxy/ProxySettings.php`, before (lines 7-14):
```php
use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Http\Admin\ProxySettingsJson;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;
```
Before (lines 30-45):
```php
    /**
     * @return array{
     *     enabled: bool,
     *     directFallback: bool,
     *     type: string,
     *     host: string,
     *     port: int,
     *     username: string|null,
     *     remoteDns: bool,
     *     hasPassword: bool,
     * }
     */
    public function view(): array
    {
        return ProxySettingsJson::from($this->repository->findSingleton());
    }
```
after:
```php
    public function stored(): ?ProxyServerSettings
    {
        return $this->repository->findSingleton();
    }
```

- [ ] **Step 9: Run the service tests to see them pass.**

Run: `php bin/phpunit tests/Service/Grafana tests/Service/Mail/Settings tests/Service/Proxy`
Expected: PASS.

- [ ] **Step 10: Wire the three admin controllers.**

Replace the whole of `src/Controller/Admin/AdminGrafanaController.php` with:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Grafana\GrafanaSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/grafana')]
final readonly class AdminGrafanaController
{
    public function __construct(private GrafanaSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_grafana_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(GrafanaSettingsJson::from($this->settings->overview()));
    }

    #[Route('', name: 'api_admin_grafana_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] GrafanaSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse(GrafanaSettingsJson::from($this->settings->overview()));
    }
}
```
Replace the whole of `src/Controller/Admin/AdminMailController.php` (it already carries Task 15's `errors()` change) with:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\MailSettingsRequest;
use App\Http\Admin\MailSettingsJson;
use App\Http\MailDeliveryHealthJson;
use App\Service\Mail\MailDeliveryHealth;
use App\Service\Mail\Settings\MailConnectionTester;
use App\Service\Mail\Settings\MailSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/mail')]
final readonly class AdminMailController
{
    public function __construct(private MailSettings $settings)
    {
    }

    #[Route('', name: 'api_admin_mail_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(MailSettingsJson::from($this->settings->overview()));
    }

    #[Route('', name: 'api_admin_mail_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] MailSettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse(MailSettingsJson::from($this->settings->overview()));
    }

    #[Route('/test', name: 'api_admin_mail_test', methods: ['POST'])]
    public function test(MailConnectionTester $tester): JsonResponse
    {
        return new JsonResponse($tester->test()->toArray());
    }

    #[Route('/reset', name: 'api_admin_mail_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        $this->settings->resetToEnvironment();

        return new JsonResponse(MailSettingsJson::from($this->settings->overview()));
    }

    #[Route('/errors', name: 'api_admin_mail_errors', methods: ['GET'])]
    public function errors(MailDeliveryHealth $health): JsonResponse
    {
        return new JsonResponse(MailDeliveryHealthJson::view($health->recentFailures()));
    }
}
```
Replace the whole of `src/Controller/Admin/AdminProxyController.php` with:
```php
<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Admin\ProxySettingsRequest;
use App\Http\Admin\ProxySettingsJson;
use App\Service\Proxy\ProxyConnectionTester;
use App\Service\Proxy\ProxySettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ROLE_ADMIN is enforced by the `^/api/admin/` prefix rule in security.yaml,
 * not by a per-action attribute here.
 */
#[Route('/api/admin/proxy')]
final readonly class AdminProxyController
{
    public function __construct(private ProxySettings $settings)
    {
    }

    #[Route('', name: 'api_admin_proxy_get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(ProxySettingsJson::from($this->settings->stored()));
    }

    #[Route('', name: 'api_admin_proxy_update', methods: ['PUT'])]
    public function update(#[MapRequestPayload] ProxySettingsRequest $request): JsonResponse
    {
        $this->settings->update($request);

        return new JsonResponse(ProxySettingsJson::from($this->settings->stored()));
    }

    #[Route('/test', name: 'api_admin_proxy_test', methods: ['POST'])]
    public function test(ProxyConnectionTester $tester): JsonResponse
    {
        return new JsonResponse($tester->test()->toArray());
    }
}
```

- [ ] **Step 11: Confirm no `view()` is left on the three services or their callers.**

Run: `grep -rnE '(settings|health)->view\(\)|MailSettingsPayload from' src tests`
Expected: no output.

- [ ] **Step 12: Run the tests and the gates.**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/Admin tests/Service/Grafana tests/Service/Mail tests/Service/Proxy tests/Service/Fetch tests/Controller/Admin/AdminGrafanaControllerTest.php tests/Controller/Admin/AdminMailControllerTest.php tests/Controller/Admin/AdminMailErrorsControllerTest.php tests/Controller/Admin/AdminProxyControllerTest.php && composer check && composer md`
Expected: all green.

- [ ] **Step 13: Commit.**

```bash
git add src/Service/Grafana src/Service/Mail/Settings src/Service/Proxy/ProxySettings.php src/Http/Admin \
  src/Controller/Admin/AdminGrafanaController.php src/Controller/Admin/AdminMailController.php \
  src/Controller/Admin/AdminProxyController.php tests/Http/Admin tests/Service/Grafana tests/Service/Mail/Settings \
  tests/Service/Proxy/ProxySettingsTest.php
git commit -m "refactor(#1158): admin settings services return typed overviews; the controllers map them"
```

---

### Task 17: Rule, step 2: `App\Http` forbidden in services too; CLAUDE.md

**Files:**
- Modify (whole file): `tests/PhpStan/DomainKnowsNoHttpRule.php`
- Modify (whole file): `tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
- Modify: `CLAUDE.md:58` (layout table) and after `CLAUDE.md:91` (one bullet)

**Interfaces:**
- Produces: the final rule. In every domain namespace it forbids `App\Http\*`, `Symfony\Component\HttpFoundation\*`, `Symfony\Component\HttpKernel\Exception\*` and `Symfony\Component\Security\Core\Exception\AccessDeniedException`, in code and in strings. The `App\Service` carve-out from Task 8 is gone. Message and identifier unchanged from Task 8.

- [ ] **Step 1: Confirm the tree is ready for the carve-out to go.**

Run: `grep -rnE '^use App\\Http\\' src/Service src/Repository src/Entity src/Enum src/Exception src/Pagination`
Expected: no output. Anything listed is a service Tasks 9-16 missed; fix it before continuing.

- [ ] **Step 2: Add the three service expectations to the test.** Replace the whole of `tests/PhpStan/DomainKnowsNoHttpRuleTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<DomainKnowsNoHttpRule> */
final class DomainKnowsNoHttpRuleTest extends RuleTestCase
{
    private const string SERVICE = 'App\Service\Fixtures';
    private const string SERVICE_EXCEPTION = 'App\Service\Fixtures\Exception';
    private const string REPOSITORY = 'App\Repository\Fixtures';
    private const string PAGINATION = 'App\Pagination\Fixtures';
    private const string FOUNDATION = 'Symfony\Component\HttpFoundation\\';
    private const string HTTP_KERNEL = 'Symfony\Component\HttpKernel\Exception\\';
    private const string ACCESS_DENIED = 'Symfony\Component\Security\Core\Exception\AccessDeniedException';
    private const string API_PROBLEM = 'App\Http\Problem\ApiProblem';
    private const string FEED_JSON = 'App\Http\RecommendationFeedJson';

    protected function getRule(): Rule
    {
        return new DomainKnowsNoHttpRule(new NodeFinder());
    }

    public function testItReportsHttpInDomainCodeButNotInTheHttpLayer(): void
    {
        $this->analyse(
            [__DIR__ . '/data/domain-knows-no-http-fixtures.php'],
            [
                [self::message(self::SERVICE, self::FEED_JSON), 9],
                [self::message(self::SERVICE, self::FOUNDATION . 'Request'), 10],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 11],
                [self::message(self::SERVICE, self::HTTP_KERNEL . 'NotFoundHttpException'), 12],
                [self::message(self::SERVICE, self::HTTP_KERNEL . 'NotFoundHttpException'), 18],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 23],
                [self::message(self::SERVICE, self::FOUNDATION . 'Request'), 26],
                [self::message(self::SERVICE, self::FEED_JSON), 33],
                [self::message(self::SERVICE, self::FEED_JSON), 38],
                [self::message(self::SERVICE, self::FOUNDATION . 'Response'), 43],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 49],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 50],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 51],
                [self::message(self::SERVICE_EXCEPTION, self::API_PROBLEM), 55],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 60],
                [self::message(self::SERVICE_EXCEPTION, self::FOUNDATION . 'Exception\BadRequestException'), 62],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 65],
                [self::message(self::SERVICE_EXCEPTION, self::ACCESS_DENIED), 67],
                [self::message(self::REPOSITORY, self::HTTP_KERNEL . 'BadRequestHttpException'), 77],
                [self::message(self::REPOSITORY, 'App\Http\EntryCursor'), 82],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 88],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 92],
                [self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 94],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).',
            $namespaceName,
            $reference,
        );
    }
}
```
The fixtures file from Task 8 is unchanged.

- [ ] **Step 3: Run it to see it fail.**

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: FAIL; the rule still skips lines 9, 33 and 38.

- [ ] **Step 4: Delete the carve-out.** Replace the whole of `tests/PhpStan/DomainKnowsNoHttpRule.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code returns typed values and throws typed exceptions; src/Http shapes and maps them (#1158).
 * Class names inside strings count too, so a string-built reference cannot slip past.
 *
 * @implements Rule<FileNode>
 */
final readonly class DomainKnowsNoHttpRule implements Rule
{
    private const array DOMAIN_NAMESPACES = [
        'App\\Pagination\\',
        'App\\Service\\',
        'App\\Repository\\',
        'App\\Entity\\',
        'App\\Enum\\',
        'App\\Exception\\',
    ];

    private const array HTTP_PREFIXES = [
        'App\\Http\\',
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\Exception\\',
    ];

    private const array HTTP_CLASSES = [
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
    ];

    public function __construct(private NodeFinder $finder)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->finder->findInstanceOf($node->getNodes(), Namespace_::class) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!self::isDomain($namespaceName)) {
            return [];
        }

        $errors = [];
        foreach ($this->references($namespace) as [$reference, $line]) {
            if (self::isHttp($reference)) {
                $errors[] = self::error($namespaceName, $reference, $line);
            }
        }

        return $errors;
    }

    /** @return list<array{string, int}> every class name mentioned, in code or in a string, with its line */
    private function references(Namespace_ $namespace): array
    {
        $references = [];
        foreach ($this->finder->findInstanceOf($namespace->stmts, Name::class) as $name) {
            $references[] = [$name->toString(), $name->getStartLine()];
        }
        foreach ($this->finder->findInstanceOf($namespace->stmts, String_::class) as $string) {
            $references[] = [ltrim($string->value, '\\'), $string->getStartLine()];
        }

        return $references;
    }

    private static function isDomain(string $namespaceName): bool
    {
        return self::startsWithAny($namespaceName . '\\', self::DOMAIN_NAMESPACES);
    }

    private static function isHttp(string $reference): bool
    {
        return \in_array($reference, self::HTTP_CLASSES, true)
            || self::startsWithAny($reference, self::HTTP_PREFIXES);
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $subject, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function error(string $namespaceName, string $reference, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Domain code must not know HTTP: %s references %s. '
            . 'Return a typed value or throw a typed exception, and let src/Http shape it (#1158).',
            $namespaceName,
            $reference,
        ))
            ->identifier('simpleFeedReader.domainKnowsNoHttp')
            ->line($line)
            ->build();
    }
}
```

- [ ] **Step 5: Run the rule test to see it pass, then the rule over the tree.**

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php && bin/console cache:clear && composer stan`
Expected: PASS, then green. A `simpleFeedReader.domainKnowsNoHttp` error is a leak the earlier tasks missed: fix it in its module.

- [ ] **Step 6: Break what the rule guards, then restore it by hand.** Add `use App\Http\MeJson;` below the `namespace` line of `src/Service/Ai/AiReadiness.php` and run `composer stan`: expect exactly one `simpleFeedReader.domainKnowsNoHttp` error naming `App\Service\Ai` and `App\Http\MeJson`. Delete the line with the editor (not `git checkout --`), then `git diff --exit-code src/Service/Ai/AiReadiness.php` (expected: exit 0) and `composer stan` (expected: green).

- [ ] **Step 7: Run the final sweep.**

Run:
```bash
grep -rnE 'App\\Http\\|Symfony\\Component\\HttpFoundation|HttpKernel\\Exception|AccessDeniedException' \
  src/Service src/Repository src/Entity src/Enum src/Exception src/Pagination | grep -vE ':[0-9]+:[[:space:]]*(\*|/\*\*|//)'
```
Expected: no output. Comment lines are filtered out, as the rule ignores comments too.

- [ ] **Step 8: Write the rule into CLAUDE.md** (R10: approved; `Pagination` replaces the draft's `Domain`).

`CLAUDE.md`, before (line 58):
```markdown
| `backend/src/Dto/**` | Request/response shapes, grouped by feature |
```
after:
```markdown
| `backend/src/Dto/**` | Inbound request shapes, grouped by feature |
| `backend/src/Http/**` | Outbound response shapes (`*Json` mappers), problem mapping, and the helpers that read a `Request` or build a `Response` |
| `backend/src/Pagination/**` | The keyset cursors repositories, services and `src/Http` share |
```
Before (lines 88-91):
```markdown
- **Errors are exceptions**, typed and namespaced next to their service
  (`Service/*/Exception/`). Never signal failure with `null` or a magic value.
  Map a new one to HTTP by adding an arm to its module's `src/Http/Problem/*Problems`
  mapper; domain code never imports HTTP classes (`DomainKnowsNoHttpRule`).
```
after:
```markdown
- **Errors are exceptions**, typed and namespaced next to their service
  (`Service/*/Exception/`). Never signal failure with `null` or a magic value.
  Map a new one to HTTP by adding an arm to its module's `src/Http/Problem/*Problems`
  mapper; domain code never imports HTTP classes (`DomainKnowsNoHttpRule`).
- **Services return typed values; `src/Http` shapes them.** A service or repository
  never builds a JSON array, reads a `Request` or returns a `Response`: it returns a
  `final readonly` value, and the controller hands that to an `src/Http/*Json` mapper.
  `DomainKnowsNoHttpRule` forbids `App\Http\*` and Symfony's HTTP classes, class
  names in strings included, in `Service`, `Repository`, `Entity`, `Enum`, `Exception`
  and `Pagination`.
```

- [ ] **Step 9: Run the gates.**

Run: `composer check && composer md`
Expected: green.

- [ ] **Step 10: Commit.**

```bash
git add tests/PhpStan/DomainKnowsNoHttpRule.php tests/PhpStan/DomainKnowsNoHttpRuleTest.php ../CLAUDE.md
git commit -m "refactor(#1158): no domain namespace may reference App\Http; CLAUDE.md states the layer rule"
```

---

## Finishing

### PR 1 (after Task 8)

1. **Run the branch-wide gates, all green:**
   - `composer check`, `composer md`
   - `php bin/phpunit` (SQLite)
   - `docker compose exec php composer test` (MySQL). First confirm the container sees this branch: `docker compose exec php test -f src/Pagination/EntryCursor.php && echo current` must print `current`, and run `docker compose exec php bin/console cache:clear` so the container is not serving a stale DI graph (moved services).
   - `composer infection:diff`, after the last commit (untracked files are invisible to it).
   - Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. No new deprecation or error.
2. **SDD final whole-branch review** (not optional). Ask the reviewer to attack:
   - **Wire contract:** `git diff origin/develop --stat -- tests/Controller tests/E2e` is empty, and every `tests/Http` change constructs a new input without changing an assertion.
   - **Moves are moves:** `git diff -M origin/develop --summary` shows each relocated class as a rename.
   - **Leniency kept:** a garbled For You cursor still restarts the feed; a garbled entry or search cursor is still a 422.
   - **Rate-limit keys:** `enforceForClient()` still keys on exactly `Request::getClientIp()` (null included) at all six sites.
   - **The rule:** Task 8's break test reported; the `App\Service` carve-out applies only outside exception namespaces.
   - **Controllers:** every changed controller line calls only `get*`, `is*`, `has*` or `requireId()` on a mapped class, and no controller gains a private or protected method.
3. **Run `/simplify`** over the branch diff; re-run step 1's gates if it changed anything.
4. **Write the PR body and check it and the commits for closing keywords (R1).** Save this as `/tmp/pr-1158-1.md` (outside the repository):

   ```markdown
   Refs #1158

   First half of #1158: domain code no longer touches Symfony's HTTP layer.

   - "AI is ready" moved from `Http/AiSettingsJson::isReady()` to `Service/Ai/AiReadiness::of()`; the scheduler and the run starter no longer import a JSON mapper.
   - `EntryCursor`, `RecommendationCursor` and `MalformedCursorException` moved to the new `App\Pagination` namespace; repositories no longer import `App\Http`. The For You feed still treats a garbled cursor as none.
   - Request/response helpers moved into `App\Http`: `MaintenanceTokenGuard`, `BackupDownloadResponseFactory`, `EntrySearchRequestFactory`, and `OAuth\{FlowCookie, OAuthRedirectFactory, CallbackParameters}`.
   - `RateLimitGuard::enforceForClient()` takes the client IP; `ServingHost` is an interface implemented by `Http\RequestServingHost`; `HtmlPageFetcher` asks `StatusReasonPhrases` (implemented by `Http\SymfonyStatusReasonPhrases`).
   - `DomainKnowsNoHttpRule` now covers `App\Pagination` and forbids all of `HttpFoundation`, `HttpKernel\Exception` and Security's `AccessDeniedException` everywhere in domain code, class names in strings included. `App\Http` stays allowed in services until the second PR.

   No wire-contract change: no controller or e2e test was edited.
   ```
   Run:
   ```bash
   KEYWORDS='\b(close[sd]?|fix(e[sd])?|resolve[sd]?)\b'
   grep -niE "$KEYWORDS" /tmp/pr-1158-1.md
   git log --format='%s%n%b' origin/develop..HEAD | grep -niE "$KEYWORDS"
   ```
   Expected: no output from either. A hit is reworded before the PR is opened (for a commit, stop and ask Lars rather than rewrite history).
5. **Open the PR against `develop`:**

   Run: `gh pr create --base develop --head refactor/1158-presentation-out-of-services --title "refactor(#1158): domain code no longer touches Symfony HTTP (part 1 of 2)" --body-file /tmp/pr-1158-1.md`
6. **Merge when CI is green, then confirm #1158 is still open.** Arm a Monitor that polls `gh pr checks refactor/1158-presentation-out-of-services` until every check has passed, then run `gh pr merge refactor/1158-presentation-out-of-services --merge`. Never `--auto` (it merges immediately here). If a check fails, stop and report; if phptramp fails, read `composer show larspohlmann/phptramp` before blaming the diff. After the merge, run `gh issue view 1158 --json state -q .state`. Expected: `OPEN`. If it reads `CLOSED`, stop and tell Lars; do not reopen it or start PR 2.

### PR 2 (after Task 17)

1. **Run the branch-wide gates, all green:**
   - `composer check`, `composer md`
   - `php bin/phpunit` (SQLite)
   - `docker compose exec php composer test` (MySQL). First confirm the container sees this branch: `docker compose exec php test -f src/Service/Recommendation/ForYouFeed.php && echo current` must print `current`, and run `docker compose exec php bin/console cache:clear` (renamed services).
   - `composer infection:diff`, after the last commit.
   - Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. No new deprecation or error.
2. **SDD final whole-branch review.** Attack:
   - **Wire contract:** `git diff origin/develop --stat -- tests/Controller tests/E2e` is empty; every `tests/Http` change constructs a new input without changing an assertion; and every renamed service's controller now calls exactly the mapper the service used to call, with the same arguments in the same order.
   - **Status clock:** `elapsedSeconds` is computed from the resolver's single `now()`, as before.
   - **#1159 boundary:** the three settings services changed only `view()`; `update()`, the runtime getters and the class declarations are untouched.
   - **The rule:** Task 17's break test reported; the fixtures prove `App\Http` in a service is now an error; no allow-list exists.
   - **Controllers:** every changed controller line calls only `get*`, `is*`, `has*` or `requireId()` on a mapped class, and no controller gains a private or protected method.
3. **Run `/simplify`**; re-run the gates if it changed anything.
4. **Write the PR body and check its closing keywords (R1).** Save this as `/tmp/pr-1158-2.md`:

   ```markdown
   Closes #1158

   Second half of #1158: services return typed values and controllers call the `Http/*Json` mappers.

   - `ForYouFeed` → `ForYouFeedPage`, `RecommendationRunStatusResolver` → `RecommendationRunStatus`, `RecommendationRunHistory` → `RunHistoryOverview` / `RunHistoryMonthPage`, `RecommendationDebugLogLoader` → `RecommendationDebugLog`, `ReadingActivityCounter` → `ReadingActivity`, `PasskeyListing` → `AccountPasskeys`, `MailDeliveryHealth::recentFailures()`.
   - The admin Grafana, mail and proxy settings services return `GrafanaSettingsOverview`, `MailSettingsOverview` and the stored proxy row; the admin/runtime split stays with #1159.
   - `DomainKnowsNoHttpRule` forbids `App\Http` in every domain namespace, services included; CLAUDE.md states the layer rule.

   No wire-contract change: no controller or e2e test was edited.

   Follow-up: #1182
   ```
   If Task 16 was skipped (R6), the second bullet reads instead: `- Task 16 skipped: #1159 already removed the JSON mappers from the admin Grafana, mail and proxy settings services.`

   Run:
   ```bash
   KEYWORDS='\b(close[sd]?|fix(e[sd])?|resolve[sd]?)\b'
   grep -niE "$KEYWORDS" /tmp/pr-1158-2.md
   git log --format='%s%n%b' origin/develop..HEAD | grep -niE "$KEYWORDS"
   ```
   Expected: exactly one hit, `1:Closes #1158`, from the first command, and none from the second.
5. **Open the PR against `develop`:**

   Run: `gh pr create --base develop --head refactor/1158-presentation-out-of-services-2 --title "refactor(#1158): services return typed values; controllers map them (part 2 of 2)" --body-file /tmp/pr-1158-2.md`
6. **Merge when CI is green.** Arm a Monitor that polls `gh pr checks refactor/1158-presentation-out-of-services-2` until every check has passed, then run `gh pr merge refactor/1158-presentation-out-of-services-2 --merge`. Never `--auto`. If a check fails, stop and report; if phptramp fails, read `composer show larspohlmann/phptramp` before blaming the diff. After the merge, confirm #1158 closed: `gh issue view 1158 --json state -q .state` reads `CLOSED` (do not close it by hand).

---

## Appendix C: what #1158 leaves to #1182

Request DTOs taken by services, `toArray()` on service values, and the shared value types and enums without a home are tracked in #1182 (`gh issue view 1182`), which holds the inventory; nothing here implements them.

## Execution rulings (PR 1)

Made during execution, on the planner's pre-flight scan and the reviews. Each: what was decided, why, what it costs if wrong.

- **F1:** Task 0 Step 6's `git switch -c` was skipped, because the branch was already cut with the plan commit and ancestry was verified instead. Cost if wrong: none.
- **F2:** `AiReadinessTest` builds settings through `tests/Support/AiProviderSettingsFactory::build()`, since the helper already existed (DRY).
- **F3:** Task 8's fixture names `\App\Http\EntryPage::class`, not the deleted `\App\Http\EntryCursor::class`. Task 17 inherits this.
- **F4:** Task 8's negative fixture namespace is `App\Repository\Fixtures\Clean`, so the clean case proves something while the Service carve-out exists. Line numbers are unchanged.
- **F5:** `src/Http/Exception/` was removed once Task 2 emptied it.
- **F6:** The docblocks the plan edits (`RateLimitGuard::enforceForClient`, the `AiSettingsJson` class) were trimmed to at most 3 lines, per CLAUDE.md's comment rule. The `AiReadiness::of()` docblock states the verifiedAt invariant in 2 lines.
- **F7:** The ≤3-parameter rule applies to methods, not DI constructors, so `HtmlPageFetcher` takes 5 constructor arguments.
- **F8:** `RequestServingHost` is a split, not a rename. Its body is the old `ServingHost` verbatim.
- **F9:** Task 8's real-tree break test ran three breaks, each restored by hand: HttpFoundation in `AiReadiness`, `App\Http` in `Pagination/EntryCursor`, and a Symfony class name in a string.
- **F10:** Rewritten `use` blocks stay alphabetical.
- **Batching:** Tasks 3 and 4 ran as one batch (two commits, one review), since both were pure moves.
- **Final review and /simplify, taken:**
  - `DomainKnowsNoHttpRule::references()` walks each namespace once, not twice.
  - A null-IP `RateLimitGuard` test was added.
  - `tests/Http/RequestServingHostTest` covers all three branches.
- **Final review and /simplify, not taken:**
  - Folding `AccessDeniedException` into the prefix list. It would widen the rule past R3.
  - Owning the reason-phrase table as a constant. R4 approved the seam, and a copied table would duplicate Symfony's.
  - Dropping `readonly` from `AiReadiness`. The Global Constraints mandate `final readonly`.
  - The duplicated `SERVICES` constant. Task 17 deletes the carve-out.
- **Deferred:** `SetupController::createAdmin`'s rate limit has no test. Infection's only escape is removing that `enforceForClient` call. The gap existed before this plan, and a test would live under `tests/Controller`, which both PRs keep frozen. It goes to a follow-up.
- **Deferred to Task 17 / #1182:** rule gaps that no code in `src` uses today:
  - group-`use` imports and namespace-alias imports (only unused or docblock-only ones escape);
  - case-insensitive and interpolated class strings;
  - an `*\Exceptions` segment, which the carve-out would miss (Task 17 deletes the carve-out);
  - a `Security\Core\User\*` negative line in the fixture.

## Execution rulings (PR 2)

- **P1–P2:** The branch was already cut, and EntryController's imports were already sorted by PR 1. Only `RecommendationFeedJson` was inserted.
- **P3–P5:** Task 17 edited the rule in place and kept PR 1's one-pass `references()`. The fixture keeps `\App\Http\EntryPage` (F3). A `Symfony\Component\Security\Core\User\UserInterface` negative was added at fixture line 118 and used in a signature, and no asserted line moved.
- **P6:** Rule gaps are left to #1182: group-use and alias imports, case-insensitive and interpolated strings.
- **P7–P8 (planner-accepted):** The Dto layout row stays "Request/response shapes". CLAUDE.md's new bullet states only what `DomainKnowsNoHttpRule` enforces. The drafted sentence "a service returns a final readonly value and the controller hands it to a mapper" was dropped, because six controllers still serialise `toArray()` values until #1182. The older "Errors are exceptions" bullet no longer repeats the rule.
- **P10:** `tests/Http/MailDeliveryHealthJsonTest` pins the `error` and `at` keys.
- **P11:** The `DebugLogRow` shape is declared once, on `RecommendationRunLogRepository`, which produces it. The value object and tests import it.
- **P12:** Docblocks this PR edits or rewrites stay at 3 lines or fewer. Moved docblocks stay verbatim.
- **P13:** No test helper takes a bool flag. The settings tests read the mapped view through one small `view()` / `viewOf()` helper per file.
- **P14:** `ForYouFeedPage` stays a separate type from `RecommendationFeedPage`. It carries enriched rows plus the annotation visibility, for a different consumer.
- **P16:** `ProxySettings::stored()` hands out the entity. That belongs to #1159.
- **P17:** Four `tests/Service` files import `App\Http` mappers. Tests are outside the rule's scope.
- **AutowireWrongClass:** The Symfony plugin flags any constructor parameter typed with a Doctrine-mapped class. The three new value objects that carry an entity (`RecommendationDebugLog`, `GrafanaSettingsOverview`, `MailSettingsOverview`) suppress it with `@noinspection AutowireWrongClass` and a one-line reason. Every PHPStan-clean alternative failed level max. The three older bare occurrences (SubscribeOutcome, SavedSearchOutcome, AddedConfiguration) are in the #1182 plan.
- **Not taken:**
  - A bool-flag test factory for `ForYouFeedPage`, because it is banned.
  - Snapshotting the settings entities into plain values, because it needs entity read-side getters and that is #1159's scope.
  - Tightening the nullable `GrafanaSettingsOverview::$settings`, also #1159's scope.
