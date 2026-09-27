# Finish the Layering: Request DTOs, `toArray()` and Shared Value Homes (#1182) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1182 in two PRs. No service takes a request DTO, no service builds a response array, and every value or enum that crosses a layer has one home.
- **PR A** (Tasks A0–A12, `Refs #1182`): issue bullet 1. Every service that took an `App\Dto` request takes a `Service/<Module>` value instead, the three non-request types filed under `Dto` (plus `UserFootprint`) move into their services, and `DomainKnowsNoHttpRule` forbids `App\Dto\` and loses its four known gaps.
- **PR B** (Tasks B0–B9, `Closes #1182`): issue bullets 2 and 3. Every wire shape a service built moves into a `src/Http/*Json` mapper, a serialiser that feeds a non-HTTP store is named after that store, a PHPStan rule stops `toArray()` coming back, the three bare `AutowireWrongClass` warnings are suppressed, and the shared values and enums move to the layer below their users, guarded by a second rule.

**Architecture:**
- **Request DTO → service value (PR A).** The DTO converts itself, following `InstanceSettingsRequest::toUpdate()` and #1159's `toUpdate()` methods. Each service value mirrors its DTO's constructor (names, order, defaults) without the validation attributes, so every test that built the DTO migrates with a class rename. A DTO that carried a single field, or a list of ids, is not wrapped: the controller passes `$request->field`.
- **Moves (PR A).** `OAuthIdentity`, `OAuthStartState`, `OAuthCallbackAttempt` → `App\Service\OAuth`; `PendingApprovalNotice` → `App\Service\Mail`; `UserFootprint` → `App\Service\Admin`. Each keeps its code; its comments are brought to the CLAUDE.md bar (D4, ruled). Nothing in `IdToken*` changes.
- **Mappers (PR B).** One `src/Http/*Json` mapper per wire shape: `MailTestResultJson`, `ProxyTestResultJson`, `AltchaChallengeJson`, `RefreshReportJson`, `ForYouSweepReportJson`, `MaintenanceTickJson`. `MaintenanceTickReport` becomes typed (`RefreshReport`, `MaintenanceSweeps`, `LokiSpoolReport`) instead of six pre-serialised arrays. `RefreshJson` and `RecommendationRunStatusJson` stop calling a service's `toArray()`.
- **Stores keep a serialiser, named after the store (PR B).** `toLogContext()` (worker log lines), `toCacheEntry()`/`fromCacheEntryOrNull()` (Grafana cache), `toFindingsFileRecord()`/`fromFindingsFileRecord()` (reader-audit JSONL file). `RefreshRunProgress` loses its serialiser: its store builds the record itself.
- **Value homes (PR B).** A value or enum that crosses a layer lives at the lowest layer that uses it: `App\Entity` for what an entity stores, embeds or returns, `App\Enum` for enums used by more than one layer, `App\Doctrine` for what the ORM extensions share. Module-private enums stay in their `Service/*` module. `docs/architecture.md` §8 records it.
- **Rules.** `DomainKnowsNoHttpRule` gains `App\Dto\` and closes group-use, namespace-alias, case-insensitive and interpolated references (PR A). PR B extracts the reference collection into `ClassNameReferences`, adds `NoToArrayInServicesRule` and `PersistenceKnowsNoServiceRule`.

**Tech Stack:** PHP 8.4, Symfony 7.4 (`#[MapRequestPayload]`, autowiring over `src/`), Doctrine ORM, PHPUnit 12, PHPStan 2.2 at level max with nikic/php-parser 5.8 and the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection 0.34.

**Spec:**
- GitHub issue #1182 (`gh issue view 1182 --comments`). The comment adds eight services: `AccountPreferencesWriter`, `CatalogCategoryEditor`, `CatalogFeedEditor`, `OAuthCallback`, `SavedSearchEditor`, `SubscriptionEditor`, `TagEditor`, `TagOrdering`.
- Planner carry-forward rulings for #1182:
  1. Close the `DomainKnowsNoHttpRule` gaps no `src` code uses yet (group-use and namespace-alias imports; case-insensitive and interpolated class-name strings), each with a fixture line that fails before the fix.
  2. Suppress the three bare PhpStorm `AutowireWrongClass` WARNINGs (`SubscribeOutcome.php:18`, `SavedSearchOutcome.php:12`, `AddedConfiguration.php:19`) the way `RecommendationDebugLog` does.
  3. Move the service `toArray()` wire shapes into `src/Http/*Json` mappers, byte-identical, pinned by the existing controller tests, which stay unedited.
  4. Once `toArray()` is gone, tighten the CLAUDE.md layer bullet: services build no JSON arrays; enforce it with a cheap PHPStan rule.
- CLAUDE.md "PHP code style — Clean Code is mandatory"; `docs/architecture.md` §6 (native-client checklist) and §7 (queries live in repositories).

## Status

Reconciled against a124ad8a (2026-09-27). #1159, #1164 Part B, #1167 and #1168 have all landed; every task below was checked against that SHA (see "Reconcile changes (a124ad8a)").

| Task | State |
|---|---|
| A0: Preflight | ⬜ |
| A1: `EntryStateChange` | ⬜ |
| A2: `BulkSubscriptionChange` | ⬜ |
| A3: `SubscriptionChange`, `TagMove`, subscription reorder ids | ⬜ |
| A4: `DigestConfiguration`; preference writes take plain values | ⬜ |
| A5: `PasskeyAttestation` | ⬜ |
| A6: `RelyingPartyIdChoice` | ⬜ |
| A7: `ClientError` | ⬜ |
| A8: `CatalogCategoryDetails`, `CatalogFeedDetails`, catalog reorder ids | ⬜ |
| A9: `SavedSearchDefinition`; digest inclusion takes a plain value | ⬜ |
| A10: `TagDetails`; tag ordering takes ids | ⬜ |
| A11: The types misfiled under `Dto` move into their services | ⬜ |
| A12: `DomainKnowsNoHttpRule` forbids `App\Dto\` and loses four gaps | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: `MailTestResultJson`, `ProxyTestResultJson` | ⬜ |
| B2: `AltchaChallengeJson` | ⬜ |
| B3: Maintenance responses: `RefreshReportJson`, `ForYouSweepReportJson`, typed `MaintenanceTickJson` | ⬜ |
| B4: `RefreshJson` and `RecommendationRunStatusJson` build their own shapes | ⬜ |
| B5: Store serialisers are named after their store | ⬜ |
| B6: `NoToArrayInServicesRule`; CLAUDE.md says services build no response arrays | ⬜ |
| B7: The three `AutowireWrongClass` suppressions | ⬜ |
| B8: `ClassNameReferences` and `PersistenceKnowsNoServiceRule` | ⬜ |
| B9: Shared values and enums move to their home | ⬜ |

## Scope

| Issue bullet or ruling | Task |
|---|---|
| 1: `EntryStateUpdater` (`UpdateEntryStateRequest`) | A1 |
| 1: `BulkSubscriptionUpdater` (`BulkUpdateSubscriptionsRequest`) | A2 |
| 1 (comment): `SubscriptionEditor` (`UpdateSubscriptionRequest`, `MoveFeedToTagRequest`, `ReorderSubscriptionsRequest`), and `FeedTagMove`, which #1167 A5 gives `MoveFeedToTagRequest` | A3 |
| 1: `DigestEnablement` (`UpdateDigestRequest`); comment: `AccountPreferencesWriter` (four `Me` DTOs) | A4 |
| 1: `AttestationVerifier` (`RegisterPasskeyRequest`) | A5 |
| 1: `RelyingPartyChange` (`InstanceSettingsRequest`) | A6 |
| 1: `ClientErrorRecorder`, `ClientErrorScrubber` (`ClientErrorItem`) | A7 |
| 1 (comment): `CatalogCategoryEditor`, `CatalogFeedEditor` (`CatalogCategoryRequest`, `CatalogFeedRequest`, `ReorderRequest`) | A8 |
| 1 (comment): `SavedSearchEditor` (`CreateSavedSearchRequest`, `UpdateSavedSearchRequest`) | A9 |
| 1 (comment): `TagEditor`, `TagOrdering` (four `Tag` DTOs) | A10 |
| 1 (comment): `OAuthCallback` (`OAuthCallbackAttempt`); 1: `OAuthIdentity`, `OAuthStartState`, `PendingApprovalNotice`; `UserStatistics` (`UserFootprint`) | A11 |
| 1: the admin settings request DTOs | #1159 (A2, B2, B5), not re-planned |
| 1: add `App\Dto\` to `DomainKnowsNoHttpRule`; ruling 1 (four gaps) | A12 |
| 2: `AdminMailController` (`MailTestResult`), `AdminProxyController` (`ProxyTestResult`) | B1 |
| 2: `AuthController` (`AltchaChallenge`) | B2 |
| 2: `MaintenanceController` (`RefreshReport`, `ForYouSweepReport`, `MaintenanceTickReport`) | B3 |
| 2: inside mappers: `RecommendationRunReport::toArray()`, `RefreshRunProgress::toArray()` | B4 |
| 2: keep a `toArray()` only for a non-HTTP store, named after that store | B3 (log contexts), B4 (`RefreshRunStore`), B5 (cache, findings file) |
| Ruling 4: CLAUDE.md + a cheap PHPStan rule | B6 |
| Ruling 2: `AutowireWrongClass` | B7 |
| 3: entities import `Service` values; enums split; `Doctrine/*` imports `WordBoundaries` | B8 (rule), B9 (moves, docs) |

## Design decisions (ruled 2026-09-26: D1 confirmed with a condition, D4 overruled, the rest confirmed)

- **D1: the value homes.** A value or enum used by more than one layer lives at the lowest layer that uses it.
  - **Ruling:** only a value an entity stores, embeds or returns goes to `App\Entity`; anything else stays with its owning `Service` module, or goes to `App\Enum` if it is a cross-layer enum. Every value below qualifies: `SealedSecret` (the `getSealed…()` getters), `Discussion` (`Entry::getDiscussion()`), the three connection values (`connection()`, #1159), `RecommendationSettingsValues` (`values()`), and `InstanceSettingsUpdate`, which is the six columns `InstanceSetting` stores, as one value.
  - `App\Entity` holds the values an entity stores, embeds or returns, next to the embeddables and the values already there (`BackedUpReadMark`, `RunTuning`, `PasskeyRegistration`…): `SealedSecret`, `Discussion`, `ProxyConnection`, `MailConnection`, `GrafanaConnection`, `InstanceSettingsUpdate`, `RecommendationSettingsValues`. The six recommendation defaults move onto `RecommendationSettings` itself, because the entity imported `EffectiveRecommendationSettings` only for them.
  - `App\Enum` stays and is the one home for enums that cross a layer: `DigestCadence`, `DigestFormat`, `MagazineStyle`, `RecommendationBatchSize` and `MailKind` (from `App\Entity`) move in; `ScrapeFallback` (only `Service/Discovery` uses it) and `SocksReplyCode` (only `Service/Fetch`) move out to their module. `App\Enum` does **not** fold into `App\Entity`: half its enums are read by code that never touches an entity (`ListOrder`, `RegistrationMethod`), and folding would move the 13 enums `App\Enum` holds at `a124ad8a` (#1167 added `EntryView`) through every layer for no dependency gain.
  - `App\Doctrine` holds `WordBoundaries`, which the DQL function, the SQLite UDF, a repository and a service share.
  - Rejected: a catch-all `App\Domain`/`App\Value` (#1158 ruled against a catch-all when it placed the cursors in `App\Pagination`).
- **D2: a single field is passed, not wrapped.** `changeLocale(User, string)`, `changeMagazineStyle(User, MagazineStyle)`, the reorders (`list<int>`) and two stored booleans: `changeScrapeFallback(User, bool $scrapeFallbackEnabled)` and `changeDigestInclusion(SavedSearch, bool $includeInDigest)`. #1167's scope ruling says a `bool` that is a stored value is not a flag parameter (the entity-setter precedent). If the planner rules otherwise, each becomes a one-field value.
- **D3: the DTO converts itself** (`toChange()`, `toMove()`, `toConfiguration()`, `toAttestation()`, `toRelyingPartyIdChoice()`, `toClientError()`/`toClientErrors()`, `toDetails()`, `toDefinition()`), as `InstanceSettingsRequest::toUpdate()` does. The value keeps the DTO's defaults where tests rely on them (a partial change's `null`s, a saved search's `false` match modes, a catalog row's `enabled`/`locked`).
- **D4 (overruled, now): a moved class's comments are brought to the bar.** CLAUDE.md's "delete on sight in code you touch" applies to a move: restatements, banners and history go, and what stays is at most three lines and a non-obvious invariant. The plan shows every moved class's (and moved test's) final content in full. Code is unchanged.
- **D5: log lines are a store.** `RefreshReport`, `DigestSweepReport` and `SavedSearchMembershipSweepReport` keep their map, renamed `toLogContext()`, because the worker handlers log it. `RefreshFeedsCommand` prints the same map (the operator's view of the run). The wire gets its own mappers; the two maps coincide today and are separate contracts.
- **D6: the tick report is typed.** `MaintenanceTickReport(RefreshReport, MaintenanceSweeps, LokiSpoolReport)`. `MaintenanceSweeps` groups the four EntityManager-bound sweeps plus `bool $skipped`. The skip reason is wire text fully determined by `skipped`, so it moves from `MaintenanceTick::ABORTED_REASON` to `MaintenanceTickJson::SKIPPED_REASON`.
- **D7: two cheap rules.** `NoToArrayInServicesRule` rejects a method named `toArray` or `jsonSerialize` (case-insensitive) on any class in `App\Service`. `PersistenceKnowsNoServiceRule` rejects any `App\Service\` reference in `App\Entity`, `App\Enum` and `App\Doctrine`. Both reuse one `ClassNameReferences` collector with `DomainKnowsNoHttpRule`.
- **D8: all commits are `refactor(#1182): …`**, the plan copy included, as the planner's workflow facts say.
- **D-reconcile-1 (ACCEPTED by the planner): `BulkSubscriptionChange::$subscriptionIds` defaults to `[]`**, like `BulkUpdateSubscriptionsRequest`. The draft left it without a default, against D3 ("each service value mirrors its DTO's defaults"). Every caller passes it by name, so either compiles. Recommendation: mirror the DTO, as now written in A2.

## Not in scope

- The admin settings request DTOs and services: #1159 already moves them onto `toUpdate()` values; #1167 A7 makes the DTOs full-replace.
- `SecretChange` stays in `App\Service\Crypto`: no entity takes it (#1159 D6).
- `Repository` → `Service` value imports (`SearchTerms`, `SavedSearchTerm`, `NormalizedCategory`, `EntryLine`, `UrlNormalizer`, `CompletionUsage`, `MonthWindow`, `DatabaseValue`, `LikePattern`, `SavedSearchMatcher`, `SavedSearchMembershipWriter`): the issue lists entities and `Doctrine/*` only, and query criteria are #1169's architecture question. `SearchTermsPredicateBuilder`'s `WordBoundaries` import changes only because the class moves. `PersistenceKnowsNoServiceRule` (B8) does not trip on them: it checks only files in `App\Entity`, `App\Enum` and `App\Doctrine`, and all 13 imports sit in `App\Repository`.
- The response DTOs under `App\Dto\Admin` (`AdminUserDetail` and its parts): only the controller and `src/Http` build them.
- `App\Service\Backup\Dto\*`: the backup module's own line types, not `App\Dto`.
- A namespace-cycle rule (#1169 carry-forward).

## Wire changes

None. Every response body, status code and header stays byte-identical. The new `src/Http` mapper tests pin each moved shape with the exact array the deleted `toArray()` tests pinned. No frontend file changes, so `npm run check` is not a gate.

## Depends on #1159, #1164, #1167 and #1168

All four have landed (checked at `a124ad8a`, #1164 Part B included). Locate every edit by its **text**. Line numbers here were taken at `fdd9a7e2` and re-checked at `a124ad8a`, for orientation only.

| Step | File | Earlier plan's change | How this plan reconciles |
|---|---|---|---|
| A1 Step 3 | `src/Service/Reader/EntryStateUpdater.php` | #1167 B2 rewrites the `siblingRowsForUser(` call (owner first) | The perl touches only the DTO import, `UpdateEntryStateRequest $request`, `$request->` and `$request)`. That line has none of them. |
| A1 Step 3 | `src/Controller/Api/EntryController.php` | #1167 A4 (`EntryView`), B2 (`getRowForUser`) | Only the line `$state = $this->entryStateUpdater->apply($user, $row, $request);` changes. |
| A1 Step 1 | `tests/Service/Reader/EntryStateUpdaterTest.php` | #1167 B2 (row lookups) | The perl matches the DTO name and the `request(` helper only. |
| A2 Step 3 | `src/Service/Subscription/BulkSubscriptionUpdater.php` | #1167 B3 swaps `findAllByIdsForUser`'s arguments | Same perl pattern as A1; that line carries no `$request`. |
| A2, A3 Step 3 | `src/Controller/Api/SubscriptionController.php` | #1167 B1 (`getOneForUser` ×3), B3 (`create()`'s tag lookup) | Only the four service-call lines named in A2/A3 change, plus the DTO imports stay. |
| A2 Step 1 | `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php` | #1168 B1 (fixture helper) | The perl matches the DTO name only. |
| A3 Step 3 | `src/Service/Subscription/SubscriptionEditor.php` | #1167 A5 rewrites `moveToTag()` | Rewritten in full from A5's version. **Hard dependency:** A0 checks for A5's `$this->feedTagMove->move($subscription, $request);`. |
| A3 Step 3 | `src/Service/Subscription/FeedTagMove.php` | #1167 A5 (`move(Subscription, MoveFeedToTagRequest $move)`), B1 (`findOneForUser`) | The perl replaces the import and the parameter type only. |
| A3 Step 1 | `tests/Service/Subscription/FeedTagMoveTest.php`, `SubscriptionEditorTest.php` | #1167 A5; #1168 B1 (`SeedsUsers`/`ReloadsEntities`) | Perl on DTO names and `new ReorderSubscriptionsRequest(…)` only. |
| A4 Step 3 | `src/Service/Mail/Digest/DigestEnablement.php` | #1168 B6 (`NaiveUtcClock`, `nowAsNaiveUtc()` goes) | The perl touches the import, the `applyTo()` signature and `$request->`. |
| A4 Step 1 | `tests/Service/Mail/Digest/DigestEnablementTest.php`, `tests/Service/Account/AccountPreferencesWriterTest.php` | #1168 B6 (clock perl), B1 (traits) | Perl on DTO names and imports only. |
| A5 Step 3 | `src/Service/Passkey/AttestationVerifier.php` | #1164 Part B B1 (`passkeyFrom()`, adds `use App\Entity\PasskeyRegistration;` after the DTO import) | The perl deletes the DTO import line and renames the `verifyAndStore()` parameter; `passkeyFrom()` is untouched. |
| A6 Step 3 | `src/Dto/Admin/InstanceSettingsRequest.php` | #1167 A7a rewrites it (no defaults) | One import after `use App\Service\Settings\InstanceSettingsUpdate;` and one method after `toUpdate()`. |
| A6 Step 1 | `tests/Service/Settings/RelyingPartyChangeTest.php`, `tests/Dto/Admin/InstanceSettingsRequestTest.php` | #1167 A7a (`requestFor()` builds through `SettingsRequests::instance()`; the DTO test rewritten) | A6 replaces A7a's `requestFor()` by its text and appends one test to the DTO test. |
| A6 Step 3 | `src/Controller/Admin/AdminSettingsController.php` | #1167 A7a (`update()` signature with `FullReplacePayload::CONTEXT`) | Only the `guardAndInvalidatePasskeysIfChanged($request)` line changes. |
| A8, A9, A10 Step 1 | `tests/Service/Catalog/*EditorTest.php`, `tests/Service/Search/SavedSearchEditorTest.php`, `tests/Service/Tag/TagEditorTest.php`, `TagOrderingTest.php` | #1168 B1 (traits replace `user()`/`reload()`) | Perl on DTO names, imports and `new ReorderRequest(…)`-style wrappers only. |
| A9, A10 Step 3 | `src/Controller/Api/SavedSearchController.php`, `TagController.php` | #1167 B1 (`getOneForUser`) | Only the service-call lines change. |
| A11 Step 1 | `tests/Service/OAuth/OAuthCallbackTest.php`, `tests/Support/FakeOAuthProvider.php`, `tests/Controller/Api/OAuthFlowTest.php` | #1167 A8 (helpers), #1168 B3 (one OAuthFlowTest test) | The perl rewrites the three `App\Dto\OAuth\…` names only. |
| A11 Step 3 | `src/Service/OAuth/OAuthStateStore.php` | #1168 A4 (`Base64UrlSafe`) | Only the `OAuthStartState` import line is deleted. |
| A11 Step 3 | `src/Service/Mail/AccountMailer.php`; `tests/Service/Mail/AccountMailerTest.php` | #1159 B6 (`MailSendingSettings` import perl) | Only the `PendingApprovalNotice` import line changes. |
| A11 Step 3 | `src/Http/AdminUserJson.php` | #1168 B10 (`TrialEndJson`) | Only the `UserFootprint` import line changes. |
| B1 Step 3 | `src/Controller/Admin/AdminMailController.php`, `AdminProxyController.php` | #1159 A1/A2/B5 (`get`/`update` lines), #1167 A7b (`update` signature) | Only the `test()` action's return line and one import after `use App\Http\Admin\MailSettingsJson;` / `ProxySettingsJson;`. |
| B2 Step 3 | `src/Controller/Api/AuthController.php` | #1168 B9 (the two ALTCHA blocks and one import) | Only `altchaChallenge()`'s return line, and one import placed before `use App\Service\Auth\AltchaService;`. |
| B3 Step 3 | `src/Command/RefreshFeedsCommand.php` | #1167 A1 (constructor, `default =>` arm), #1168 B8 (`intOption` calls) | Only the line `foreach ($report->toArray() as $key => $value) {`. |
| B5 Step 3 | `src/Service/Grafana/GrafanaSettingsSnapshot.php`, `GrafanaSettingsCache.php`, `tests/Service/Grafana/GrafanaSettingsSnapshotTest.php`, `GrafanaSettingsCacheTest.php` | #1159 B1 (snapshot rewritten, `toArray()`/`fromArrayOrNull()` kept), B3 (cache docblock) | Perl on the two method names. **Hard dependency:** `toEntity` must be gone from the snapshot. |
| B9 Step 3 | every file that names a moved class. At `a124ad8a`: `src/Entity/{Proxy,Mail}ServerSettings.php`, `GrafanaSettings.php` (#1159 `connection()`/`apply()`); the same-namespace users that get an import added, #1159's `ProxySettingsSnapshot`, `ProxySettingsUpdate`, `MailSettingsSnapshot`, `MailSettingsUpdate`, `MailSettings`, `MailSettingsOverview`, `GrafanaSettingsSnapshot`, `GrafanaSettingsUpdate` among them; #1159's test helpers `tests/Support/UnreadableProxyPasswordRows.php`, `SettingsRequests.php`, `EnablesMailInTests.php` and the tests `EffectiveGrafanaSettingsTest`, `StoredProxyTest`, `ProxySettingsSnapshotTest`, `MailSettingsSnapshotTest`. `SecretChange`, `StoredProxy`, `ConfiguredProxySource`, `EffectiveGrafanaSettings`, `EffectiveMailSettings` and `MailSendingSettings` name no moved class and stay untouched | Various | The move script rewrites fully qualified names by text, adds an import to every same-namespace user it finds by token, and drops imports that became same-namespace. Nothing is edited by line. |
| B9 Step 6 | `docs/architecture.md` | #1167 B3 adds a §7 bullet | §8 is appended at the end of the file. |

Apart from these, no earlier plan edits a file this plan edits. Checked by grepping `1164-draft.md`, `1167-draft.md`, `1168-draft.md` and `1159-draft.md` for every file below.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **Wire:** none changes. Every response body, status code and header stays byte-identical. No controller test assertion changes. The only edits to `tests/Controller` are class-name renames: Task A11's one import in `tests/Controller/Api/OAuthFlowTest.php`, and Task B9's script in the eight controller tests that name a moved class (`Admin/AdminMailErrorsControllerTest`, `Api/EntryCommentsControllerTest`, `EntryControllerTest`, `EntryReaderControllerTest`, `MagazineStyleControllerTest`, `PasskeyLoginTest`, `RegistrationTest`, `SetupControllerTest`).
- **Clean Code (CLAUDE.md) is mandatory.**
  - Names reveal intent. No `$data`, `$request` for a value that is no longer a request.
  - No boolean flag parameters (D2 names the two stored-value exceptions).
  - Three parameters at most, constructors aside.
  - Guard clauses over nesting.
  - `final readonly class` by default; mappers are `final class` with static methods, like the other `src/Http/*Json`.
  - A controller calls only `get*`/`is*`/`has*`/`requireId()` on an entity. `$request->toChange()` is a DTO call.
  - Queries live in `src/Repository`.
  - Domain code imports nothing from `App\Http` or, after A12, `App\Dto` (`DomainKnowsNoHttpRule`).
  - Errors are typed exceptions.
- **Comments:** default none. At most three lines, and only where a future reader would otherwise get the code wrong. A docblock on a member this plan rewrites is trimmed to that bar; a `@param`/`@return` stays only where PHPStan needs the shape. Docblocks on untouched members stay. A moved class's comments are brought to the bar, and its final content is shown in full (D4).
- **Tests read persisted ids with `requireId()`** (`EntityIdCoercionRule` covers `tests/`).
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`.
- **PSR-12, 120 columns.** If a substitution pushes a line past 120, wrap that call's arguments one per line (`composer cs` reports it).
- **Gates for every task:**
  - the task's own tests,
  - `composer check` (cs + stan + tramp; `bin/console cache:warmup` first if the dev cache is cold),
  - `composer md`,
  - PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`). ERROR and WARNING block.
- **Gates per PR (Finishing):**
  - `php bin/phpunit` (SQLite),
  - `docker compose exec php composer test` (MySQL),
  - `composer check`,
  - `composer md`,
  - `composer infection:diff`,
  - PhpStorm lint on all changed PHP.
- **Every new test gets a deletion check.** Break the production line it covers, run it and watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Commits:** `refactor(#1182): <lower-case summary>`, one per task, no attribution lines. Never commit to `develop`.
- **PR A must never say it ends the issue.** No commit message and no PR text in PR A contains "close", "closes", "fix", "fixes", "resolve" or "resolves" anywhere, not only next to `#1182`. The PR A body says `Refs #1182`. Only the PR B body says `Closes #1182`.
- **Branches, both cut from `origin/develop`:**
  - PR A: `refactor/1182-request-dtos-to-service-values`.
  - PR B: `refactor/1182-response-mappers-and-value-homes`, cut after PR A merges.
- **The checkout is shared.** Run `git status --short` and `git branch --show-current` before any `switch`, `reset` or `stash`. Another session may be mid-edit.
- **Subagent-driven.** Every task below is self-contained: it names its files, the exact code and the exact commands. A task never refers to another task's code.

## Reconcile changes (a124ad8a)

- Status and "Depends on": the four predecessor issues are recorded as landed; line numbers re-checked at `a124ad8a`.
- A0 Step 1: `AttestationVerifier` now gives two `PasskeyRegistration` hits (the import and `new PasskeyRegistration(`), not one.
- A2: `BulkSubscriptionChange::$subscriptionIds` gains the DTO's `= []` default (D-reconcile-1).
- A3 Step 1: the check grep matches the DTO names only; `Request` alone also hit three existing test method names.
- A6 Step 1: the `requestFor()` "before" block is #1167's real version (`SettingsRequests::instance(…)`); its now-unused `SettingsRequests` import is deleted, and the new import is placed in order.
- A8: the check grep ignores `Requested` in a test name; the lint count is twelve files, not ten.
- A9, A10: the one-line docblocks on `SavedSearchDefinition` and `TagDetails`, which restated their fields, are gone. A10's OPEN QUESTION is resolved: #1167 kept the repository method names.
- A11: the file list is taken at `a124ad8a` (`RegistrationTest` and `AbstractOidcProviderTest` name `OAuthIdentity` only in comments). The rename check includes the old path. The "no code line changed" check compares comment-stripped files, because `git diff -M30%` shows the trimmed `OAuthStartState` and `OAuthIdentity` as a delete plus an add.
- A12 Step 6: the fixture file is excluded from phpcs and PHPStan by path; its `@noinspection` line does not cover all inspections.
- Global Constraints, Finishing PR A/B, both PR bodies: controller tests keep every assertion, but A11 (`OAuthFlowTest`) and B9 (eight controller tests) rename imports, so "tests/Controller unchanged" no longer holds literally. `SendDueDigestsHandlerTest` likewise follows B9's digest enums.
- B0 Step 3: a note that Doctrine `Collection::toArray()` and `ApiProblem::toArray()` hits stay.
- B1: the `git add` no longer names the `git rm`'d `MailTestResultTest.php`, which would abort the add; the lint count is nine.
- B3: the same `git add` fix for `ForYouSweepReportTest.php`; Step 4 also runs #1167's `RefreshFeedsCommandRequestTest`.
- B5: `GrafanaSettingsCacheTest` is dropped (it names neither method), the snapshot docblock says "cache entry", and the lint count is ten.
- B7: the existing `EntryState`, `RecommendationRun`, `RecommendationRunLog` and `UserPasskey` constructor suppressions are named (carry-forward note); they need no change.
- B8: `ClassNameReferences::namespacesIn()` wraps `findInstanceOf()` in `array_values()`; php-parser types that return as `array`, not `list`.
- B9 (blocking fix): the move script's `trackedFiles()` and the Step 6 greps exclude `tests/PhpStan`. Otherwise the script would rewrite B8's `PersistenceKnowsNoServiceRuleTest` fixture and constants, which name the old classes on purpose.
- B9 Step 4b: the `DEFAULT_PORT` comments on `ProxyConnection` and `MailConnection` no longer claim that a request DTO reads them (#1167 removed those defaults). §8 and the CLAUDE.md bullet say `App\Enum` holds enums used by an entity, a repository or several modules, which matches the enums that stay in `Service` (`CatalogImportMode`, `CommentsStatus`). The §8 append point is named by text.
- B9: "Depends on" names #1159's real users of the moved values; `SecretChange`, `StoredProxy`, `ConfiguredProxySource`, `EffectiveGrafanaSettings`, `EffectiveMailSettings` and `MailSendingSettings` name none. The code-unchanged check and Finishing PR B's "Moves" point use the stripped comparison, not `--diff-filter=R`. D1's enum count is 13 (#1167's `EntryView`).
- Checked with no change needed: A1, A4, A5, A7, B2, B4, B6, the fifteen moved classes' and four moved tests' code (identical to the Step 4b listings), B9 Step 5's sixteen retarget files, §8, and the `docs/architecture.md` append point.

---

# PR A

### Task A0: Preflight

**Files:** none changed, except the plan copy.

- [ ] **Step 1: Confirm #1159, #1164, #1167 and #1168 have landed**

Run:
```bash
for n in 1159 1164 1167 1168; do gh issue view $n --json state --jq .state; done
git fetch origin
git grep -nF 'public function move(Subscription $subscription, MoveFeedToTagRequest $move)' origin/develop -- backend/src/Service/Subscription/FeedTagMove.php
git grep -nF 'this->feedTagMove->move($subscription, $request);' origin/develop -- backend/src/Service/Subscription/SubscriptionEditor.php
git grep -nF 'private NaiveUtcClock $clock' origin/develop -- backend/src/Service/Mail/Digest/DigestEnablement.php
git grep -n 'PasskeyRegistration' origin/develop -- backend/src/Service/Passkey/AttestationVerifier.php
git grep -n 'public function toUpdate' origin/develop -- backend/src/Dto/Admin
git grep -nF 'private function requestFor(string $passkeyRpId, string $publicBaseUrl): InstanceSettingsRequest' origin/develop -- backend/tests/Service/Settings/RelyingPartyChangeTest.php
```
Expected:
- `CLOSED` four times.
- One hit each for `FeedTagMove::move`, `SubscriptionEditor` and `DigestEnablement`; two for `AttestationVerifier` (the `PasskeyRegistration` import and `new PasskeyRegistration(`) (#1167 A5, #1168 B6, #1164 B1).
- Four `toUpdate` hits: instance, proxy, Grafana and mail settings requests (#1159).
- One `requestFor` hit (#1167 A7a).

If any issue is open or any line is missing, stop and report: A3, A4, A5 and A6 build on those versions.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1182-request-dtos-to-service-values origin/develop
```

- [ ] **Step 3: Commit the plan (from the repository root)**

```bash
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1182-draft.md docs/superpowers/plans/2026-09-26-1182-finish-the-layering.md
git add docs/superpowers/plans/2026-09-26-1182-finish-the-layering.md
git commit -m "refactor(#1182): add the implementation plan"
```

- [ ] **Step 4: Re-take the list of domain files that import `App\Dto`**

Run:
```bash
git grep -nF 'use App\Dto\' -- src/Service src/Repository src/Entity src/Enum src/Exception src/Pagination src/EventListener
```
Expected (the list A1–A11 empties):
- `Service/Reader/EntryStateUpdater.php`, `Service/Subscription/BulkSubscriptionUpdater.php`, `Service/Subscription/SubscriptionEditor.php` (×3), `Service/Subscription/FeedTagMove.php`,
- `Service/Mail/Digest/DigestEnablement.php`, `Service/Account/AccountPreferencesWriter.php` (×4),
- `Service/Passkey/AttestationVerifier.php`, `Service/Settings/RelyingPartyChange.php`,
- `Service/ClientError/ClientErrorRecorder.php`, `Service/ClientError/ClientErrorScrubber.php`, `Service/Admin/UserStatistics.php`,
- `Service/Catalog/CatalogCategoryEditor.php` (×2), `Service/Catalog/CatalogFeedEditor.php` (×2),
- `Service/OAuth/AbstractOidcProvider.php`, `OAuthAccountLinker.php`, `OAuthCallback.php` (×3), `OAuthProviderInterface.php`, `OAuthSignIn.php`, `OAuthStateStore.php`, `Oidc/IdTokenVerifier.php`,
- `Service/Search/SavedSearchEditor.php` (×2), `Service/Tag/TagEditor.php` (×2), `Service/Tag/TagOrdering.php` (×2),
- `Service/Mail/AccountMailer.php`, `AccountMailerInterface.php`, `MailGatedAccountMailer.php`, `EventListener/NotifyAdminsOfPendingApproval.php`.

A `Proxy`, `Grafana` or `Mail/Settings` hit means #1159 has not landed: stop. Any other extra hit gets the same treatment as its siblings in the task that owns its module; note it in that task's report.

---

### Task A1: `EntryStateChange`

**Files:**
- Create: `src/Service/Reader/EntryStateChange.php`
- Create: `tests/Dto/Entry/UpdateEntryStateRequestTest.php`
- Modify: `src/Dto/Entry/UpdateEntryStateRequest.php` (one import, `toChange()`)
- Modify: `src/Service/Reader/EntryStateUpdater.php` (perl: import, parameter type and name)
- Modify: `src/Controller/Api/EntryController.php` (one line)
- Modify: `tests/Service/Reader/EntryStateUpdaterTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Reader\EntryStateChange::__construct(?bool $isHidden = null, ?bool $isFavorite = null, ?bool $isKept = null, ?bool $isViewed = null)`.
- Produces: `UpdateEntryStateRequest::toChange(): EntryStateChange`.
- Produces: `EntryStateUpdater::apply(User $user, EntryListRow $row, EntryStateChange $change): EntryState`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Entry/UpdateEntryStateRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Entry;

use App\Dto\Entry\UpdateEntryStateRequest;
use PHPUnit\Framework\TestCase;

final class UpdateEntryStateRequestTest extends TestCase
{
    public function testToChangeCarriesEachFlagInItsOwnField(): void
    {
        $change = (new UpdateEntryStateRequest(isHidden: true, isFavorite: false, isKept: null, isViewed: false))
            ->toChange();

        self::assertSame(
            ['isHidden' => true, 'isFavorite' => false, 'isKept' => null, 'isViewed' => false],
            get_object_vars($change),
        );
    }

    public function testToChangeKeepsTheOtherFlagsApartToo(): void
    {
        $change = (new UpdateEntryStateRequest(isHidden: null, isFavorite: true, isKept: false, isViewed: null))
            ->toChange();

        self::assertSame(
            ['isHidden' => null, 'isFavorite' => true, 'isKept' => false, 'isViewed' => null],
            get_object_vars($change),
        );
    }
}
```

Migrate the service test to the value:
```bash
perl -pi -e 's/^use App\\Dto\\Entry\\UpdateEntryStateRequest;$/use App\\Service\\Reader\\EntryStateChange;/; s/\bUpdateEntryStateRequest\b/EntryStateChange/g; s/\$this->request\(/\$this->change(/g; s/private function request\(/private function change(/' tests/Service/Reader/EntryStateUpdaterTest.php
git grep -nE 'Request|request\(' -- tests/Service/Reader/EntryStateUpdaterTest.php
```
Expected: the grep prints nothing.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Entry/UpdateEntryStateRequestTest.php tests/Service/Reader/EntryStateUpdaterTest.php`
Expected: FAIL. The DTO test errors with `Call to undefined method App\Dto\Entry\UpdateEntryStateRequest::toChange()`; the service test errors with `Class "App\Service\Reader\EntryStateChange" not found`.

- [ ] **Step 3: Implement**

`src/Service/Reader/EntryStateChange.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

/** A partial state change: a null flag stays as it is. */
final readonly class EntryStateChange
{
    public function __construct(
        public ?bool $isHidden = null,
        public ?bool $isFavorite = null,
        public ?bool $isKept = null,
        public ?bool $isViewed = null,
    ) {
    }
}
```

`src/Dto/Entry/UpdateEntryStateRequest.php`:
- Directly after `namespace App\Dto\Entry;` and its blank line, insert:
```php
use App\Service\Reader\EntryStateChange;

```
- Before the class's closing `}`, after the constructor, add:
```php

    public function toChange(): EntryStateChange
    {
        return new EntryStateChange(
            isHidden: $this->isHidden,
            isFavorite: $this->isFavorite,
            isKept: $this->isKept,
            isViewed: $this->isViewed,
        );
    }
```

`src/Service/Reader/EntryStateUpdater.php` (the value is in the same namespace, so the DTO import simply goes):
```bash
perl -0pi -e 's/use App\\Dto\\Entry\\UpdateEntryStateRequest;\n//; s/UpdateEntryStateRequest \$request/EntryStateChange \$change/g; s/\$request->/\$change->/g; s/\$request\)/\$change)/g' src/Service/Reader/EntryStateUpdater.php
git grep -nE 'request|Request' -- src/Service/Reader/EntryStateUpdater.php
```
Expected: the grep prints nothing. `applyTo()` now reads:
```php
    private function applyTo(EntryState $state, EntryStateChange $change): void
    {
        if ($change->isHidden !== null) {
            // Unread also clears "opened" (EntryState::markUnread, #478), so the
            // rule reaches every client, not just the web app.
            $change->isHidden ? $state->hide($this->clock->now()) : $state->markUnread();
        }
```

`src/Controller/Api/EntryController.php`, in `updateState()`:
```diff
-        $state = $this->entryStateUpdater->apply($user, $row, $request);
+        $state = $this->entryStateUpdater->apply($user, $row, $request->toChange());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Entry tests/Service/Reader/EntryStateUpdaterTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `UpdateEntryStateRequest::toChange()`, change `isKept: $this->isKept,` to `isKept: $this->isFavorite,`. Expected: both DTO tests fail. Restore.
2. In `EntryStateUpdater::applyTo()`, change `$change->isKept ? $state->markKept()` to `$change->isFavorite ? $state->markKept()`. Expected: `EntryStateUpdaterTest::testKeptDoesNotMirror` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the five PHP files.
```bash
git add src/Service/Reader/EntryStateChange.php src/Dto/Entry/UpdateEntryStateRequest.php src/Service/Reader/EntryStateUpdater.php src/Controller/Api/EntryController.php tests/Dto/Entry/UpdateEntryStateRequestTest.php tests/Service/Reader/EntryStateUpdaterTest.php
git commit -m "refactor(#1182): entry state updates take an EntryStateChange"
```

---

### Task A2: `BulkSubscriptionChange`

**Files:**
- Create: `src/Service/Subscription/BulkSubscriptionChange.php`
- Create: `tests/Dto/Subscription/BulkUpdateSubscriptionsRequestTest.php`
- Modify: `src/Dto/Subscription/BulkUpdateSubscriptionsRequest.php` (one import, `toChange()`)
- Modify: `src/Service/Subscription/BulkSubscriptionUpdater.php` (perl)
- Modify: `src/Controller/Api/SubscriptionController.php` (one line)
- Modify: `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Subscription\BulkSubscriptionChange::__construct(list<int> $subscriptionIds, list<int> $addTagIds = [], list<int> $removeTagIds = [], ?bool $includeInAllItems = null, ?bool $includeInForYou = null)`.
- Produces: `BulkUpdateSubscriptionsRequest::toChange(): BulkSubscriptionChange`.
- Produces: `BulkSubscriptionUpdater::apply(BulkSubscriptionChange $change, int $userId): list<Subscription>`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Subscription/BulkUpdateSubscriptionsRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use PHPUnit\Framework\TestCase;

final class BulkUpdateSubscriptionsRequestTest extends TestCase
{
    public function testToChangeCarriesTheSelectionTheTagChangesAndTheFlags(): void
    {
        $change = (new BulkUpdateSubscriptionsRequest([1, 2], [3], [4], true, false))->toChange();

        self::assertSame(
            [
                'subscriptionIds' => [1, 2],
                'addTagIds' => [3],
                'removeTagIds' => [4],
                'includeInAllItems' => true,
                'includeInForYou' => false,
            ],
            get_object_vars($change),
        );
    }
}
```

Migrate the service test:
```bash
perl -pi -e 's/^use App\\Dto\\Subscription\\BulkUpdateSubscriptionsRequest;$/use App\\Service\\Subscription\\BulkSubscriptionChange;/; s/\bBulkUpdateSubscriptionsRequest\b/BulkSubscriptionChange/g' tests/Service/Subscription/BulkSubscriptionUpdaterTest.php
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Subscription/BulkUpdateSubscriptionsRequestTest.php tests/Service/Subscription/BulkSubscriptionUpdaterTest.php`
Expected: FAIL: `Call to undefined method …::toChange()`, and `Class "App\Service\Subscription\BulkSubscriptionChange" not found`.

- [ ] **Step 3: Implement**

`src/Service/Subscription/BulkSubscriptionChange.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

/** One tag and flag change across many feeds. A null flag stays as it is. */
final readonly class BulkSubscriptionChange
{
    /**
     * @param list<int> $subscriptionIds
     * @param list<int> $addTagIds
     * @param list<int> $removeTagIds
     */
    public function __construct(
        public array $subscriptionIds = [],
        public array $addTagIds = [],
        public array $removeTagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
    ) {
    }
}
```

`src/Dto/Subscription/BulkUpdateSubscriptionsRequest.php`:
- Insert `use App\Service\Subscription\BulkSubscriptionChange;` directly before `use App\Service\Subscription\SubscriptionService;`.
- Before the class's closing `}`, add:
```php

    public function toChange(): BulkSubscriptionChange
    {
        return new BulkSubscriptionChange(
            subscriptionIds: $this->subscriptionIds,
            addTagIds: $this->addTagIds,
            removeTagIds: $this->removeTagIds,
            includeInAllItems: $this->includeInAllItems,
            includeInForYou: $this->includeInForYou,
        );
    }
```

`src/Service/Subscription/BulkSubscriptionUpdater.php`:
```bash
perl -0pi -e 's/use App\\Dto\\Subscription\\BulkUpdateSubscriptionsRequest;\n//; s/BulkUpdateSubscriptionsRequest \$request/BulkSubscriptionChange \$change/g; s/\$request->/\$change->/g; s/\$request\)/\$change)/g' src/Service/Subscription/BulkSubscriptionUpdater.php
git grep -nE '\$request|Request' -- src/Service/Subscription/BulkSubscriptionUpdater.php
```
Expected: the grep prints nothing. `apply()` now opens `public function apply(BulkSubscriptionChange $change, int $userId): array` and reads `$change->addTagIds`, `$change->removeTagIds`, `$change->subscriptionIds`.

`src/Controller/Api/SubscriptionController.php`, in `bulkUpdate()`:
```diff
-        $changed = $this->bulkUpdater->apply($request, $user->requireId());
+        $changed = $this->bulkUpdater->apply($request->toChange(), $user->requireId());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Subscription tests/Service/Subscription/BulkSubscriptionUpdaterTest.php tests/Controller/Api/SubscriptionBulkTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `toChange()`, swap the two tag lists (`addTagIds: $this->removeTagIds, removeTagIds: $this->addTagIds`). Expected: the DTO test fails. Restore.
2. In `BulkSubscriptionUpdater::applyFlags()`, change `setIncludeInForYou($change->includeInForYou)` to `setIncludeInForYou($change->includeInAllItems)`. Expected: `testEachFlagIsWrittenToItsOwnField` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the five PHP files.
```bash
git add src/Service/Subscription/BulkSubscriptionChange.php src/Dto/Subscription/BulkUpdateSubscriptionsRequest.php src/Service/Subscription/BulkSubscriptionUpdater.php src/Controller/Api/SubscriptionController.php tests/Dto/Subscription/BulkUpdateSubscriptionsRequestTest.php tests/Service/Subscription/BulkSubscriptionUpdaterTest.php
git commit -m "refactor(#1182): bulk subscription updates take a BulkSubscriptionChange"
```

---

### Task A3: `SubscriptionChange`, `TagMove`, subscription reorder ids

**Files:**
- Create: `src/Service/Subscription/SubscriptionChange.php`, `src/Service/Subscription/TagMove.php`
- Create: `tests/Dto/Subscription/UpdateSubscriptionRequestTest.php`, `tests/Dto/Subscription/MoveFeedToTagRequestTest.php`
- Modify: `src/Dto/Subscription/UpdateSubscriptionRequest.php` (`toChange()`), `src/Dto/Subscription/MoveFeedToTagRequest.php` (`toMove()`)
- Modify: `src/Service/Subscription/SubscriptionEditor.php` (rewritten in full from #1167 A5's version)
- Modify: `src/Service/Subscription/FeedTagMove.php` (perl: import and parameter type)
- Modify: `src/Controller/Api/SubscriptionController.php` (three lines)
- Modify: `tests/Service/Subscription/SubscriptionEditorTest.php`, `tests/Service/Subscription/FeedTagMoveTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Subscription\SubscriptionChange::__construct(?string $customTitle = null, list<int> $tagIds = [], ?bool $includeInAllItems = null, ?bool $includeInForYou = null)`.
- Produces: `App\Service\Subscription\TagMove::__construct(?int $fromTagId = null, ?int $toTagId = null, ?int $position = null)`.
- Produces: `UpdateSubscriptionRequest::toChange(): SubscriptionChange`, `MoveFeedToTagRequest::toMove(): TagMove`.
- Produces: `SubscriptionEditor::update(Subscription, SubscriptionChange): void`, `moveToTag(Subscription, TagMove): void`, `reorder(User, list<int> $subscriptionIds): void`.
- Produces: `FeedTagMove::move(Subscription $subscription, TagMove $move): void` (#1167 A5's body, unchanged).

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Subscription/UpdateSubscriptionRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\UpdateSubscriptionRequest;
use PHPUnit\Framework\TestCase;

final class UpdateSubscriptionRequestTest extends TestCase
{
    public function testToChangeCarriesTheTitleTheTagsAndBothFlags(): void
    {
        $change = (new UpdateSubscriptionRequest('Mine', [7, 8], false, true))->toChange();

        self::assertSame(
            ['customTitle' => 'Mine', 'tagIds' => [7, 8], 'includeInAllItems' => false, 'includeInForYou' => true],
            get_object_vars($change),
        );
    }
}
```

`tests/Dto/Subscription/MoveFeedToTagRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\MoveFeedToTagRequest;
use PHPUnit\Framework\TestCase;

final class MoveFeedToTagRequestTest extends TestCase
{
    public function testToMoveCarriesTheSourceTheTargetAndThePosition(): void
    {
        $move = (new MoveFeedToTagRequest(3, 5, 2))->toMove();

        self::assertSame(['fromTagId' => 3, 'toTagId' => 5, 'position' => 2], get_object_vars($move));
    }
}
```

Migrate the service tests:
```bash
perl -pi -e 's/^use App\\Dto\\Subscription\\MoveFeedToTagRequest;$/use App\\Service\\Subscription\\TagMove;/; s/^use App\\Dto\\Subscription\\UpdateSubscriptionRequest;$/use App\\Service\\Subscription\\SubscriptionChange;/; $_ = "" if /^use App\\Dto\\Subscription\\ReorderSubscriptionsRequest;$/; s/\bMoveFeedToTagRequest\b/TagMove/g; s/\bUpdateSubscriptionRequest\b/SubscriptionChange/g; s/new ReorderSubscriptionsRequest\((\[[^\]]*\])\)/$1/g' tests/Service/Subscription/SubscriptionEditorTest.php tests/Service/Subscription/FeedTagMoveTest.php
git grep -nE 'App\\Dto|MoveFeedToTagRequest|UpdateSubscriptionRequest|ReorderSubscriptionsRequest' -- tests/Service/Subscription/SubscriptionEditorTest.php tests/Service/Subscription/FeedTagMoveTest.php
```
Expected: the grep prints nothing. `SubscriptionEditorTest` now calls `$this->editor()->reorder($user, [$second->requireId(), $first->requireId()]);`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Subscription tests/Service/Subscription/SubscriptionEditorTest.php tests/Service/Subscription/FeedTagMoveTest.php`
Expected: FAIL: the two DTO tests with `Call to undefined method`, the service tests with `Class "App\Service\Subscription\SubscriptionChange" not found` / `…\TagMove" not found`.

- [ ] **Step 3: Implement**

`src/Service/Subscription/SubscriptionChange.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

/** An edit of one subscription. An empty title clears the custom title; a null flag stays as it is. */
final readonly class SubscriptionChange
{
    /** @param list<int> $tagIds */
    public function __construct(
        public ?string $customTitle = null,
        public array $tagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
    ) {
    }
}
```

`src/Service/Subscription/TagMove.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

/** A drag between the sidebar's lists. A null tag id is the untagged "Feeds" list; a null position appends. */
final readonly class TagMove
{
    public function __construct(
        public ?int $fromTagId = null,
        public ?int $toTagId = null,
        public ?int $position = null,
    ) {
    }
}
```

`src/Dto/Subscription/UpdateSubscriptionRequest.php`:
- Insert `use App\Service\Subscription\SubscriptionChange;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toChange(): SubscriptionChange
    {
        return new SubscriptionChange(
            customTitle: $this->customTitle,
            tagIds: $this->tagIds,
            includeInAllItems: $this->includeInAllItems,
            includeInForYou: $this->includeInForYou,
        );
    }
```

`src/Dto/Subscription/MoveFeedToTagRequest.php`:
- Insert `use App\Service\Subscription\TagMove;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toMove(): TagMove
    {
        return new TagMove(fromTagId: $this->fromTagId, toTagId: $this->toTagId, position: $this->position);
    }
```

`src/Service/Subscription/SubscriptionEditor.php` (rewritten in full; it differs from #1167 A5's version in the three signatures, `reorder()`'s body and the imports):
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionEditor
{
    public function __construct(
        private SubscriptionTagSync $tagSync,
        private FeedTagMove $feedTagMove,
        private OwnedSubscriptions $ownedSubscriptions,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function update(Subscription $subscription, SubscriptionChange $change): void
    {
        $subscription->setCustomTitle('' === $change->customTitle ? null : $change->customTitle);
        $this->tagSync->sync($subscription, $change->tagIds, $subscription->getUser()->requireId());
        $this->applyFlags($subscription, $change);
        $this->entityManager->flush();
    }

    public function moveToTag(Subscription $subscription, TagMove $move): void
    {
        $this->feedTagMove->move($subscription, $move);
        $this->entityManager->flush();
    }

    /** @param list<int> $subscriptionIds the untagged feeds in their new order */
    public function reorder(User $user, array $subscriptionIds): void
    {
        $this->reorderer->reorder(
            $subscriptionIds,
            $this->ownedSubscriptions->resolve($subscriptionIds, $user->requireId()),
        );
    }

    private function applyFlags(Subscription $subscription, SubscriptionChange $change): void
    {
        if (null !== $change->includeInAllItems) {
            $subscription->setIncludeInAllItems($change->includeInAllItems);
        }
        if (null !== $change->includeInForYou) {
            $subscription->setIncludeInForYou($change->includeInForYou);
        }
    }
}
```

`src/Service/Subscription/FeedTagMove.php` (the value is in the same namespace; #1167 A5's body already reads `$move->…`):
```bash
perl -0pi -e 's/use App\\Dto\\Subscription\\MoveFeedToTagRequest;\n//; s/MoveFeedToTagRequest \$move/TagMove \$move/' src/Service/Subscription/FeedTagMove.php
git grep -n 'Request' -- src/Service/Subscription/FeedTagMove.php
```
Expected: the grep prints nothing.

`src/Controller/Api/SubscriptionController.php`:
```diff
-        $this->editor->update($sub, $request);
+        $this->editor->update($sub, $request->toChange());
```
```diff
-        $this->editor->moveToTag($sub, $request);
+        $this->editor->moveToTag($sub, $request->toMove());
```
```diff
-        $this->editor->reorder($user, $request);
+        $this->editor->reorder($user, $request->subscriptionIds);
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Subscription tests/Service/Subscription tests/Controller/Api/MoveFeedToTagTest.php tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `MoveFeedToTagRequest::toMove()`, swap `fromTagId: $this->toTagId, toTagId: $this->fromTagId`. Expected: `MoveFeedToTagRequestTest` fails. Restore.
2. In `UpdateSubscriptionRequest::toChange()`, set `includeInForYou: $this->includeInAllItems`. Expected: `UpdateSubscriptionRequestTest` fails. Restore.
3. In `SubscriptionEditor::reorder()`, pass `array_reverse($subscriptionIds)` as the first argument to `$this->reorderer->reorder(`. Expected: `SubscriptionEditorTest::testReorderGivesEachFeedItsIndex` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the nine PHP files.
```bash
git add src/Service/Subscription/SubscriptionChange.php src/Service/Subscription/TagMove.php src/Dto/Subscription/UpdateSubscriptionRequest.php src/Dto/Subscription/MoveFeedToTagRequest.php src/Service/Subscription/SubscriptionEditor.php src/Service/Subscription/FeedTagMove.php src/Controller/Api/SubscriptionController.php tests/Dto/Subscription tests/Service/Subscription/SubscriptionEditorTest.php tests/Service/Subscription/FeedTagMoveTest.php
git commit -m "refactor(#1182): subscription edits take a SubscriptionChange, a TagMove and plain ids"
```

---

### Task A4: `DigestConfiguration`; preference writes take plain values

**Files:**
- Create: `src/Service/Mail/Digest/DigestConfiguration.php`
- Create: `tests/Dto/Me/UpdateDigestRequestTest.php`
- Modify: `src/Dto/Me/UpdateDigestRequest.php` (one import, `toConfiguration()`)
- Modify: `src/Service/Mail/Digest/DigestEnablement.php` (perl on #1168 B6's version)
- Modify: `src/Service/Account/AccountPreferencesWriter.php` (rewritten in full)
- Modify: `src/Controller/Api/MeController.php` (four lines)
- Modify: `tests/Service/Mail/Digest/DigestEnablementTest.php`, `tests/Service/Account/AccountPreferencesWriterTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Mail\Digest\DigestConfiguration::__construct(bool $enabled, DigestCadence $cadence, int $sendHour, int $weekday, DigestFormat $format)`.
- Produces: `UpdateDigestRequest::toConfiguration(): DigestConfiguration`.
- Produces: `DigestEnablement::applyTo(Preferences $preferences, DigestConfiguration $configuration): void`.
- Produces: `AccountPreferencesWriter::changeLocale(User, string $locale)`, `changeScrapeFallback(User, bool $scrapeFallbackEnabled)` (D2), `changeMagazineStyle(User, MagazineStyle $magazineStyle)`, `changeDigest(User, DigestConfiguration $configuration)`; `answerPasskeyOffer(User)` unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Me/UpdateDigestRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Me;

use App\Dto\Me\UpdateDigestRequest;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestFormat;
use PHPUnit\Framework\TestCase;

final class UpdateDigestRequestTest extends TestCase
{
    public function testToConfigurationCarriesEverySetting(): void
    {
        $configuration = (new UpdateDigestRequest(true, DigestCadence::Weekly, 7, 3, DigestFormat::Text))
            ->toConfiguration();

        self::assertSame(
            [
                'enabled' => true,
                'cadence' => DigestCadence::Weekly,
                'sendHour' => 7,
                'weekday' => 3,
                'format' => DigestFormat::Text,
            ],
            get_object_vars($configuration),
        );
    }
}
```

Migrate the service tests:
```bash
perl -pi -e 's/^use App\\Dto\\Me\\UpdateDigestRequest;$/use App\\Service\\Mail\\Digest\\DigestConfiguration;/; $_ = "" if /^use App\\Dto\\Me\\Update(Locale|MagazineStyle|Preferences)Request;$/; s/\bUpdateDigestRequest\b/DigestConfiguration/g; s/new Update(Locale|MagazineStyle|Preferences)Request\(([^()]*)\)/$2/g' tests/Service/Mail/Digest/DigestEnablementTest.php tests/Service/Account/AccountPreferencesWriterTest.php
git grep -nE 'Dto|Update[A-Za-z]*Request' -- tests/Service/Mail/Digest/DigestEnablementTest.php tests/Service/Account/AccountPreferencesWriterTest.php
```
Expected: the grep prints nothing. `AccountPreferencesWriterTest` now calls `changeLocale($user, SupportedLocale::GERMAN)`, `changeScrapeFallback($user, $wanted)` and `changeMagazineStyle($user, $wanted)`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Me tests/Service/Mail/Digest/DigestEnablementTest.php tests/Service/Account/AccountPreferencesWriterTest.php`
Expected: FAIL: `Call to undefined method …::toConfiguration()`, `Class "App\Service\Mail\Digest\DigestConfiguration" not found`, and `TypeError`s in `AccountPreferencesWriterTest` (a `string` passed where `UpdateLocaleRequest` is expected).

- [ ] **Step 3: Implement**

`src/Service/Mail/Digest/DigestConfiguration.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest;

/** The whole digest configuration, written at once. */
final readonly class DigestConfiguration
{
    public function __construct(
        public bool $enabled,
        public DigestCadence $cadence,
        public int $sendHour,
        public int $weekday,
        public DigestFormat $format,
    ) {
    }
}
```

`src/Dto/Me/UpdateDigestRequest.php`:
- Insert `use App\Service\Mail\Digest\DigestConfiguration;` directly after `use App\Service\Mail\Digest\DigestCadence;`.
- Before the class's closing `}`, add:
```php

    public function toConfiguration(): DigestConfiguration
    {
        return new DigestConfiguration(
            enabled: $this->enabled,
            cadence: $this->cadence,
            sendHour: $this->sendHour,
            weekday: $this->weekday,
            format: $this->format,
        );
    }
```

`src/Service/Mail/Digest/DigestEnablement.php` (#1168 B6 already injects `NaiveUtcClock`; this touches only the DTO):
```bash
perl -0pi -e 's/use App\\Dto\\Me\\UpdateDigestRequest;\n//; s/UpdateDigestRequest \$request/DigestConfiguration \$configuration/; s/\$request->/\$configuration->/g' src/Service/Mail/Digest/DigestEnablement.php
git grep -nE 'Request|\$request' -- src/Service/Mail/Digest/DigestEnablement.php
```
Expected: the grep prints nothing. `applyTo()` now opens `public function applyTo(Preferences $preferences, DigestConfiguration $configuration): void` and sets the five fields from `$configuration->…`.

`src/Service/Account/AccountPreferencesWriter.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\User;
use App\Service\Mail\Digest\DigestConfiguration;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Passkey\PasskeyOffer;
use App\Service\Reader\MagazineStyle;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountPreferencesWriter
{
    public function __construct(
        private DigestEnablement $digestEnablement,
        private PasskeyOffer $passkeyOffer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function changeLocale(User $user, string $locale): void
    {
        $user->setLocale($locale);
        $this->entityManager->flush();
    }

    public function changeScrapeFallback(User $user, bool $scrapeFallbackEnabled): void
    {
        $user->getPreferences()->setScrapeFallbackEnabled($scrapeFallbackEnabled);
        $this->entityManager->flush();
    }

    public function changeMagazineStyle(User $user, MagazineStyle $magazineStyle): void
    {
        $user->getPreferences()->setMagazineStyle($magazineStyle);
        $this->entityManager->flush();
    }

    public function changeDigest(User $user, DigestConfiguration $configuration): void
    {
        $this->digestEnablement->applyTo($user->getPreferences(), $configuration);
        $this->entityManager->flush();
    }

    public function answerPasskeyOffer(User $user): void
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();
    }
}
```

`src/Controller/Api/MeController.php`:
```diff
-        $this->preferences->changeLocale($user, $request);
+        $this->preferences->changeLocale($user, $request->locale);
```
```diff
-        $this->preferences->changeScrapeFallback($user, $request);
+        $this->preferences->changeScrapeFallback($user, $request->scrapeFallbackEnabled);
```
```diff
-        $this->preferences->changeMagazineStyle($user, $request);
+        $this->preferences->changeMagazineStyle($user, $request->magazineStyle);
```
```diff
-        $this->preferences->changeDigest($user, $request);
+        $this->preferences->changeDigest($user, $request->toConfiguration());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Me tests/Service/Mail/Digest tests/Service/Account tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeDigestControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `toConfiguration()`, swap `sendHour: $this->weekday, weekday: $this->sendHour`. Expected: `UpdateDigestRequestTest` fails. Restore.
2. In `DigestEnablement::applyTo()`, change `setDigestWeekday($configuration->weekday)` to `setDigestWeekday($configuration->sendHour)`. Expected: `DigestEnablementTest::testItAppliesAllFourSettings` fails. Restore.
3. In `AccountPreferencesWriter::changeMagazineStyle()`, delete `$this->entityManager->flush();`. Expected: `AccountPreferencesWriterTest::testChangeMagazineStylePersists` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the six PHP files.
```bash
git add src/Service/Mail/Digest/DigestConfiguration.php src/Dto/Me/UpdateDigestRequest.php src/Service/Mail/Digest/DigestEnablement.php src/Service/Account/AccountPreferencesWriter.php src/Controller/Api/MeController.php tests/Dto/Me/UpdateDigestRequestTest.php tests/Service/Mail/Digest/DigestEnablementTest.php tests/Service/Account/AccountPreferencesWriterTest.php
git commit -m "refactor(#1182): preference writes take a DigestConfiguration and plain values"
```

---

### Task A5: `PasskeyAttestation`

**Files:**
- Create: `src/Service/Passkey/PasskeyAttestation.php`
- Modify: `src/Dto/Passkey/RegisterPasskeyRequest.php` (one import, `toAttestation()`)
- Modify: `src/Service/Passkey/AttestationVerifier.php` (perl on #1164 B1's version)
- Modify: `src/Controller/Api/PasskeyController.php` (one line)
- Modify: `tests/Dto/Passkey/RegisterPasskeyRequestTest.php` (one test), `tests/Service/Passkey/AttestationVerifierTest.php`, `tests/Service/Passkey/AssertionVerifierTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Passkey\PasskeyAttestation::__construct(string $handle, array<string, mixed> $credential, string $label)`.
- Produces: `RegisterPasskeyRequest::toAttestation(): PasskeyAttestation`.
- Produces: `AttestationVerifier::verifyAndStore(User $user, PasskeyAttestation $attestation): UserPasskey`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Passkey/RegisterPasskeyRequestTest.php`, before the class's closing `}`, add:
```php

    public function testToAttestationCarriesTheHandleTheCredentialAndTheLabel(): void
    {
        $attestation = (new RegisterPasskeyRequest('the-handle', ['id' => 'abc'], 'My phone'))->toAttestation();

        self::assertSame(
            ['handle' => 'the-handle', 'credential' => ['id' => 'abc'], 'label' => 'My phone'],
            get_object_vars($attestation),
        );
    }
```

Migrate the service tests:
```bash
perl -pi -e 's/^use App\\Dto\\Passkey\\RegisterPasskeyRequest;$/use App\\Service\\Passkey\\PasskeyAttestation;/; s/\bRegisterPasskeyRequest\b/PasskeyAttestation/g' tests/Service/Passkey/AttestationVerifierTest.php tests/Service/Passkey/AssertionVerifierTest.php
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Passkey tests/Service/Passkey`
Expected: FAIL: `Call to undefined method …::toAttestation()` and `Class "App\Service\Passkey\PasskeyAttestation" not found`.

- [ ] **Step 3: Implement**

`src/Service/Passkey/PasskeyAttestation.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey;

/** A registration ceremony's completion: the challenge handle, the browser's credential, the chosen label. */
final readonly class PasskeyAttestation
{
    /** @param array<string, mixed> $credential opaque WebAuthn wire data; only the library's deserializer checks it */
    public function __construct(
        public string $handle,
        public array $credential,
        public string $label,
    ) {
    }
}
```

`src/Dto/Passkey/RegisterPasskeyRequest.php`:
- Insert `use App\Service\Passkey\PasskeyAttestation;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toAttestation(): PasskeyAttestation
    {
        return new PasskeyAttestation(handle: $this->handle, credential: $this->credential, label: $this->label);
    }
```

`src/Service/Passkey/AttestationVerifier.php` (same namespace, so the DTO import goes; #1164 B1's `passkeyFrom()` is untouched):
```bash
perl -0pi -e 's/use App\\Dto\\Passkey\\RegisterPasskeyRequest;\n//; s/RegisterPasskeyRequest \$request/PasskeyAttestation \$attestation/; s/\$request->/\$attestation->/g' src/Service/Passkey/AttestationVerifier.php
git grep -nE 'RegisterPasskeyRequest|\$request' -- src/Service/Passkey/AttestationVerifier.php
```
Expected: the grep prints nothing. `verifyAndStore()` now reads:
```php
    public function verifyAndStore(User $user, PasskeyAttestation $attestation): UserPasskey
    {
        $challenge = $this->challengeStore->consume($attestation->handle);
        $this->guardOwnership($user, $challenge);

        $credentialRecord = $this->check($user, $challenge, $attestation->credential);
        $passkey = $this->passkeyFrom($user, $credentialRecord, $attestation->label);
```

`src/Controller/Api/PasskeyController.php`, in `register()`:
```diff
-        $this->attestationVerifier->verifyAndStore($user, $request);
+        $this->attestationVerifier->verifyAndStore($user, $request->toAttestation());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Passkey tests/Service/Passkey tests/Controller/Api/PasskeyRegistrationTest.php tests/Controller/Api/PasskeyListTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `toAttestation()`, pass `label: $this->handle`. Expected: `testToAttestationCarriesTheHandleTheCredentialAndTheLabel` fails. Restore.
2. In `verifyAndStore()`, pass `$attestation->handle` instead of `$attestation->label` to `passkeyFrom()`. Run `php bin/phpunit tests/Service/Passkey tests/Controller/Api/PasskeyRegistrationTest.php tests/Controller/Api/PasskeyListTest.php`. Expected: a test that reads the stored label fails. If none does, report it: the label then needs a pin in `PasskeyRegistrationTest`. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the seven PHP files.
```bash
git add src/Service/Passkey/PasskeyAttestation.php src/Dto/Passkey/RegisterPasskeyRequest.php src/Service/Passkey/AttestationVerifier.php src/Controller/Api/PasskeyController.php tests/Dto/Passkey/RegisterPasskeyRequestTest.php tests/Service/Passkey/AttestationVerifierTest.php tests/Service/Passkey/AssertionVerifierTest.php
git commit -m "refactor(#1182): passkey registration takes a PasskeyAttestation"
```

---

### Task A6: `RelyingPartyIdChoice`

**Files:**
- Create: `src/Service/Settings/RelyingPartyIdChoice.php`
- Modify: `src/Dto/Admin/InstanceSettingsRequest.php` (#1167 A7a's version: one import, one method)
- Modify: `src/Service/Settings/RelyingPartyChange.php` (rewritten in full)
- Modify: `src/Controller/Admin/AdminSettingsController.php` (one line)
- Modify: `tests/Dto/Admin/InstanceSettingsRequestTest.php` (one test), `tests/Service/Settings/RelyingPartyChangeTest.php` (import, helper, five call sites)

**Interfaces:**
- Consumes: `InstanceSettingsRequest::__construct(bool $requireEmailConfirmation, bool $requireApproval, ?string $publicBaseUrl, ?string $passkeyRpId, ?string $passkeyRpName, bool $passkeySignInEnabled, bool $invalidateExistingPasskeys = false)` (#1167 A7a).
- Produces: `App\Service\Settings\RelyingPartyIdChoice::__construct(?string $passkeyRpId, bool $invalidateExistingPasskeys)`.
- Produces: `InstanceSettingsRequest::toRelyingPartyIdChoice(): RelyingPartyIdChoice`.
- Produces: `RelyingPartyChange::guardAndInvalidatePasskeysIfChanged(RelyingPartyIdChoice $choice): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Admin/InstanceSettingsRequestTest.php`, before the class's closing `}`, add:
```php

    public function testToRelyingPartyIdChoiceCarriesTheIdAndTheConfirmation(): void
    {
        $request = new InstanceSettingsRequest(
            requireEmailConfirmation: true,
            requireApproval: false,
            publicBaseUrl: 'https://reader.example.com',
            passkeyRpId: 'example.com',
            passkeyRpName: 'Reader',
            passkeySignInEnabled: true,
            invalidateExistingPasskeys: true,
        );

        self::assertSame(
            ['passkeyRpId' => 'example.com', 'invalidateExistingPasskeys' => true],
            get_object_vars($request->toRelyingPartyIdChoice()),
        );
    }
```

`tests/Service/Settings/RelyingPartyChangeTest.php`:
- Delete the line `use App\Dto\Admin\InstanceSettingsRequest;`, and insert `use App\Service\Settings\RelyingPartyIdChoice;` between `use App\Service\Settings\RelyingPartyChange;` and `use App\Service\Settings\RelyingPartyIdRule;`.
- Delete the line `use App\Tests\Support\SettingsRequests;` (only the helper below used it).
- Replace #1167 A7a's helper, located by its text:
```php
    private function requestFor(string $passkeyRpId, string $publicBaseUrl): InstanceSettingsRequest
    {
        return SettingsRequests::instance(
            publicBaseUrl: $publicBaseUrl,
            passkeyRpId: $passkeyRpId,
            passkeyRpName: 'Reader',
        );
    }
```
with:
```php
    private function choiceOf(string $passkeyRpId): RelyingPartyIdChoice
    {
        return new RelyingPartyIdChoice($passkeyRpId, invalidateExistingPasskeys: false);
    }
```
- Rewrite the five call sites (the serving host already comes from `change()`'s `$publicBaseUrl`):
```bash
perl -pi -e "s/\\\$this->requestFor\\(('[^']*'), '[^']*'\\)/\\\$this->choiceOf(\$1)/g" tests/Service/Settings/RelyingPartyChangeTest.php
git grep -nE 'requestFor|InstanceSettingsRequest|SettingsRequests' -- tests/Service/Settings/RelyingPartyChangeTest.php
git grep -c 'choiceOf(' -- tests/Service/Settings/RelyingPartyChangeTest.php
```
Expected: the first grep prints nothing; the count is `6` (five calls and the helper).

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Admin/InstanceSettingsRequestTest.php tests/Service/Settings/RelyingPartyChangeTest.php`
Expected: FAIL: `Call to undefined method …::toRelyingPartyIdChoice()` and `Class "App\Service\Settings\RelyingPartyIdChoice" not found`.

- [ ] **Step 3: Implement**

`src/Service/Settings/RelyingPartyIdChoice.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Settings;

/** The relying-party id an admin asks for (null: the derived default), and whether they confirmed losing passkeys. */
final readonly class RelyingPartyIdChoice
{
    public function __construct(
        public ?string $passkeyRpId,
        public bool $invalidateExistingPasskeys,
    ) {
    }
}
```

`src/Dto/Admin/InstanceSettingsRequest.php`:
- Insert `use App\Service\Settings\RelyingPartyIdChoice;` directly after `use App\Service\Settings\InstanceSettingsUpdate;`.
- After `toUpdate()`, before the class's closing `}`, add:
```php

    public function toRelyingPartyIdChoice(): RelyingPartyIdChoice
    {
        return new RelyingPartyIdChoice(
            passkeyRpId: $this->passkeyRpId,
            invalidateExistingPasskeys: $this->invalidateExistingPasskeys,
        );
    }
```

`src/Service/Settings/RelyingPartyChange.php` (rewritten in full; the class docblock is trimmed to the bar and no longer names the DTO):
```php
<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Exception\ValidationException;
use App\Repository\UserPasskeyRepository;
use App\Service\Settings\Exception\RelyingPartyChangeRequiresConfirmationException;

/**
 * A change of the EFFECTIVE relying-party id orphans every enrolled passkey, so it needs confirmation and then deletes
 * them all. The delete commits before the settings flush; a crash between the two is accepted.
 */
final readonly class RelyingPartyChange
{
    public function __construct(
        private PasskeyRelyingParty $relyingParty,
        private EffectivePasskeyRelyingPartyId $effectiveId,
        private UserPasskeyRepository $passkeys,
        private RelyingPartyIdRule $relyingPartyIdRule,
        private ServingHost $servingHost,
    ) {
    }

    /**
     * @throws ValidationException if the id could not work as a relying-party id at all
     * @throws RelyingPartyChangeRequiresConfirmationException if the effective id changes while passkeys exist
     *         and the change was not confirmed
     */
    public function guardAndInvalidatePasskeysIfChanged(RelyingPartyIdChoice $choice): void
    {
        $this->assertUsableRelyingPartyId($choice->passkeyRpId);

        $requestedEffectiveId = $this->effectiveId->derive($choice->passkeyRpId, $this->servingHost->get());
        if ($requestedEffectiveId === $this->relyingParty->id()) {
            return;
        }

        $enrolledCount = $this->passkeys->countAll();
        if (0 === $enrolledCount) {
            return;
        }

        if (!$choice->invalidateExistingPasskeys) {
            throw new RelyingPartyChangeRequiresConfirmationException($enrolledCount);
        }

        $this->passkeys->deleteAll();
    }

    private function assertUsableRelyingPartyId(?string $passkeyRpId): void
    {
        if (null === $passkeyRpId || $this->relyingPartyIdRule->isUsable($passkeyRpId)) {
            return;
        }

        throw new ValidationException([
            'passkeyRpId' => [
                'Must be a domain name, not an IP address or a bare top-level domain.',
            ],
        ]);
    }
}
```

`src/Controller/Admin/AdminSettingsController.php`, in `update()`:
```diff
-        $this->relyingPartyChange->guardAndInvalidatePasskeysIfChanged($request);
+        $this->relyingPartyChange->guardAndInvalidatePasskeysIfChanged($request->toRelyingPartyIdChoice());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Admin/InstanceSettingsRequestTest.php tests/Service/Settings tests/Controller/Admin/AdminSettingsControllerTest.php`
Expected: PASS. `AdminSettingsControllerTest` covers the 409 and the confirmed delete.

- [ ] **Step 5: Deletion checks**

1. In `toRelyingPartyIdChoice()`, pass `invalidateExistingPasskeys: false`. Expected: the new DTO test fails, and `AdminSettingsControllerTest`'s confirmed-change test fails too (the 409 comes back). Restore.
2. In `assertUsableRelyingPartyId()`, change `null === $passkeyRpId ||` to `null !== $passkeyRpId ||`. Expected: `RelyingPartyChangeTest::testASingleLabelRelyingPartyIdIsRefused` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the six PHP files.
```bash
git add src/Service/Settings/RelyingPartyIdChoice.php src/Dto/Admin/InstanceSettingsRequest.php src/Service/Settings/RelyingPartyChange.php src/Controller/Admin/AdminSettingsController.php tests/Dto/Admin/InstanceSettingsRequestTest.php tests/Service/Settings/RelyingPartyChangeTest.php
git commit -m "refactor(#1182): the relying-party guard takes a RelyingPartyIdChoice"
```

---

### Task A7: `ClientError`

**Files:**
- Create: `src/Service/ClientError/ClientError.php`
- Modify: `src/Dto/ClientError/ClientErrorItem.php` (`toClientError()`), `src/Dto/ClientError/ClientErrorReportRequest.php` (`toClientErrors()`)
- Modify: `src/Service/ClientError/ClientErrorScrubber.php`, `src/Service/ClientError/ClientErrorRecorder.php` (perl)
- Modify: `src/Controller/Api/ClientErrorController.php` (one line)
- Modify: `tests/Dto/ClientError/ClientErrorItemTest.php`, `tests/Dto/ClientError/ClientErrorReportRequestTest.php` (one test each), `tests/Service/ClientError/ClientErrorScrubberTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\ClientError\ClientError::__construct(string $message, ?string $stack, ?string $kind, ?string $url, ?string $route, ?string $buildVersion, ?string $userAgent, ?string $at)`.
- Produces: `ClientErrorItem::toClientError(): ClientError`; `ClientErrorReportRequest::toClientErrors(): list<ClientError>`.
- Produces: `ClientErrorScrubber::scrub(ClientError $clientError): ClientError`; `ClientErrorRecorder::record(list<ClientError> $clientErrors, ?User $user): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/ClientError/ClientErrorItemTest.php`, before the class's closing `}`, add:
```php

    public function testToClientErrorCarriesEveryField(): void
    {
        $item = new ClientErrorItem('m', 's', 'k', 'u', 'r', 'b', 'a', 't');

        self::assertSame(
            [
                'message' => 'm',
                'stack' => 's',
                'kind' => 'k',
                'url' => 'u',
                'route' => 'r',
                'buildVersion' => 'b',
                'userAgent' => 'a',
                'at' => 't',
            ],
            get_object_vars($item->toClientError()),
        );
    }
```

`tests/Dto/ClientError/ClientErrorReportRequestTest.php`, before the class's closing `}`, add:
```php

    public function testToClientErrorsKeepsEveryReportedErrorInOrder(): void
    {
        $request = new ClientErrorReportRequest([new ClientErrorItem('first'), new ClientErrorItem('second')]);

        self::assertSame(
            ['first', 'second'],
            array_map(static fn (ClientError $error): string => $error->message, $request->toClientErrors()),
        );
    }
```
and add `use App\Service\ClientError\ClientError;` after `use App\Dto\ClientError\ClientErrorReportRequest;`.

Migrate the scrubber test:
```bash
perl -pi -e 's/^use App\\Dto\\ClientError\\ClientErrorItem;$/use App\\Service\\ClientError\\ClientError;/; s/\bClientErrorItem\b/ClientError/g' tests/Service/ClientError/ClientErrorScrubberTest.php
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/ClientError tests/Service/ClientError`
Expected: FAIL: `Call to undefined method …::toClientError()`/`toClientErrors()` and `Class "App\Service\ClientError\ClientError" not found`.

- [ ] **Step 3: Implement**

`src/Service/ClientError/ClientError.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\ClientError;

/** One error a client reported, as the recorder scrubs and logs it. */
final readonly class ClientError
{
    public function __construct(
        public string $message,
        public ?string $stack,
        public ?string $kind,
        public ?string $url,
        public ?string $route,
        public ?string $buildVersion,
        public ?string $userAgent,
        public ?string $at,
    ) {
    }
}
```

`src/Dto/ClientError/ClientErrorItem.php`:
- Insert `use App\Service\ClientError\ClientError;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toClientError(): ClientError
    {
        return new ClientError(
            message: $this->message,
            stack: $this->stack,
            kind: $this->kind,
            url: $this->url,
            route: $this->route,
            buildVersion: $this->buildVersion,
            userAgent: $this->userAgent,
            at: $this->at,
        );
    }
```

`src/Dto/ClientError/ClientErrorReportRequest.php`:
- Insert `use App\Service\ClientError\ClientError;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    /** @return list<ClientError> */
    public function toClientErrors(): array
    {
        return array_map(static fn (ClientErrorItem $item): ClientError => $item->toClientError(), $this->errors);
    }
```

`src/Service/ClientError/ClientErrorScrubber.php` and `ClientErrorRecorder.php` (same namespace as the value; the DTO import and the blank line after it go):
```bash
perl -0pi -e 's/use App\\Dto\\ClientError\\ClientErrorItem;\n\n//; s/use App\\Dto\\ClientError\\ClientErrorItem;\n//; s/ClientErrorItem \$item/ClientError \$clientError/g; s/list<ClientErrorItem> \$items/list<ClientError> \$clientErrors/; s/array \$items/array \$clientErrors/; s/foreach \(\$items as \$item\)/foreach (\$clientErrors as \$clientError)/; s/scrub\(\$item\)/scrub(\$clientError)/; s/\): ClientErrorItem/): ClientError/g; s/new ClientErrorItem\(/new ClientError(/g; s/\$item->/\$clientError->/g' src/Service/ClientError/ClientErrorScrubber.php src/Service/ClientError/ClientErrorRecorder.php
git grep -nE 'ClientErrorItem|\$items?([^A-Za-z]|$)' -- src/Service/ClientError
```
Expected: the grep prints nothing. The results read:
```php
    public function scrub(ClientError $clientError): ClientError
    {
        return new ClientError(
            message: $this->redact($clientError->message),
            stack: null === $clientError->stack ? null : $this->redact($clientError->stack),
            kind: $clientError->kind,
            url: $this->stripQueryAndFragment($clientError->url),
            route: $this->stripQueryAndFragment($clientError->route),
            buildVersion: $clientError->buildVersion,
            userAgent: $clientError->userAgent,
            at: $clientError->at,
        );
    }
```
```php
    /**
     * @param list<ClientError> $clientErrors
     */
    public function record(array $clientErrors, ?User $user): void
    {
        foreach ($clientErrors as $clientError) {
            $scrubbed = $this->scrubber->scrub($clientError);
            $this->logger->error($scrubbed->message, $this->context($scrubbed, $user));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ClientError $clientError, ?User $user): array
```
If the scrubber file now opens `namespace App\Service\ClientError;` followed by two blank lines, delete one (`composer cs` reports it).

`src/Controller/Api/ClientErrorController.php`:
```diff
-        $this->recorder->record($request->errors, $user);
+        $this->recorder->record($request->toClientErrors(), $user);
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/ClientError tests/Service/ClientError tests/Controller/Api/ClientErrorControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `ClientErrorItem::toClientError()`, pass `route: $this->url`. Expected: `testToClientErrorCarriesEveryField` and `ClientErrorControllerTest::testContextCarriesEveryReportedField` fail. Restore.
2. In `toClientErrors()`, wrap the result in `array_reverse(…)`. Expected: `testToClientErrorsKeepsEveryReportedErrorInOrder` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the nine PHP files. The Infection ignore for `ClientErrorScrubber::redact` still names an existing method.
```bash
git add src/Service/ClientError src/Dto/ClientError src/Controller/Api/ClientErrorController.php tests/Dto/ClientError tests/Service/ClientError/ClientErrorScrubberTest.php
git commit -m "refactor(#1182): client error recording takes ClientError values"
```

---

### Task A8: `CatalogCategoryDetails`, `CatalogFeedDetails`, catalog reorder ids

**Files:**
- Create: `src/Service/Catalog/CatalogCategoryDetails.php`, `src/Service/Catalog/CatalogFeedDetails.php`
- Create: `tests/Dto/Admin/CatalogCategoryRequestTest.php`, `tests/Dto/Admin/CatalogFeedRequestTest.php`
- Modify: `src/Dto/Admin/CatalogCategoryRequest.php`, `src/Dto/Admin/CatalogFeedRequest.php` (`toDetails()`)
- Modify: `src/Service/Catalog/CatalogCategoryEditor.php`, `src/Service/Catalog/CatalogFeedEditor.php` (rewritten in full)
- Modify: `src/Controller/Admin/AdminCatalogCategoryController.php`, `src/Controller/Admin/AdminCatalogFeedController.php` (three lines each)
- Modify: `tests/Service/Catalog/CatalogCategoryEditorTest.php`, `tests/Service/Catalog/CatalogFeedEditorTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Catalog\CatalogCategoryDetails::__construct(string $key, string $name, string $icon, string $color = '#000000', bool $enabled = true, bool $locked = true)`.
- Produces: `App\Service\Catalog\CatalogFeedDetails::__construct(int $categoryId, string $title, string $url, ?string $siteUrl = null, ?string $description = null, string $sourceFormat = SourceFormat::XML, bool $enabled = true, bool $locked = true)`.
- Produces: `CatalogCategoryRequest::toDetails(): CatalogCategoryDetails`, `CatalogFeedRequest::toDetails(): CatalogFeedDetails`.
- Produces: `CatalogCategoryEditor::create(CatalogCategoryDetails): CatalogCategory`, `update(CatalogCategory, CatalogCategoryDetails): void`, `reorder(list<int> $orderedIds): void`; `CatalogFeedEditor::create(CatalogFeedDetails): CatalogFeed`, `update(CatalogFeed, CatalogFeedDetails): void`, `reorder(list<int> $orderedIds): void`. `delete()` unchanged on both.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Admin/CatalogCategoryRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\CatalogCategoryRequest;
use PHPUnit\Framework\TestCase;

final class CatalogCategoryRequestTest extends TestCase
{
    public function testToDetailsCarriesEveryField(): void
    {
        $details = (new CatalogCategoryRequest('tech', 'Tech', 'bolt', '#112233', false, true))->toDetails();

        self::assertSame(
            [
                'key' => 'tech',
                'name' => 'Tech',
                'icon' => 'bolt',
                'color' => '#112233',
                'enabled' => false,
                'locked' => true,
            ],
            get_object_vars($details),
        );
    }
}
```

`tests/Dto/Admin/CatalogFeedRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\CatalogFeedRequest;
use App\Enum\SourceFormat;
use PHPUnit\Framework\TestCase;

final class CatalogFeedRequestTest extends TestCase
{
    public function testToDetailsCarriesEveryField(): void
    {
        $request = new CatalogFeedRequest(
            4,
            'Title',
            'https://feed.example.com/rss',
            'https://feed.example.com',
            'About',
            SourceFormat::SCRAPED,
            false,
            true,
        );

        self::assertSame(
            [
                'categoryId' => 4,
                'title' => 'Title',
                'url' => 'https://feed.example.com/rss',
                'siteUrl' => 'https://feed.example.com',
                'description' => 'About',
                'sourceFormat' => SourceFormat::SCRAPED,
                'enabled' => false,
                'locked' => true,
            ],
            get_object_vars($request->toDetails()),
        );
    }
}
```

Migrate the service tests:
```bash
perl -pi -e 's/^use App\\Dto\\Admin\\CatalogCategoryRequest;$/use App\\Service\\Catalog\\CatalogCategoryDetails;/; s/^use App\\Dto\\Admin\\CatalogFeedRequest;$/use App\\Service\\Catalog\\CatalogFeedDetails;/; $_ = "" if /^use App\\Dto\\Admin\\ReorderRequest;$/; s/\bCatalogCategoryRequest\b/CatalogCategoryDetails/g; s/\bCatalogFeedRequest\b/CatalogFeedDetails/g; s/new ReorderRequest\((\[[^\]]*\])\)/$1/g; s/\$this->request\(/\$this->details(/g; s/private function request\(/private function details(/' tests/Service/Catalog/CatalogCategoryEditorTest.php tests/Service/Catalog/CatalogFeedEditorTest.php
git grep -nE 'Dto|Request([^a-z]|$)|request\(' -- tests/Service/Catalog/CatalogCategoryEditorTest.php tests/Service/Catalog/CatalogFeedEditorTest.php
```
Expected: the grep prints nothing.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Admin/CatalogCategoryRequestTest.php tests/Dto/Admin/CatalogFeedRequestTest.php tests/Service/Catalog/CatalogCategoryEditorTest.php tests/Service/Catalog/CatalogFeedEditorTest.php`
Expected: FAIL: `Call to undefined method …::toDetails()` and `Class "App\Service\Catalog\CatalogCategoryDetails" not found`.

- [ ] **Step 3: Implement**

`src/Service/Catalog/CatalogCategoryDetails.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/** A catalog category as the admin edits it. The key is set once, on create. */
final readonly class CatalogCategoryDetails
{
    public function __construct(
        public string $key,
        public string $name,
        public string $icon,
        public string $color = '#000000',
        public bool $enabled = true,
        public bool $locked = true,
    ) {
    }
}
```
Hex colours are forbidden only in `.scss`; this default mirrors the DTO's.

`src/Service/Catalog/CatalogFeedDetails.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Enum\SourceFormat;

/** A catalog feed as the admin edits it. */
final readonly class CatalogFeedDetails
{
    public function __construct(
        public int $categoryId,
        public string $title,
        public string $url,
        public ?string $siteUrl = null,
        public ?string $description = null,
        public string $sourceFormat = SourceFormat::XML,
        public bool $enabled = true,
        public bool $locked = true,
    ) {
    }
}
```

`src/Dto/Admin/CatalogCategoryRequest.php`:
- Insert `use App\Service\Catalog\CatalogCategoryDetails;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toDetails(): CatalogCategoryDetails
    {
        return new CatalogCategoryDetails(
            key: $this->key,
            name: $this->name,
            icon: $this->icon,
            color: $this->color,
            enabled: $this->enabled,
            locked: $this->locked,
        );
    }
```

`src/Dto/Admin/CatalogFeedRequest.php`:
- Insert `use App\Service\Catalog\CatalogFeedDetails;` directly after `use App\Enum\SourceFormat;`.
- Before the class's closing `}`, add:
```php

    public function toDetails(): CatalogFeedDetails
    {
        return new CatalogFeedDetails(
            categoryId: $this->categoryId,
            title: $this->title,
            url: $this->url,
            siteUrl: $this->siteUrl,
            description: $this->description,
            sourceFormat: $this->sourceFormat,
            enabled: $this->enabled,
            locked: $this->locked,
        );
    }
```

`src/Service/Catalog/CatalogCategoryEditor.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogCategory;
use App\Repository\CatalogCategoryRepository;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogCategoryEditor
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CatalogCategoryDetails $details): CatalogCategory
    {
        $category = new CatalogCategory($details->key, $details->name, $details->icon, $details->color);
        $category->setEnabled($details->enabled);
        $category->setLocked($details->locked);
        $category->setPosition($this->categories->nextPosition());
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function update(CatalogCategory $category, CatalogCategoryDetails $details): void
    {
        $category->setName($details->name);
        $category->setIcon($details->icon);
        $category->setColor($details->color);
        $category->setEnabled($details->enabled);
        $category->setLocked($details->locked);
        $this->entityManager->flush();
    }

    public function delete(CatalogCategory $category): void
    {
        // The FK cascades to its catalog feeds; users' subscriptions are Feed rows and stay untouched.
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    /** @param list<int> $orderedIds */
    public function reorder(array $orderedIds): void
    {
        $byId = [];
        foreach ($orderedIds as $id) {
            $byId[$id] = $this->categories->getById($id);
        }
        $this->reorderer->reorder($orderedIds, $byId);
    }
}
```

`src/Service/Catalog/CatalogFeedEditor.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogFeedEditor
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CatalogFeedDetails $details): CatalogFeed
    {
        $category = $this->categories->getById($details->categoryId);
        $feed = new CatalogFeed($category, $details->title, $details->url);
        $this->applyEditableFields($feed, $details);
        $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    public function update(CatalogFeed $feed, CatalogFeedDetails $details): void
    {
        $feed->setCategory($this->categories->getById($details->categoryId));
        $feed->setTitle($details->title);
        $feed->setUrl($details->url);
        $this->applyEditableFields($feed, $details);
        $this->entityManager->flush();
    }

    public function delete(CatalogFeed $feed): void
    {
        $this->entityManager->remove($feed);
        $this->entityManager->flush();
    }

    /** @param list<int> $orderedIds */
    public function reorder(array $orderedIds): void
    {
        $byId = [];
        foreach ($orderedIds as $id) {
            $byId[$id] = $this->feeds->getById($id);
        }
        $this->reorderer->reorder($orderedIds, $byId);
    }

    private function applyEditableFields(CatalogFeed $feed, CatalogFeedDetails $details): void
    {
        $feed->setSiteUrl($details->siteUrl);
        $feed->setDescription($details->description);
        $feed->setSourceFormat($details->sourceFormat);
        $feed->setEnabled($details->enabled);
        $feed->setLocked($details->locked);
    }
}
```

`src/Controller/Admin/AdminCatalogCategoryController.php`:
```diff
-            ['category' => AdminCatalogJson::category($this->editor->create($request))],
+            ['category' => AdminCatalogJson::category($this->editor->create($request->toDetails()))],
```
```diff
-        $this->editor->reorder($request);
+        $this->editor->reorder($request->ids);
```
```diff
-        $this->editor->update($category, $request);
+        $this->editor->update($category, $request->toDetails());
```

`src/Controller/Admin/AdminCatalogFeedController.php`:
```diff
-            ['feed' => AdminCatalogJson::feed($this->editor->create($request))],
+            ['feed' => AdminCatalogJson::feed($this->editor->create($request->toDetails()))],
```
```diff
-        $this->editor->reorder($request);
+        $this->editor->reorder($request->ids);
```
```diff
-        $this->editor->update($feed, $request);
+        $this->editor->update($feed, $request->toDetails());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Admin tests/Service/Catalog tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `CatalogFeedRequest::toDetails()`, pass `siteUrl: $this->description`. Expected: `CatalogFeedRequestTest` fails. Restore.
2. In `CatalogCategoryRequest::toDetails()`, pass `enabled: $this->locked`. Expected: `CatalogCategoryRequestTest` fails. Restore.
3. In `CatalogCategoryEditor::reorder()`, pass `array_reverse($orderedIds)` to `$this->reorderer->reorder(`. Expected: `testReorderGivesEachCategoryItsIndex` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the twelve PHP files.
```bash
git add src/Service/Catalog/CatalogCategoryDetails.php src/Service/Catalog/CatalogFeedDetails.php src/Dto/Admin/CatalogCategoryRequest.php src/Dto/Admin/CatalogFeedRequest.php src/Service/Catalog/CatalogCategoryEditor.php src/Service/Catalog/CatalogFeedEditor.php src/Controller/Admin/AdminCatalogCategoryController.php src/Controller/Admin/AdminCatalogFeedController.php tests/Dto/Admin/CatalogCategoryRequestTest.php tests/Dto/Admin/CatalogFeedRequestTest.php tests/Service/Catalog/CatalogCategoryEditorTest.php tests/Service/Catalog/CatalogFeedEditorTest.php
git commit -m "refactor(#1182): catalog edits take details values and plain ids"
```

---

### Task A9: `SavedSearchDefinition`; digest inclusion takes a plain value

**Files:**
- Create: `src/Service/Search/SavedSearchDefinition.php`
- Create: `tests/Dto/SavedSearch/CreateSavedSearchRequestTest.php`
- Modify: `src/Dto/SavedSearch/CreateSavedSearchRequest.php` (`toDefinition()`)
- Modify: `src/Service/Search/SavedSearchEditor.php` (rewritten in full)
- Modify: `src/Controller/Api/SavedSearchController.php` (two lines)
- Modify: `tests/Service/Search/SavedSearchEditorTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Search\SavedSearchDefinition::__construct(string $term, bool $wholeWord = false, bool $phrase = false)`.
- Produces: `CreateSavedSearchRequest::toDefinition(): SavedSearchDefinition`.
- Produces: `SavedSearchEditor::save(User, SavedSearchDefinition): SavedSearchOutcome`, `changeDigestInclusion(SavedSearch, bool $includeInDigest): void` (D2); `delete()` unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/SavedSearch/CreateSavedSearchRequestTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\SavedSearch;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use PHPUnit\Framework\TestCase;

final class CreateSavedSearchRequestTest extends TestCase
{
    public function testToDefinitionCarriesTheTermAndBothMatchModes(): void
    {
        $definition = (new CreateSavedSearchRequest('climate', true, false))->toDefinition();

        self::assertSame(['term' => 'climate', 'wholeWord' => true, 'phrase' => false], get_object_vars($definition));
    }

    public function testToDefinitionKeepsThePhraseModeApartFromTheWholeWordMode(): void
    {
        $definition = (new CreateSavedSearchRequest('climate', false, true))->toDefinition();

        self::assertSame(['term' => 'climate', 'wholeWord' => false, 'phrase' => true], get_object_vars($definition));
    }
}
```

Migrate the service test:
```bash
perl -pi -e 's/^use App\\Dto\\SavedSearch\\CreateSavedSearchRequest;$/use App\\Service\\Search\\SavedSearchDefinition;/; $_ = "" if /^use App\\Dto\\SavedSearch\\UpdateSavedSearchRequest;$/; s/\bCreateSavedSearchRequest\b/SavedSearchDefinition/g; s/new UpdateSavedSearchRequest\((\$\w+)\)/$1/g' tests/Service/Search/SavedSearchEditorTest.php
git grep -nE 'Dto|Request' -- tests/Service/Search/SavedSearchEditorTest.php
```
Expected: the grep prints nothing.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/SavedSearch tests/Service/Search/SavedSearchEditorTest.php`
Expected: FAIL: `Call to undefined method …::toDefinition()` and `Class "App\Service\Search\SavedSearchDefinition" not found`.

- [ ] **Step 3: Implement**

`src/Service/Search/SavedSearchDefinition.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

final readonly class SavedSearchDefinition
{
    public function __construct(
        public string $term,
        public bool $wholeWord = false,
        public bool $phrase = false,
    ) {
    }
}
```

`src/Dto/SavedSearch/CreateSavedSearchRequest.php`:
- Insert `use App\Service\Search\SavedSearchDefinition;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toDefinition(): SavedSearchDefinition
    {
        return new SavedSearchDefinition(term: $this->term, wholeWord: $this->wholeWord, phrase: $this->phrase);
    }
```

`src/Service/Search/SavedSearchEditor.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedSearchEditor
{
    private const int CREATE_SWEEP_BUDGET_SECONDS = 8;

    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchMembershipSweep $sweep,
        private SavedSearchSlug $slug,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user, SavedSearchDefinition $definition): SavedSearchOutcome
    {
        $existing = $this->savedSearches->findOneForUserByTerm(
            $user->requireId(),
            $definition->term,
            $definition->wholeWord,
            $definition->phrase,
        );
        if (null !== $existing) {
            return SavedSearchOutcome::existing($existing);
        }

        return SavedSearchOutcome::created($this->create($user, $definition));
    }

    public function changeDigestInclusion(SavedSearch $savedSearch, bool $includeInDigest): void
    {
        $savedSearch->setIncludeInDigest($includeInDigest);
        $this->entityManager->flush();
    }

    public function delete(SavedSearch $savedSearch): void
    {
        $this->entityManager->remove($savedSearch);
        $this->entityManager->flush();
    }

    private function create(User $user, SavedSearchDefinition $definition): SavedSearch
    {
        $savedSearch = new SavedSearch($user, $definition->term, $definition->wholeWord, $definition->phrase);
        $this->entityManager->persist($savedSearch);
        $this->entityManager->flush();
        $this->slug->assignTo($savedSearch);
        $this->entityManager->flush();
        $this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));

        return $savedSearch;
    }
}
```

`src/Controller/Api/SavedSearchController.php`:
```diff
-        $outcome = $this->editor->save($user, $request);
+        $outcome = $this->editor->save($user, $request->toDefinition());
```
```diff
-        $this->editor->changeDigestInclusion($savedSearch, $request);
+        $this->editor->changeDigestInclusion($savedSearch, $request->includeInDigest);
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/SavedSearch tests/Service/Search/SavedSearchEditorTest.php tests/Controller/Api/SavedSearchControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `toDefinition()`, pass `phrase: $this->wholeWord`. Expected: both DTO tests fail. Restore.
2. In `SavedSearchEditor::save()`, pass `$definition->phrase` as the third argument to `findOneForUserByTerm(`. Expected: `testSavingAnAlreadySavedTermReturnsTheExistingRow` fails (the whole-word search is not found again). Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the six PHP files.
```bash
git add src/Service/Search/SavedSearchDefinition.php src/Dto/SavedSearch/CreateSavedSearchRequest.php src/Service/Search/SavedSearchEditor.php src/Controller/Api/SavedSearchController.php tests/Dto/SavedSearch/CreateSavedSearchRequestTest.php tests/Service/Search/SavedSearchEditorTest.php
git commit -m "refactor(#1182): saved search edits take a SavedSearchDefinition and a plain flag value"
```

---

### Task A10: `TagDetails`; tag ordering takes ids

**Files:**
- Create: `src/Service/Tag/TagDetails.php`
- Create: `tests/Dto/Tag/TagRequestsTest.php`
- Modify: `src/Dto/Tag/CreateTagRequest.php`, `src/Dto/Tag/UpdateTagRequest.php` (`toDetails()`)
- Modify: `src/Service/Tag/TagEditor.php`, `src/Service/Tag/TagOrdering.php` (rewritten in full)
- Modify: `src/Controller/Api/TagController.php` (four lines)
- Modify: `tests/Service/Tag/TagEditorTest.php`, `tests/Service/Tag/TagOrderingTest.php` (perl)

**Interfaces:**
- Produces: `App\Service\Tag\TagDetails::__construct(string $name, ?string $color = null, ?string $icon = null)`.
- Produces: `CreateTagRequest::toDetails(): TagDetails`, `UpdateTagRequest::toDetails(): TagDetails`.
- Produces: `TagEditor::create(User, TagDetails): Tag`, `update(Tag, TagDetails): void`; `delete()` unchanged.
- Produces: `TagOrdering::reorder(User $user, list<int> $tagIds): list<Tag>`, `orderFeeds(Tag $tag, list<int> $subscriptionIds): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Tag/TagRequestsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Tag;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use PHPUnit\Framework\TestCase;

final class TagRequestsTest extends TestCase
{
    public function testACreateRequestCarriesTheNameTheColourAndTheIcon(): void
    {
        $details = (new CreateTagRequest('News', '#ff8800', 'star'))->toDetails();

        self::assertSame(['name' => 'News', 'color' => '#ff8800', 'icon' => 'star'], get_object_vars($details));
    }

    public function testAnUpdateRequestCarriesTheNameTheColourAndTheIcon(): void
    {
        $details = (new UpdateTagRequest('Tech', '#000000', 'label'))->toDetails();

        self::assertSame(['name' => 'Tech', 'color' => '#000000', 'icon' => 'label'], get_object_vars($details));
    }
}
```

Migrate the service tests:
```bash
perl -pi -e 's/^use App\\Dto\\Tag\\CreateTagRequest;$/use App\\Service\\Tag\\TagDetails;/; $_ = "" if /^use App\\Dto\\Tag\\(UpdateTagRequest|ReorderTagsRequest|TagFeedOrderRequest);$/; s/\b(Create|Update)TagRequest\b/TagDetails/g; s/new (ReorderTagsRequest|TagFeedOrderRequest)\((\[[^\]]*\])\)/$2/g' tests/Service/Tag/TagEditorTest.php tests/Service/Tag/TagOrderingTest.php
git grep -nE 'Dto|Request' -- tests/Service/Tag/TagEditorTest.php tests/Service/Tag/TagOrderingTest.php
```
Expected: the grep prints nothing. `TagOrderingTest` now calls `$this->ordering()->reorder($user, [$third->requireId(), $first->requireId(), $second->requireId()])` and `orderFeeds($tag, [999999])`.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Tag tests/Service/Tag`
Expected: FAIL: `Call to undefined method …::toDetails()`, `Class "App\Service\Tag\TagDetails" not found`, and `TypeError`s in `TagOrderingTest` (an array where a request is expected).

- [ ] **Step 3: Implement**

`src/Service/Tag/TagDetails.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Tag;

final readonly class TagDetails
{
    public function __construct(
        public string $name,
        public ?string $color = null,
        public ?string $icon = null,
    ) {
    }
}
```

`src/Dto/Tag/CreateTagRequest.php` and `src/Dto/Tag/UpdateTagRequest.php`, each:
- Insert `use App\Service\Tag\TagDetails;` directly before `use Symfony\Component\Validator\Constraints as Assert;`.
- Before the class's closing `}`, add:
```php

    public function toDetails(): TagDetails
    {
        return new TagDetails(name: $this->name, color: $this->color, icon: $this->icon);
    }
```

`src/Service/Tag/TagEditor.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Tag\Exception\TagNameTakenException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagEditor
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $user, TagDetails $details): Tag
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $details->name)) {
            throw new TagNameTakenException();
        }

        $tag = new Tag($user, $details->name);
        $tag->setColor($details->color);
        $tag->setIcon($details->icon);
        $tag->setPosition($this->tags->nextPositionForUser($user->requireId()));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    public function update(Tag $tag, TagDetails $details): void
    {
        if ($this->tags->existsForUserAndName($tag->getUser()->requireId(), $details->name, $tag->requireId())) {
            throw new TagNameTakenException();
        }

        $tag->setName($details->name);
        $tag->setColor($details->color);
        $tag->setIcon($details->icon);
        $this->entityManager->flush();
    }

    public function delete(Tag $tag): void
    {
        $carriers = $this->subscriptions->findForUserByTagId($tag->getUser()->requireId(), $tag->requireId());
        foreach ($carriers as $subscription) {
            $subscription->removeTag($tag);
        }
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }
}
```
The repository calls are the ones `TagEditor` makes at `a124ad8a` (`existsForUserAndName`, `nextPositionForUser`, `findForUserByTagId`; #1167 kept them).

`src/Service/Tag/TagOrdering.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Ordering\PositionReorderer;
use App\Service\Reader\ExactSetGuard;

final readonly class TagOrdering
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private ExactSetGuard $exactSet,
        private PositionReorderer $reorderer,
    ) {
    }

    /**
     * @param list<int> $tagIds the user's tags in their new order
     *
     * @return list<Tag>
     */
    public function reorder(User $user, array $tagIds): array
    {
        $byId = $this->ownedTagsById($user);
        $this->exactSet->assertPermutation($tagIds, array_keys($byId), 'tagIds must list exactly your tags.');
        $this->reorderer->reorder($tagIds, $byId);

        return array_map(static fn (int $id): Tag => $byId[$id], $tagIds);
    }

    /** @param list<int> $subscriptionIds the tag's feeds in their new order */
    public function orderFeeds(Tag $tag, array $subscriptionIds): void
    {
        $joinsBySubscriptionId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->exactSet->assertPermutation(
            $subscriptionIds,
            array_keys($joinsBySubscriptionId),
            "subscriptionIds must list exactly this tag's feeds.",
        );
        $this->reorderer->reorder($subscriptionIds, $joinsBySubscriptionId);
    }

    /** @return array<int, Tag> */
    private function ownedTagsById(User $user): array
    {
        $byId = [];
        foreach ($this->tags->findForUser($user->requireId()) as $tag) {
            $byId[$tag->requireId()] = $tag;
        }

        return $byId;
    }
}
```

`src/Controller/Api/TagController.php`:
```diff
-            ['tag' => TagJson::one($this->editor->create($user, $request))],
+            ['tag' => TagJson::one($this->editor->create($user, $request->toDetails()))],
```
```diff
-                $this->ordering->reorder($user, $request),
+                $this->ordering->reorder($user, $request->tagIds),
```
```diff
-        $this->ordering->orderFeeds($tag, $request);
+        $this->ordering->orderFeeds($tag, $request->subscriptionIds);
```
```diff
-        $this->editor->update($tag, $request);
+        $this->editor->update($tag, $request->toDetails());
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Dto/Tag tests/Service/Tag tests/Controller/Api/TagControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `UpdateTagRequest::toDetails()`, pass `icon: $this->color`. Expected: `testAnUpdateRequestCarriesTheNameTheColourAndTheIcon` fails. Restore.
2. In `TagOrdering::reorder()`, return `array_map(static fn (int $id): Tag => $byId[$id], array_reverse($tagIds))`. Expected: `testReorderGivesEachTagItsIndexAndReturnsThemInThatOrder` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the eight PHP files.
```bash
git add src/Service/Tag/TagDetails.php src/Dto/Tag/CreateTagRequest.php src/Dto/Tag/UpdateTagRequest.php src/Service/Tag/TagEditor.php src/Service/Tag/TagOrdering.php src/Controller/Api/TagController.php tests/Dto/Tag/TagRequestsTest.php tests/Service/Tag/TagEditorTest.php tests/Service/Tag/TagOrderingTest.php
git commit -m "refactor(#1182): tag edits take TagDetails and tag ordering takes ids"
```

---

### Task A11: The types misfiled under `Dto` move into their services

**Files:**
- Move: `src/Dto/OAuth/OAuthIdentity.php`, `OAuthStartState.php`, `OAuthCallbackAttempt.php` → `src/Service/OAuth/`
- Move: `src/Dto/Mail/PendingApprovalNotice.php` → `src/Service/Mail/PendingApprovalNotice.php`
- Move: `src/Dto/Admin/UserFootprint.php` → `src/Service/Admin/UserFootprint.php`
- Move: `tests/Dto/OAuth/OAuthIdentityTest.php` → `tests/Service/OAuth/OAuthIdentityTest.php`
- Modify (names only, by perl): every `src` and `tests` file that names one of the five. At `a124ad8a` that is, in `src`: `Controller/Api/OAuthController.php`, `EventListener/NotifyAdminsOfPendingApproval.php`, `Http/AdminUserJson.php`, `Service/Admin/UserStatistics.php`, `Service/Mail/AccountMailer.php`, `AccountMailerInterface.php`, `MailGatedAccountMailer.php`, `Service/OAuth/AbstractOidcProvider.php`, `OAuthAccountLinker.php`, `OAuthCallback.php`, `OAuthProviderInterface.php`, `OAuthSignIn.php`, `OAuthStateStore.php`, `Oidc/IdTokenVerifier.php`; in `tests`: `Controller/Api/OAuthFlowTest.php`, `Service/Mail/AccountMailerTest.php`, `Service/OAuth/OAuthAccountLinkerTest.php`, `OAuthCallbackTest.php`, `OAuthProviderRegistryTest.php`, `OAuthSignInTest.php`, `Oidc/IdTokenVerifierTest.php`, `Support/FakeOAuthProvider.php` (plus the moved `OAuthIdentityTest`). `RegistrationTest` and `AbstractOidcProviderTest` mention `OAuthIdentity` only in a comment by its short name and stay as they are.
- Modify: `src/Dto/Admin/AdminUserFootprint.php` (one docblock reference)
- Rewrite in full after the move (comments only, D4): the five moved classes and `tests/Service/OAuth/OAuthIdentityTest.php` (Step 3b)

**Interfaces:**
- Produces: `App\Service\OAuth\OAuthIdentity`, `App\Service\OAuth\OAuthStartState`, `App\Service\OAuth\OAuthCallbackAttempt`, `App\Service\Mail\PendingApprovalNotice`, `App\Service\Admin\UserFootprint`. Constructors and members unchanged.

Each moved class keeps its code; its comments are brought to the bar, and Step 3b shows its final content (D4). `OAuthIdentity`'s security reasoning stays in short form. The OIDC boundary is a security control: nothing in `IdToken*` changes except `IdTokenVerifier`'s one import line, and `OidcBoundaryTest` stays as it is.

- [ ] **Step 1: Point the tests at the new names**

```bash
git mv tests/Dto/OAuth/OAuthIdentityTest.php tests/Service/OAuth/OAuthIdentityTest.php
perl -pi -e 's/^namespace App\\Tests\\Dto\\OAuth;$/namespace App\\Tests\\Service\\OAuth;/' tests/Service/OAuth/OAuthIdentityTest.php
git grep -lF -e 'App\Dto\OAuth\OAuthIdentity' -e 'App\Dto\OAuth\OAuthStartState' -e 'App\Dto\OAuth\OAuthCallbackAttempt' -e 'App\Dto\Mail\PendingApprovalNotice' -e 'App\Dto\Admin\UserFootprint' -- tests \
  | xargs perl -pi -e 's/App\\Dto\\OAuth\\(OAuthIdentity|OAuthStartState|OAuthCallbackAttempt)(?!\w)/App\\Service\\OAuth\\$1/g; s/App\\Dto\\Mail\\PendingApprovalNotice(?!\w)/App\\Service\\Mail\\PendingApprovalNotice/g; s/App\\Dto\\Admin\\UserFootprint(?!\w)/App\\Service\\Admin\\UserFootprint/g'
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/OAuth tests/Service/Mail/AccountMailerTest.php tests/Controller/Api/OAuthFlowTest.php`
Expected: FAIL with `Class "App\Service\OAuth\OAuthIdentity" not found` (and the `OAuthStartState`, `OAuthCallbackAttempt`, `PendingApprovalNotice` equivalents).

- [ ] **Step 3: Move the classes and rewrite the names**

```bash
git mv src/Dto/OAuth/OAuthIdentity.php src/Service/OAuth/OAuthIdentity.php
git mv src/Dto/OAuth/OAuthStartState.php src/Service/OAuth/OAuthStartState.php
git mv src/Dto/OAuth/OAuthCallbackAttempt.php src/Service/OAuth/OAuthCallbackAttempt.php
git mv src/Dto/Mail/PendingApprovalNotice.php src/Service/Mail/PendingApprovalNotice.php
git mv src/Dto/Admin/UserFootprint.php src/Service/Admin/UserFootprint.php
perl -pi -e 's/^namespace App\\Dto\\OAuth;$/namespace App\\Service\\OAuth;/' src/Service/OAuth/OAuthIdentity.php src/Service/OAuth/OAuthStartState.php src/Service/OAuth/OAuthCallbackAttempt.php
perl -pi -e 's/^namespace App\\Dto\\Mail;$/namespace App\\Service\\Mail;/' src/Service/Mail/PendingApprovalNotice.php
perl -pi -e 's/^namespace App\\Dto\\Admin;$/namespace App\\Service\\Admin;/' src/Service/Admin/UserFootprint.php
git grep -lF -e 'App\Dto\OAuth\OAuthIdentity' -e 'App\Dto\OAuth\OAuthStartState' -e 'App\Dto\OAuth\OAuthCallbackAttempt' -e 'App\Dto\Mail\PendingApprovalNotice' -e 'App\Dto\Admin\UserFootprint' -- src \
  | xargs perl -pi -e 's/App\\Dto\\OAuth\\(OAuthIdentity|OAuthStartState|OAuthCallbackAttempt)(?!\w)/App\\Service\\OAuth\\$1/g; s/App\\Dto\\Mail\\PendingApprovalNotice(?!\w)/App\\Service\\Mail\\PendingApprovalNotice/g; s/App\\Dto\\Admin\\UserFootprint(?!\w)/App\\Service\\Admin\\UserFootprint/g'
```

A class now imports a neighbour from its own namespace. Drop those imports, and the blank line an emptied import block leaves:
```bash
perl -0pi -e 's/^use App\\Service\\OAuth\\(OAuthIdentity|OAuthStartState|OAuthCallbackAttempt);\n//mg; s/^(namespace [^;]+;\n)\n\n+/$1\n/m' src/Service/OAuth/*.php
perl -0pi -e 's/^use App\\Service\\Mail\\PendingApprovalNotice;\n//mg; s/^(namespace [^;]+;\n)\n\n+/$1\n/m' src/Service/Mail/*.php
perl -0pi -e 's/^use App\\Service\\Admin\\UserFootprint;\n//mg; s/^(namespace [^;]+;\n)\n\n+/$1\n/m' src/Service/Admin/*.php
```

`src/Dto/Admin/AdminUserFootprint.php`, in the class docblock:
```diff
- * The JSON-ready shape of a {@see UserFootprint}: the same figures, with the
+ * The JSON-ready shape of a {@see \App\Service\Admin\UserFootprint}: the same figures, with the
```

Check:
```bash
git grep -nF -e 'Dto\OAuth\OAuthIdentity' -e 'Dto\OAuth\OAuthStartState' -e 'Dto\OAuth\OAuthCallbackAttempt' -e 'Dto\Mail\PendingApprovalNotice' -e 'Dto\Admin\UserFootprint' -- src tests config
git grep -nF -e 'use App\Service\OAuth\OAuth' -- src/Service/OAuth/*.php
git diff --stat -M30% origin/develop -- src/Service/OAuth/Oidc src/Dto/OAuth/OAuthIdentity.php src/Service/OAuth/OAuthIdentity.php
```
Expected:
- The first two greps print nothing. `src/Dto/OAuth/OAuthExchangeRequest.php` stays where it is: it is a real request DTO.
- In `src/Service/OAuth/Oidc/` only `IdTokenVerifier.php` changed, by its one import line; `OAuthIdentity.php` shows as a rename.

- [ ] **Step 3b: Bring the moved files' comments to the bar (D4)**

Write each moved file in full as below. Only the namespace line and the comments differ from `a124ad8a`; every code line is the same. What went: restatements of the class name, issue history, and reasoning a reader recovers from the code. What stayed: the invariants a future edit would otherwise break (the verified-address rule, the blank-claim rule, the relay anchor, the challenge/verifier pairing, the per-recipient locale).

`src/Service/OAuth/OAuthIdentity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use App\Entity\User;

/** One provider-verified identity from a completed code exchange: everything linking and sign-up decide from. */
final readonly class OAuthIdentity
{
    private const string PRIVATE_RELAY_DOMAIN = 'privaterelay.appleid.com';

    public ?string $email;

    /** A typed bool: converting a provider's raw "verified" claim is IdTokenVerifier's job alone. */
    public function __construct(
        public string $provider,
        public string $providerUserId,
        ?string $email,
        public bool $emailVerified,
    ) {
        // Normalised like User::$email so linking compares like with like. A blank claim is no address at all:
        // an empty string would slip past isLinkableByEmail()'s null check.
        $normalized = null === $email ? null : User::normalizeEmail($email);

        $this->email = '' === $normalized ? null : $normalized;
    }

    /** Anchored on '@' and the end, so neither a subdomain nor a registrable lookalike counts as a relay address. */
    public function isPrivateRelay(): bool
    {
        if (null === $this->email) {
            return false;
        }

        return str_ends_with($this->email, '@' . self::PRIVATE_RELAY_DOMAIN);
    }

    /**
     * Links on a provider-VERIFIED address only: an unverified one would let anyone claim an existing account.
     * A private-relay address can never be one a human signed up with.
     */
    public function isLinkableByEmail(): bool
    {
        return null !== $this->email && $this->emailVerified && !$this->isPrivateRelay();
    }
}
```

`src/Service/OAuth/OAuthStartState.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth;

/**
 * The secrets of one in-flight sign-in. `$codeChallenge` is recomputed from `$codeVerifier` on both legs, so the two
 * always match; `$browserToken` is set only by start() and binds the flow to the browser that began it.
 */
final readonly class OAuthStartState
{
    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
        public string $codeChallenge,
        public ?string $browserToken = null,
    ) {
    }
}
```

`src/Service/OAuth/OAuthCallbackAttempt.php` (its one-line docblock restated the class name):
```php
<?php

declare(strict_types=1);

namespace App\Service\OAuth;

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

`src/Service/Mail/PendingApprovalNotice.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Enum\RegistrationMethod;

/** Built once per applicant and sent to every admin; AccountMailer adds each recipient's own locale. */
final readonly class PendingApprovalNotice
{
    public function __construct(
        public string $applicantEmail,
        public RegistrationMethod $method,
        public ?string $oauthProvider,
        public string $reviewUrl,
        public int $pendingApprovalCount,
    ) {
    }
}
```

`src/Service/Admin/UserFootprint.php` (the class docblock restated the name; the `feedsLimit` note named per-user caps as future work that has shipped; the `lastRefreshAt` note was incomplete, as null also means "never fetched"):
```php
<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class UserFootprint
{
    public function __construct(
        public int $feedsCount,
        public int $tagsCount,
        public int $feedsLimit,
        public int $staleFeedsCount,
        public ?\DateTimeImmutable $lastRefreshAt,
        public bool $dormant,
    ) {
    }
}
```

`tests/Service/OAuth/OAuthIdentityTest.php` (tests are production code: the comments that restated a test name go; the anchor rationale stays in one line):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\OAuthIdentity;
use PHPUnit\Framework\TestCase;

final class OAuthIdentityTest extends TestCase
{
    public function testALinkableAddressIsVerifiedAndNotPrivateRelay(): void
    {
        $identity = new OAuthIdentity('google', 'sub-1', 'Bob@Example.com', true);

        self::assertSame('bob@example.com', $identity->email);
        self::assertTrue($identity->isLinkableByEmail());
    }

    public function testAnUnverifiedAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentity('google', 'sub-1', 'bob@example.com', false);

        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testAMissingAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentity('apple', 'sub-1', null, false);

        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testAnApplePrivateRelayAddressIsNotLinkable(): void
    {
        $identity = new OAuthIdentity('apple', 'sub-1', 'abc123@privaterelay.appleid.com', true);

        self::assertTrue($identity->isPrivateRelay());
        self::assertFalse($identity->isLinkableByEmail());
    }

    public function testPrivateRelayDetectionIsCaseInsensitiveAndAnchored(): void
    {
        self::assertTrue(
            (new OAuthIdentity('apple', 's', 'X@PrivateRelay.AppleID.com', true))->isPrivateRelay(),
        );
        self::assertFalse(
            (new OAuthIdentity('apple', 's', 'x@privaterelay.appleid.com.evil.test', true))->isPrivateRelay(),
        );
    }

    /** Pins the '@' anchor the suffix test above cannot: Apple mints relay addresses on the bare domain only. */
    public function testOnlyTheExactRelayDomainCounts(): void
    {
        $lookalikes = [
            'x@sub.privaterelay.appleid.com',
            'x@notprivaterelay.appleid.com',
            'x@evil.test?privaterelay.appleid.com',
            'privaterelay.appleid.com@example.com',
        ];

        foreach ($lookalikes as $email) {
            self::assertFalse(
                (new OAuthIdentity('apple', 's', $email, true))->isPrivateRelay(),
                $email . ' must not be read as a private relay address',
            );
        }
    }

    public function testABlankAddressIsTreatedAsAbsentAndIsNotLinkable(): void
    {
        foreach (['', '   ', "\t\n"] as $blank) {
            $identity = new OAuthIdentity('google', 'sub-1', $blank, true);

            self::assertNull($identity->email);
            self::assertFalse($identity->isLinkableByEmail());
            self::assertFalse($identity->isPrivateRelay());
        }
    }
}
```

Check that no code line changed (prints nothing). `git diff -M30%` cannot show it: after the trim, `OAuthStartState` and `OAuthIdentity` fall below 30% similarity and show as a delete plus an add.
```bash
strip() { grep -vE '^[[:space:]]*(\*|/\*\*|//|namespace |use App\\(Dto|Service)\\|$)'; }
for pair in \
  src/Dto/OAuth/OAuthIdentity.php=src/Service/OAuth/OAuthIdentity.php \
  src/Dto/OAuth/OAuthStartState.php=src/Service/OAuth/OAuthStartState.php \
  src/Dto/OAuth/OAuthCallbackAttempt.php=src/Service/OAuth/OAuthCallbackAttempt.php \
  src/Dto/Mail/PendingApprovalNotice.php=src/Service/Mail/PendingApprovalNotice.php \
  src/Dto/Admin/UserFootprint.php=src/Service/Admin/UserFootprint.php \
  tests/Dto/OAuth/OAuthIdentityTest.php=tests/Service/OAuth/OAuthIdentityTest.php; do
  diff <(git show "origin/develop:backend/${pair%%=*}" | strip) <(strip < "${pair##*=}") || echo "CODE CHANGED: ${pair##*=}"
done
```
Only namespace, import, comment and blank lines are filtered out; every remaining line must be identical.

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Service/OAuth tests/Service/Mail tests/Service/Admin tests/Controller/Api/OAuthFlowTest.php tests/Controller/Api/RegistrationTest.php tests/Controller/Admin/AdminUserControllerTest.php tests/EventListener`
Expected: PASS, `OidcBoundaryTest` included.

- [ ] **Step 5: Gates and commit**

No new test, so no deletion check: the moved test and the migrated suites are the net. Run `composer check && composer md`, then the PhpStorm lint on every changed PHP file (`git diff --name-only origin/develop -- '*.php'`).
```bash
git add -A src/Dto src/Service/OAuth src/Service/Mail src/Service/Admin src/Controller/Api/OAuthController.php src/EventListener/NotifyAdminsOfPendingApproval.php src/Http/AdminUserJson.php tests
git commit -m "refactor(#1182): the OAuth values, the approval notice and the user footprint move into their services"
```

---

### Task A12: `DomainKnowsNoHttpRule` forbids `App\Dto\` and loses four gaps

**Files:**
- Modify: `tests/PhpStan/DomainKnowsNoHttpRule.php` (rewritten in full)
- Modify: `tests/PhpStan/DomainKnowsNoHttpRuleTest.php` (one constant, nine expectations)
- Modify: `tests/PhpStan/data/domain-knows-no-http-fixtures.php` (one namespace block appended)
- Modify: `CLAUDE.md` (the "Domain code knows no HTTP" bullet)

**Interfaces:**
- Consumes: nothing from earlier tasks, but A1–A11 must be done: the rule now fails `composer stan` on any domain import of `App\Dto\`.
- Produces: `DomainKnowsNoHttpRule::__construct(NodeFinder $finder)` (unchanged). It additionally reports `App\Dto\*`, the classes a group import names, a namespace imported under an alias, names in any letter case, and class names in the literal parts of an interpolated string.

The four gaps, each pinned by a fixture line that passes unreported today:
- a group import (`use Symfony\Component\{HttpFoundation\Request, …};`): the rule saw only the prefix and the tails;
- a namespace alias (`use Symfony\Component\HttpFoundation as Foundation;`): the name has no trailing separator, so no prefix matched;
- case (`'app\http\RecommendationFeedJson'`, `\app\http\EntryPage::class`): PHP resolves names case-insensitively, the rule did not;
- interpolation (`"App\\Http\\{$suffix}"`): the rule looked at plain strings only.

Only unused or docblock-only imports escaped today; a used one is resolved into a full name in code and was already caught.

- [ ] **Step 1: Write the failing fixture lines and expectations**

`tests/PhpStan/data/domain-knows-no-http-fixtures.php` ends at line 132 with the `}` that closes `namespace App\Repository\Fixtures\Clean`. Append this block, so that the file gains lines 133–162:
```php

namespace App\Service\Fixtures\Gaps {
    use Symfony\Component\{HttpFoundation\Request, HttpKernel\Exception\GoneHttpException};
    use Symfony\Component\HttpFoundation as Foundation;
    use App\Http as HttpLayer;
    use App\Dto\Tag\CreateTagRequest;

    final class KnowsHttpThroughTheGaps
    {
        public function lowercaseString(): string
        {
            return 'app\http\RecommendationFeedJson';
        }

        public function lowercaseName(): string
        {
            return \app\http\EntryPage::class;
        }

        public function interpolatedString(string $suffix): string
        {
            return "App\\Http\\{$suffix}";
        }

        public function requestDto(): string
        {
            return CreateTagRequest::class;
        }
    }
}
```
Check the lines: `sed -n '134p;135p;136p;137p;138p;144p;149p;154p;159p' tests/PhpStan/data/domain-knows-no-http-fixtures.php` must print, in order, the `namespace` line, the four `use` lines, then the four `return` lines.

`tests/PhpStan/DomainKnowsNoHttpRuleTest.php`:
- After `private const string FEED_JSON = 'App\Http\RecommendationFeedJson';`, add:
```php
    private const string GAPS = 'App\Service\Fixtures\Gaps';
```
- After the expectation `[self::message(self::PAGINATION, self::FOUNDATION . 'Cookie'), 94],`, add:
```php
                [self::message(self::GAPS, self::FOUNDATION . 'Request'), 135],
                [self::message(self::GAPS, self::HTTP_KERNEL . 'GoneHttpException'), 135],
                [self::message(self::GAPS, 'Symfony\Component\HttpFoundation'), 136],
                [self::message(self::GAPS, 'App\Http'), 137],
                [self::message(self::GAPS, 'App\Dto\Tag\CreateTagRequest'), 138],
                [self::message(self::GAPS, 'app\http\RecommendationFeedJson'), 144],
                [self::message(self::GAPS, 'app\http\EntryPage'), 149],
                [self::message(self::GAPS, 'App\Http\\'), 154],
                [self::message(self::GAPS, 'App\Dto\Tag\CreateTagRequest'), 159],
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: FAIL. The diff lists the nine new expectations as missing and nothing else.
SETTLED (PR A): PHPStan 2.2.5 hands the rule the `GroupUse` node intact, so the two line-135 expectations fail here and the `GroupUse` branch in Step 3 stays.

- [ ] **Step 3: Implement**

`tests/PhpStan/DomainKnowsNoHttpRule.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code returns typed values and throws typed exceptions; src/Http shapes them (#1158), and a controller hands a
 * service a value, never a request DTO (#1182). Strings, group imports and namespace aliases count too.
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
        'App\\Dto\\',
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
        if (!self::startsWithAny($namespaceName, self::DOMAIN_NAMESPACES)) {
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

    /** @return list<array{string, int}> every class or namespace name mentioned, in code or in a string, with its line */
    private function references(Namespace_ $namespace): array
    {
        $nodes = $this->finder->find(
            $namespace->stmts,
            static fn (Node $node): bool => $node instanceof Name
                || $node instanceof String_
                || $node instanceof InterpolatedStringPart
                || $node instanceof GroupUse,
        );

        $references = [];
        foreach ($nodes as $node) {
            $references = [...$references, ...self::referencesIn($node)];
        }

        return $references;
    }

    /** @return list<array{string, int}> */
    private static function referencesIn(Node $node): array
    {
        if ($node instanceof GroupUse) {
            return self::groupedReferences($node);
        }
        if ($node instanceof Name) {
            return [[$node->toString(), $node->getStartLine()]];
        }
        if ($node instanceof String_ || $node instanceof InterpolatedStringPart) {
            return [[ltrim($node->value, '\\'), $node->getStartLine()]];
        }

        return [];
    }

    /** @return list<array{string, int}> a group import names each class by its prefix and its own tail */
    private static function groupedReferences(GroupUse $groupUse): array
    {
        return array_values(array_map(
            static fn (UseItem $use): array => [
                $groupUse->prefix->toString() . '\\' . $use->name->toString(),
                $use->getStartLine(),
            ],
            $groupUse->uses,
        ));
    }

    private static function isHttp(string $reference): bool
    {
        return \in_array(strtolower($reference), array_map(strtolower(...), self::HTTP_CLASSES), true)
            || self::startsWithAny($reference, self::HTTP_PREFIXES);
    }

    /**
     * Case-insensitive, as PHP names are. The appended separator lets a bare namespace, as an alias import names it,
     * match its own prefix.
     *
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $name, array $prefixes): bool
    {
        $subject = strtolower($name) . '\\';
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, strtolower($prefix))) {
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

`CLAUDE.md`, replace the bullet
```
- **Domain code knows no HTTP.** `DomainKnowsNoHttpRule` forbids `App\Http\*` and
  Symfony's HttpFoundation and HTTP-exception classes, class names in strings
  included, in `Service`, `Repository`, `Entity`, `Enum`, `Exception` and `Pagination`.
```
with
```
- **Domain code knows no HTTP.** `DomainKnowsNoHttpRule` forbids `App\Http\*`, the
  request DTOs in `App\Dto\*`, and Symfony's HttpFoundation and HTTP-exception classes,
  class names in strings included, in `Service`, `Repository`, `Entity`, `Enum`,
  `Exception` and `Pagination`. A controller hands a service a `Service/<Module>` value
  (`$request->toChange()`) or a plain field, never the DTO (#1182).
```

- [ ] **Step 4: Run to verify it passes, and that `src` is clean**

Run:
```bash
php bin/phpunit tests/PhpStan
bin/console cache:warmup && composer stan
```
Expected: PASS, and `composer stan` reports no error. A `simpleFeedReader.domainKnowsNoHttp` error on a `src` file means a service still takes a DTO: stop and report it with the file.

- [ ] **Step 5: Deletion checks (one per gap and one for the prefix)**

Undo one change at a time, run `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`, watch the named lines go missing from the actual errors, and restore by hand:
1. Delete the `if ($node instanceof GroupUse) { … }` branch in `referencesIn()`. Expected: both line-135 errors missing.
2. In `startsWithAny()`, change `strtolower($name) . '\\'` to `strtolower($name)`. Expected: the 136 and 137 errors missing.
3. In `startsWithAny()`, drop both `strtolower(…)` calls. Expected: the 144 and 149 errors missing.
4. Delete `|| $node instanceof InterpolatedStringPart` from the finder filter. Expected: the 154 error missing.
5. Delete `'App\\Dto\\',` from `HTTP_PREFIXES`. Expected: the 138 and 159 errors missing.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the rule and its test (the fixture file is not linted: `phpcs.xml.dist` and `phpstan.dist.neon` exclude `tests/PhpStan/data/*` by path).
```bash
git add tests/PhpStan/DomainKnowsNoHttpRule.php tests/PhpStan/DomainKnowsNoHttpRuleTest.php tests/PhpStan/data/domain-knows-no-http-fixtures.php ../CLAUDE.md
git commit -m "refactor(#1182): domain code may not name a request DTO, and the rule sees group, alias, case and interpolated names"
```

---

### Execution rulings (PR A)

- **Preflight (opus scan):**
  - Every task runs `infection:diff` in its own gates. The renames put stable logic under the diff for the first time. A8 and A9 pinned new defaults that tests used but never asserted: the catalog rows' `enabled`/`locked`, and the saved search's `wholeWord`/`phrase` (`SavedSearchDefinitionTest`).
  - A12 adds a lowercase HTTP-class fixture line with a sixth deletion check, so the case-insensitive match on the exact class list is pinned.
  - New values carry no docblock that only restates them (`DigestConfiguration`, `PasskeyAttestation`, `ClientError`, `CatalogFeedDetails`). Docblocks that state a rule stay.
  - Other rulings: `AdminUserFootprint`'s docblock is one line, A3's stale test names are renamed, A6 builds its DTO through `SettingsRequests::instance()`, and every touched `use` block is sorted.
- **A12:** PHPStan 2.2.5 passes the `GroupUse` node intact, so the branch stays. PhpStorm findings on the fixture's own violation lines are accepted, and so is the existing `RuleError` warning on `error()`.
- **Final review:**
  - For an `App\Dto` reference, the rule's message now names the #1182 remedy.
  - A group import with a forbidden prefix reports each class once.
  - `isHttp()` uses `array_any` with `strcasecmp`.
  - Reorder parameters are `$ordered<Entity>Ids`.
- **Kept, on purpose:**
  - The rule does not read docblocks, because a docblock mention is not a reference.
  - `groupedReferences()` keeps `array_values()`, because php-parser types `GroupUse::$uses` as an array, not a list.
  - The name `TagMove`.
  - The duplicated flag patch in `BulkSubscriptionUpdater` and `SubscriptionEditor` and the catalog reorder lookup were already there before this PR, and there are two copies of each. Both are watch items.

### Finishing PR A

1. **Branch-wide gates**, all green:
   - `php bin/phpunit`
   - `docker compose exec php composer test` (first check the container runs this branch's code, per the standing "check the container is current" rule)
   - `composer check`
   - `composer md`
   - `composer infection:diff`
   - PhpStorm `lint_files` on `git diff --name-only origin/develop -- '*.php'`. ERROR and WARNING block.
2. **Scan the dev log**: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'` shows no new deprecation or error.
3. **/simplify** on the branch diff. Apply what it finds that keeps this plan's constraints (no wire change, D1–D8); re-run the gates it affects.
4. **SDD final review.** Dispatch a fresh reviewer subagent with the diff (`git diff origin/develop`) and these attack points, in this order:
   1. **Wire drift.** Every changed controller line only wraps an argument (`$request->toX()` or `$request->field`); no response expression changed. `git diff origin/develop -- src/Controller src/Http` shows nothing else apart from import lines (Task A11 rewrites `OAuthCallbackAttempt` in `OAuthController.php` and `UserFootprint` in `src/Http/AdminUserJson.php`). The only controller-test change is Task A11's import rewrite: `git diff origin/develop -- tests/Controller` shows exactly `-use App\Dto\OAuth\OAuthIdentity;` / `+use App\Service\OAuth\OAuthIdentity;` in `tests/Controller/Api/OAuthFlowTest.php`.
   2. **Field swaps.** Each `toX()` maps every field to its namesake. Same-typed neighbours (`addTagIds`/`removeTagIds`, `sendHour`/`weekday`, `fromTagId`/`toTagId`, `wholeWord`/`phrase`, the four entry flags, `url`/`route`) are the likely bug; each DTO test pins distinct values.
   3. **Defaults.** Each service value's defaults equal its DTO's; no controller relies on one.
   4. **No domain `App\Dto` import left:** `git grep -nF 'use App\Dto\' -- src/Service src/Repository src/Entity src/Enum src/Exception src/Pagination` is empty. A docblock mention (`SupportedLocale`) is not a reference.
   5. **OIDC boundary.** `git diff origin/develop -- src/Service/OAuth/Oidc` is `IdTokenVerifier`'s import line only; `OidcBoundaryTest` is unchanged and green.
   6. **Moves.** The five classes and the test keep every code line: the comment-stripped comparison loop from Task A11 Step 3b prints nothing. (Do not rely on `git diff -M30%`: after the comment trim, `OAuthStartState` and `OAuthIdentity` fall below 30% similarity and show as a delete plus an add.) Every remaining comment clears the bar, and the security invariants of `OAuthIdentity` and `OAuthStartState` survive.
   7. **The rule.** Each gap's fixture line failed before its fix (Task A12 report); the rule still ignores `App\HttpClientSettings` and the HTTP layer's own fixtures.
   8. **D2.** The two stored-value booleans are the only boolean parameters added.
   9. **PR text and commits:** `git log --format=%B origin/develop..HEAD | grep -inE 'clos|fix|resolv'` prints nothing.
   Fix every confirmed finding in its own commit (`refactor(#1182): …`) and re-run the gates.
5. **Push and open the PR** against `develop`:
```bash
git push -u origin refactor/1182-request-dtos-to-service-values
gh pr create --base develop --title "refactor(#1182): services take service values, not request DTOs (part A)" --body-file - <<'BODY'
Refs #1182

Part A of #1182: issue bullet 1.

- Every service that took an `App\Dto` request now takes a `Service/<Module>` value the DTO builds (`toChange()`, `toMove()`, `toConfiguration()`, `toAttestation()`, `toRelyingPartyIdChoice()`, `toClientErrors()`, `toDetails()`, `toDefinition()`), or a plain field: `EntryStateUpdater`, `BulkSubscriptionUpdater`, `SubscriptionEditor`, `FeedTagMove`, `DigestEnablement`, `AccountPreferencesWriter`, `AttestationVerifier`, `RelyingPartyChange`, `ClientErrorRecorder`/`ClientErrorScrubber`, `CatalogCategoryEditor`, `CatalogFeedEditor`, `SavedSearchEditor`, `TagEditor`, `TagOrdering`.
- `OAuthIdentity`, `OAuthStartState`, `OAuthCallbackAttempt`, `PendingApprovalNotice` and `UserFootprint` move out of `App\Dto` into their services; code unchanged, comments brought to the CLAUDE.md bar. `IdToken*` and `OidcBoundaryTest` are untouched.
- `DomainKnowsNoHttpRule` forbids `App\Dto\` in domain code, and now also sees group imports, namespace aliases, names in any letter case and interpolated class-name strings.

No wire change: every response is byte-identical, and no controller test assertion changed (`OAuthFlowTest` only follows the `OAuthIdentity` import).

Part B (response mappers, store serialisers, shared value homes) follows in its own PR.
BODY
```
6. **Merge when green.** Start a Monitor on `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never `--auto`. If a check fails, fix it on the branch (first `composer show larspohlmann/phptramp` for a `tramp` failure: CI runs phptramp's `develop` tip) and restart the Monitor.
7. **Verify:** `gh pr view <PR> --json state,mergeCommit --jq '.state + " " + .mergeCommit.oid'` prints `MERGED …`, and `gh issue view 1182 --json state --jq .state` prints `OPEN`. If the issue closed, reopen it and report: PR B is still to come.
8. **Report** to the planner: the merge SHA, the deletion-check outputs, any OPEN QUESTION outcome, and anything /simplify or the review changed.

---

# PR B

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and #1182 is still open**

```bash
gh pr list --state merged --search "1182 in:title" --json number,title,mergedAt
gh issue view 1182 --json state --jq .state
git fetch origin
git grep -n 'toRelyingPartyIdChoice' origin/develop -- backend/src/Dto/Admin/InstanceSettingsRequest.php
git grep -nF 'App\\Dto\\' origin/develop -- backend/tests/PhpStan/DomainKnowsNoHttpRule.php
```
Expected: PR A listed as merged; `OPEN`; one hit each. If PR A has not merged, stop: PR B starts from it.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1182-response-mappers-and-value-homes origin/develop
```

- [ ] **Step 3: Re-take the `toArray()` inventory and the persistence imports**

Run:
```bash
git grep -nE 'function (toArray|fromArray|fromArrayOrNull|jsonSerialize)\(' -- src/Service
git grep -n 'toArray()' -- src/Controller src/Http src/Service src/Command
git grep -nE '^use App\\Service\\' -- src/Entity src/Enum src/Doctrine
```
Expected (checked at `a124ad8a`):
- `function toArray(`: `Recommendation/RecommendationRunReport`, `Recommendation/ForYouSweepReport`, `Auth/AltchaChallenge`, `Proxy/ProxyTestResult`, `Refresh/RefreshRunProgress`, `Refresh/RefreshReport`, `Mail/Settings/MailTestResult`, `Mail/Digest/DigestSweepReport`, `Image/ImageVerificationReport`, `Grafana/GrafanaSettingsSnapshot`, `Search/Membership/SavedSearchMembershipSweepReport`, `Maintenance/MaintenanceTickReport`, `Logging/Loki/LokiSpoolReport`, `ReaderAudit/AuditFinding`, `ReaderAudit/CleanupMarker`; `fromArray(`: `AuditFinding`, `CleanupMarker`; `fromArrayOrNull(`: `GrafanaSettingsSnapshot`. No `jsonSerialize`.
- `toArray()` callers: the four controllers (`AdminMailController`, `AdminProxyController`, `AuthController`, `MaintenanceController` ×3), `Http/RecommendationRunStatusJson`, `Http/RefreshJson` (via `progress->toArray()`), `Command/RefreshFeedsCommand`, `Service/Maintenance/MaintenanceTick` (×9), `Service/Refresh/RefreshRunStore`, `Service/Grafana/GrafanaSettingsCache`, `Service/ReaderAudit/AuditFindingsFile`, `AuditFinding` (markers), the three worker handlers; plus the HTTP-client `$response->toArray()` in `OAuth/Oidc/TokenEndpoint` and `Version/GitHubLatestReleaseReader`, which is Symfony's response API, not ours, and stays.
- Also listed, and staying as they are (not value serialisers): Doctrine `Collection::toArray()` in `Http/AdminUserJson`, `Service/Subscription/BulkSubscriptionUpdater` and `Service/Subscription/SubscriptionTagSync`, and `Http/Problem/ApiProblem::toArray()` with its caller `Http/Problem/ProblemResponseFactory` (the HTTP layer's own problem shape).
- Persistence imports: `Entity/AiProviderSettings` (`SealedSecret`), `Entity/Entry` and `EntryDiscussion` (`Discussion`), `Entity/GrafanaSettings` (`SealedSecret`, `GrafanaConnection`), `Entity/InstanceSetting` (`InstanceSettingsUpdate`), `Entity/MailServerSettings` (`SealedSecret`, `MailConnection`), `Entity/Preferences` (`DigestCadence`, `DigestFormat`, `MagazineStyle`), `Entity/ProxyServerSettings` (`SealedSecret`, `ProxyConnection`), `Entity/RecommendationSettings` (`EffectiveRecommendationSettings`, `RecommendationBatchSize`, `RecommendationSettingsValues`), `Doctrine/NormalizeWordBoundariesFunction` and `Doctrine/SqliteConnectionSetupDriver` (`WordBoundaries`).

An extra hit gets the same treatment as its siblings in the task that owns it; note it in that task's report. An extra persistence import of a class this plan does not move: stop and report it.

---

### Task B1: `MailTestResultJson`, `ProxyTestResultJson`

**Files:**
- Create: `src/Http/Admin/MailTestResultJson.php`, `src/Http/Admin/ProxyTestResultJson.php`
- Create: `tests/Http/Admin/MailTestResultJsonTest.php`, `tests/Http/Admin/ProxyTestResultJsonTest.php`
- Modify: `src/Service/Mail/Settings/MailTestResult.php`, `src/Service/Proxy/ProxyTestResult.php` (`toArray()` goes)
- Modify: `src/Controller/Admin/AdminMailController.php`, `src/Controller/Admin/AdminProxyController.php` (one import, one line each)
- Delete: `tests/Service/Mail/Settings/MailTestResultTest.php` (its three pins move to `MailTestResultJsonTest`)
- Modify: `tests/Service/Proxy/ProxyTestResultTest.php` (the three `toArray()` assertions move to `ProxyTestResultJsonTest`)

**Interfaces:**
- Produces: `App\Http\Admin\MailTestResultJson::from(MailTestResult $result): array{ok: bool, reason: string|null}`.
- Produces: `App\Http\Admin\ProxyTestResultJson::from(ProxyTestResult $result): array{ok: bool, egressIp: string|null, reason: string|null}`.

- [ ] **Step 1: Write the failing tests**

`tests/Http/Admin/MailTestResultJsonTest.php` (the three arrays `MailTestResultTest` pinned, byte for byte):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Http\Admin\MailTestResultJson;
use App\Service\Mail\Settings\MailTestFailure;
use App\Service\Mail\Settings\MailTestResult;
use PHPUnit\Framework\TestCase;

final class MailTestResultJsonTest extends TestCase
{
    public function testAGuardFailureSendsItsCode(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => 'not_configured'],
            MailTestResultJson::from(MailTestResult::failed(MailTestFailure::NotConfigured)),
        );
    }

    public function testARejectedSendSendsTheTransportMessage(): void
    {
        self::assertSame(
            ['ok' => false, 'reason' => '535 5.7.8 bad credentials'],
            MailTestResultJson::from(
                MailTestResult::failed(MailTestFailure::SendRejected, '535 5.7.8 bad credentials'),
            ),
        );
    }

    public function testSuccessSendsNoReason(): void
    {
        self::assertSame(['ok' => true, 'reason' => null], MailTestResultJson::from(MailTestResult::ok()));
    }
}
```

`tests/Http/Admin/ProxyTestResultJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Http\Admin\ProxyTestResultJson;
use App\Service\Proxy\ProxyTestFailure;
use App\Service\Proxy\ProxyTestResult;
use PHPUnit\Framework\TestCase;

final class ProxyTestResultJsonTest extends TestCase
{
    public function testAnOkResultSendsTheEgressIpAndNoReason(): void
    {
        self::assertSame(
            ['ok' => true, 'egressIp' => '203.0.113.7', 'reason' => null],
            ProxyTestResultJson::from(ProxyTestResult::ok('203.0.113.7')),
        );
    }

    public function testAGuardFailureSendsItsCodeAsTheReason(): void
    {
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'not_configured'],
            ProxyTestResultJson::from(ProxyTestResult::failed(ProxyTestFailure::NotConfigured)),
        );
    }

    public function testAFailureWithADetailSendsTheDetailAsTheReason(): void
    {
        self::assertSame(
            ['ok' => false, 'egressIp' => null, 'reason' => 'HTTP 404'],
            ProxyTestResultJson::from(ProxyTestResult::failed(ProxyTestFailure::UnexpectedStatus, 'HTTP 404')),
        );
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Http/Admin/MailTestResultJsonTest.php tests/Http/Admin/ProxyTestResultJsonTest.php`
Expected: FAIL: `Class "App\Http\Admin\MailTestResultJson" not found` and the proxy equivalent.

- [ ] **Step 3: Implement**

`src/Http/Admin/MailTestResultJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Mail\Settings\MailTestResult;

/** The admin "send a test mail" response: a failure's detail, else its code, is the reason. */
final class MailTestResultJson
{
    /** @return array{ok: bool, reason: string|null} */
    public static function from(MailTestResult $result): array
    {
        return ['ok' => $result->ok, 'reason' => $result->detail ?? $result->failure?->value];
    }
}
```

`src/Http/Admin/ProxyTestResultJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Proxy\ProxyTestResult;

/** The admin "test the proxy" response: a failure's detail, else its code, is the reason. */
final class ProxyTestResultJson
{
    /** @return array{ok: bool, egressIp: string|null, reason: string|null} */
    public static function from(ProxyTestResult $result): array
    {
        return [
            'ok' => $result->ok,
            'egressIp' => $result->egressIp,
            'reason' => $result->detail ?? $result->failure?->value,
        ];
    }
}
```

`src/Service/Mail/Settings/MailTestResult.php`: delete `toArray()` with its docblock and the blank line above it:
```php

    /** @return array{ok: bool, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'reason' => $this->detail ?? $this->failure?->value];
    }
```

`src/Service/Proxy/ProxyTestResult.php`: delete `toArray()` with its docblock and the blank line above it:
```php

    /** @return array{ok: bool, egressIp: string|null, reason: string|null} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'egressIp' => $this->egressIp, 'reason' => $this->detail ?? $this->failure?->value];
    }
```

`src/Controller/Admin/AdminMailController.php`:
- Add `use App\Http\Admin\MailTestResultJson;` directly after `use App\Http\Admin\MailSettingsJson;`.
- In `test()`:
```diff
-        return new JsonResponse($tester->test()->toArray());
+        return new JsonResponse(MailTestResultJson::from($tester->test()));
```

`src/Controller/Admin/AdminProxyController.php`:
- Add `use App\Http\Admin\ProxyTestResultJson;` directly after `use App\Http\Admin\ProxySettingsJson;`.
- In `test()`:
```diff
-        return new JsonResponse($tester->test()->toArray());
+        return new JsonResponse(ProxyTestResultJson::from($tester->test()));
```

`tests/Service/Proxy/ProxyTestResultTest.php`: in each of its three tests, delete the trailing `self::assertSame([…], $result->toArray());` statement (the `ok`/`egressIp`/`failure` field assertions stay). In `testAFailureWithADetailSendsTheDetailAsTheReason`, which then has no assertion left, replace the deleted statement with:
```php
        self::assertSame('HTTP 404', $result->detail);
        self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);
```

```bash
git rm tests/Service/Mail/Settings/MailTestResultTest.php
git grep -n 'toArray' -- src/Service/Mail/Settings/MailTestResult.php src/Service/Proxy/ProxyTestResult.php tests/Service/Proxy/ProxyTestResultTest.php
```
Expected: the grep prints nothing.

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Http/Admin tests/Service/Proxy tests/Service/Mail/Settings tests/Controller/Admin/AdminMailControllerTest.php tests/Controller/Admin/AdminProxyControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `MailTestResultJson::from()`, swap to `$result->failure?->value ?? $result->detail`. Expected: `testARejectedSendSendsTheTransportMessage` fails. Restore.
2. In `ProxyTestResultJson::from()`, drop the `'egressIp'` entry. Expected: all three proxy mapper tests fail. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the nine PHP files.
```bash
git add src/Http/Admin/MailTestResultJson.php src/Http/Admin/ProxyTestResultJson.php src/Service/Mail/Settings/MailTestResult.php src/Service/Proxy/ProxyTestResult.php src/Controller/Admin/AdminMailController.php src/Controller/Admin/AdminProxyController.php tests/Http/Admin/MailTestResultJsonTest.php tests/Http/Admin/ProxyTestResultJsonTest.php tests/Service/Proxy/ProxyTestResultTest.php
git commit -m "refactor(#1182): src/Http maps the mail and proxy test results"
```

---

### Task B2: `AltchaChallengeJson`

**Files:**
- Create: `src/Http/AltchaChallengeJson.php`
- Create: `tests/Http/AltchaChallengeJsonTest.php`
- Modify: `src/Service/Auth/AltchaChallenge.php` (`toArray()` goes; docblock)
- Modify: `src/Controller/Api/AuthController.php` (one import, one line; #1168 B9's version)

**Interfaces:**
- Produces: `App\Http\AltchaChallengeJson::from(AltchaChallenge $challenge): array{algorithm: string, challenge: string, salt: string, signature: string, maxnumber: int}`.

- [ ] **Step 1: Write the failing test**

`tests/Http/AltchaChallengeJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\AltchaChallengeJson;
use App\Service\Auth\AltchaChallenge;
use PHPUnit\Framework\TestCase;

final class AltchaChallengeJsonTest extends TestCase
{
    public function testTheWidgetGetsEveryFieldWithItsLowercaseMaxnumber(): void
    {
        self::assertSame(
            [
                'algorithm' => 'SHA-256',
                'challenge' => 'c0ffee',
                'salt' => 'salt?expires=1',
                'signature' => 'beef',
                'maxnumber' => 150000,
            ],
            AltchaChallengeJson::from(new AltchaChallenge('SHA-256', 'c0ffee', 'salt?expires=1', 'beef', 150000)),
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/Http/AltchaChallengeJsonTest.php`
Expected: FAIL: `Class "App\Http\AltchaChallengeJson" not found`.

- [ ] **Step 3: Implement**

`src/Http/AltchaChallengeJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Auth\AltchaChallenge;

/** The JSON the ALTCHA browser widget consumes verbatim. */
final class AltchaChallengeJson
{
    /** @return array{algorithm: string, challenge: string, salt: string, signature: string, maxnumber: int} */
    public static function from(AltchaChallenge $challenge): array
    {
        return [
            'algorithm' => $challenge->algorithm,
            'challenge' => $challenge->challenge,
            'salt' => $challenge->salt,
            'signature' => $challenge->signature,
            // The widget's field name is lowercase.
            'maxnumber' => $challenge->maxNumber,
        ];
    }
}
```

`src/Service/Auth/AltchaChallenge.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** One sha256 proof-of-work challenge, as AltchaService issues it. */
final readonly class AltchaChallenge
{
    public function __construct(
        public string $algorithm,
        public string $challenge,
        public string $salt,
        public string $signature,
        public int $maxNumber,
    ) {
    }
}
```

`src/Controller/Api/AuthController.php`:
- Add `use App\Http\AltchaChallengeJson;` directly before `use App\Service\Auth\AltchaService;`.
- In `altchaChallenge()`:
```diff
-        return new JsonResponse($this->altcha->createChallenge()->toArray());
+        return new JsonResponse(AltchaChallengeJson::from($this->altcha->createChallenge()));
```

- [ ] **Step 4: Run to verify it passes**

Run: `php bin/phpunit tests/Http/AltchaChallengeJsonTest.php tests/Service/Auth tests/Controller/Api/RegistrationTest.php tests/Controller/Api/AuthJourneyTest.php`
Expected: PASS. `RegistrationTest::testAltchaChallengeIsPublicAndWellFormed` still sees `maxnumber`.

- [ ] **Step 5: Deletion check**

In `AltchaChallengeJson::from()`, rename the key to `'maxNumber'`. Expected: `AltchaChallengeJsonTest` and `RegistrationTest::testAltchaChallengeIsPublicAndWellFormed` fail. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the four PHP files.
```bash
git add src/Http/AltchaChallengeJson.php src/Service/Auth/AltchaChallenge.php src/Controller/Api/AuthController.php tests/Http/AltchaChallengeJsonTest.php
git commit -m "refactor(#1182): src/Http maps the ALTCHA challenge"
```

---

### Task B3: Maintenance responses: `RefreshReportJson`, `ForYouSweepReportJson`, typed `MaintenanceTickJson`

**Files:**
- Create: `src/Http/RefreshReportJson.php`, `src/Http/ForYouSweepReportJson.php`, `src/Http/MaintenanceTickJson.php`
- Create: `src/Service/Maintenance/MaintenanceSweeps.php`
- Create: `tests/Http/RefreshReportJsonTest.php`, `tests/Http/ForYouSweepReportJsonTest.php`, `tests/Http/MaintenanceTickJsonTest.php`
- Modify: `src/Service/Maintenance/MaintenanceTickReport.php` (rewritten: typed), `src/Service/Maintenance/MaintenanceTick.php` (`run()` and one private method; the constant `ABORTED_REASON` and `skipped()` go)
- Modify: `src/Service/Refresh/RefreshReport.php`, `src/Service/Mail/Digest/DigestSweepReport.php`, `src/Service/Search/Membership/SavedSearchMembershipSweepReport.php` (`toArray()` → `toLogContext()`, D5)
- Modify: `src/Service/Recommendation/ForYouSweepReport.php`, `src/Service/Image/ImageVerificationReport.php`, `src/Service/Logging/Loki/LokiSpoolReport.php` (`toArray()` goes)
- Modify: `src/Controller/MaintenanceController.php` (three lines, three imports)
- Modify: `src/Service/Worker/Handler/RefreshDueFeedsHandler.php`, `SendDueDigestsHandler.php`, `SweepSavedSearchMembershipsHandler.php` (one line each)
- Modify: `src/Command/RefreshFeedsCommand.php` (one line)
- Delete: `tests/Service/Maintenance/MaintenanceTickReportTest.php`, `tests/Service/Recommendation/ForYouSweepReportTest.php` (their pins move to the mapper tests)
- Modify: `tests/Service/Maintenance/MaintenanceTickTest.php` (two assertion blocks, imports), `tests/Service/Image/ImageVerificationSweepTest.php`, `tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php`, `tests/Service/Search/Membership/SweepTallyTest.php` (perl)

**Interfaces:**
- Produces: `App\Http\RefreshReportJson::report(RefreshReport $report): array{status: string, total: int, fetched: int, notModified: int, failed: int, throttled: int, skippedForBudget: int, remaining: int, pruned: int}`.
- Produces: `App\Http\ForYouSweepReportJson::report(ForYouSweepReport $report): array{startedRuns: int, advancedRuns: int, activeRuns: int}`.
- Produces: `App\Http\MaintenanceTickJson::report(MaintenanceTickReport $report): array<string, array<string, int|bool|string>>`; `MaintenanceTickJson::SKIPPED_REASON`.
- Produces: `App\Service\Maintenance\MaintenanceSweeps::__construct(ForYouSweepReport $recommendations, DigestSweepReport $digests, ImageVerificationReport $imageVerification, SavedSearchMembershipSweepReport $savedSearchMemberships, bool $skipped = false)`; `MaintenanceSweeps::skippedAfterAbortedRefresh(): self`.
- Produces: `App\Service\Maintenance\MaintenanceTickReport::__construct(RefreshReport $refresh, MaintenanceSweeps $sweeps, LokiSpoolReport $logShipping)`.
- Produces: `RefreshReport::toLogContext()`, `DigestSweepReport::toLogContext()`, `SavedSearchMembershipSweepReport::toLogContext()`, each the exact map its `toArray()` returned.

- [ ] **Step 1: Write the failing tests**

`tests/Http/RefreshReportJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RefreshReportJson;
use App\Service\Refresh\RefreshReport;
use PHPUnit\Framework\TestCase;

final class RefreshReportJsonTest extends TestCase
{
    public function testAFinishedRunSendsEveryCounterUnderItsOwnKey(): void
    {
        $report = RefreshReport::finished(
            total: 9,
            fetched: 1,
            notModified: 2,
            failed: 3,
            throttled: 4,
            skippedForBudget: 5,
            remaining: 6,
            pruned: 7,
        );

        self::assertSame(
            [
                'status' => 'partial',
                'total' => 9,
                'fetched' => 1,
                'notModified' => 2,
                'failed' => 3,
                'throttled' => 4,
                'skippedForBudget' => 5,
                'remaining' => 6,
                'pruned' => 7,
            ],
            RefreshReportJson::report($report),
        );
    }

    public function testABusyRunSendsItsStatusAndZeroes(): void
    {
        self::assertSame(
            [
                'status' => 'busy',
                'total' => 0,
                'fetched' => 0,
                'notModified' => 0,
                'failed' => 0,
                'throttled' => 0,
                'skippedForBudget' => 0,
                'remaining' => 0,
                'pruned' => 0,
            ],
            RefreshReportJson::report(RefreshReport::busy()),
        );
    }
}
```

`tests/Http/ForYouSweepReportJsonTest.php` (the pin `ForYouSweepReportTest` held):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ForYouSweepReportJson;
use App\Service\Recommendation\ForYouSweepReport;
use PHPUnit\Framework\TestCase;

final class ForYouSweepReportJsonTest extends TestCase
{
    public function testItSendsTheThreeCounts(): void
    {
        self::assertSame(
            ['startedRuns' => 2, 'advancedRuns' => 3, 'activeRuns' => 1],
            ForYouSweepReportJson::report(new ForYouSweepReport(2, 3, 1)),
        );
    }
}
```

`tests/Http/MaintenanceTickJsonTest.php` (the shapes `MaintenanceTickReportTest` and `MaintenanceTickTest` pinned, the skip reason spelled out):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\MaintenanceTickJson;
use App\Service\Image\ImageVerificationReport;
use App\Service\Logging\Loki\LokiSpoolReport;
use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Maintenance\MaintenanceSweeps;
use App\Service\Maintenance\MaintenanceTickReport;
use App\Service\Recommendation\ForYouSweepReport;
use App\Service\Refresh\RefreshReport;
use App\Service\Search\Membership\SavedSearchMembershipSweepReport;
use PHPUnit\Framework\TestCase;

final class MaintenanceTickJsonTest extends TestCase
{
    public function testACompletedTickSendsEveryHalfUnderItsKey(): void
    {
        $report = new MaintenanceTickReport(
            RefreshReport::finished(9, 1, 2, 3, 4, 5, 0, 7),
            new MaintenanceSweeps(
                new ForYouSweepReport(1, 2, 3),
                new DigestSweepReport(4, 5, 6),
                new ImageVerificationReport(7, 8, 9, 10),
                new SavedSearchMembershipSweepReport(11, 12, 13, true),
            ),
            new LokiSpoolReport(14, 15),
        );

        self::assertSame(
            [
                'refresh' => [
                    'status' => 'completed',
                    'total' => 9,
                    'fetched' => 1,
                    'notModified' => 2,
                    'failed' => 3,
                    'throttled' => 4,
                    'skippedForBudget' => 5,
                    'remaining' => 0,
                    'pruned' => 7,
                ],
                'recommendations' => ['startedRuns' => 1, 'advancedRuns' => 2, 'activeRuns' => 3],
                'digests' => ['considered' => 4, 'sent' => 5, 'skippedEmpty' => 6],
                'imageVerification' => ['measured' => 7, 'kept' => 8, 'dropped' => 9, 'retried' => 10],
                'savedSearchMemberships' => [
                    'searchesSwept' => 11,
                    'entriesScanned' => 12,
                    'matchesInserted' => 13,
                    'caughtUp' => true,
                ],
                'logShipping' => ['shipped' => 14, 'failed' => 15],
            ],
            MaintenanceTickJson::report($report),
        );
    }

    public function testSweepsSkippedAfterAnAbortedRefreshSayWhy(): void
    {
        $report = new MaintenanceTickReport(
            RefreshReport::aborted(5, 1, 1, 1, 0, 2),
            MaintenanceSweeps::skippedAfterAbortedRefresh(),
            new LokiSpoolReport(0, 0),
        );
        $reason = 'refresh aborted: the shared EntityManager is unusable this tick';

        self::assertSame(
            [
                'refresh' => [
                    'status' => 'aborted',
                    'total' => 5,
                    'fetched' => 1,
                    'notModified' => 1,
                    'failed' => 1,
                    'throttled' => 0,
                    'skippedForBudget' => 0,
                    'remaining' => 2,
                    'pruned' => 0,
                ],
                'recommendations' => [
                    'startedRuns' => 0,
                    'advancedRuns' => 0,
                    'activeRuns' => 0,
                    'skipped' => $reason,
                ],
                'digests' => ['considered' => 0, 'sent' => 0, 'skippedEmpty' => 0, 'skipped' => $reason],
                'imageVerification' => [
                    'measured' => 0,
                    'kept' => 0,
                    'dropped' => 0,
                    'retried' => 0,
                    'skipped' => $reason,
                ],
                'savedSearchMemberships' => [
                    'searchesSwept' => 0,
                    'entriesScanned' => 0,
                    'matchesInserted' => 0,
                    'caughtUp' => false,
                    'skipped' => $reason,
                ],
                'logShipping' => ['shipped' => 0, 'failed' => 0],
            ],
            MaintenanceTickJson::report($report),
        );
    }
}
```

`tests/Service/Maintenance/MaintenanceTickTest.php`:
- Add these imports next to their neighbours (alphabetical order is not enforced; keep them in the `App\Service\…` block): `use App\Service\Logging\Loki\LokiSpoolReport;`, `use App\Service\Maintenance\MaintenanceSweeps;`.
- In `testRunProducesAReportCarryingBothHalves()`, replace everything from `$report = $tick->run()->toArray();` to the method's closing `}` with:
```php
        $report = $tick->run();

        // The shared test database may hold other classes' rows: this proves the sweeps ran, not their counts.
        self::assertFalse($report->refresh->isAborted());
        self::assertFalse($report->sweeps->skipped);
    }
```
- In `testSkipsTheRecommendationSweepWhenRefreshAborts()`, replace everything from `$report = $tick->run()->toArray();` to the method's closing `}` with:
```php
        $report = $tick->run();

        self::assertTrue($report->refresh->isAborted());
        self::assertEquals(MaintenanceSweeps::skippedAfterAbortedRefresh(), $report->sweeps);
        self::assertEquals(new LokiSpoolReport(0, 0), $report->logShipping);
    }
```
The throwing `PreferencesRepository` stub still proves `SendDueDigests::run()` never ran on the aborted path.

The other three tests read the reports' fields or log maps:
```bash
perl -pi -e 's/->verifyDue\(\)->toArray\(\)/->verifyDue()/g; s/\$report\[\x27(\w+)\x27\]/\$report->$1/g' tests/Service/Image/ImageVerificationSweepTest.php
perl -pi -e 's/\$report->toArray\(\)/\$report->toLogContext()/; s/->caughtUp\(\)->toArray\(\)/->caughtUp()->toLogContext()/' tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php tests/Service/Search/Membership/SweepTallyTest.php
git rm tests/Service/Maintenance/MaintenanceTickReportTest.php tests/Service/Recommendation/ForYouSweepReportTest.php
git grep -nE 'toArray|\$report\[' -- tests/Service/Image/ImageVerificationSweepTest.php tests/Service/Search/Membership tests/Service/Maintenance
```
Expected: the grep prints nothing.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Http/RefreshReportJsonTest.php tests/Http/ForYouSweepReportJsonTest.php tests/Http/MaintenanceTickJsonTest.php tests/Service/Maintenance tests/Service/Image/ImageVerificationSweepTest.php tests/Service/Search/Membership`
Expected: FAIL: the three mapper classes and `MaintenanceSweeps` not found; `Call to undefined method …::toLogContext()`; `Undefined property` on the tick report.

- [ ] **Step 3: Implement**

`src/Service/Maintenance/MaintenanceSweeps.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Image\ImageVerificationReport;
use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Recommendation\ForYouSweepReport;
use App\Service\Search\Membership\SavedSearchMembershipSweepReport;

/** The tick's sweeps that flush through the default EntityManager, which an aborted refresh leaves closed. */
final readonly class MaintenanceSweeps
{
    public function __construct(
        public ForYouSweepReport $recommendations,
        public DigestSweepReport $digests,
        public ImageVerificationReport $imageVerification,
        public SavedSearchMembershipSweepReport $savedSearchMemberships,
        public bool $skipped = false,
    ) {
    }

    public static function skippedAfterAbortedRefresh(): self
    {
        return new self(
            new ForYouSweepReport(0, 0, 0),
            new DigestSweepReport(0, 0, 0),
            new ImageVerificationReport(0, 0, 0, 0),
            new SavedSearchMembershipSweepReport(0, 0, 0, false),
            skipped: true,
        );
    }
}
```

`src/Service/Maintenance/MaintenanceTickReport.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

use App\Service\Logging\Loki\LokiSpoolReport;
use App\Service\Refresh\RefreshReport;

/** The outcome of one maintenance tick (#346). */
final readonly class MaintenanceTickReport
{
    public function __construct(
        public RefreshReport $refresh,
        public MaintenanceSweeps $sweeps,
        public LokiSpoolReport $logShipping,
    ) {
    }
}
```

`src/Service/Maintenance/MaintenanceTick.php`:
- Delete the imports `use App\Service\Image\ImageVerificationReport;`, `use App\Service\Mail\Digest\DigestSweepReport;` and `use App\Service\Search\Membership\SavedSearchMembershipSweepReport;`.
- Delete `    private const string ABORTED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';` and the blank line above it.
- Replace `run()` and `skipped()` (from `    public function run(): MaintenanceTickReport` to the class's closing `}`) with:
```php
    public function run(): MaintenanceTickReport
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', self::TICK_WINDOW_SECONDS));
        $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
        $sweeps = $refresh->isAborted() ? MaintenanceSweeps::skippedAfterAbortedRefresh() : $this->sweep($deadline);

        return new MaintenanceTickReport($refresh, $sweeps, $this->logSpoolShipper->ship());
    }

    private function sweep(\DateTimeImmutable $deadline): MaintenanceSweeps
    {
        $recommendations = $this->forYouSweep->sweepOnce();
        $digests = $this->sendDueDigests->run();
        $imageVerification = $this->imageVerificationSweep->verifyDue();
        $budget = SweepBudget::remainingUntil($deadline, $this->clock->now(), self::MEMBERSHIP_BUDGET_SECONDS);

        return new MaintenanceSweeps(
            $recommendations,
            $digests,
            $imageVerification,
            $this->membershipSweep->sweep($budget),
        );
    }
}
```
The order is unchanged: refresh, the four sweeps (or none), then the log spool. The class docblock stays true and stays.

`src/Service/Refresh/RefreshReport.php`, `src/Service/Mail/Digest/DigestSweepReport.php`, `src/Service/Search/Membership/SavedSearchMembershipSweepReport.php`: rename the method, keeping the body and the `@return` shape:
```bash
perl -pi -e 's/public function toArray\(\): array/public function toLogContext(): array/' src/Service/Refresh/RefreshReport.php src/Service/Mail/Digest/DigestSweepReport.php src/Service/Search/Membership/SavedSearchMembershipSweepReport.php
```

`src/Service/Recommendation/ForYouSweepReport.php`, `src/Service/Image/ImageVerificationReport.php`, `src/Service/Logging/Loki/LokiSpoolReport.php`: delete `toArray()` with its docblock and the blank line above it, in each file (the method is the last member; the constructor stays).

The three handlers:
```bash
perl -pi -e "s/\['report' => \\\$report->toArray\(\)\]/['report' => \\\$report->toLogContext()]/" src/Service/Worker/Handler/RefreshDueFeedsHandler.php src/Service/Worker/Handler/SendDueDigestsHandler.php src/Service/Worker/Handler/SweepSavedSearchMembershipsHandler.php
```

`src/Command/RefreshFeedsCommand.php`:
```diff
-        foreach ($report->toArray() as $key => $value) {
+        foreach ($report->toLogContext() as $key => $value) {
```

`src/Http/RefreshReportJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Refresh\RefreshReport;

/** A whole refresh run, `total` included, as the maintenance endpoints report it. */
final class RefreshReportJson
{
    /**
     * @return array{status: string, total: int, fetched: int, notModified: int, failed: int, throttled: int,
     *     skippedForBudget: int, remaining: int, pruned: int}
     */
    public static function report(RefreshReport $report): array
    {
        return [
            'status' => $report->status,
            'total' => $report->total,
            'fetched' => $report->fetched,
            'notModified' => $report->notModified,
            'failed' => $report->failed,
            'throttled' => $report->throttled,
            'skippedForBudget' => $report->skippedForBudget,
            'remaining' => $report->remaining,
            'pruned' => $report->pruned,
        ];
    }
}
```

`src/Http/ForYouSweepReportJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Recommendation\ForYouSweepReport;

final class ForYouSweepReportJson
{
    /** @return array{startedRuns: int, advancedRuns: int, activeRuns: int} */
    public static function report(ForYouSweepReport $report): array
    {
        return [
            'startedRuns' => $report->startedRuns,
            'advancedRuns' => $report->advancedRuns,
            'activeRuns' => $report->activeRuns,
        ];
    }
}
```

`src/Http/MaintenanceTickJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Image\ImageVerificationReport;
use App\Service\Logging\Loki\LokiSpoolReport;
use App\Service\Mail\Digest\DigestSweepReport;
use App\Service\Maintenance\MaintenanceSweeps;
use App\Service\Maintenance\MaintenanceTickReport;
use App\Service\Search\Membership\SavedSearchMembershipSweepReport;

/** The /maintenance/tick response: every half under a stable key; a skipped sweep says why. */
final class MaintenanceTickJson
{
    public const string SKIPPED_REASON = 'refresh aborted: the shared EntityManager is unusable this tick';

    /** @return array<string, array<string, int|bool|string>> */
    public static function report(MaintenanceTickReport $report): array
    {
        $sweeps = $report->sweeps;

        return [
            'refresh' => RefreshReportJson::report($report->refresh),
            'recommendations' => self::markedIfSkipped(
                $sweeps,
                ForYouSweepReportJson::report($sweeps->recommendations),
            ),
            'digests' => self::markedIfSkipped($sweeps, self::digests($sweeps->digests)),
            'imageVerification' => self::markedIfSkipped(
                $sweeps,
                self::imageVerification($sweeps->imageVerification),
            ),
            'savedSearchMemberships' => self::markedIfSkipped(
                $sweeps,
                self::memberships($sweeps->savedSearchMemberships),
            ),
            'logShipping' => self::logShipping($report->logShipping),
        ];
    }

    /**
     * @param array<string, int|bool> $counts
     *
     * @return array<string, int|bool|string>
     */
    private static function markedIfSkipped(MaintenanceSweeps $sweeps, array $counts): array
    {
        if (!$sweeps->skipped) {
            return $counts;
        }

        return $counts + ['skipped' => self::SKIPPED_REASON];
    }

    /** @return array{considered: int, sent: int, skippedEmpty: int} */
    private static function digests(DigestSweepReport $report): array
    {
        return ['considered' => $report->considered, 'sent' => $report->sent, 'skippedEmpty' => $report->skippedEmpty];
    }

    /** @return array{measured: int, kept: int, dropped: int, retried: int} */
    private static function imageVerification(ImageVerificationReport $report): array
    {
        return [
            'measured' => $report->measured,
            'kept' => $report->kept,
            'dropped' => $report->dropped,
            'retried' => $report->retried,
        ];
    }

    /** @return array{searchesSwept: int, entriesScanned: int, matchesInserted: int, caughtUp: bool} */
    private static function memberships(SavedSearchMembershipSweepReport $report): array
    {
        return [
            'searchesSwept' => $report->searchesSwept,
            'entriesScanned' => $report->entriesScanned,
            'matchesInserted' => $report->matchesInserted,
            'caughtUp' => $report->caughtUp,
        ];
    }

    /** @return array{shipped: int, failed: int} */
    private static function logShipping(LokiSpoolReport $report): array
    {
        return ['shipped' => $report->shipped, 'failed' => $report->failed];
    }
}
```
The digest and membership maps equal those reports' `toLogContext()` today. They are two contracts (the wire and the worker log, D5), so each side keeps its own.

`src/Controller/MaintenanceController.php`:
- Add `use App\Http\ForYouSweepReportJson;`, `use App\Http\MaintenanceTickJson;` and `use App\Http\RefreshReportJson;` directly after `use App\Http\MaintenanceTokenGuard;`.
- In `refresh()`:
```diff
-        return new JsonResponse($report->toArray(), $status);
+        return new JsonResponse(RefreshReportJson::report($report), $status);
```
- In `sweepRecommendations()`:
```diff
-        return new JsonResponse($this->forYouSweep->sweepOnce()->toArray());
+        return new JsonResponse(ForYouSweepReportJson::report($this->forYouSweep->sweepOnce()));
```
- In `tick()`:
```diff
-        return new JsonResponse($this->maintenanceTick->run()->toArray());
+        return new JsonResponse(MaintenanceTickJson::report($this->maintenanceTick->run()));
```

- [ ] **Step 4: Run to verify they pass**

Run:
```bash
php bin/phpunit tests/Http tests/Service/Maintenance tests/Service/Image tests/Service/Search/Membership tests/Service/Refresh tests/Service/Worker tests/Service/Mail/Digest tests/Service/Logging tests/Controller/MaintenanceControllerTest.php tests/Command/RefreshFeedsCommandTest.php tests/Command/RefreshFeedsCommandRequestTest.php
git grep -n 'toArray' -- src/Service/Maintenance src/Service/Refresh/RefreshReport.php src/Service/Recommendation/ForYouSweepReport.php src/Service/Image/ImageVerificationReport.php src/Service/Logging/Loki/LokiSpoolReport.php src/Service/Mail/Digest/DigestSweepReport.php src/Service/Search/Membership/SavedSearchMembershipSweepReport.php src/Service/Worker src/Command/RefreshFeedsCommand.php src/Controller/MaintenanceController.php
```
Expected: PASS; the grep prints nothing. `MaintenanceControllerTest`, `RefreshDueFeedsHandlerTest` and `SweepSavedSearchMembershipsHandlerTest` are unedited and green.

- [ ] **Step 5: Deletion checks**

1. In `MaintenanceTickJson::markedIfSkipped()`, return `$counts` unconditionally. Expected: `testSweepsSkippedAfterAnAbortedRefreshSayWhy` fails. Restore.
2. In `MaintenanceTick::run()`, always call `$this->sweep($deadline)`. Expected: `MaintenanceTickTest::testSkipsTheRecommendationSweepWhenRefreshAborts` fails (the throwing stub or the `skipped` assertion). Restore.
3. In `RefreshReportJson::report()`, drop the `'total'` entry. Expected: both `RefreshReportJsonTest` tests and `testACompletedTickSendsEveryHalfUnderItsKey` fail. Restore.
4. In `ForYouSweepReportJson::report()`, map `'activeRuns' => $report->advancedRuns`. Expected: `ForYouSweepReportJsonTest` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on every changed PHP file.
```bash
git add src/Http/RefreshReportJson.php src/Http/ForYouSweepReportJson.php src/Http/MaintenanceTickJson.php src/Service/Maintenance src/Service/Refresh/RefreshReport.php src/Service/Mail/Digest/DigestSweepReport.php src/Service/Search/Membership/SavedSearchMembershipSweepReport.php src/Service/Recommendation/ForYouSweepReport.php src/Service/Image/ImageVerificationReport.php src/Service/Logging/Loki/LokiSpoolReport.php src/Controller/MaintenanceController.php src/Service/Worker/Handler src/Command/RefreshFeedsCommand.php tests/Http tests/Service/Maintenance tests/Service/Image/ImageVerificationSweepTest.php tests/Service/Search/Membership
git commit -m "refactor(#1182): src/Http maps the maintenance reports; the tick report is typed"
```

---

### Task B4: `RefreshJson` and `RecommendationRunStatusJson` build their own shapes

**Files:**
- Modify: `src/Service/Refresh/RefreshRunProgress.php`, `src/Service/Recommendation/RecommendationRunReport.php` (`toArray()` goes)
- Modify: `src/Http/RefreshJson.php` (one entry), `src/Http/RecommendationRunStatusJson.php` (`report()` rewritten)
- Modify: `src/Service/Refresh/RefreshRunStore.php` (`save()` builds the stored record)
- Modify: `tests/Http/RecommendationRunStatusJsonTest.php` (one pin), `tests/Service/Refresh/RefreshRunProgressTest.php` (`testItSerialisesForTheWire` goes: `RefreshJsonTest` pins the wire, `RefreshRunStoreTest` the store round trip)

**Interfaces:**
- Consumes: `RefreshRunProgress::$done`, `$total`; `RecommendationRunReport`'s public fields and `elapsedSecondsAt()`.
- Produces: no public signature change except the two deleted `toArray()` methods.

- [ ] **Step 1: Pin the run-status shape (it passes today)**

`tests/Http/RecommendationRunStatusJsonTest.php`, before `private function user(): User`, add:
```php
    public function testItSendsTheRunReportFieldsInTheirWireOrder(): void
    {
        $json = RecommendationRunStatusJson::report(new RecommendationRunStatus(
            RecommendationRunReport::busy()->waitingForLock(),
            $this->emptySummary(),
            new \DateTimeImmutable('2026-08-09T10:00:00'),
            null,
        ));

        self::assertSame(
            [
                'status' => 'busy',
                'batchesTotal' => null,
                'batchesDone' => 0,
                'error' => null,
                'background' => false,
                'waitingForLock' => true,
                'streamedChars' => 0,
                'firstBatchStarted' => false,
                'elapsedSeconds' => null,
                'etaSeconds' => null,
                'forYou' => ['itemCount' => 0, 'totalCount' => 0, 'generatedAt' => null, 'newestRunId' => null],
            ],
            $json,
        );
    }
```
Run: `php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php tests/Http/RefreshJsonTest.php`
Expected: PASS. These two pins are what the next steps must keep green.

- [ ] **Step 2: Take the serialisers off the service values**

`src/Service/Refresh/RefreshRunProgress.php`: delete, with the blank line above it:
```php

    /** @return array{done: int, total: int} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'total' => $this->total];
    }
```

`src/Service/Recommendation/RecommendationRunReport.php`: delete `toArray()` with its docblock and the blank line above it (from `    /**` over `@return array{status: string, batchesTotal: ?int, …}` to the method's closing `}`).

`tests/Service/Refresh/RefreshRunProgressTest.php`: delete `testItSerialisesForTheWire()` with the blank line above it.

Run: `php bin/phpunit tests/Http/RecommendationRunStatusJsonTest.php tests/Http/RefreshJsonTest.php tests/Service/Refresh/RefreshRunStoreTest.php`
Expected: FAIL: `Call to undefined method App\Service\Recommendation\RecommendationRunReport::toArray()` and `…\RefreshRunProgress::toArray()`.

- [ ] **Step 3: The mapper and the store build their own arrays**

`src/Http/RefreshJson.php`, in `slice()`:
```diff
-            'progress' => $tracked->progress->toArray(),
+            'progress' => ['done' => $tracked->progress->done, 'total' => $tracked->progress->total],
```

`src/Service/Refresh/RefreshRunStore.php`, in `save()`:
```diff
-        $item->set($progress->toArray());
+        $item->set(['done' => $progress->done, 'total' => $progress->total]);
```
The same two keys `open()` reads back; the store owns its record now.

`src/Http/RecommendationRunStatusJson.php`, replace `report()`:
```php
    /** @return array<string, mixed> */
    public static function report(RecommendationRunStatus $status): array
    {
        $report = $status->report;
        $summary = $status->forYou;

        return [
            'status' => $report->status,
            'batchesTotal' => $report->batchesTotal,
            'batchesDone' => $report->batchesDone,
            'error' => $report->error,
            'background' => $report->background,
            'waitingForLock' => $report->waitingForLock,
            'streamedChars' => $report->streamedChars,
            'firstBatchStarted' => $report->firstBatchStarted,
            'elapsedSeconds' => $report->elapsedSecondsAt($status->observedAt),
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
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Http tests/Service/Refresh tests/Service/Recommendation tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RefreshControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

1. In `RecommendationRunStatusJson::report()`, swap the `'background'` and `'waitingForLock'` values. Expected: `testItSendsTheRunReportFieldsInTheirWireOrder` fails. Restore.
2. In `RefreshRunStore::save()`, store `['done' => $progress->total, 'total' => $progress->done]`. Expected: `RefreshRunStoreTest::testASavedRunIsHandedBackToTheNextSlice` fails. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the seven PHP files.
```bash
git add src/Service/Refresh/RefreshRunProgress.php src/Service/Recommendation/RecommendationRunReport.php src/Http/RefreshJson.php src/Http/RecommendationRunStatusJson.php src/Service/Refresh/RefreshRunStore.php tests/Http/RecommendationRunStatusJsonTest.php tests/Service/Refresh/RefreshRunProgressTest.php
git commit -m "refactor(#1182): the refresh and run-status mappers build their own shapes"
```

---

### Task B5: Store serialisers are named after their store

**Files:**
- Modify: `src/Service/Grafana/GrafanaSettingsSnapshot.php` (#1159 B1's version: two method names), `src/Service/Grafana/GrafanaSettingsCache.php` (two calls)
- Modify: `src/Service/ReaderAudit/AuditFinding.php`, `src/Service/ReaderAudit/CleanupMarker.php` (four method names), `src/Service/ReaderAudit/AuditFindingsFile.php`, `src/Service/ReaderAudit/AuditFindings.php` (one call each)
- Modify: `tests/Service/Grafana/GrafanaSettingsSnapshotTest.php`, `tests/Service/ReaderAudit/AuditFindingsFileTest.php`, `tests/Service/ReaderAudit/AuditReportHtmlTest.php`, `tests/Service/ReaderAudit/AuditFindingsTest.php` (perl)

**Interfaces:**
- Produces: `GrafanaSettingsSnapshot::toCacheEntry(): array<string, string|bool|int|null>` and `GrafanaSettingsSnapshot::fromCacheEntryOrNull(mixed $stored): ?self` (were `toArray()`/`fromArrayOrNull()`; bodies unchanged).
- Produces: `AuditFinding::toFindingsFileRecord(): array<string, mixed>`, `AuditFinding::fromFindingsFileRecord(array $row): self`, `CleanupMarker::toFindingsFileRecord(): array{code: string, weight: int, suspect: string, detail: string}`, `CleanupMarker::fromFindingsFileRecord(array $row): self` (were `toArray()`/`fromArray()`; bodies unchanged).

The stored formats do not change: an entry already in the Grafana cache pool, and a findings file from an earlier sweep, read back exactly as before.

- [ ] **Step 1: Point the tests at the new names**

```bash
git grep -n 'toEntity' -- src/Service/Grafana/GrafanaSettingsSnapshot.php
perl -pi -e 's/->toArray\(\)/->toCacheEntry()/g; s/GrafanaSettingsSnapshot::fromArrayOrNull\(/GrafanaSettingsSnapshot::fromCacheEntryOrNull(/g; s/TheArrayRoundTrip/TheCacheEntryRoundTrip/g' tests/Service/Grafana/GrafanaSettingsSnapshotTest.php
perl -pi -e 's/->toArray\(\)/->toFindingsFileRecord()/g; s/::fromArray\(/::fromFindingsFileRecord(/g' tests/Service/ReaderAudit/AuditFindingsFileTest.php tests/Service/ReaderAudit/AuditReportHtmlTest.php tests/Service/ReaderAudit/AuditFindingsTest.php
```
Expected: the first grep prints nothing. **Hard dependency:** a `toEntity` hit means #1159 B1 has not landed; stop.

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/Grafana tests/Service/ReaderAudit`
Expected: FAIL: `Call to undefined method App\Service\Grafana\GrafanaSettingsSnapshot::toCacheEntry()` / `fromCacheEntryOrNull()` and `App\Service\ReaderAudit\AuditFinding::toFindingsFileRecord()`.

- [ ] **Step 3: Rename in `src`**

```bash
perl -pi -e 's/public function toArray\(\): array/public function toCacheEntry(): array/; s/public static function fromArrayOrNull\(/public static function fromCacheEntryOrNull(/; s/ The array form is\b/ The cache entry is/' src/Service/Grafana/GrafanaSettingsSnapshot.php
perl -pi -e 's/->toArray\(\)/->toCacheEntry()/g; s/GrafanaSettingsSnapshot::fromArrayOrNull\(/GrafanaSettingsSnapshot::fromCacheEntryOrNull(/g' src/Service/Grafana/GrafanaSettingsCache.php
perl -pi -e 's/public function toArray\(\): array/public function toFindingsFileRecord(): array/; s/public static function fromArray\(/public static function fromFindingsFileRecord(/; s/->toArray\(\)/->toFindingsFileRecord()/g; s/::fromArray\(/::fromFindingsFileRecord(/g' src/Service/ReaderAudit/AuditFinding.php src/Service/ReaderAudit/CleanupMarker.php src/Service/ReaderAudit/AuditFindingsFile.php src/Service/ReaderAudit/AuditFindings.php
git grep -nE 'toArray|fromArray\(|fromArrayOrNull\(' -- src/Service/Grafana src/Service/ReaderAudit tests/Service/Grafana tests/Service/ReaderAudit
```
Expected: the grep prints nothing. The private helpers `connectionFromArrayOrNull()` and `sealedTokenFromArrayOrNull()` keep their names: they read one part of the entry. The snapshot's class docblock now reads "… and GrafanaSettingsCache. The cache entry is flat scalars, so an entry from an earlier release either reads back or is rejected as a miss." `GrafanaSettingsCacheTest` needs no edit: it names neither method.

`AuditFinding::toFindingsFileRecord()` now maps its markers with `$m->toFindingsFileRecord()`, and `fromFindingsFileRecord()` with `CleanupMarker::fromFindingsFileRecord(...)`.

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/Service/Grafana tests/Service/ReaderAudit tests/Command tests/Functional/RequestProfilingTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion check**

In `GrafanaSettingsCache`, replace `$snapshot->toCacheEntry()` with `[]`. Expected: `GrafanaSettingsCacheTest` fails (every read becomes a miss). Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the ten PHP files.
```bash
git add src/Service/Grafana/GrafanaSettingsSnapshot.php src/Service/Grafana/GrafanaSettingsCache.php src/Service/ReaderAudit/AuditFinding.php src/Service/ReaderAudit/CleanupMarker.php src/Service/ReaderAudit/AuditFindingsFile.php src/Service/ReaderAudit/AuditFindings.php tests/Service/Grafana/GrafanaSettingsSnapshotTest.php tests/Service/ReaderAudit
git commit -m "refactor(#1182): the cache and findings-file serialisers are named after their store"
```

---

### Task B6: `NoToArrayInServicesRule`; CLAUDE.md says services build no response arrays

**Files:**
- Create: `tests/PhpStan/NoToArrayInServicesRule.php`, `tests/PhpStan/NoToArrayInServicesRuleTest.php`, `tests/PhpStan/data/no-to-array-in-services-fixtures.php`
- Modify: `phpstan.dist.neon` (register the rule)
- Modify: `CLAUDE.md` (the "Domain code knows no HTTP" bullet as PR A left it)

**Interfaces:**
- Consumes: B1–B5 done. No class in `App\Service` declares `toArray()` or `jsonSerialize()`.
- Produces: `App\Tests\PhpStan\NoToArrayInServicesRule` (`Rule<ClassMethod>`, identifier `simpleFeedReader.noToArrayInServices`).

The rule is cheap because the method name carries the whole decision: a service value never shapes a response, and a store's serialiser is named after its store (D5, B5). A mapper in `src/Http` may call its method anything.

- [ ] **Step 1: Write the failing rule test**

`tests/PhpStan/data/no-to-array-in-services-fixtures.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for NoToArrayInServicesRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    final class ShapesItsOwnJson implements \JsonSerializable
    {
        public function toArray(): array
        {
            return [];
        }

        public function jsonSerialize(): array
        {
            return [];
        }
    }
}

namespace App\Service\Fixtures\Nested {
    final class ShoutsItsJson
    {
        public function TOARRAY(): array
        {
            return [];
        }
    }
}

namespace App\Service\Fixtures\Clean {
    final class NamesItsStore
    {
        public function toCacheEntry(): array
        {
            return [];
        }

        public function toLogContext(): array
        {
            return [];
        }
    }
}

namespace App\Http\Fixtures {
    final class MapsTheWire
    {
        public function toArray(): array
        {
            return [];
        }
    }
}

namespace App\ServiceLocator\Fixtures {
    final class LooksLikeAServiceButIsNot
    {
        public function toArray(): array
        {
            return [];
        }
    }
}
```
Check: `sed -n '11p;16p;26p' tests/PhpStan/data/no-to-array-in-services-fixtures.php` prints the `toArray`, `jsonSerialize` and `TOARRAY` declarations.

`tests/PhpStan/NoToArrayInServicesRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoToArrayInServicesRule> */
final class NoToArrayInServicesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoToArrayInServicesRule();
    }

    public function testItReportsAResponseShaperOnAServiceClassOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-to-array-in-services-fixtures.php'],
            [
                [self::message('toArray', 'App\Service\Fixtures'), 11],
                [self::message('jsonSerialize', 'App\Service\Fixtures'), 16],
                [self::message('TOARRAY', 'App\Service\Fixtures\Nested'), 26],
            ],
        );
    }

    private static function message(string $method, string $namespaceName): string
    {
        return sprintf(
            'Services build no response arrays: %s() in %s. '
            . 'Map the value in a src/Http/*Json mapper, or name a store\'s serialiser after the store (#1182).',
            $method,
            $namespaceName,
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php bin/phpunit tests/PhpStan/NoToArrayInServicesRuleTest.php`
Expected: FAIL: `Class "App\Tests\PhpStan\NoToArrayInServicesRule" not found`.

- [ ] **Step 3: Implement**

`tests/PhpStan/NoToArrayInServicesRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Services build no response arrays (#1182): a src/Http/*Json mapper shapes the wire, and a value that serialises for
 * a store names the method after it (toLogContext(), toCacheEntry()).
 *
 * @implements Rule<ClassMethod>
 */
final readonly class NoToArrayInServicesRule implements Rule
{
    private const string SERVICE_NAMESPACE = 'app\\service\\';

    private const array RESPONSE_SHAPERS = ['toarray', 'jsonserialize'];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $namespaceName = $scope->getNamespace() ?? '';
        $method = $node->name->toString();
        if (!self::isService($namespaceName) || !\in_array(strtolower($method), self::RESPONSE_SHAPERS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Services build no response arrays: %s() in %s. '
                . 'Map the value in a src/Http/*Json mapper, or name a store\'s serialiser after the store (#1182).',
                $method,
                $namespaceName,
            ))
                ->identifier('simpleFeedReader.noToArrayInServices')
                ->build(),
        ];
    }

    private static function isService(string $namespaceName): bool
    {
        return str_starts_with(strtolower($namespaceName) . '\\', self::SERVICE_NAMESPACE);
    }
}
```

`phpstan.dist.neon`, directly after the `App\Tests\PhpStan\DomainKnowsNoHttpRule` service block:
```yaml
    -
        class: App\Tests\PhpStan\NoToArrayInServicesRule
        tags:
            - phpstan.rules.rule
```

`CLAUDE.md`, replace the bullet PR A left
```
- **Domain code knows no HTTP.** `DomainKnowsNoHttpRule` forbids `App\Http\*`, the
  request DTOs in `App\Dto\*`, and Symfony's HttpFoundation and HTTP-exception classes,
  class names in strings included, in `Service`, `Repository`, `Entity`, `Enum`,
  `Exception` and `Pagination`. A controller hands a service a `Service/<Module>` value
  (`$request->toChange()`) or a plain field, never the DTO (#1182).
```
with
```
- **Domain code knows no HTTP.** `DomainKnowsNoHttpRule` forbids `App\Http\*`, the
  request DTOs in `App\Dto\*`, and Symfony's HttpFoundation and HTTP-exception classes,
  class names in strings included, in `Service`, `Repository`, `Entity`, `Enum`,
  `Exception` and `Pagination`. A controller hands a service a `Service/<Module>` value
  (`$request->toChange()`) or a plain field, never the DTO (#1182). Services build no
  response arrays either: a `src/Http/*Json` mapper shapes every response, and a value
  that serialises for a store names the method after it (`toLogContext()`,
  `toCacheEntry()`). `NoToArrayInServicesRule` rejects `toArray()` and `jsonSerialize()`
  in `App\Service`.
```

- [ ] **Step 4: Run to verify it passes, and that `src` is clean**

Run:
```bash
php bin/phpunit tests/PhpStan
bin/console cache:warmup && composer stan
```
Expected: PASS; `composer stan` reports nothing. A `simpleFeedReader.noToArrayInServices` error names a serialiser B1–B5 missed: map it in `src/Http` if it reaches a response, otherwise name it after its store, and report it.

- [ ] **Step 5: Deletion checks**

1. Change `SERVICE_NAMESPACE` to `'app\\service'` (no trailing separator). Expected: the rule test fails, because the `App\ServiceLocator` lookalike is now reported. Restore.
2. In `processNode()`, drop the `strtolower(…)` around `$method`. Expected: all three expectations go missing (`RESPONSE_SHAPERS` is lower-case). Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the rule and its test.
```bash
git add tests/PhpStan/NoToArrayInServicesRule.php tests/PhpStan/NoToArrayInServicesRuleTest.php tests/PhpStan/data/no-to-array-in-services-fixtures.php phpstan.dist.neon ../CLAUDE.md
git commit -m "refactor(#1182): services may not declare toArray() or jsonSerialize()"
```

---

### Task B7: The three `AutowireWrongClass` suppressions

**Files:**
- Modify: `src/Service/Subscription/SubscribeOutcome.php`, `src/Service/Search/SavedSearchOutcome.php`, `src/Service/Ai/AddedConfiguration.php` (one docblock line each)

**Interfaces:** none change.

Each class carries an entity to its controller's mapper, which needs the entity itself, so this issue does not remove the entity from the constructor (ruling 2's preferred path does not apply). Symfony autowires everything under `src/` as a candidate service, and PhpStorm warns that an entity cannot be autowired; these values are only ever built with `new`. The suppression and its reason match `RecommendationDebugLog` (on a promoted property, as here) and the entity constructors that already carry it at `a124ad8a`: `EntryState::__construct` (#1164 PR A), `RecommendationRun`, `RecommendationRunLog` and `UserPasskey`. Those need no change; after this task no bare `AutowireWrongClass` WARNING is left in `src`.

- [ ] **Step 1: See the warnings**

Run the PhpStorm lint (`mcp__phpstorm__lint_files`) on the three files.
Expected: one `AutowireWrongClass` WARNING each, at `SubscribeOutcome.php` on `public ?Subscription $subscription,`, `SavedSearchOutcome.php` on `public SavedSearch $savedSearch,`, `AddedConfiguration.php` on `public AiProviderSettings $configuration,`.

- [ ] **Step 2: Suppress them**

`src/Service/Subscription/SubscribeOutcome.php`:
```diff
     private function __construct(
+        /** @noinspection AutowireWrongClass Built with new, never autowired */
         public ?Subscription $subscription,
```

`src/Service/Search/SavedSearchOutcome.php`:
```diff
     private function __construct(
+        /** @noinspection AutowireWrongClass Built with new, never autowired */
         public SavedSearch $savedSearch,
```

`src/Service/Ai/AddedConfiguration.php`:
```diff
     public function __construct(
+        /** @noinspection AutowireWrongClass Built with new, never autowired */
         public AiProviderSettings $configuration,
```

- [ ] **Step 3: Verify**

Run the PhpStorm lint on the three files again. Expected: no WARNING. Then `php bin/phpunit tests/Service/Subscription tests/Service/Search/SavedSearchEditorTest.php tests/Service/Ai`: PASS. No test covers a comment, so there is no deletion check; Step 1's output is the "before".

- [ ] **Step 4: Gates and commit**

Run `composer check && composer md`.
```bash
git add src/Service/Subscription/SubscribeOutcome.php src/Service/Search/SavedSearchOutcome.php src/Service/Ai/AddedConfiguration.php
git commit -m "refactor(#1182): the three outcome values say why they are never autowired"
```

---

### Task B8: `ClassNameReferences` and `PersistenceKnowsNoServiceRule`

**Files:**
- Create: `tests/PhpStan/ClassNameReferences.php`
- Create: `tests/PhpStan/PersistenceKnowsNoServiceRule.php`, `tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php`, `tests/PhpStan/data/persistence-knows-no-service-fixtures.php`
- Modify: `tests/PhpStan/DomainKnowsNoHttpRule.php` (rewritten in full on `ClassNameReferences`; behaviour unchanged)
- Modify: `tests/PhpStan/DomainKnowsNoHttpRuleTest.php` (`getRule()`)
- Modify: `phpstan.dist.neon` (register `ClassNameReferences` as a service)

**Interfaces:**
- Produces: `App\Tests\PhpStan\ClassNameReferences::__construct(NodeFinder $finder)`; `namespacesIn(FileNode $file): list<Namespace_>`; `in(Namespace_ $namespace): list<array{string, int}>`; `static isAnyOf(string $name, list<string> $classes): bool`; `static startsWithAny(string $name, list<string> $prefixes): bool` (case-insensitive; a bare namespace matches its own prefix).
- Produces: `DomainKnowsNoHttpRule::__construct(ClassNameReferences $references)`.
- Produces: `App\Tests\PhpStan\PersistenceKnowsNoServiceRule::__construct(ClassNameReferences $references)` (`Rule<FileNode>`, identifier `simpleFeedReader.persistenceKnowsNoService`). **Not registered in this task:** `src` still breaks it until B9 moves the values; B9 registers it.

- [ ] **Step 1: Write the failing rule test**

`tests/PhpStan/data/persistence-knows-no-service-fixtures.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    use App\Service\Crypto\SealedSecret;

    final class StoresAServiceValue
    {
        public function __construct(public SealedSecret $secret)
        {
        }
    }
}

namespace App\Enum\Fixtures {
    enum NamesAService: string
    {
        case Mailer = 'App\Service\Mail\AccountMailer';
    }
}

namespace App\Doctrine\Fixtures {
    use App\{Service\Search\WordBoundaries};

    final class SharesAServiceHelper
    {
        public function boundaries(): string
        {
            return WordBoundaries::class;
        }
    }
}

namespace App\Service\Fixtures {
    use App\Entity\SealedSecret;

    final class ServicesMayKnowEntities
    {
        public function __construct(public SealedSecret $secret)
        {
        }
    }
}

namespace App\Entity\Fixtures\Clean {
    use App\Enum\ProxyType;

    final class KnowsItsOwnLayer
    {
        public function type(): string
        {
            return ProxyType::class;
        }

        public function lookalike(): string
        {
            return 'App\ServiceLocator\Thing';
        }
    }
}
```
Check: `sed -n '9p;13p;22p;27p;33p' tests/PhpStan/data/persistence-knows-no-service-fixtures.php` prints the `SealedSecret` import, the constructor, the enum case, the group import and the `WordBoundaries::class` return.

`tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<PersistenceKnowsNoServiceRule> */
final class PersistenceKnowsNoServiceRuleTest extends RuleTestCase
{
    private const string SEALED_SECRET = 'App\Service\Crypto\SealedSecret';
    private const string WORD_BOUNDARIES = 'App\Service\Search\WordBoundaries';

    protected function getRule(): Rule
    {
        return new PersistenceKnowsNoServiceRule(new ClassNameReferences(new NodeFinder()));
    }

    public function testItReportsServicesInEntitiesEnumsAndTheOrmExtensionsOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/persistence-knows-no-service-fixtures.php'],
            [
                [self::message('App\Entity\Fixtures', self::SEALED_SECRET), 9],
                [self::message('App\Entity\Fixtures', self::SEALED_SECRET), 13],
                [self::message('App\Enum\Fixtures', 'App\Service\Mail\AccountMailer'), 22],
                [self::message('App\Doctrine\Fixtures', self::WORD_BOUNDARIES), 27],
                [self::message('App\Doctrine\Fixtures', self::WORD_BOUNDARIES), 33],
            ],
        );
    }

    private static function message(string $namespaceName, string $reference): string
    {
        return sprintf(
            'Persistence code must not know a service: %s references %s. '
            . 'Move the shared value to App\Entity, App\Enum or App\Doctrine (docs/architecture.md §8).',
            $namespaceName,
            $reference,
        );
    }
}
```

`tests/PhpStan/DomainKnowsNoHttpRuleTest.php`, in `getRule()`:
```diff
-        return new DomainKnowsNoHttpRule(new NodeFinder());
+        return new DomainKnowsNoHttpRule(new ClassNameReferences(new NodeFinder()));
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php tests/PhpStan/DomainKnowsNoHttpRuleTest.php`
Expected: FAIL: `Class "App\Tests\PhpStan\ClassNameReferences" not found`.

- [ ] **Step 3: Implement**

`tests/PhpStan/ClassNameReferences.php` (the collection logic PR A put in `DomainKnowsNoHttpRule`, moved as it was):
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PHPStan\Node\FileNode;

/** Every class or namespace name a file mentions, for the layering rules: in code, in strings and in imports. */
final readonly class ClassNameReferences
{
    public function __construct(private NodeFinder $finder)
    {
    }

    /** @return list<Namespace_> */
    public function namespacesIn(FileNode $file): array
    {
        return array_values($this->finder->findInstanceOf($file->getNodes(), Namespace_::class));
    }

    /** @return list<array{string, int}> each name with its line */
    public function in(Namespace_ $namespace): array
    {
        $nodes = $this->finder->find(
            $namespace->stmts,
            static fn (Node $node): bool => $node instanceof Name
                || $node instanceof String_
                || $node instanceof InterpolatedStringPart
                || $node instanceof GroupUse,
        );

        $references = [];
        foreach ($nodes as $node) {
            $references = [...$references, ...self::referencesIn($node)];
        }

        return $references;
    }

    /** @param list<string> $classes */
    public static function isAnyOf(string $name, array $classes): bool
    {
        return \in_array(strtolower($name), array_map(strtolower(...), $classes), true);
    }

    /**
     * Case-insensitive, as PHP names are. The appended separator lets a bare namespace, as an alias import names it,
     * match its own prefix.
     *
     * @param list<string> $prefixes
     */
    public static function startsWithAny(string $name, array $prefixes): bool
    {
        $subject = strtolower($name) . '\\';
        foreach ($prefixes as $prefix) {
            if (str_starts_with($subject, strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{string, int}> */
    private static function referencesIn(Node $node): array
    {
        if ($node instanceof GroupUse) {
            return self::groupedReferences($node);
        }
        if ($node instanceof Name) {
            return [[$node->toString(), $node->getStartLine()]];
        }
        if ($node instanceof String_ || $node instanceof InterpolatedStringPart) {
            return [[ltrim($node->value, '\\'), $node->getStartLine()]];
        }

        return [];
    }

    /** @return list<array{string, int}> a group import names each class by its prefix and its own tail */
    private static function groupedReferences(GroupUse $groupUse): array
    {
        return array_values(array_map(
            static fn (UseItem $use): array => [
                $groupUse->prefix->toString() . '\\' . $use->name->toString(),
                $use->getStartLine(),
            ],
            $groupUse->uses,
        ));
    }
}
```

`tests/PhpStan/DomainKnowsNoHttpRule.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Domain code returns typed values and throws typed exceptions; src/Http shapes them (#1158), and a controller hands a
 * service a value, never a request DTO (#1182). Strings, group imports and namespace aliases count too.
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
        'App\\Dto\\',
        'App\\Http\\',
        'Symfony\\Component\\HttpFoundation\\',
        'Symfony\\Component\\HttpKernel\\Exception\\',
    ];

    private const array HTTP_CLASSES = [
        'Symfony\\Component\\Security\\Core\\Exception\\AccessDeniedException',
    ];

    public function __construct(private ClassNameReferences $references)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->references->namespacesIn($node) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!ClassNameReferences::startsWithAny($namespaceName, self::DOMAIN_NAMESPACES)) {
            return [];
        }

        $errors = [];
        foreach ($this->references->in($namespace) as [$reference, $line]) {
            if (self::isHttp($reference)) {
                $errors[] = self::error($namespaceName, $reference, $line);
            }
        }

        return $errors;
    }

    private static function isHttp(string $reference): bool
    {
        return ClassNameReferences::isAnyOf($reference, self::HTTP_CLASSES)
            || ClassNameReferences::startsWithAny($reference, self::HTTP_PREFIXES);
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

`tests/PhpStan/PersistenceKnowsNoServiceRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Namespace_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Entities, enums and the ORM extensions sit below the services, so what they share with a service lives with them
 * (docs/architecture.md §8, #1182).
 *
 * @implements Rule<FileNode>
 */
final readonly class PersistenceKnowsNoServiceRule implements Rule
{
    private const array PERSISTENCE_NAMESPACES = ['App\\Entity\\', 'App\\Enum\\', 'App\\Doctrine\\'];

    private const array SERVICE_PREFIXES = ['App\\Service\\'];

    public function __construct(private ClassNameReferences $references)
    {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($this->references->namespacesIn($node) as $namespace) {
            $errors = [...$errors, ...$this->errorsIn($namespace)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError> */
    private function errorsIn(Namespace_ $namespace): array
    {
        $namespaceName = $namespace->name?->toString() ?? '';
        if (!ClassNameReferences::startsWithAny($namespaceName, self::PERSISTENCE_NAMESPACES)) {
            return [];
        }

        $errors = [];
        foreach ($this->references->in($namespace) as [$reference, $line]) {
            if (ClassNameReferences::startsWithAny($reference, self::SERVICE_PREFIXES)) {
                $errors[] = self::error($namespaceName, $reference, $line);
            }
        }

        return $errors;
    }

    private static function error(string $namespaceName, string $reference, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Persistence code must not know a service: %s references %s. '
            . 'Move the shared value to App\\Entity, App\\Enum or App\\Doctrine (docs/architecture.md §8).',
            $namespaceName,
            $reference,
        ))
            ->identifier('simpleFeedReader.persistenceKnowsNoService')
            ->line($line)
            ->build();
    }
}
```
`processNode()` and `errorsIn()` repeat `DomainKnowsNoHttpRule`'s loop shape with different namespaces and a different verdict; that is two occurrences, under the DRY bar.

`phpstan.dist.neon`, directly before the `App\Tests\PhpStan\DomainKnowsNoHttpRule` service block:
```yaml
    -
        class: App\Tests\PhpStan\ClassNameReferences
```

- [ ] **Step 4: Run to verify they pass**

Run: `php bin/phpunit tests/PhpStan && bin/console cache:warmup && composer stan`
Expected: PASS; `DomainKnowsNoHttpRuleTest` is green with its expectations unchanged, and `composer stan` reports nothing.

- [ ] **Step 5: Deletion checks**

1. In `PersistenceKnowsNoServiceRule::PERSISTENCE_NAMESPACES`, delete `'App\\Doctrine\\'`. Expected: the line-27 and line-33 expectations go missing. Restore.
2. In `ClassNameReferences::referencesIn()`, delete the `GroupUse` branch. Expected: the persistence rule's line-27 error and `DomainKnowsNoHttpRuleTest`'s two line-135 errors go missing. Restore.

- [ ] **Step 6: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on the five PHP files outside `data/`.
```bash
git add tests/PhpStan/ClassNameReferences.php tests/PhpStan/PersistenceKnowsNoServiceRule.php tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php tests/PhpStan/data/persistence-knows-no-service-fixtures.php tests/PhpStan/DomainKnowsNoHttpRule.php tests/PhpStan/DomainKnowsNoHttpRuleTest.php phpstan.dist.neon
git commit -m "refactor(#1182): the layering rules share one name collector; persistence may not know a service"
```

---

### Task B9: Shared values and enums move to their home

**Files:**
- Create (not committed; `var/` is gitignored): `var/refactor-1182/imports.php`, `var/refactor-1182/move-class.php`, `var/refactor-1182/retarget-recommendation-defaults.php`
- Move (with their tests, where one exists):
  - `App\Service\Recommendation\RecommendationBatchSize` → `App\Enum\RecommendationBatchSize` (test → `tests/Enum/`)
  - `App\Service\Mail\Digest\DigestCadence` → `App\Enum\DigestCadence`
  - `App\Service\Mail\Digest\DigestFormat` → `App\Enum\DigestFormat`
  - `App\Service\Reader\MagazineStyle` → `App\Enum\MagazineStyle`
  - `App\Entity\MailKind` → `App\Enum\MailKind`
  - `App\Enum\ScrapeFallback` → `App\Service\Discovery\ScrapeFallback`
  - `App\Enum\SocksReplyCode` → `App\Service\Fetch\SocksReplyCode`
  - `App\Service\Crypto\SealedSecret` → `App\Entity\SealedSecret`
  - `App\Service\Discussion\Discussion` → `App\Entity\Discussion` (test → `tests/Entity/`)
  - `App\Service\Proxy\ProxyConnection` → `App\Entity\ProxyConnection`
  - `App\Service\Mail\Settings\MailConnection` → `App\Entity\MailConnection`
  - `App\Service\Grafana\GrafanaConnection` → `App\Entity\GrafanaConnection`
  - `App\Service\Settings\InstanceSettingsUpdate` → `App\Entity\InstanceSettingsUpdate` (test → `tests/Entity/`)
  - `App\Service\Recommendation\RecommendationSettingsValues` → `App\Entity\RecommendationSettingsValues`
  - `App\Service\Search\WordBoundaries` → `App\Doctrine\WordBoundaries` (test → `tests/Doctrine/`)
- Rewrite in full after the move (comments only, D4): the fifteen moved classes and the four moved tests (Step 4b)
- Modify: `src/Entity/RecommendationSettings.php` (six `DEFAULT_*` constants), `src/Service/Recommendation/EffectiveRecommendationSettings.php` (the six constants go)
- Modify (by the scripts, names and imports only): every `src`, `tests` and `config` file that names a moved class or a moved default. This includes #1159's, #1164's and #1167's files (see "Depends on").
- Modify: `phpstan.dist.neon` (register `PersistenceKnowsNoServiceRule`), `docs/architecture.md` (§8), `CLAUDE.md` (one bullet, one Layout row)

**Interfaces:**
- Consumes: `App\Tests\PhpStan\PersistenceKnowsNoServiceRule` (B8).
- Produces: the fifteen classes under their new names, code unchanged, comments brought to the bar (D4; Step 4b shows each file). `App\Entity\RecommendationSettings::DEFAULT_FAVORITES_CAP` (40), `DEFAULT_KEPT_CAP` (40), `DEFAULT_VIEWED_CAP` (80), `DEFAULT_CANDIDATE_POOL_SIZE` (500), `DEFAULT_LOOKBACK_DAYS` (2), `DEFAULT_PICKS_LIMIT` (50). `EffectiveRecommendationSettings::FALLBACK_CONTEXT_WINDOW` stays.

The rule is the failing test: registered first, it lists today's leaks; the moves empty the list. No code line of a moved class changes (only its namespace and comments), so each class's own tests are the regression net.

- [ ] **Step 1: Register the rule and watch it fail**

`phpstan.dist.neon`, directly after the `App\Tests\PhpStan\NoToArrayInServicesRule` service block:
```yaml
    -
        class: App\Tests\PhpStan\PersistenceKnowsNoServiceRule
        tags:
            - phpstan.rules.rule
```
Run: `bin/console cache:warmup && composer stan`
Expected: FAIL with `simpleFeedReader.persistenceKnowsNoService` errors on the entity and `Doctrine/*` files B0 Step 3 listed (the imports and each code reference to those classes), and nothing else. Paste the error count into the report.

- [ ] **Step 2: Write the move scripts**

`var/refactor-1182/imports.php`:
```php
<?php

declare(strict_types=1);

function namespaceOf(string $class): string
{
    return substr($class, 0, (int) strrpos($class, '\\'));
}

function shortNameOf(string $class): string
{
    return substr($class, (int) strrpos($class, '\\') + 1);
}

function relativePathOf(string $class): string
{
    return str_replace('\\', '/', substr($class, strlen('App\\')));
}

function testNamespaceOf(string $class): string
{
    return 'App\\Tests\\' . substr(namespaceOf($class), strlen('App\\'));
}

function run(string $command): void
{
    passthru($command, $status);
    if (0 !== $status) {
        fwrite(STDERR, "Failed: {$command}\n");
        exit(1);
    }
}

function moveFile(string $from, string $to, string $fromNamespace, string $toNamespace): void
{
    if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0o775, true);
    }
    run(sprintf('git mv %s %s', escapeshellarg($from), escapeshellarg($to)));
    $code = (string) file_get_contents($to);
    file_put_contents($to, str_replace("namespace {$fromNamespace};\n", "namespace {$toNamespace};\n", $code));
}

/** @return list<string> the rule fixtures under tests/PhpStan name the old classes on purpose and stay */
function trackedFiles(): array
{
    return array_values(array_filter(explode("\n", (string) shell_exec(
        "git ls-files src tests config ':(exclude)tests/PhpStan'",
    ))));
}

/** @return list<string> */
function phpFilesIn(string $directory): array
{
    return glob($directory . '/*.php') ?: [];
}

function doubled(string $class): string
{
    return str_replace('\\', '\\\\', $class);
}

function rewriteClassName(string $file, string $oldClass, string $newClass): void
{
    $code = (string) file_get_contents($file);
    $rewritten = $code;
    foreach ([[$oldClass, $newClass], [doubled($oldClass), doubled($newClass)]] as [$from, $to]) {
        $rewritten = (string) preg_replace_callback(
            '/(?<!\w)' . preg_quote($from, '/') . '(?!\w)/',
            static fn (): string => $to,
            $rewritten,
        );
    }
    if ($rewritten !== $code) {
        file_put_contents($file, $rewritten);
    }
}

function namespaceOfCode(string $code): string
{
    return 1 === preg_match('/^namespace ([^;]+);$/m', $code, $match) ? $match[1] : '';
}

function usesBareName(string $file, string $shortName): bool
{
    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (is_array($token) && T_STRING === $token[0] && $shortName === $token[1]) {
            return true;
        }
    }

    return false;
}

function addImport(string $file, string $class): void
{
    $code = (string) file_get_contents($file);
    $import = "use {$class};";
    if (str_contains($code, "\n{$import}\n") || namespaceOfCode($code) === namespaceOf($class)) {
        return;
    }
    $code = 1 === preg_match('/^use /m', $code)
        ? (string) preg_replace_callback('/^use /m', static fn (): string => "{$import}\nuse ", $code, 1)
        : (string) preg_replace_callback(
            '/^(namespace [^;]+;\n\n)/m',
            static fn (array $match): string => $match[1] . "{$import}\n\n",
            $code,
            1,
        );
    file_put_contents($file, $code);
}

function removeImport(string $file, string $class): void
{
    $code = (string) file_get_contents($file);
    $trimmed = str_replace("use {$class};\n", '', $code);
    if ($trimmed === $code) {
        return;
    }
    $trimmed = (string) preg_replace_callback(
        '/^(namespace [^;]+;\n)\n\n+/m',
        static fn (array $match): string => $match[1] . "\n",
        $trimmed,
    );
    file_put_contents($file, $trimmed);
}
```

`var/refactor-1182/move-class.php`:
```php
<?php

declare(strict_types=1);

require __DIR__ . '/imports.php';

[, $oldClass, $newClass] = $argv;

$oldPath = 'src/' . relativePathOf($oldClass) . '.php';
$newPath = 'src/' . relativePathOf($newClass) . '.php';
moveFile($oldPath, $newPath, namespaceOf($oldClass), namespaceOf($newClass));

$oldTest = 'tests/' . relativePathOf($oldClass) . 'Test.php';
if (is_file($oldTest)) {
    $newTest = 'tests/' . relativePathOf($newClass) . 'Test.php';
    moveFile($oldTest, $newTest, testNamespaceOf($oldClass), testNamespaceOf($newClass));
}

// Every spelling of the old name, in code, strings, docblocks and config, becomes the new one.
foreach (trackedFiles() as $file) {
    rewriteClassName($file, $oldClass, $newClass);
}

// A neighbour in the new namespace needs no import.
foreach (phpFilesIn(dirname($newPath)) as $file) {
    removeImport($file, $newClass);
}

// A neighbour in the old namespace used the bare name and now needs one.
foreach (phpFilesIn(dirname($oldPath)) as $file) {
    if (usesBareName($file, shortNameOf($oldClass))) {
        addImport($file, $newClass);
    }
}

echo "Moved {$oldClass} to {$newClass}\n";
```

`var/refactor-1182/retarget-recommendation-defaults.php`:
```php
<?php

declare(strict_types=1);

require __DIR__ . '/imports.php';

$files = array_values(array_filter(explode("\n", (string) shell_exec(
    "git grep -l 'EffectiveRecommendationSettings::DEFAULT_' -- src tests",
))));

foreach ($files as $file) {
    $code = (string) file_get_contents($file);
    file_put_contents(
        $file,
        str_replace('EffectiveRecommendationSettings::DEFAULT_', 'RecommendationSettings::DEFAULT_', $code),
    );
    addImport($file, 'App\\Entity\\RecommendationSettings');
    if (!usesBareName($file, 'EffectiveRecommendationSettings')) {
        removeImport($file, 'App\\Service\\Recommendation\\EffectiveRecommendationSettings');
    }
    echo "Retargeted {$file}\n";
}
```

- [ ] **Step 3: Move the enums**

Enums first: `RecommendationSettingsValues` names `RecommendationBatchSize` bare, so the batch size must have its `App\Enum` import in place before the values class leaves `Service/Recommendation`.
```bash
php var/refactor-1182/move-class.php 'App\Service\Recommendation\RecommendationBatchSize' 'App\Enum\RecommendationBatchSize'
php var/refactor-1182/move-class.php 'App\Service\Mail\Digest\DigestCadence' 'App\Enum\DigestCadence'
php var/refactor-1182/move-class.php 'App\Service\Mail\Digest\DigestFormat' 'App\Enum\DigestFormat'
php var/refactor-1182/move-class.php 'App\Service\Reader\MagazineStyle' 'App\Enum\MagazineStyle'
php var/refactor-1182/move-class.php 'App\Entity\MailKind' 'App\Enum\MailKind'
php var/refactor-1182/move-class.php 'App\Enum\ScrapeFallback' 'App\Service\Discovery\ScrapeFallback'
php var/refactor-1182/move-class.php 'App\Enum\SocksReplyCode' 'App\Service\Fetch\SocksReplyCode'
```
Expected: seven `Moved …` lines, no `Failed:`.

- [ ] **Step 4: Move the values**

```bash
php var/refactor-1182/move-class.php 'App\Service\Crypto\SealedSecret' 'App\Entity\SealedSecret'
php var/refactor-1182/move-class.php 'App\Service\Discussion\Discussion' 'App\Entity\Discussion'
php var/refactor-1182/move-class.php 'App\Service\Proxy\ProxyConnection' 'App\Entity\ProxyConnection'
php var/refactor-1182/move-class.php 'App\Service\Mail\Settings\MailConnection' 'App\Entity\MailConnection'
php var/refactor-1182/move-class.php 'App\Service\Grafana\GrafanaConnection' 'App\Entity\GrafanaConnection'
php var/refactor-1182/move-class.php 'App\Service\Settings\InstanceSettingsUpdate' 'App\Entity\InstanceSettingsUpdate'
php var/refactor-1182/move-class.php 'App\Service\Recommendation\RecommendationSettingsValues' 'App\Entity\RecommendationSettingsValues'
php var/refactor-1182/move-class.php 'App\Service\Search\WordBoundaries' 'App\Doctrine\WordBoundaries'
```
Expected: eight `Moved …` lines, no `Failed:`.

- [ ] **Step 4b: Bring the moved files' comments to the bar (D4)**

Write each moved file in full as below, over what the script left. Only the namespace line, imports and comments differ from `a124ad8a`; every code line is the same. What went: docblocks that restate the class name, issue history (`#624 follow-up`, "callers that predate #541"), and reasoning the code already shows. What stayed, in at most three lines: the invariants a future edit would otherwise break (base64 for both databases, what the default port is for, the env-fallback empty host, "null means use the default", the SQL/PHP normalisation symmetry, what a SOCKS reply code can mean). `DigestCadence`, `DigestFormat`, `GrafanaConnection` and `Discussion` had no comment; they are shown so every moved file is on the page.

`src/Enum/RecommendationBatchSize.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

/** How large each recommendation batch is packed, as a scale over the connection's automatic ceiling (#935). */
enum RecommendationBatchSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

    public function batchItemCap(int $automaticCeiling): int
    {
        $percentOfCeiling = match ($this) {
            self::Small => 50,
            self::Medium => 100,
            self::Large => 200,
        };

        return max(1, intdiv($automaticCeiling * $percentOfCeiling, 100));
    }
}
```

`src/Enum/DigestCadence.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum DigestCadence: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
```

`src/Enum/DigestFormat.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum DigestFormat: string
{
    case Html = 'html';
    case Text = 'text';
}
```

`src/Enum/MagazineStyle.php` (its docblock restated the name):
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum MagazineStyle: string
{
    case Boxed = 'boxed';
    case Airy = 'airy';
}
```

`src/Enum/MailKind.php` (its docblock restated the name):
```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum MailKind: string
{
    case Digest = 'digest';
    case Account = 'account';
    case Test = 'test';
}
```

`src/Service/Discovery/ScrapeFallback.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Discovery;

/** Whether discovery may offer a plain HTML page as a scraped source. */
enum ScrapeFallback
{
    case Enabled;
    case Disabled;
}
```

`src/Service/Fetch/SocksReplyCode.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/** The REP byte of a SOCKS5 CONNECT reply (RFC 1928 §6). curl surfaces only the number; this knows its meaning. */
enum SocksReplyCode: int
{
    case GeneralFailure = 1;
    case NotAllowedByRuleset = 2;
    case NetworkUnreachable = 3;
    case HostUnreachable = 4;
    case ConnectionRefused = 5;
    case TtlExpired = 6;
    case CommandNotSupported = 7;
    case AddressTypeNotSupported = 8;

    public function meaning(): string
    {
        return match ($this) {
            self::GeneralFailure => 'the proxy reported a general failure',
            self::NotAllowedByRuleset => 'the proxy refused the request by its own rules — usually a rejected '
                . 'username and password, or a destination port it does not allow',
            self::NetworkUnreachable => 'the proxy could not reach the destination network',
            self::HostUnreachable => 'the proxy could not reach the destination host',
            self::ConnectionRefused => 'the destination refused the connection',
            self::TtlExpired => 'the connection expired on the way to the destination',
            self::CommandNotSupported => 'the proxy does not support the CONNECT command',
            self::AddressTypeNotSupported => 'the proxy does not support the address type it was given',
        };
    }

    /** A proxy cannot say "I do not do DNS": it reports the name as unreachable or rejects the address type. */
    public function canMeanTheProxyDoesNotResolveNames(): bool
    {
        return self::HostUnreachable === $this || self::AddressTypeNotSupported === $this;
    }
}
```

`src/Entity/SealedSecret.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** A secret at rest. The three byte strings are base64, so one column type serves MySQL and SQLite. */
final readonly class SealedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $nonce,
        public string $salt,
        public int $version,
    ) {
    }
}
```

`src/Entity/Discussion.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentsLoad;

final readonly class Discussion
{
    private function __construct(
        public ?string $url,
        public ?string $commentsFeedUrl,
        public ?CommentsLoad $commentsLoad,
        public bool $bodyIsOpeningPost = false,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public static function withCommentsFeed(?string $pageUrl, string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $load);
    }

    public static function of(?string $pageUrl, ?string $commentsFeedUrl, CommentsLoad $load): self
    {
        return new self($pageUrl, $commentsFeedUrl, $commentsFeedUrl === null ? null : $load);
    }

    public function withOpeningPostBody(): self
    {
        return new self($this->url, $this->commentsFeedUrl, $this->commentsLoad, true);
    }
}
```

`src/Entity/ProxyConnection.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProxyType;

/** The non-secret proxy fields. The sealed password travels separately, because an update may leave it out. */
final readonly class ProxyConnection
{
    /** The SOCKS5 port; a fresh row and the "not configured yet" payload both start from it. */
    public const int DEFAULT_PORT = 1080;

    public function __construct(
        public bool $enabled,
        public bool $directFallback,
        public ProxyType $type,
        public string $host,
        public int $port,
        public ?string $username,
        public bool $remoteDns = false,
    ) {
    }
}
```

`src/Entity/MailConnection.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailEncryption;

/**
 * The non-secret mail fields. The sealed password travels separately, because an update may leave it out. The env
 * fallback uses the same shape, with host '' when its DSN is not SMTP (sendmail, null).
 */
final readonly class MailConnection
{
    /** SMTP submission with STARTTLS: a fresh row's port, and the env fallback's when its DSN names none. */
    public const int DEFAULT_PORT = 587;

    public function __construct(
        public bool $enabled,
        public string $host,
        public int $port,
        public ?string $username,
        public MailEncryption $encryption,
        public string $fromAddress,
        public string $fromName,
        public bool $useProxy = false,
    ) {
    }
}
```

`src/Entity/GrafanaConnection.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

final readonly class GrafanaConnection
{
    public function __construct(
        public ?string $lokiPushUrl,
        public ?string $lokiUsername,
        public ?string $grafanaUrl,
        public ?string $pyroscopePushUrl,
        public bool $profilingEnabled,
    ) {
    }
}
```

`src/Entity/InstanceSettingsUpdate.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** The whole instance-setting row, written at once. */
final readonly class InstanceSettingsUpdate
{
    public function __construct(
        public bool $requireEmailConfirmation,
        public bool $requireApproval,
        public ?string $publicBaseUrl,
        public ?string $passkeyRpId,
        public ?string $passkeyRpName,
        // Matches InstanceSetting's column default: a test that needs working passkeys must pass true.
        public bool $passkeySignInEnabled = false,
    ) {
    }
}
```

`src/Entity/RecommendationSettingsValues.php` (the PHPMD suppression stays: 13 fields exceed the parameter-list threshold, and this is a data carrier that mirrors the row):
```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecommendationBatchSize;

/**
 * The stored recommendation settings row: every field is an override, so null (or no row) means "use the default".
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") a data carrier that mirrors the row 1:1
 */
final readonly class RecommendationSettingsValues
{
    public function __construct(
        public ?string $guidancePrompt,
        public int $favoritesCap,
        public int $keptCap,
        public int $viewedCap,
        public int $candidatePoolSize,
        public int $lookbackDays,
        public int $picksLimit,
        public ?int $contextWindow,
        public RecommendationBatchSize $batchSize,
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
        /** Written only by RecommendationSettingsWriter::storeProfile() (#493); read-only everywhere else. */
        public ?string $profileText = null,
        public bool $showReasons = false,
    ) {
    }
}
```

`src/Doctrine/WordBoundaries.php`:
```php
<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * The punctuation a whole-word match treats as a word boundary. The search term (here) and the haystack
 * (NormalizeWordBoundariesFunction's REPLACE chain) must normalize identically, or "E-Mail" stops matching itself.
 */
final readonly class WordBoundaries
{
    /** @var list<string> */
    public const array CHARACTERS = [
        '.', ',', ';', ':', '!', '?',
        '(', ')', '[', ']', '{', '}',
        '"', "'", '„', '“', '”', '‚', '‘', '’', '«', '»',
        '-', '–', '—',
        '/',
    ];

    /** One space per boundary character and no collapsing of runs, exactly as the SQL side does. */
    public static function normalize(string $value): string
    {
        return str_replace(self::CHARACTERS, ' ', $value);
    }

    public static function areIn(string $term): bool
    {
        return self::normalize($term) !== $term;
    }
}
```

`tests/Enum/RecommendationBatchSizeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\RecommendationBatchSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationBatchSizeTest extends TestCase
{
    public function testMediumReproducesTheAutomaticCeilingUnchanged(): void
    {
        self::assertSame(100, RecommendationBatchSize::Medium->batchItemCap(100));
        self::assertSame(45, RecommendationBatchSize::Medium->batchItemCap(45));
    }

    #[DataProvider('capCases')]
    public function testSizeScalesTheAutomaticCeiling(
        RecommendationBatchSize $size,
        int $automaticCeiling,
        int $expectedCap,
    ): void {
        self::assertSame($expectedCap, $size->batchItemCap($automaticCeiling));
    }

    /**
     * @return iterable<string, array{RecommendationBatchSize, int, int}>
     */
    public static function capCases(): iterable
    {
        yield 'small halves' => [RecommendationBatchSize::Small, 100, 50];
        yield 'large doubles' => [RecommendationBatchSize::Large, 100, 200];
        yield 'small of an odd ceiling floors' => [RecommendationBatchSize::Small, 45, 22];
        yield 'large of an odd ceiling' => [RecommendationBatchSize::Large, 45, 90];
    }

    public function testCapNeverFallsBelowOne(): void
    {
        self::assertSame(1, RecommendationBatchSize::Small->batchItemCap(1));
    }
}
```

`tests/Entity/DiscussionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Discussion;
use App\Enum\CommentsLoad;
use PHPUnit\Framework\TestCase;

final class DiscussionTest extends TestCase
{
    public function testNoneCarriesNothing(): void
    {
        $discussion = Discussion::none();

        self::assertNull($discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertNull($discussion->commentsLoad);
    }

    public function testAPageWithoutACommentsFeedDropsTheLoadMode(): void
    {
        $discussion = Discussion::of('https://news.example/item?id=1', null, CommentsLoad::Auto);

        self::assertSame('https://news.example/item?id=1', $discussion->url);
        self::assertNull($discussion->commentsFeedUrl);
        self::assertNull($discussion->commentsLoad);
    }

    public function testNothingAtAllIsNone(): void
    {
        self::assertEquals(Discussion::none(), Discussion::of(null, null, CommentsLoad::Manual));
    }

    public function testACommentsFeedKeepsItsLoadMode(): void
    {
        $discussion = Discussion::of(
            'https://blog.example/post/',
            'https://blog.example/post/feed/',
            CommentsLoad::Auto,
        );

        self::assertSame('https://blog.example/post/', $discussion->url);
        self::assertSame('https://blog.example/post/feed/', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $discussion->commentsLoad);
    }

    public function testCommentsFeedCarriesItsLoadMode(): void
    {
        $discussion = Discussion::withCommentsFeed(null, 'https://blog.example/post/feed/', CommentsLoad::Manual);

        self::assertNull($discussion->url);
        self::assertSame('https://blog.example/post/feed/', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Manual, $discussion->commentsLoad);
    }

    public function testNoFactoryMarksTheBodyAsTheOpeningPost(): void
    {
        self::assertFalse(Discussion::none()->bodyIsOpeningPost);
        self::assertFalse(Discussion::of('https://t.example/1', null, CommentsLoad::Auto)->bodyIsOpeningPost);
        self::assertFalse(
            Discussion::withCommentsFeed(null, 'https://t.example/1.rss', CommentsLoad::Auto)->bodyIsOpeningPost,
        );
    }

    public function testWithOpeningPostBodyMarksTheBodyAndKeepsTheThread(): void
    {
        $discussion = Discussion::withCommentsFeed(
            'https://t.example/1',
            'https://t.example/1/.rss',
            CommentsLoad::Auto,
        )->withOpeningPostBody();

        self::assertTrue($discussion->bodyIsOpeningPost);
        self::assertSame('https://t.example/1', $discussion->url);
        self::assertSame('https://t.example/1/.rss', $discussion->commentsFeedUrl);
        self::assertSame(CommentsLoad::Auto, $discussion->commentsLoad);
    }
}
```

`tests/Entity/InstanceSettingsUpdateTest.php` (the eight-line history docblock goes; the one reason the test exists stays):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\InstanceSettingsUpdate;
use PHPUnit\Framework\TestCase;

/** No other test asserts the constructor default, so this one pins it. */
final class InstanceSettingsUpdateTest extends TestCase
{
    public function testPasskeySignInEnabledDefaultsToFalse(): void
    {
        $update = new InstanceSettingsUpdate(
            requireEmailConfirmation: true,
            requireApproval: true,
            publicBaseUrl: null,
            passkeyRpId: null,
            passkeyRpName: null,
        );

        self::assertFalse($update->passkeySignInEnabled);
    }
}
```

`tests/Doctrine/WordBoundariesTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\WordBoundaries;
use PHPUnit\Framework\TestCase;

final class WordBoundariesTest extends TestCase
{
    public function testReplacesAHyphenWithASpace(): void
    {
        self::assertSame('E Mail', WordBoundaries::normalize('E-Mail'));
    }

    public function testLeavesATermWithoutPunctuationAlone(): void
    {
        self::assertSame('punk', WordBoundaries::normalize('punk'));
    }

    /** The SQL side cannot collapse runs, so this side must not either, or "E--Mail" stops matching itself. */
    public function testDoesNotCollapseTheRunsItCreates(): void
    {
        self::assertSame('E  Mail', WordBoundaries::normalize('E--Mail'));
    }

    public function testLeavesTheLikeWildcardsAlone(): void
    {
        // '%' and '_' are LikePattern's business, not a word boundary.
        self::assertSame('100%', WordBoundaries::normalize('100%'));
        self::assertSame('snake_case', WordBoundaries::normalize('snake_case'));
    }

    public function testTreatsTheEscapeCharacterAsABoundary(): void
    {
        // '!' is both LikePattern's escape character and sentence punctuation.
        // Normalizing first is what stops it ever reaching escape().
        self::assertSame('wow ', WordBoundaries::normalize('wow!'));
    }

    public function testReportsWhetherATermCarriesAnyBoundaryCharacter(): void
    {
        self::assertTrue(WordBoundaries::areIn('E-Mail'));
        self::assertTrue(WordBoundaries::areIn('TCP/IP'));
        self::assertFalse(WordBoundaries::areIn('punk'));
        self::assertFalse(WordBoundaries::areIn('100%'));
    }
}
```

Check that no code line changed (a `git diff -M30% --diff-filter=R` would silently skip a file whose comment trim pushes it below 30% similarity, so compare the stripped files directly):
```bash
strip() { grep -vE '^[[:space:]]*(\*|/\*\*|//|namespace |use App\\|$)'; }
for pair in \
  src/Service/Recommendation/RecommendationBatchSize.php=src/Enum/RecommendationBatchSize.php \
  src/Service/Mail/Digest/DigestCadence.php=src/Enum/DigestCadence.php \
  src/Service/Mail/Digest/DigestFormat.php=src/Enum/DigestFormat.php \
  src/Service/Reader/MagazineStyle.php=src/Enum/MagazineStyle.php \
  src/Entity/MailKind.php=src/Enum/MailKind.php \
  src/Enum/ScrapeFallback.php=src/Service/Discovery/ScrapeFallback.php \
  src/Enum/SocksReplyCode.php=src/Service/Fetch/SocksReplyCode.php \
  src/Service/Crypto/SealedSecret.php=src/Entity/SealedSecret.php \
  src/Service/Discussion/Discussion.php=src/Entity/Discussion.php \
  src/Service/Proxy/ProxyConnection.php=src/Entity/ProxyConnection.php \
  src/Service/Mail/Settings/MailConnection.php=src/Entity/MailConnection.php \
  src/Service/Grafana/GrafanaConnection.php=src/Entity/GrafanaConnection.php \
  src/Service/Settings/InstanceSettingsUpdate.php=src/Entity/InstanceSettingsUpdate.php \
  src/Service/Recommendation/RecommendationSettingsValues.php=src/Entity/RecommendationSettingsValues.php \
  src/Service/Search/WordBoundaries.php=src/Doctrine/WordBoundaries.php \
  tests/Service/Recommendation/RecommendationBatchSizeTest.php=tests/Enum/RecommendationBatchSizeTest.php \
  tests/Service/Discussion/DiscussionTest.php=tests/Entity/DiscussionTest.php \
  tests/Service/Settings/InstanceSettingsUpdateTest.php=tests/Entity/InstanceSettingsUpdateTest.php \
  tests/Service/Search/WordBoundariesTest.php=tests/Doctrine/WordBoundariesTest.php; do
  diff <(git show "origin/develop:backend/${pair%%=*}" | strip) <(strip < "${pair##*=}") || echo "CODE CHANGED: ${pair##*=}"
done
```
Expected: nothing (only namespace, import, comment and blank lines differ).

- [ ] **Step 5: The recommendation defaults move onto the row**

`src/Entity/RecommendationSettings.php`:
- Delete `use App\Service\Recommendation\EffectiveRecommendationSettings;`.
- In the class docblock:
```diff
- * No row = all defaults (see EffectiveRecommendationSettings); the row exists
+ * No row = all defaults (the DEFAULT_ constants below); the row exists
```
- Directly after `class RecommendationSettings` and its `{`, insert:
```php
    public const int DEFAULT_FAVORITES_CAP = 40;
    public const int DEFAULT_KEPT_CAP = 40;
    public const int DEFAULT_VIEWED_CAP = 80;
    public const int DEFAULT_CANDIDATE_POOL_SIZE = 500;
    public const int DEFAULT_LOOKBACK_DAYS = 2;
    public const int DEFAULT_PICKS_LIMIT = 50;

```
- Then: `perl -pi -e 's/EffectiveRecommendationSettings::DEFAULT_/self::DEFAULT_/g' src/Entity/RecommendationSettings.php`

`src/Service/Recommendation/EffectiveRecommendationSettings.php`: delete the six lines `    public const int DEFAULT_FAVORITES_CAP = 40;` … `    public const int DEFAULT_PICKS_LIMIT = 50;`; keep `FALLBACK_CONTEXT_WINDOW`.

Retarget every other reader:
```bash
php var/refactor-1182/retarget-recommendation-defaults.php
git grep -n 'EffectiveRecommendationSettings::DEFAULT_' -- src tests
```
Expected: `Retargeted …` for `src/Http/RecommendationSettingsJson.php`, `src/Service/Recommendation/RecommendationSettingsResolver.php` and the fourteen test files (`tests/Command/RecommendationDrainCommandTest.php`, `tests/Http/RecommendationSettingsJsonTest.php`, `tests/Service/Recommendation/{DueRecommendationRunFinder,ForYouSweep,RecommendationHistoryLoader,RecommendationPipeline,RecommendationPromptBuilder,RecommendationRunAdvancer,RecommendationSettingsResolver,RecommendationSettingsRoundTrip,RecommendationSettingsWriter}Test.php`, `tests/Service/Worker/{AdvanceRecommendationRuns,StartDueRecommendationRuns}HandlerTest.php`, `tests/Support/RecommendationRunFixtures.php`); the grep prints nothing. The column defaults in the mapping are the same numbers, so the schema does not change.

- [ ] **Step 6: Verify the moves**

Run:
```bash
git grep -nE '^use App\\Service\\' -- src/Entity src/Enum src/Doctrine
git grep -nE 'App\\Service\\(Crypto\\SealedSecret|Discussion\\Discussion|Proxy\\ProxyConnection|Mail\\Settings\\MailConnection|Grafana\\GrafanaConnection|Settings\\InstanceSettingsUpdate|Recommendation\\RecommendationSettingsValues|Recommendation\\RecommendationBatchSize|Mail\\Digest\\DigestCadence|Mail\\Digest\\DigestFormat|Reader\\MagazineStyle|Search\\WordBoundaries)([^A-Za-z]|$)' -- src tests config ':(exclude)tests/PhpStan'
git grep -nE 'App\\Entity\\MailKind|App\\Enum\\(ScrapeFallback|SocksReplyCode)([^A-Za-z]|$)' -- src tests config ':(exclude)tests/PhpStan'
git diff -M30% --stat origin/develop | grep '=>'
composer cs
bin/console cache:clear && composer stan
bin/console lint:container
bin/console doctrine:schema:validate --skip-sync
```
Expected:
- The three greps print nothing. `PersistenceKnowsNoServiceRuleTest` and its fixture (B8) keep `App\Service\Crypto\SealedSecret` and `App\Service\Search\WordBoundaries`: they are what the rule must reject, so the script leaves `tests/PhpStan` alone.
- The diff stat shows the moved classes and tests as renames (`=>`) where the comment trim left them above 30% similarity; one that dropped below shows as a delete plus an add. Either is fine: Step 4b's comparison loop is the proof that no code line changed.
- `composer cs` is clean. If it reports a missing blank line after an inserted import or two blank lines where an import block was emptied, run `composer cs:fix` and check the diff.
- `composer stan` is green with `PersistenceKnowsNoServiceRule` registered.
- The container lints; the mapping validates.

A `Class not found` from `composer stan` means a same-namespace neighbour used the moved class through a construct the tokenizer did not see as a bare name: add the import by hand and report the file.

- [ ] **Step 7: Record the decision**

`docs/architecture.md`, append at the end of the file (after §7's closing "Enforced by `QueriesLiveInRepositoriesRule`…" paragraph):
```markdown

## 8. Where shared values live

A value or enum that more than one layer uses lives at the lowest layer that uses it, so an entity, an enum or an ORM
extension never imports a service. Decided in #1182.

- **`App\Entity`** holds what an entity stores, embeds or returns, next to the embeddables: `SealedSecret`,
  `Discussion`, the proxy, mail and Grafana connection values, `InstanceSettingsUpdate`, `RecommendationSettingsValues`,
  and the recommendation defaults as constants on `RecommendationSettings`.
- **`App\Enum`** holds the enums an entity or a repository uses, or several `Service/*` modules share (`FeedStatus`,
  `ListOrder`, `DigestCadence`, `MagazineStyle`, `MailKind`, `RecommendationBatchSize`…). It does not fold into
  `App\Entity`: several of its enums never touch an entity. An enum whose lowest user is one `Service/*` module stays
  in that module, even when a controller, DTO, mapper or command reads it too (`ScrapeFallback`, `SocksReplyCode`,
  `CatalogImportMode`, `CommentsStatus`).
- **`App\Doctrine`** holds what the ORM extensions share with the query and search code: `WordBoundaries`.
- **`App\Dto`** is HTTP input. A controller turns a request DTO into a service value (`$request->toUpdate()`,
  `->toChange()`) or passes a plain field; no domain class imports `App\Dto`.

Enforced by `PersistenceKnowsNoServiceRule` (no `App\Service` in `App\Entity`, `App\Enum` or `App\Doctrine`) and
`DomainKnowsNoHttpRule` (no `App\Http` or `App\Dto` in domain code), both in `backend/tests/PhpStan/` and run by
`composer stan`.
```

`CLAUDE.md`:
- In the Layout table, replace the row
```
| `backend/src/Entity`, `Repository`, `Doctrine` | Persistence |
```
with
```
| `backend/src/Entity`, `Enum`, `Repository`, `Doctrine` | Persistence; `Entity` and `Enum` also hold the values and enums that cross a layer ([docs/architecture.md](docs/architecture.md) §8) |
```
- Directly after the "Domain code knows no HTTP" bullet (as B6 left it), insert:
```
- **Shared values have one home.** A value or enum that crosses a layer lives at the
  lowest layer that uses it: `App\Entity` for what an entity stores, embeds or returns,
  `App\Enum` for enums an entity or a repository uses or several modules share,
  `App\Doctrine` for what the ORM extensions share. Anything else, module-private enums
  included, stays in its `Service/*` module ([docs/architecture.md](docs/architecture.md) §8,
  `PersistenceKnowsNoServiceRule`).
```

- [ ] **Step 8: Run the whole suite**

Run: `php bin/phpunit`
Expected: PASS. The moves touch every layer, so the task's own tests are the whole suite.

- [ ] **Step 9: Deletion check**

Add `use App\Service\Search\SearchTerms;` to the imports of `src/Entity/Feed.php` and run `composer stan`. Expected: one `simpleFeedReader.persistenceKnowsNoService` error on that line. Remove the line by hand.

- [ ] **Step 10: Gates and commit**

Run `composer check && composer md`, then the PhpStorm lint on `git diff --name-only HEAD -- '*.php'`. An import the scripts placed out of alphabetical order is not a WARNING; an unused or same-namespace import is: fix it by hand.
```bash
git add -A src tests config phpstan.dist.neon ../docs/architecture.md ../CLAUDE.md
git status --short var
git commit -m "refactor(#1182): shared values live in App\\Entity, shared enums in App\\Enum, WordBoundaries in App\\Doctrine"
```
Expected: `git status --short var` prints nothing (the scripts stay out of the commit).

---

### Finishing PR B

1. **Branch-wide gates**, all green:
   - `php bin/phpunit`
   - `docker compose exec php bin/console cache:clear && docker compose exec php composer test` (classes moved: the dev container must not serve a stale compiled container; check it runs this branch's code first)
   - `composer check`
   - `composer md`
   - `composer infection:diff`
   - PhpStorm `lint_files` on `git diff --name-only origin/develop -- '*.php'`. ERROR and WARNING block.
   - The migration leg is unaffected (no migration, no mapping change); `bin/console doctrine:schema:validate --skip-sync` stays green.
2. **Scan the dev log**: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'` shows no new deprecation or error.
3. **/simplify** on the branch diff. Apply what it finds that keeps this plan's constraints (no wire change, D1–D8); re-run the gates it affects.
4. **SDD final review.** Dispatch a fresh reviewer subagent with the diff and these attack points, in this order:
   1. **Wire drift.** For each mapper, compare its keys, their order and their values with the deleted `toArray()` (`git show origin/develop:backend/src/Service/…`): `MailTestResultJson`, `ProxyTestResultJson`, `AltchaChallengeJson` (`maxnumber` stays lowercase), `RefreshReportJson` (`total` included, unlike `RefreshJson`), `ForYouSweepReportJson`, `MaintenanceTickJson` (the `skipped` key last and only on a skipped tick, the reason text byte-identical), `RefreshJson`'s `progress`, `RecommendationRunStatusJson` (the report keys before `elapsedSeconds`). `git diff origin/develop -- tests/Controller` shows only Task B9's `use` and class-name renames, in the eight files the Global Constraints name.
   2. **Log and store formats.** `toLogContext()` bodies equal the old `toArray()` bodies; no assertion in the three handler tests changed (`SendDueDigestsHandlerTest` only follows `DigestCadence`/`DigestFormat` to `App\Enum` in Task B9). The Grafana cache entry, the findings-file record and the refresh-run record keep their keys, so data written before the deploy reads back.
   3. **The tick.** `MaintenanceTick::run()` still refreshes first, runs the four sweeps only after a refresh that did not abort, and ships the log spool last in both cases.
   4. **Moves.** Task B9 Step 4b's comment-stripped comparison loop prints nothing (no code line of a moved class or test differs; do not rely on `git diff -M30%`, which shows a heavily trimmed file as a delete plus an add); every remaining comment clears the bar; `RecommendationSettings`' column defaults are the same numbers; nothing serialises a moved class into a cache or a message.
   5. **Rules.** Each fixture expectation fails when its handling is removed (B6, B8, B9 reports); `DomainKnowsNoHttpRuleTest` kept its expectations through the `ClassNameReferences` extraction.
   6. **Docs.** `docs/architecture.md` §8 and the two CLAUDE.md bullets say what D1 decided, and nothing the code does not enforce.
   7. **`AutowireWrongClass`.** The PhpStorm lint is clean on the three outcome values.
   Fix every confirmed finding in its own commit (`refactor(#1182): …`) and re-run the gates.
5. **Push and open the PR** against `develop`:
```bash
git push -u origin refactor/1182-response-mappers-and-value-homes
gh pr create --base develop --title "refactor(#1182): response mappers, store serialisers and shared value homes (part B)" --body-file - <<'BODY'
Closes #1182

Part B of #1182: issue bullets 2 and 3. Part A (request DTOs to service values) merged earlier.

- Every wire shape a service built moves into a `src/Http/*Json` mapper: `MailTestResultJson`, `ProxyTestResultJson`, `AltchaChallengeJson`, `RefreshReportJson`, `ForYouSweepReportJson`, `MaintenanceTickJson`. `MaintenanceTickReport` is typed now. `RefreshJson` and `RecommendationRunStatusJson` build their own shapes.
- A serialiser that feeds a store is named after it: `toLogContext()` (worker log lines), `toCacheEntry()`/`fromCacheEntryOrNull()` (Grafana cache), `toFindingsFileRecord()`/`fromFindingsFileRecord()` (reader-audit file). `RefreshRunStore` builds its own record.
- `NoToArrayInServicesRule` keeps `toArray()` and `jsonSerialize()` out of `App\Service`.
- Shared values and enums live at the lowest layer that uses them (`docs/architecture.md` §8): values an entity stores, embeds or returns in `App\Entity`, shared enums in `App\Enum` (which stays), `WordBoundaries` in `App\Doctrine`. `PersistenceKnowsNoServiceRule` keeps entities, enums and the ORM extensions free of `App\Service`.
- The three bare `AutowireWrongClass` warnings are suppressed with their reason.

No wire change: every response, log line and stored record is byte-identical, and no controller test assertion changed (eight controller tests only follow a moved class's new name).
BODY
```
6. **Merge when green.** Start a Monitor on `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never `--auto`. If a check fails, fix it on the branch (first `composer show larspohlmann/phptramp` for a `tramp` failure) and restart the Monitor.
7. **Verify:** `gh pr view <PR> --json state,mergeCommit --jq '.state + " " + .mergeCommit.oid'` prints `MERGED …`, and `gh issue view 1182 --json state --jq .state` prints `CLOSED`. If it is still open, check the PR body kept `Closes #1182` and report; do not close the issue by hand.
8. **Report** to the planner: both merge SHAs, the deletion-check outputs, any OPEN QUESTION outcome, anything /simplify or the reviews changed, and one carry-forward for the FINAL STEP spec refresh: the design spec names classes this issue moved (`SealedSecret`, the connection values, the digest enums, `WordBoundaries`, the OAuth values).
