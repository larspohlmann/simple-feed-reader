# Fix Abbreviated and Misleading Names in the Backend (#1172) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1172 in six PRs. No variable, parameter or property in `backend/src` or `backend/tests` is a single letter or a truncated word, every property that holds a repository is named for what it holds, the two misleading method names say what the methods do, and a PHPStan rule keeps it so.
- **PR A** (A0–A3, `Refs #1172`): `$em` becomes `$entityManager` everywhere: 48 src files, 258 test files, the `em()` test helpers and `DbTestCase::$entityManager`; the 7 helpers whose class already inherits the same entity manager are deleted (D-1). `AbbreviatedNameRule` lands unregistered, as the survey tool every later PR runs.
- **PR B** (B0–B5, `Refs #1172`): the names outside `src/Service`: controllers, the `Http` mappers, the `Subscription` entity, `EntryScopePredicates`, the commands, the Doctrine listeners. `keepsHoldingTheLock()` becomes `refreshOrReacquireLock()`; `NormalizedLoginRateLimiter` mutates the request in a method named for it; `UnknownChallengeException` gets its own problem mapper.
- **PR C** (C0–C3, `Refs #1172`): the service modules A to M: caught exceptions, truncations, `SubscriptionTagReference`, and eight repository properties.
- **PR D** (D0–D6, `Refs #1172`): the service modules N to Z: caught exceptions and closures, `AbstractAtomParser` reads `namespaceUri()`, the change marker's names and its file (`change-marker.json`, backend and SPA), `extraAuthorizationParameters()`, `$flagCounts`, eight repository properties, and `RelyingPartyChangeGuard`.
- **PR E** (E0–E2, `Refs #1172`): the tests outside `tests/Service`.
- **PR F** (F0–F4, `Closes #1172`): the tests in `tests/Service`; `AbbreviatedNameRule` is registered; CLAUDE.md states the rules.

**Architecture:**
- **One mechanical tool, fed by reviewed rows.** Every rename that is a pure rename runs through `var/refactor-1172/rename-names.php` (Appendix S, uncommitted scratch). A row names one file, one old name, the new name and the number of hits at `0863e373`. The script renames `$old` (docblocks and interpolated strings too), `$this->old` and a method `old()`, and refuses before writing anything when a count differs or the new name already lives in a method that uses the old one. `--check` validates without writing, so every task starts with a dry check that doubles as the reconcile against whatever landed on develop after `0863e373`.
- **The rows are the plan.** Each task's rows block is the complete list of what that task renames, with its hit count. Names were chosen per file from the declaring line (the survey tables below); every row was run end to end, in task order, against a copy of `0863e373` (the reconcile, below).
- **The guard is the survey.** `AbbreviatedNameRule` (a `Rule<FileNode>` over every `Variable` and declared property) lands in A1 unregistered. Each PR runs it through `var/refactor-1172/names.neon` (Appendix N) over its scope: the count before is the positive control, and the PR ends at zero. PR F registers it in `phpstan.dist.neon`.
- **Behaviour changes are few and named:** the marker file's name (D3), the problem mapper that answers an unknown passkey challenge (B5, same response), and `NormalizedLoginRateLimiter`'s method shape (B4, same behaviour). Everything else is a rename; the test suites are the proof.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPStan 2 (`Rule<FileNode>`, `RuleTestCase`), nikic/php-parser 5 (`NodeFinder`, `PropertyItem`), PHPUnit 12, PHP_CodeSniffer PSR-12 (120 columns), PHPMD codesize, phptramp, Infection; Angular 20 and Jest for the one SPA constant (D3).

**Spec:**
- GitHub issue #1172 (`gh issue view 1172`; no comments at `0863e373`).
- The planner's carry-forward ledger, `#1172` lines: `Settings\RelyingPartyChange` is a service named like a `…Change` model; properties still named "repository"; `UnknownChallengeException` mapped in `PasskeyRegistrationProblems`; the `RecommendationRunRateLimitedException` docblock (left to #1171); `new EntrySanitizer(new TrailingBlankRemover())` at 8 test sites (note only).
- CLAUDE.md, "PHP code style — Clean Code is mandatory" ("Names reveal intent"); `docs/architecture.md` §10.
- The #1169 plan (`docs/superpowers/plans/2026-09-29-1169-final-readonly-injection.md`) for format and process; #1169 closed at `0863e373`.

Written at `021f9ec1` (develop after #1169 PR D); reconciled at `0863e373` (origin/develop after #1169 closed, with its PRs E–H). Every count, path, anchor and line number below is at `0863e373` unless it says otherwise.

## Survey and scope at `0863e373`

The issue's counts are from the 2026-09-25 audit. "Occurrences" counts `$name` tokens (declarations and uses); "sites" counts the distinct (line, name) pairs `AbbreviatedNameRule` reports.

| Issue item | The issue said | At `0863e373` | Task |
|---|---|---|---|
| `$em` beside `$entityManager` | ~27 classes | src: 48 files (48 promoted parameters, 113 `$this->em`); `$entityManager` already names it in 38 src files. tests: 258 files (839 `$em`, 2359 `$this->em` reads, 14 `em()` helpers with 286 calls, `DbTestCase::$em`) | A2, A3 |
| `$data`, `TagesschauCarouselRecognizer` | 7× | 7 (lines 39–54) | D4 |
| `$data`, `EntryMedium:25`, `EntryAttachment:24` | 14× together | 0: both already read `$stored` | — |
| `$data`, `Security/AccountStatusException` | listed | 2 + 1 docblock, in `__unserialize()` | kept (D-13): Symfony's `AuthenticationException::__unserialize(array $data)` names it; the rule skips it |
| `$temp`, `ContentChangeMarker:65-74` | 6× | 6 (lines 65–74) | D3 |
| `$token` holds the JSON payload (`:63`) | 1 | 2 (parameter, use) | D3 |
| The marker file is `counts.json` but holds `lastUpdated` | 1 | backend 1 (+3 in its test), SPA 1 (+1 in its spec) | D3 (D-5) |
| `$sub` (`SubscriptionController:133,166`, `TagController:153`) | 3 | `SubscriptionController` 6 (lines 89–111), `TagController` 0; also `SubscriptionJson` 10 and `OpmlExporter` 11; tests 152 in 12 files plus 18 compound names (`$strangerSub`, `$subA`, …) | B1, D4, E1, F1 |
| `$subscriptionRepo` | 1 | 1 promoted parameter, 4 uses | B1 |
| `$joinsBySubId` | 1 | 0: gone | — |
| `$prefs` (`SendDueDigests`) | 1 file | 12; tests 97 in 6 files, 4 compound names, a `prefs()` helper | C2, F1 |
| `$st` (`Subscription:152,191`) | 2 | 5 (lines 154–197); tests 3 | B1, E1 |
| `$a` for `EntryAliases` (`EntryScopePredicates`) | 21× | 21 | B1 |
| `$ns` threaded through `AbstractAtomParser` | every private method | 31, six private parameters; also `AtomDiscussion` 4, `ItemImageExtractor` 2 | D2 |
| `$m` (`FaviconResolver:163`) | 1 | 2 (lines 168–169); `$m` in src: 16 in 8 files | C2, B1, D1 |
| `$mine` (`ReaderAuditCommand`) | 1 file | 5; `AuditShardModel::pick()` 3 more | B1, D4 |
| `$flags` (`SubscriptionController::list`) | 1 | moved: `SubscriptionTalliesModel::$flags`, read 3× in `SubscriptionCountsJson`, 1× in a test | D4 |
| One-letter closure variables in controllers (`$s`, `$c`, `$t`, `$f`, `$e`, `$m`) | not counted | 2 closures left: `SavedSearchController` `$s` (3), `TagController` `$t` (2) | B1 |
| `RecommendationDrainCommand::keepsHoldingTheLock` refreshes or re-bids | 1 | private: 1 declaration, 1 call, 1 docblock mention; tests 0 | B3 |
| `NormalizedLoginRateLimiter::peek` mutates the request | 1 | `peek()` is Symfony's `PeekableRequestRateLimiterInterface` method (our callers: 0); the mutation is in the private `normalize()`, called 3× | B4 (D-7) |
| `$qb` stays | — | 169 in src; untouched | — |
| Ledger: `Settings\RelyingPartyChange` is a service named like a model | — | the class, 6 references in 4 other src files, 3 in its test | D6 |
| Ledger: properties still named "repository" | — | src: 17 properties in 17 files (13 `$repository`, 2 `$feedRepository`, 1 `$entryRepository`, 1 `$subscriptionRepo`; one is a GitHub slug); tests: 5 properties | B1, C3, D5, E2, F2 |
| Ledger: `UnknownChallengeException` mapped in `PasskeyRegistrationProblems` | — | 1 arm | B5 |
| Ledger: `RecommendationRunRateLimitedException` docblock omits `App\` | — | left to #1171 | — |
| Ledger: 8 test sites build `new EntrySanitizer(new TrailingBlankRemover())` | — | note only: no factory unless the constructor grows (D-20) | — |

Beyond the issue, the same smells (the sweep the issue asked for):

| Kind | At `0863e373` | Task |
|---|---|---|
| One-letter names, numbered or not (`$e` in a catch, `$a`/`$b` comparators, `$i` counters, `$c`/`$f`/`$l`/`$r`/`$s`/`$t` closure parameters, `$m` for preg matches, `$m`/`$u`/`$o` in `MockHttpClient` callbacks, `$s1`/`$t1`/`$g5` labels, `$_`) | src 306 tokens in 69 files (plus the kept `MarkSearchReadRequest::$q`); tests 821 tokens in 96 files | B1, B2, C1, D1, D4, E1, F1 |
| Truncations: `$repo`, `$doc`, `$dom`, `$dir`, `$params`, `$args`, `$ref`, `$svc`, `$subs`, `$fav(s)`, `$ec`, `$ts` | src 161 tokens in 29 files (with `$ns`, `$sub`, `$prefs`, `$data`, `$temp`); tests 521 tokens in 68 files. D-13 keeps 5 of the src tokens and 2 of the test tokens (vendor-named parameters) | C2, D4, E1, F1 |
| Compound truncations (`$subscriptionRepo`, `$strangerSub`, `$duePrefs`, `$readResp`, `$favIds`, `$tagPos`, `$jwtDir`, `$failingEm`, `$emStub`, …) | 35 names | A3, B1, E1, F1 |
| Abbreviated method names: `extraAuthorizationParams()`; test helpers `em()`, `repo()`, `repoWithWindow()`, `makeSub()`, `subsOf()`, `prefs()` | 2 + 37 declarations | A3, D4, E1, E2, F1, F2 |
| Abbreviated class names | 1: `Backup\Dto\SubscriptionTagRef` | C2 |

## Decisions

- **D-1 (`$em`):** `$entityManager` everywhere, the `DbTestCase` property and every test helper included (`em()` → `entityManager()`). PR A touches all 16 entity-manager helpers (14 `em()`, 2 `entityManager()`), so the DRY rule applies: **A3 deletes the 7 whose class already inherits the same entity manager.** `MeControllerTest` and `MeDigestControllerTest` (`ApiTestCase` subclasses) declare a private `entityManager()` with `ApiTestCase::em()`'s body; they use the inherited, renamed helper. `WarmCatalogFaviconsCommandTest`, `CatalogSubscriberTest`, `CatalogFaviconWarmerTest`, `CatalogImporterTest` and `BulkSubscriberTest` (`DbTestCase` subclasses) fetch the container's entity manager on every call, the instance `DbTestCase::setUp()` already stores after booting the kernel; none of the five reboots it (`WarmCatalogFaviconsCommandTest` reuses `self::$kernel`), so their 85 `$this->em()` calls read `$this->entityManager`. **The follow-up** is the remaining 8 private copies, renamed to `entityManager()`, whose classes share no base that holds one: `ReadingActivityControllerTest`, `ReorderTest`, `SubscriptionBulkTest`, `MoveFeedToTagTest`, `RecommendationRunHistoryControllerTest`, `OAuthFlowTest`, `RecommendationDebugLogControllerTest` (all `WebTestCase` directly) and `AssertionVerifierTest` (`KernelTestCase`).
- **D-2 (one letter):** no variable, parameter or property is a single letter, numbered or not: closures, comparators, caught exceptions and loop counters included. A caught exception is `$exception` (`$cause` where `FetchException::from()` walks the chain); comparator operands are `$left`/`$right`; an arrow-function parameter is named for the element (`fn (Tag $tag)`); a `for` counter is `$index` unless it counts a thing (`$attempt`, `$tick`, `$number`); preg matches are `$matches`; `MockHttpClient` callbacks take `$method`, `$url`, `$options`; fixture labels become ordinals or roles (`$firstEntry`, `$favoriteState`, `$parsedFeed`). **Exception:** `$x` and `$y` when they are coordinates (pixel coordinates in `GdImageResizerTest`, elliptic-curve points in `PasskeyFixtures`). Why: the issue flags one-letter closure variables, which are the smallest scope there is; a rule with a size exception has no line a reviewer can hold, while 79 of the 146 `for` loops in `src` and `tests` already name their counter.
- **D-3 (truncations and what stays):** the rule's list is `args arr attr attrs btn cfg cnt conf ctx cur curr dir doc dom ec el elem em fav favs idx len msg ns num obj params pos prefs prev ref repo req res resp st str sub subs svc ts val`, plus the three CLAUDE.md bans outright and `$temp` (`data info tmp temp`). **Stay:** `$qb` (the issue: Doctrine's idiom) and `$io` (Symfony's `SymfonyStyle` idiom); initialisms that are the domain word (`$url`, `$id`, `$ip`, `$dsn`, `$sql`, `$xml`, `$jwt`, `$ttl`, `$uri`, `$pem`, `$svg`, `$rss`, `$cid`, `$dns`, `$eta`, `$sku`); an HTML element or attribute name when the variable holds that element or attribute (`$img`, `$src`, `$div`, `$nav`, `$toc`, `$alt`, `$rel`, `$dek`, and `SlideImageResolver::imgSources()`); the `max`/`min` prefixes; the #1202 role word `Dto` (`$dto`, `toDto()`); `$mine`/`$theirs` ownership pairs in tests; `$projectDir` (Symfony's binding name); `$entriesPartStat` (`ZipArchive::statName()`'s term); `$passkeyRpId` (WebAuthn's `rpId`, also the wire field).
- **D-4 (the guard):** a PHPStan rule, not a PHPCS sniff. The project's custom guards are PHPStan rules with a `RuleTestCase`; a sniff would need a custom standard, and PHPStan's `FileNode` plus `NodeFinder` sees every parameter, promoted and declared property, closure, catch, foreach and top-level script variable in one pass. It checks whole names, not camelCase parts: a part list would flag Symfony's `subRequest` and WebAuthn's `rpId`, and the 35 compound names are renamed by the rows. One allow-list entry: `MarkSearchReadRequest::$q` (D-14). The allow-list is a constructor argument defaulting to the rule's constant, the `ThinControllerRule` pattern, so the rule's test passes its own and `[]`. The rule also skips the parameters D-13 keeps.
- **D-5 (`counts.json` → `change-marker.json`):** renamed, backend and SPA together. The file is the change marker (`ContentChangeMarker`, the SPA's `CHANGE_MARKER_PATH`) and holds `lastUpdated`, not counts. Transition: `public/state` is per release on Strato (not in the shared links of `activate-release.sh`), so every deploy already starts without a marker until the first import; a tab loaded before the deploy polls the old path, gets a 404, and falls back to fetching the counts every tick (the documented fallback, the behaviour before #720) until it reloads. In Docker the stale `public/state/counts.json` is left behind and harmless.
- **D-6 (`$token` → `$payload`):** the writer's parameter holds the JSON it writes, which `payload()` built. The SPA keeps calling the value it compares a token (`lastMarkerToken`): that is the reader's own term.
- **D-7 (`peek`):** `peek()` keeps its name: it implements Symfony's `PeekableRequestRateLimiterInterface`. What misleads is the private `normalize(Request): Request`, which mutates the request and returns it, so it reads as a pure function. It becomes `normalizeLastUsername(Request): void`, and each public method calls it on a line of its own before delegating.
- **D-8 (`keepsHoldingTheLock`):** `refreshOrReacquireLock()`: it names both calls it makes (`refresh()`, then `acquire()`); the call site `if (!$this->refreshOrReacquireLock($lock))` reads as "stop when neither worked".
- **D-9 (`RelyingPartyChange`):** `RelyingPartyChangeGuard`. It guards a relying-party change and invalidates the passkeys the change orphans; its exception is already `RelyingPartyChangeRequiresConfirmationException`, and `guardAndInvalidatePasskeysIfChanged()` keeps its name. The property becomes `$relyingPartyChangeGuard`, the test `RelyingPartyChangeGuardTest` with a `guard()` helper.
- **D-10 (repository properties):** a property or promoted parameter holding a repository or a consumer-owned repository interface is named for what it holds, as 165 of the 182 repository-typed properties in `src` and `tests` already are (`UserRepository $users`, `TagRepository $tags`): the entity plural (`$feeds`, `$entries`, `$passkeys`, `$pendingVerifications`), the entity's name where it is a settings row (`$aiProviderSettings`, `$mailServerSettings`, `$recommendationSettings`), and `$storedSettings` for the three settings services whose repository holds their one stored row (`GrafanaSettings`, `ProxySettings`, `InstanceSettings`, and the two readers beside them). `SubscriptionController` holds both a `SubscriptionService` (`$subscriptions`) and the repository (`$subscriptionRepo`): the service becomes `$subscriber` (it subscribes and unsubscribes), the repository `$subscriptions`. `GitHubLatestReleaseReader::$repository` holds a GitHub `owner/name` slug: it becomes `$gitHubRepository`, and the `services.yaml` comment that explained why the argument is not a global `bind` (because `$repository` is a name Doctrine-shaped services reuse) goes with the ambiguity.
- **D-11 (`UnknownChallengeException`):** a new `PasskeyChallengeProblems` mapper holds its one arm. The challenge is issued and redeemed by both ceremonies; the exception implements `PasskeySignInFailureExceptionInterface`, and only registration lets it reach a problem mapper (the authenticator wraps every sign-in failure in an `AuthenticationException`), so neither ceremony's mapper is its honest home. The response is unchanged (`ProblemContractTest` pins it).
- **D-12 (test locals holding a repository):** `$repo` becomes `$repository` (and `repo()` → `repository()`, `repoWithWindow()` → `repositoryWithWindow()`), matching the 55 test files whose container-fetch helpers already say `$repository`. A local that only narrows a container fetch for its `return` keeps `$repository`; D-10 governs properties, which a class reads throughout.
- **D-13 (vendor overrides, planner ruling):** a method that overrides or implements a method declared outside `App\` (vendor, Symfony, Doctrine, PHP) keeps the parent's parameter names: named arguments bind to them, and PhpStorm reports a parameter-name mismatch against the parent. `AbbreviatedNameRule` skips those parameters and their uses in that method (constructors excepted: a constructor is named by its own class). Kept at `0863e373`: `AccountStatusException::__unserialize(array $data)` (Symfony), `SqliteConnectionSetupDriver::connect(array $params)` and the test `QueryRecorderDriver::connect(array $params)` (Doctrine). The Doctrine listeners' `$args` are renamed: `postGenerateSchemaTable()` and `onFlush()` are attribute-registered methods that override nothing.
- **D-14 (`$q`):** `MarkSearchReadRequest::$q` keeps the key of the JSON body it maps (`{"q": …, "until": …}`), the same key as the search query string on every client. The rule allow-lists that one class and name; `#[SerializedName]` would add an indirection the project uses nowhere else.
- **D-15 (`SubscriptionTagRef`):** `SubscriptionTagReference`, the only abbreviated class name in `src` and `tests`; the backup format never names the class.
- **D-16 (`extraAuthorizationParams()`):** `extraAuthorizationParameters()`, the protected hook `AppleOAuthProvider` overrides.
- **D-17 (`$ns`):** `AbstractAtomParser`'s private methods drop the `string $ns` parameter and call `$this->namespaceUri()` (the issue's suggestion); the value is one constant string per dialect. The static helpers keep a parameter and name it `$atomNamespace`, as `FeedImageExtractor::fromAtomFeed()` already does.
- **D-18 (`$flags`):** `SubscriptionTalliesModel::$flagCounts`: it holds the number of favorite, kept and viewed entries.
- **D-19 (`$mine`):** `$shardEntries`, in `ReaderAuditCommand` and `AuditShardModel::pick()`: the entries this shard audits.
- **D-20 (`EntrySanitizer`):** no change. 8 test sites build `new EntrySanitizer(new TrailingBlankRemover())`; a factory would add a class to save nothing until the constructor grows.
- **D-21 (line length):** a rename that pushes a line past 120 columns is wrapped in the same task (Appendix W): 160 lines over the six PRs, listed per task.
- **D-22 (comments):** comments and docblocks follow the names (the script renames `$old` inside them); no comment is added.

## Reconciled at `0863e373`

Written at `021f9ec1` with some rows checked against the #1169 E branch; reconciled at `0863e373` (origin/develop after #1169 PRs E–H; #1169 is closed). Every rows block, path, anchor, count, wrap and grep below was re-run or re-read at `0863e373`; the rest held.

- **D-reconcile-1:** Every rows block passed Appendix S's `--check` at `0863e373` in task order, on a scratch copy of the tree, with each task's rows applied before the next was checked and every explicit edit in between (A3 Step 1's deletions, D1 Step 1, D2's substitutions, D3, D4 Step 3, D5 Step 3, D6 Step 1, F1 Step 1, and every wrap given as a replacement): 16 blocks, 709 rows, each followed by the third run's expected refusals. A token-level survey of the result reports only the seven sites D-13 keeps. Why: this is the reconcile the task-level `--check` repeats.
- **D-reconcile-2:** #1169 E–H added no name the rule reports. Per file, the survey at `0863e373` matches `021f9ec1` but for the moved paths: 2337 sites, 848 of them `$em`; 109, 121, 218, 1171 and 718 for the scopes of PRs B–F. None of the files #1169 added reports one (`src/Entity/RunningThrottle.php`, `RunningCallAttempts.php`, `RecommendationHistoryCaps.php`, `RecommendationPoolLimits.php`, `tests/Support/AwaitingApprovalRecorder.php`, `FetchWiring.php`, `FeedFormatParsers.php`, `ProseParagraphs.php`, `tests/EventListener/ApiExceptionIsAnsweredBeforeSymfonyLogsItTest.php`, `tests/Service/Auth/EmailVerifierTest.php`, the new `tests/PhpStan` classes); `SpeaksServiceValues` and the other new fixtures sit in `tests/PhpStan/data`, which neither the rule nor `composer stan` reads. So no row was added for them. Why: survey at `0863e373`.
- **D-reconcile-3:** One count changed: E1's `tests/Repository/SubscriptionRepositoryTest.php repo repository` is 7 (was 6), because #1169 G3's `testFindIncludedInAllItemsForUserSkipsHiddenFeedsAndOtherUsers` calls `$this->repo()` once more; `repo()` has 142 calls. A3's grep-built rows follow the tree: #1169 F3's new `EmailVerifierTest` joins (4 `$this->em`), the folded `BulkSubscriberTagLookupTest` leaves, and the list stays 251 files plus 7 compound rows; `$this->em` reads are 2359 (was 2346). Why: G3 and F3 edited those tests.
- **D-reconcile-4:** Paths. The moves the draft assumed from the #1169 E branch landed, so its assumptions table is gone: `src/Service/Parser/ItemImageExtractor.php`, `tests/Service/Parser/{FeedItemImageSelectorTest,ItemImageExtractorTest,ItemMediaExtractorTest}.php`, `tests/Service/Scraper/CardTitleTest.php`, and `AbstractAtomParser`'s constructor with `$this->imageSelector->fromAtom($entry, $ns, …)`. #1169's other moves (`ViewerTimeZoneModel` to `Service/Clock/Model`, and `CrossFamilyFailover`, `DesktopViewport`, `SuspiciousPhrases`, `PlausibleDuplicateShare`, `RecommendationAnswerBudget` out of `Support/`, with their tests) touch no file this plan names. Why: `git diff -M --name-status 021f9ec1 0863e373`.
- **D-reconcile-5:** Two wraps are new, so D-21's count is 160 (was 158). A2 wraps `RestoreLoader::load()`'s `new RestoreLoadPass(…)`: #1169 G8 named its last argument `$this->foundationFactory`, and `$this->entityManager` pushes the line past 120 columns. D5 wraps `GitHubLatestReleaseReaderTest::reader()`'s signature, which the `$gitHubRepository` parameter pushes past 120 (the draft missed it at `021f9ec1` too). A3, E1 and F1's tables were recomputed from the scratch run: the same 73, 36 and 24 lines with the same text, their line numbers now at `0863e373` (A3's `BulkSubscriberTest` lines are counted before Step 1's deletion, like every other line; F1's `CardFieldsTest` line is 122). E2's positive control moved to `RecommendationSettingsWriterTest.php:28` (#1169 H4 added an import). Why: #1169 F–H edited those files.
- **D-reconcile-6:** CLAUDE.md. #1169 G8 and its D-19 sentence end the "Names reveal intent" bullet with `container binds it by that name. If a name needs a comment to be understood, rename it.` (line 82), so A0 Step 4 greps that line and F4 Step 1 replaces it; the draft's `repository). If a name …` anchor is gone. The D-19 sentence keeps Symfony's `RateLimiterFactoryInterface $…Limiter` names; no row here renames a limiter, and none is an abbreviation. F4 Step 2 still inserts before `- **\`EntityIdCoercionRule\`**` (line 183). Why: `git diff 021f9ec1 0863e373 -- CLAUDE.md`.
- **D-reconcile-7:** Migrations stay out. #1169 F9 put `backend/migrations` under PHPCS's `strict_types` sniff; `phpstan.dist.neon` analyses `src` and `tests` and only scans `migrations` for symbols, so `AbbreviatedNameRule` never reads a migration, and no rows block, grep or pathspec here names `migrations`. A0 Step 4 checks the `paths:`. Why: F9 widened PHPCS, not PHPStan.
- **D-reconcile-8:** Nothing dropped. #1169 G8 renamed four test properties `UserFactory $users` to `$userFactory`, but no row here named them (D-10 covers properties holding a repository); every other row's name is still in its file. Why: every row passed `--check`.
- **D-reconcile-9:** Three checks that failed at `021f9ec1` too are fixed. A3 Step 1 expects `WarmCatalogFaviconsCommandTest.php:53:` (the grep runs after Step 1 removed nine lines; 62 is the line before). D6 Step 2's `function change\(` also matched `EntryStateUpdaterTest::change()`, so it is narrowed to `tests/Service/Settings`, with that helper as the positive control. F4 Step 3's pattern matched D-13's kept `AccountStatusException::__unserialize(array $data)` and an `AbstractOidcProviderTest` docblock that quotes `'' === $sub`: the pathspec leaves out the kept override (with a positive control), and F1 gains the row `AbstractOidcProviderTest.php sub subject 1`, the name `IdTokenVerifier` gives the value (D-22). Why: the scratch run.
- **D-reconcile-10:** Global Constraints: every command runs in the foreground, with no background job or poll loop besides the Merge rule's one Monitor watch; the shared-checkout rule names `checkout` too. Why: planner.
- **D-reconcile-11:** Unchanged: D-1 to D-22 and the planner's amendments (D-13 reversed, so vendor overrides keep the parent's parameter names; D-1's seven duplicate `em()`/`entityManager()` helpers deleted where the class inherits one; the frontend gate in PR D's Finishing; the allow-list as a constructor argument), the Refs/Closes discipline (A–E `Refs #1172`, F `Closes #1172`) and the merge rule (`gh pr merge --merge` after the Monitor watch, never `--auto`).

## Status

| Task | State |
|---|---|
| A0: Preflight, branch, plan copy, the rename script, reconcile checks | ⬜ |
| A1: `AbbreviatedNameRule` and its test (not yet registered); the survey config | ⬜ |
| A2: `$em` is `$entityManager` in `src` | ⬜ |
| A3: `$em` is `$entityManager` in `tests`; `em()` is `entityManager()` | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: Controllers, mappers, the entity, the repository, the commands | ⬜ |
| B2: The Doctrine listeners' event parameters | ⬜ |
| B3: `RecommendationDrainCommand::refreshOrReacquireLock()` | ⬜ |
| B4: `NormalizedLoginRateLimiter::normalizeLastUsername()` | ⬜ |
| B5: `PasskeyChallengeProblems` | ⬜ |
| C0: Preflight (PR B merged) | ⬜ |
| C1: Caught exceptions in the modules A–M | ⬜ |
| C2: Truncations in the modules A–M; `SubscriptionTagReference` | ⬜ |
| C3: Repository properties in the modules A–M | ⬜ |
| D0: Preflight (PR C merged) | ⬜ |
| D1: Caught exceptions and one-letter closures in the modules N–Z | ⬜ |
| D2: `AbstractAtomParser` reads `namespaceUri()`; `$atomNamespace` | ⬜ |
| D3: The change marker: `$temporaryFile`, `$payload`, `change-marker.json` | ⬜ |
| D4: Truncations in the modules N–Z; `extraAuthorizationParameters()`; `$flagCounts`; no `$_` | ⬜ |
| D5: Repository properties in the modules N–Z; `$gitHubRepository` | ⬜ |
| D6: `RelyingPartyChangeGuard` | ⬜ |
| E0: Preflight (PR D merged) | ⬜ |
| E1: Names in the tests outside `tests/Service` | ⬜ |
| E2: Test helpers and repository properties outside `tests/Service` | ⬜ |
| F0: Preflight (PR E merged) | ⬜ |
| F1: Names in `tests/Service` | ⬜ |
| F2: Test helpers and repository properties in `tests/Service` | ⬜ |
| F3: `AbbreviatedNameRule` is registered | ⬜ |
| F4: CLAUDE.md and the closing sweep | ⬜ |

## Renamed methods, classes and public members, with every caller

Counts at `0863e373`, from `backend/` (`git grep -c -P '<pattern>' 0863e373 -- src tests config ../docs ../frontend/src`); `docs/superpowers/` holds plans, which are history and stay as written.

| Old → new | Kind | Declarations | Callers and mentions | Task |
|---|---|---|---|---|
| `ApiTestCase::em()` → `entityManager()` | protected test helper | 1 (`tests/Support/ApiTestCase.php`) + 13 private copies; 5 copies and the 2 private `entityManager()` duplicates are deleted (D-1), 8 copies are renamed | 286 calls in 31 test files: 85 in the 5 `DbTestCase` subclasses become `$this->entityManager`, 201 become `entityManager()`; `config`, `docs`, `frontend` 0 | A3 |
| `repo()` → `repository()` | private test helpers | 17 | 142 calls in the same 17 files | E1, F1 |
| `repoWithWindow()` → `repositoryWithWindow()` | private test helper | 1 (`EntryListTest`) | 10 in that file | E2 |
| `makeSub()` → `makeSubscription()` | private test helpers | 3 (`MoveFeedToTagTest`, `ReorderTest`, `SubscriptionBulkTest`) | 39 in those files | E2 |
| `subsOf()` → `subscriptionsOf()` | private test helper | 1 (`OpmlImporterTest`) | 4 in that file | F2 |
| `prefs()` → `preferences()` | private test helper | 1 (`DigestScheduleTest`) | 5 in that file | F1 |
| `change()` → `guard()` | private test helper | 1 (`RelyingPartyChangeTest`) | 5 in that file | D6 |
| `AbstractOidcProvider::extraAuthorizationParams()` → `extraAuthorizationParameters()` | protected hook | 2 (`AbstractOidcProvider:101`, `AppleOAuthProvider:97`) | 1 call (`AbstractOidcProvider:129`), 1 docblock (`AbstractOidcProvider:20`); tests, config, docs 0 | D4 |
| `RecommendationDrainCommand::keepsHoldingTheLock()` → `refreshOrReacquireLock()` | private | 1 | 1 call (`:181`), 1 docblock (`:49`); tests 0 | B3 |
| `NormalizedLoginRateLimiter::normalize()` → `normalizeLastUsername()` | private | 1 | 3 calls in that file | B4 |
| `SubscriptionTalliesModel::$flags` → `$flagCounts` | public property | 1 | 3 reads in `src/Http/SubscriptionCountsJson.php`, 1 in `tests/Service/Subscription/SubscriptionTallyReaderTest.php`; built positionally (1 src, 3 tests) | D4 |
| `Settings\RelyingPartyChange` → `RelyingPartyChangeGuard` | class | 1 | `AdminSettingsController` (import, property type), `PasskeyRelyingPartyInterface` (import, `{@see}`), `InstanceSetting` and `UserPasskeyRepository` (`{@see}` FQCN); the test (import, return type, `new`); frontend 0 (it names only the exception); docs 0 | D6 |
| `Backup\Dto\SubscriptionTagRef` → `SubscriptionTagReference` | class | 1 | 3 in `SubscriptionLine.php`; tests, docs 0 | C2 |
| `GitHubLatestReleaseReader::__construct($repository)` → `$gitHubRepository` | constructor argument | 1 | `config/services.yaml` `arguments:` key; the test helper's named argument `repository:` (`GitHubLatestReleaseReaderTest:75`) | D5 |

Every other renamed parameter of a public method is called positionally: a named argument to a renamed parameter would fail `composer stan` ("Unknown parameter"), and the survey at `0863e373` finds two, both handled (`SendDueDigestsTest:84` `em:`, `GitHubLatestReleaseReaderTest:75` `repository:`).

## Global Constraints

- **Paths and commands are relative to `backend/`**, except steps marked "from the repository root", `docs/…`, `../CLAUDE.md` and `../frontend/…`.
- **Read before you write.** Every explicit edit names the exact text it replaces. If that text is not there, stop and report the file and the text you found.
- **Renames run through the script** (Appendix S) from a rows file under `var/refactor-1172/` (uncommitted; `var/` is ignored). Each task writes its rows block to that file verbatim (fields separated by spaces), runs `php var/refactor-1172/rename-names.php --check <file>` first, then without `--check`, then `--check` again: the third run must refuse every row with `0 found` (the two expected exceptions are named in B1 and C2). **A count mismatch in the first check is a reconcile gap** (every block held at `0863e373`, so one means develop moved past it): open the file, and if the extra or missing hits are the same variable with the same meaning (a #1169 edit added or removed a use), set the row's count to the number found and list the row in the PR body; if a hit means something else, stop and report it. Never edit a count to force a pass without reading the file.
- **Clean Code (CLAUDE.md) is mandatory:** the names in the rows follow the Decisions; a new name you have to invent for a reconcile hit follows D-2, D-3 and D-10 and is listed in the PR body.
- **Comments:** default none; at most three lines. The script renames `$old` inside comments and docblocks; read those lines in the diff, and fix any sentence the rename made wrong.
- **PSR-12, 120 columns.** A rename that pushes a line over 120 columns is wrapped in the same task (each task lists its lines; Appendix W has the rules). `composer cs` must print nothing.
- **pdepend 2.16.2 (`composer md`) cannot parse `public private(set)`, and `new Foo()->bar()` must be written `(new Foo())->bar()`.** Never use either in `src`.
- **Every touched `src` file is PHPMD-clean** under `composer md` (it is at `0863e373`: CI gates `composer md` over `src`). Fix the design, never the threshold.
- **phptramp:** no chain of 4+ hops across 2+ classes forwarding an unread parameter. If only the tramp step fails in CI, run `composer show larspohlmann/phptramp` first.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`. Run `bin/console cache:clear && bin/console cache:warmup` after a class rename or a new class, before `composer stan`. A named argument to a renamed parameter fails `composer stan` with `Unknown parameter`; fix the call.
- **The survey** (Appendix N): `vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress <paths> 2>&1 | grep -c 'Name reveals intent'`. Each PR's scope ends at `0`; the count before the PR's first rename is its positive control. Counts are given at `0863e373`; a different non-zero "before" means develop moved past it: a note, not a stop.
- **PhpStorm inspections** (`mcp__phpstorm__lint_files`) on every changed PHP file, in batches of 20: ERROR and WARNING block, weak warnings are advisory.
- **Tests:** PHPUnit 12 attributes; `assertSame`; invocation matchers on `$this`. A rename changes no assertion.
- **Every new test or pin gets a deletion check** that only its one edit makes fail, with the expected FAIL quoted. The task implementer runs **every** deletion check, restores each by hand with the Edit tool (never `git checkout --`), and quotes the FAIL output in the task report. The reviewer re-runs at least one per task it reviews and one per PR.
- **Every grep-based check gets a positive control**: the same pattern and pathspec must print a known hit (named in the step). Use `git grep -P` for `\b` (plain `-E` treats `\b` as a letter here), `':(glob)…'` or directories for pathspecs, and never `-F` with backslashes.
- **Never run the MySQL leg's cache prep beside the native leg.** `docker compose exec php rm -rf var/cache/test*` deletes the native leg's `var/cache/test*` on the bind mount. Run the native leg, then the prep and the MySQL leg, one after the other.
- **Infection:** `composer infection:diff` mutates the lines a branch touches, and a rename touches many. An escaped mutant on a touched line gets a killing test in the same PR, in its own commit (`test(#1172): <what the test pins>`). Never an `ignore`, never a lower `minMsi`. A file git sees as renamed (`R`) is outside `--git-diff-filter=AM`.
- **Gates for every task:** its tests, `composer check`, `composer md`, `bin/console lint:container`, and the PhpStorm inspections.
- **Gates per PR (Finishing):** the survey over the PR's scope; `php bin/phpunit` (SQLite), then, after it finishes, `docker compose exec php rm -rf var/cache/test* && docker compose exec php composer test` (MySQL; check the containers are current first); `composer check`, `composer md`, `composer infection:diff`; today's dev log (`ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'`).
- **Commits:** `refactor(#1172): <lower-case summary>`, one per task; killing tests `test(#1172): …`; no attribution lines.
- **PR bodies:** PRs A–E say `Refs #1172`. Neither their bodies nor any commit on their branches contains "close", "fix" or "resolve" in any form (Appendix K runs the gate). PR F's body says `Closes #1172`.
- **Branches**, each cut from `origin/develop` after the previous PR merges: `refactor/1172-entity-manager-name`, `refactor/1172-names-outside-services`, `refactor/1172-service-names-a-m`, `refactor/1172-service-names-n-z`, `refactor/1172-test-names`, `refactor/1172-service-test-names`.
- **Merge:** watch with the Monitor tool, one command: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, `gh pr merge <PR> --merge`. Never `--auto`.
- **The checkout is shared.** Run `git status --short && git branch --show-current` before any `checkout`, `switch`, `reset` or `stash`; another session may be mid-edit. Work in place, no worktrees.
- **Every command runs in the foreground.** No background jobs, no `&`, no `sleep`/`until` poll loops; the one watch is the Merge rule's single Monitor command.
- **E2e** (`tests/E2e`) is renamed only (E1). Run `composer e2e` in PR E's Finishing only from the checkout that runs the Docker stack (`bin/e2e-preflight.sh` refuses otherwise).
- **Frontend** (D3 only): `docker compose exec -T frontend npm test -- sidebar-counts-poll` and the frontend gate `docker compose exec -T frontend npm run check` (ESLint, Prettier, Stylelint and Jest), always in the container, never natively, one at a time (two Jest runs in the container run out of memory).

---

# PR A — `$em` is `$entityManager`; the guard exists

### Task A0: Preflight, branch, plan copy, the rename script, reconcile checks

**Files:**
- Create: `docs/superpowers/plans/2026-09-29-1172-abbreviated-names.md` (this plan)
- Create (uncommitted): `var/refactor-1172/rename-names.php`

- [ ] **Step 1: #1169 is closed and the checkout is free (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1169 --json state --jq .state
gh issue view 1172 --json state --jq .state
```
Expected: a clean tree (or only another session's files, which you leave alone); `CLOSED`, then `OPEN`. If #1169 is open, stop: this plan is queued behind it.

- [ ] **Step 2: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c refactor/1172-entity-manager-name origin/develop
cp <the plan file the planner handed you> docs/superpowers/plans/2026-09-29-1172-abbreviated-names.md
git add docs/superpowers/plans/2026-09-29-1172-abbreviated-names.md
git commit -m "refactor(#1172): the plan"
```

- [ ] **Step 3: The rename script**

```bash
mkdir -p var/refactor-1172
```
Write Appendix S verbatim to `var/refactor-1172/rename-names.php`, then:
```bash
php -l var/refactor-1172/rename-names.php
```
Expected: `No syntax errors detected in var/refactor-1172/rename-names.php`.

- [ ] **Step 4: Develop still matches the reconcile (from `backend/`)**

The plan was reconciled at `0863e373` (D-reconcile-1 to -11). These re-read the facts it builds on.
```bash
git log --oneline 0863e373..origin/develop -- src tests config ../CLAUDE.md ../frontend/src/app/reader
git ls-tree --name-only origin/develop -- src/Service/Parser/ItemImageExtractor.php tests/Service/Parser/FeedItemImageSelectorTest.php tests/Service/Parser/ItemImageExtractorTest.php tests/Service/Parser/ItemMediaExtractorTest.php tests/Service/Scraper/CardTitleTest.php
git grep -n -P 'imageSelector->fromAtom\(|private function parseEntry\(\\DOMElement \$entry, string \$ns\)' origin/develop -- src/Service/Parser/FeedFormatParser/AbstractAtomParser.php
git grep -n -P 'protected EntityManagerInterface \$em;' origin/develop -- tests/DbTestCase.php
git grep -n -P 'function (em|entityManager)\(' origin/develop -- tests/Support/ApiTestCase.php tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeDigestControllerTest.php
git grep -c -P '\bRelyingPartyChange\b' origin/develop -- src tests
git grep -n -F 'container binds it by that name. If a name needs a comment to be understood, rename it.' origin/develop -- ../CLAUDE.md
git grep -n -F 'counts.json' origin/develop -- src tests ../frontend/src
git grep -n -E '^        - (src|tests|migrations)$' origin/develop -- phpstan.dist.neon
```
Expected:
- Nothing: no commit after `0863e373` touched these paths. A listed commit is not a stop (every rows block's `--check` and every replacement's text catch a drift in the task that meets it), but read each one and name it in PR A's body.
- The five paths (D-reconcile-4).
- Two lines: `:97:    private function parseEntry(\DOMElement $entry, string $ns): ?ParsedEntryModel` and `:111:        $image = $this->imageSelector->fromAtom(`.
- `tests/DbTestCase.php:12:    protected EntityManagerInterface $em;`
- Three lines: `ApiTestCase.php:37` `protected function em()`, `MeControllerTest.php:24` and `MeDigestControllerTest.php:21` `private function entityManager()`.
- Six files: `AdminSettingsController.php:2`, `InstanceSetting.php:1`, `UserPasskeyRepository.php:1`, `PasskeyRelyingPartyInterface.php:2`, `RelyingPartyChange.php:1`, `RelyingPartyChangeTest.php:3`.
- One line, `../CLAUDE.md:82` (D-reconcile-6; F4 Step 1 replaces it).
- Six lines: `ContentChangeMarker.php:37`, `ContentChangeMarkerTest.php` at 77, 100 and 120, `sidebar-counts-poll.service.ts:22`, `sidebar-counts-poll.service.spec.ts:95`.
- Three lines: `phpstan.dist.neon:9` `- src` and `:10` `- tests` (the analysed `paths:`, the positive control), and `:13` `- migrations`, under `scanDirectories:` (symbols only): the rule never reads a migration (D-reconcile-7).

A different result, other than the first line's, is a reconcile gap: stop and report it with the output.

---

### Task A1: `AbbreviatedNameRule` and its test (not yet registered); the survey config

The rule lands first and unregistered: every PR uses it as its survey through `var/refactor-1172/names.neon`, and F3 registers it once the tree is clean.

**Files:**
- Create: `tests/PhpStan/AbbreviatedNames.php`, `tests/PhpStan/AbbreviatedNameRule.php`, `tests/PhpStan/AbbreviatedNameRuleTest.php`, `tests/PhpStan/data/abbreviated-name-fixtures.php`
- Create (uncommitted): `var/refactor-1172/names.neon`

**Interfaces:**
- Produces: `App\Tests\PhpStan\AbbreviatedNameRule`, a `Rule<FileNode>` whose constructor takes `PhpParser\NodeFinder` (already a service in `phpstan.dist.neon`), `PHPStan\Reflection\ReflectionProvider` (PHPStan's own service) and `array<string, list<string>> $wireNames` defaulting to the rule's `WIRE_NAMES` allow-list (the `ThinControllerRule` pattern: only the rule's own test passes another, `[]` among them). Identifier `simpleFeedReader.abbreviatedName`, message `Name reveals intent: $<name> is a single letter or a truncated word; name what it holds (#1172).` One error per (line, name).
- It skips the parameters, and their uses in that method, of any method other than a constructor that overrides or implements a method declared outside `App\` (D-13): named arguments bind to the parent's names, and PhpStorm reports a mismatch.
- Produces: `App\Tests\PhpStan\AbbreviatedNames::isAbbreviated(string): bool`.

- [ ] **Step 1: The fixture**

`tests/PhpStan/data/abbreviated-name-fixtures.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for AbbreviatedNameRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    use Symfony\Component\Security\Core\Exception\AuthenticationException;

    final class Abbreviations
    {
        private int $idx = 0;

        public function __construct(private readonly string $cfg)
        {
        }

        public function spelledOut(string $document, int $x, int $y): int
        {
            $total = $x + $y + $this->idx;
            foreach ([1, 2] as $i) {
                $total += $i + \strlen($document . $this->cfg);
            }
            try {
                return $total;
            } catch (\Throwable $e) {
                return \count(array_map(static fn (int $n): int => $n, [1]));
            }
        }

        public function numbered(): int
        {
            $s1 = 1;
            $data = 2;

            return $data;
        }
    }

    final class InheritedNames extends AuthenticationException
    {
        public function __construct(string $msg)
        {
            parent::__construct($msg);
        }

        public function __unserialize(array $data): void
        {
            parent::__unserialize($data);
        }

        public function own(array $data): int
        {
            return \count($data);
        }
    }
}

namespace App\Service\Fixtures\Wire {
    final readonly class WireRequest
    {
        public function __construct(public string $q)
        {
        }
    }

    final readonly class OtherRequest
    {
        public function __construct(public string $q)
        {
        }
    }
}
```

- [ ] **Step 2: The failing test**

`tests/PhpStan/AbbreviatedNameRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\NodeFinder;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<AbbreviatedNameRule> */
final class AbbreviatedNameRuleTest extends RuleTestCase
{
    private const string FIXTURE = __DIR__ . '/data/abbreviated-name-fixtures.php';

    /**
     * Keyed at the fixture's request, so the test never depends on the production allow-list.
     *
     * @var array<string, list<string>>
     */
    private array $wireNames = ['App\\Service\\Fixtures\\Wire\\WireRequest' => ['q']];

    protected function getRule(): Rule
    {
        return new AbbreviatedNameRule(
            new NodeFinder(),
            self::getContainer()->getByType(ReflectionProvider::class),
            $this->wireNames,
        );
    }

    public function testItReportsEverySingleLetterAndTruncatedNameButCoordinatesInheritedParametersAndWireKeys(): void
    {
        $this->analyse([self::FIXTURE], [
            [self::message('idx'), 13],
            [self::message('cfg'), 15],
            [self::message('i'), 22],
            [self::message('i'), 23],
            [self::message('e'), 27],
            [self::message('n'), 28],
            [self::message('s1'), 34],
            [self::message('data'), 35],
            [self::message('data'), 37],
            [self::message('msg'), 43],
            [self::message('msg'), 45],
            [self::message('data'), 53],
            [self::message('data'), 55],
            [self::message('q'), 70],
        ]);
    }

    public function testAnEmptyAllowListReportsTheWireKeyToo(): void
    {
        $this->wireNames = [];

        $this->analyse([self::FIXTURE], [
            [self::message('idx'), 13],
            [self::message('cfg'), 15],
            [self::message('i'), 22],
            [self::message('i'), 23],
            [self::message('e'), 27],
            [self::message('n'), 28],
            [self::message('s1'), 34],
            [self::message('data'), 35],
            [self::message('data'), 37],
            [self::message('msg'), 43],
            [self::message('msg'), 45],
            [self::message('data'), 53],
            [self::message('data'), 55],
            [self::message('q'), 63],
            [self::message('q'), 70],
        ]);
    }

    private static function message(string $name): string
    {
        return sprintf(
            'Name reveals intent: $%s is a single letter or a truncated word; name what it holds (#1172).',
            $name,
        );
    }
}
```
Run: `php bin/phpunit tests/PhpStan/AbbreviatedNameRuleTest.php`
Expected: FAIL with `Class "App\Tests\PhpStan\AbbreviatedNameRule" not found`.

- [ ] **Step 3: The names and the rule**

`tests/PhpStan/AbbreviatedNames.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

/** The names AbbreviatedNameRule rejects (CLAUDE.md "Names reveal intent", #1172). */
final class AbbreviatedNames
{
    private const array TRUNCATIONS = [
        'args', 'arr', 'attr', 'attrs', 'btn', 'cfg', 'cnt', 'conf', 'ctx', 'cur', 'curr', 'dir', 'doc', 'dom', 'ec',
        'el', 'elem', 'em', 'fav', 'favs', 'idx', 'len', 'msg', 'ns', 'num', 'obj', 'params', 'pos', 'prefs', 'prev',
        'ref', 'repo', 'req', 'res', 'resp', 'st', 'str', 'sub', 'subs', 'svc', 'ts', 'val',
    ];

    private const array VAGUE = ['data', 'info', 'tmp', 'temp'];

    /** Coordinates keep their mathematical names: pixels, elliptic-curve points. */
    private const array COORDINATES = ['x', 'y'];

    public static function isAbbreviated(string $name): bool
    {
        if (\in_array($name, [...self::TRUNCATIONS, ...self::VAGUE], true)) {
            return true;
        }

        return 1 === preg_match('/^[a-z_]\d*$/', $name) && !\in_array($name, self::COORDINATES, true);
    }

    private function __construct()
    {
    }
}
```

`tests/PhpStan/AbbreviatedNameRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * No variable, parameter or property is a single letter or a truncated word (CLAUDE.md "Names reveal intent", #1172).
 * A method overriding one declared outside App keeps its parent's parameter names: named arguments bind to them.
 *
 * @implements Rule<FileNode>
 */
final readonly class AbbreviatedNameRule implements Rule
{
    /**
     * Class => the names it keeps for its wire shape; only ever shrinks, and every entry carries its reason.
     *
     * @var array<string, list<string>>
     */
    private const array WIRE_NAMES = [
        // The JSON body's key: `q` is the search term's key on every client.
        'App\\Dto\\Search\\MarkSearchReadRequest' => ['q'],
    ];

    /** @param array<string, list<string>> $wireNames overridable only for the rule's own test */
    public function __construct(
        private NodeFinder $nodeFinder,
        private ReflectionProvider $reflectionProvider,
        private array $wireNames = self::WIRE_NAMES,
    ) {
    }

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $kept = $this->keptIn($node->getNodes());
        $errors = [];
        foreach ($this->namedIn($node->getNodes()) as $named) {
            $name = self::nameOf($named);
            if (!AbbreviatedNames::isAbbreviated($name) || isset($kept[spl_object_id($named)])) {
                continue;
            }
            $errors[$named->getStartLine() . '$' . $name] = self::error($name, $named->getStartLine());
        }

        return array_values($errors);
    }

    /**
     * @param array<Node> $nodes
     *
     * @return list<Variable|PropertyItem>
     */
    private function namedIn(array $nodes): array
    {
        /** @var list<Variable|PropertyItem> $named */
        $named = $this->nodeFinder->find(
            $nodes,
            static fn (Node $candidate): bool => ($candidate instanceof Variable && \is_string($candidate->name))
                || $candidate instanceof PropertyItem,
        );

        return $named;
    }

    /**
     * @param array<Node> $nodes
     *
     * @return array<int, true> the object ids of the names a class keeps: its wire keys, and the parameters of a method
     *                          whose names a parent outside App fixes, with their uses in that method
     */
    private function keptIn(array $nodes): array
    {
        $kept = [];
        foreach ($this->nodeFinder->findInstanceOf($nodes, Class_::class) as $class) {
            $className = $class->namespacedName?->toString() ?? '';
            $wireNames = $this->wireNames[$className] ?? [];
            foreach ($this->namedIn([$class]) as $named) {
                if (\in_array(self::nameOf($named), $wireNames, true)) {
                    $kept[spl_object_id($named)] = true;
                }
            }
            foreach ($class->getMethods() as $method) {
                foreach ($this->inheritedParametersOf($className, $method) as $named) {
                    $kept[spl_object_id($named)] = true;
                }
            }
        }

        return $kept;
    }

    /** @return list<Variable|PropertyItem> */
    private function inheritedParametersOf(string $className, ClassMethod $method): array
    {
        if (!$this->overridesForeignMethod($className, $method->name->toString())) {
            return [];
        }
        $parameters = array_map(self::parameterName(...), $method->params);

        return array_values(array_filter(
            $this->namedIn([$method]),
            static fn (Variable|PropertyItem $named): bool => \in_array(self::nameOf($named), $parameters, true),
        ));
    }

    private function overridesForeignMethod(string $className, string $methodName): bool
    {
        if ('__construct' === strtolower($methodName) || !$this->reflectionProvider->hasClass($className)) {
            return false;
        }
        $class = $this->reflectionProvider->getClass($className);
        foreach ([...$class->getParents(), ...array_values($class->getInterfaces())] as $ancestor) {
            if ($ancestor->hasNativeMethod($methodName) && !str_starts_with($ancestor->getName(), 'App\\')) {
                return true;
            }
        }

        return false;
    }

    private static function parameterName(Param $parameter): string
    {
        return $parameter->var instanceof Variable ? self::nameOf($parameter->var) : '';
    }

    private static function nameOf(Variable|PropertyItem $named): string
    {
        if ($named instanceof PropertyItem) {
            return $named->name->toString();
        }

        return \is_string($named->name) ? $named->name : '';
    }

    private static function error(string $name, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Name reveals intent: $%s is a single letter or a truncated word; name what it holds (#1172).',
            $name,
        ))
            ->identifier('simpleFeedReader.abbreviatedName')
            ->line($line)
            ->build();
    }
}
```

- [ ] **Step 4: Run to watch it pass**

Run: `php bin/phpunit tests/PhpStan/AbbreviatedNameRuleTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Deletion checks**

One at a time, restoring each by hand; each FAILs with `Failed asserting that two strings are identical.` and the line named:
1. In `AbbreviatedNames::isAbbreviated()`, delete ` && !\in_array($name, self::COORDINATES, true)`. Expected: extra lines `19: … $x …`, `19: … $y …`, `21: … $x …`, `21: … $y …`.
2. In `namedIn()`, replace the closure's two lines with `static fn (Node $candidate): bool => $candidate instanceof Variable && \is_string($candidate->name),`. Expected: the line `13: Name reveals intent: $idx is …` missing.
3. In `overridesForeignMethod()`, replace the `return true;` inside the loop with `return false;`. Expected: extra lines `48: … $data …` and `50: … $data …` (the inherited `__unserialize()` parameter).
4. In `overridesForeignMethod()`, delete `'__construct' === strtolower($methodName) || `. Expected: the lines `43: … $msg …` and `45: … $msg …` missing (a constructor is named by its own class, never by its parent).
5. In `isAbbreviated()`, replace `'/^[a-z_]\d*$/'` with `'/^[a-z_]$/'`. Expected: the line `34: … $s1 …` missing.
6. In `keptIn()`, replace `$this->wireNames[$className] ?? []` with `[]`. Expected: `testItReportsEverySingleLetterAndTruncatedNameButCoordinatesInheritedParametersAndWireKeys` FAILs with the extra line `63: … $q …`.

- [ ] **Step 6: The survey config and its positive control**

`var/refactor-1172/names.neon` (Appendix N):
```neon
includes:
    - ../../phpstan.dist.neon

services:
    -
        class: App\Tests\PhpStan\AbbreviatedNameRule
        tags:
            - phpstan.rules.rule
```
Run:
```bash
bin/console cache:clear && bin/console cache:warmup
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src 2>&1 | grep -c 'Name reveals intent'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src tests 2>&1 | grep -c 'Name reveals intent: \$em is'
```
Expected at `0863e373`: `448`, then `848` (48 in `src`, 800 in `tests`). Both are the positive controls PR A ends against; a different number means develop moved past `0863e373`: a note.

- [ ] **Step 7: Gates and commit**

Run: `composer check`, then the PhpStorm inspections on the four new files.
```bash
git add tests/PhpStan/AbbreviatedNames.php tests/PhpStan/AbbreviatedNameRule.php tests/PhpStan/AbbreviatedNameRuleTest.php tests/PhpStan/data/abbreviated-name-fixtures.php
git commit -m "refactor(#1172): a rule that no name is a single letter or a truncated word"
```

---

### Task A2: `$em` is `$entityManager` in `src`

**Files:**
- Modify: the 48 `src` files the Step 1 grep lists

- [ ] **Step 1: The rows**

```bash
git grep -l -P '(?<![0-9\\$])\$em\b|->em\b' -- src | awk '{print $0 " em entityManager *"}' > var/refactor-1172/a2.rows
wc -l < var/refactor-1172/a2.rows
```
Expected: `48` at `0863e373`. Positive control for the pattern: `git grep -c -P '(?<![0-9\\$])\$em\b|->em\b' -- tests/DbTestCase.php` prints `tests/DbTestCase.php:4`.

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/a2.rows
php var/refactor-1172/rename-names.php var/refactor-1172/a2.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/a2.rows 2>&1 | grep -c ' found'
git grep -c -P '(?<![0-9\\$])\$em\b|->em\b' -- src
```
Expected: `Every row holds; nothing written (--check).`; `48`; `48`; the last grep prints nothing (its positive control is Step 1's).

- [ ] **Step 3: Wrap the three lines the rename lengthened**

In `src/Service/Backup/AccountBackupExporter.php`, replace
```php
        $walk = new BackupPartWalk($this->entityManager, $this->entries, $this->entryStates, $this->lines, $provenance, $userId);
```
with
```php
        $walk = new BackupPartWalk(
            $this->entityManager,
            $this->entries,
            $this->entryStates,
            $this->lines,
            $provenance,
            $userId,
        );
```
In `src/Service/Catalog/CatalogImporter.php`, replace
```php
            $pass = new CatalogImportPass($this->entityManager, $this->categories->findAllOrdered(), $this->feeds->findAll());
```
with
```php
            $pass = new CatalogImportPass(
                $this->entityManager,
                $this->categories->findAllOrdered(),
                $this->feeds->findAll(),
            );
```
In `src/Service/Backup/RestoreLoader.php`, replace
```php
        $pass = new RestoreLoadPass($this->entityManager, $this->feeds, $this->savedSearchSlug, $this->foundationFactory);
```
with
```php
        $pass = new RestoreLoadPass(
            $this->entityManager,
            $this->feeds,
            $this->savedSearchSlug,
            $this->foundationFactory,
        );
```
Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 4: Gates and commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit && composer check && composer md`
Expected: PASS.
```bash
git add src
git commit -m "refactor(#1172): the entity manager is \$entityManager in src"
```

---

### Task A3: `$em` is `$entityManager` in `tests`; `em()` is `entityManager()`

**Files:**
- Modify: the 251 test files the Step 2 grep lists; the seven files of Step 1

- [ ] **Step 1: The seven duplicate helpers go (D-1)**

Each of these classes already inherits the entity manager its helper fetches. In `tests/Controller/Api/MeControllerTest.php` and `tests/Controller/Api/MeDigestControllerTest.php` (they extend `ApiTestCase`, whose `em()` has the same body), delete the method and the blank line after it:
```php
    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

```
Their `$this->entityManager()` calls now reach the inherited helper Step 3 renames.

In `tests/Command/WarmCatalogFaviconsCommandTest.php`, `tests/Service/Catalog/CatalogSubscriberTest.php`, `tests/Service/Catalog/CatalogFaviconWarmerTest.php`, `tests/Service/Catalog/CatalogImporterTest.php` and `tests/Service/Subscription/BulkSubscriberTest.php` (they extend `DbTestCase`, whose `setUp()` boots the kernel and stores the same container service), delete the method and the blank line after it:
```php
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

```
and make their calls read the inherited property:
```bash
perl -pi -e 's/\$this->em\(\)/\$this->entityManager/g' tests/Command/WarmCatalogFaviconsCommandTest.php tests/Service/Catalog/CatalogSubscriberTest.php tests/Service/Catalog/CatalogFaviconWarmerTest.php tests/Service/Catalog/CatalogImporterTest.php tests/Service/Subscription/BulkSubscriberTest.php
```
In all seven files, delete the import line `use Doctrine\ORM\EntityManagerInterface;`: nothing else in them names it.
```bash
git grep -c -P 'EntityManagerInterface|function (em|entityManager)\(|->em\(' -- tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeDigestControllerTest.php tests/Command/WarmCatalogFaviconsCommandTest.php tests/Service/Catalog/CatalogSubscriberTest.php tests/Service/Catalog/CatalogFaviconWarmerTest.php tests/Service/Catalog/CatalogImporterTest.php tests/Service/Subscription/BulkSubscriberTest.php
git grep -c -F '$this->entityManager' -- tests/Service/Catalog/CatalogImporterTest.php tests/Controller/Api/MeControllerTest.php
git grep -n -E 'bootKernel|createClient|ensureKernelShutdown' -- tests/Command/WarmCatalogFaviconsCommandTest.php tests/Service/Catalog/CatalogSubscriberTest.php tests/Service/Catalog/CatalogFaviconWarmerTest.php tests/Service/Catalog/CatalogImporterTest.php tests/Service/Subscription/BulkSubscriberTest.php
```
Expected: nothing; then `tests/Service/Catalog/CatalogImporterTest.php:40` and a count above 0 for `MeControllerTest.php` (the positive controls); then one line, `WarmCatalogFaviconsCommandTest.php:53:        $application = new Application(self::$kernel ?? self::bootKernel());` (line 62 before this step's nine deleted lines) (it reuses the kernel `setUp()` booted, so the stored entity manager is the one its helper fetched). Another line here means a test reboots the kernel: stop and report it.

- [ ] **Step 2: The rows**

```bash
git grep -l -P '(?<![0-9\\$])\$em\b|->em\b' -- tests ':!tests/PhpStan/data' | awk '{print $0 " em entityManager *"}' > var/refactor-1172/a3.rows
wc -l < var/refactor-1172/a3.rows
cat >> var/refactor-1172/a3.rows <<'EOF'
tests/Controller/MaintenanceControllerTest.php failingEm failingEntityManager 2
tests/Service/Maintenance/MaintenanceTickTest.php failingEm failingEntityManager 2
tests/Service/Refresh/FeedOutcomePersisterTest.php failingEm failingEntityManager 4
tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php failingEm failingEntityManager 12
tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php runnerEm runnerEntityManager 2
tests/Support/RefreshRunners.php flushingEm flushingEntityManager 8
tests/Service/Mail/Digest/SendDueDigestsTest.php emStub entityManagerStub 3
EOF
```
Expected: `251` at `0863e373` (258 files name `$em`, less the seven Step 1 emptied). #1169 F3 folded `BulkSubscriberTagLookupTest.php` into `BulkSubscriberTest.php`, whose `em()` calls Step 1 already turned into `$this->entityManager`, and added `tests/Service/Auth/EmailVerifierTest.php`, which is on the list (D-reconcile-3).

- [ ] **Step 3: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/a3.rows
php var/refactor-1172/rename-names.php var/refactor-1172/a3.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/a3.rows 2>&1 | grep -c ' found'
```
Expected: `Every row holds; nothing written (--check).`; the row count (258 at `0863e373`); the same count.

- [ ] **Step 4: The one named argument**

In `tests/Service/Mail/Digest/SendDueDigestsTest.php`, replace
```php
        $report = $this->sweep(em: $entityManager)->run();
```
with
```php
        $report = $this->sweep(entityManager: $entityManager)->run();
```

- [ ] **Step 5: Nothing named `em` is left**

```bash
git grep -n -P '(?<![0-9\\$])\$em\b|->em\b|function em\(|\$\w+Em\b|emStub|\bem: ' -- src tests ':!tests/PhpStan/data'
git grep -c -P 'function entityManager\(' -- tests/Support/ApiTestCase.php
```
Expected: nothing; then `tests/Support/ApiTestCase.php:1` (the positive control: the renamed helper).

- [ ] **Step 6: Wrap the 73 lines the rename lengthened**

Wrap each line of the table (Appendix W rules). Line numbers are at `0863e373`, before this plan's earlier steps moved any line (an anchor, not a contract); the text is the line after the rename.

| File | Line at `0863e373` | The line after the rename |
|---|---|---|
| `tests/Command/ImportCatalogCommandTest.php` | 101 | `self::assertCount($catalog->document()->feedCount(), $entityManager->getRepository(CatalogFeed::class)->findAll());` |
| `tests/Command/PurgeUnverifiedUsersCommandTest.php` | 51 | `$count = $this->entityManager->createQuery('SELECT COUNT(u.id) FROM ' . User::class . ' u')->getSingleScalarResult();` |
| `tests/Command/PurgeUnverifiedUsersCommandTest.php` | 194 | `$survivors = $this->entityManager->createQuery('SELECT u.email FROM ' . User::class . ' u')->getSingleColumnResult();` |
| `tests/Controller/Admin/AdminCatalogImportControllerTest.php` | 164 | `self::assertCount(0, $entityManager->getRepository(CatalogFeed::class)->findAll(), 'describing must not import');` |
| `tests/Controller/Api/EntryControllerTest.php` | 354 | `$entityManager->persist(new Entry($feed, "tied-$i", "https://example.com/tied-$i", "Tied $i", $tied, $tied));` |
| `tests/Controller/Api/EntryControllerTest.php` | 1120 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $strangerSub->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 1251 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $strangerSub->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryPageParametersTest.php` | 196 | `$this->entityManager()->persist(new SavedSearchEntry($search, $member, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Controller/Api/ReorderTest.php` | 135 | `$reload = fn (int $id): Tag => $this->entityManager()->getRepository(Tag::class)->find($id) ?? self::fail("tag $id gone");` |
| `tests/Controller/Api/SavedSearchEntriesControllerTest.php` | 64 | `$this->entityManager()->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Controller/Api/SavedSearchSlugRoutingTest.php` | 104 | `$this->entityManager()->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Controller/Api/SavedSearchSlugRoutingTest.php` | 137 | `$this->entityManager()->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Controller/Api/SavedSearchSlugRoutingTest.php` | 210 | `$this->entityManager()->persist(new SavedSearchEntry($search, $read, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Controller/Api/SavedSearchSlugRoutingTest.php` | 211 | `$this->entityManager()->persist(new SavedSearchEntry($search, $unread, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Controller/MaintenanceControllerTest.php` | 163 | `$failingEntityManager = new FlushFailingEntityManager($entityManager, thrown: DuplicateKeyViolation::exception());` |
| `tests/Entity/FeedEntryTest.php` | 22 | `$reloaded = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://example.com/feed.xml']);` |
| `tests/Entity/SubscriptionTest.php` | 105 | `$reloaded = $this->entityManager->find(EntryState::class, ['user' => $user->getId(), 'entry' => $entry->getId()]);` |
| `tests/Entity/SubscriptionTest.php` | 152 | `$reloaded = $this->entityManager->find(EntryState::class, ['user' => $user->getId(), 'entry' => $entry->getId()]);` |
| `tests/Repository/CountInFeedsSubscribedByTest.php` | 28 | `$this->entityManager->persist(new Subscription($user, $subscribed, new \DateTimeImmutable('2026-07-01 00:00:00')));` |
| `tests/Repository/EntryBatchInserterTest.php` | 94 | `$entry = $this->entityManager->getRepository(Entry::class)->findOneBy(['guidHash' => hash('sha256', 'one-guid')]);` |
| `tests/Repository/EntryListRowEnricherTest.php` | 34 | `$this->entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Repository/EntryListTest.php` | 647 | `$this->entityManager->persist(new Subscription($stranger, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Repository/FeedIdsByUrlsForUserTest.php` | 32 | `$this->entityManager->persist(new Subscription($stranger, $theirs, new \DateTimeImmutable('2026-07-01 00:00:00')));` |
| `tests/Repository/FeedRepositoryTagScopeTest.php` | 36 | `$this->entityManager->persist(new Subscription($owner, $untagged, new \DateTimeImmutable('2026-01-01T00:00:00Z')));` |
| `tests/Repository/RecommendationFeedTest.php` | 117 | `$this->entityManager->persist(new Subscription($stranger, $strangerFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Repository/RecommendationItemRepositoryTest.php` | 44 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Repository/SavedSearchMembershipLoaderTest.php` | 136 | `$this->entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Repository/SavedSearchMembershipReadsTest.php` | 41 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Repository/SavedSearchMembershipReadsTest.php` | 334 | `$this->entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00')));` |
| `tests/Repository/SubscriptionEntryCountsTest.php` | 46 | `$this->entityManager->persist(new Subscription($stranger, $theirs, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Repository/WorkerHeartbeatRepositoryTest.php` | 87 | `$names = $this->entityManager->getConnection()->fetchFirstColumn('SELECT name FROM worker_heartbeat ORDER BY name');` |
| `tests/Service/Account/AccountResetTest.php` | 109 | `self::assertSame([], $this->entityManager->getRepository(RecommendationRun::class)->findBy(['user' => $userId]));` |
| `tests/Service/Account/AccountResetTest.php` | 110 | `self::assertSame([], $this->entityManager->getRepository(RecommendationSettings::class)->findBy(['user' => $userId]));` |
| `tests/Service/Account/AccountResetTest.php` | 112 | `self::assertSame([], $this->entityManager->getRepository(RecommendationRunLog::class)->findBy(['run' => $runId]));` |
| `tests/Service/Account/AccountResetTest.php` | 148 | `$bystanderSubscriptions = $this->entityManager->getRepository(Subscription::class)->findBy(['user' => $bystanderId]);` |
| `tests/Service/Account/AccountResetTest.php` | 157 | `$this->entityManager->getRepository(SubscriptionTag::class)->findBy(['subscription' => $bystanderSubscriptions[0]]),` |
| `tests/Service/Account/AccountResetTest.php` | 163 | `self::assertCount(1, $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $bystanderRunId]));` |
| `tests/Service/Account/AccountResetTest.php` | 186 | `self::assertSame([], $this->entityManager->getRepository(RecommendationRun::class)->findBy(['user' => $userId]));` |
| `tests/Service/Account/AccountResetTest.php` | 187 | `self::assertSame([], $this->entityManager->getRepository(RecommendationSettings::class)->findBy(['user' => $userId]));` |
| `tests/Service/Backup/AccountBackupExporterTest.php` | 487 | `$user = (new FullyPopulatedAccount($this->entityManager, $this->hasher()))->create('reader-round-trip@example.com');` |
| `tests/Service/Backup/AccountRestorerTest.php` | 691 | `$this->entityManager->persist(new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-03 10:00:00')));` |
| `tests/Service/Backup/EntryMediaBackupRoundTripTest.php` | 68 | `$restoredFeed = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://restore.example/feed.xml']);` |
| `tests/Service/Backup/EntryPartRestorerTest.php` | 212 | `$this->entityManager->persist(new Subscription($stranger, $feed, new \DateTimeImmutable('2026-07-02 00:00:00')));` |
| `tests/Service/Ingest/EntryCategoryWriterTest.php` | 82 | `self::assertCount(1, $this->entityManager->getRepository(Category::class)->findBy(['canonicalKey' => 'politics']));` |
| `tests/Service/Mail/Digest/DigestComposerTest.php` | 152 | `$this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Mail/Digest/DigestComposerTest.php` | 163 | `$this->entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')));` |
| `tests/Service/Mail/Digest/DigestEntryFinderTest.php` | 42 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Mail/Digest/DigestEntryFinderTest.php` | 107 | `$this->entityManager->persist(new SavedSearchEntry($this->search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')));` |
| `tests/Service/Maintenance/MaintenanceTickTest.php` | 85 | `$failingEntityManager = new FlushFailingEntityManager($this->entityManager, thrown: DuplicateKeyViolation::exception());` |
| `tests/Service/Opml/OpmlImporterTest.php` | 68 | `$feed = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://blog.example.com/feed.xml']);` |
| `tests/Service/Opml/OpmlImporterTest.php` | 89 | `$rows = $this->entityManager->getRepository(Feed::class)->findBy(['url' => 'https://blog.example.com/feed.xml']);` |
| `tests/Service/ReaderAudit/AuditSamplerTest.php` | 103 | `$this->entityManager->persist(new Entry($strangerFeed, 'g', 'https://stranger.example.com/g', 'T', $moment, $moment));` |
| `tests/Service/Recommendation/Feed/ForYouFeedTest.php` | 46 | `$this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Recommendation/Feed/ForYouFeedTest.php` | 124 | `$this->entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-08-07T09:00:00Z')));` |
| `tests/Service/Recommendation/Feed/RecommendationFeedPagerTest.php` | 31 | `$this->entityManager->persist(new Subscription($this->user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Recommendation/Feed/RecommendationForYouSummaryProviderTest.php` | 42 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Recommendation/Run/RecommendationPipelineTest.php` | 308 | `$items = $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $run], ['position' => 'ASC']);` |
| `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php` | 97 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php` | 2886 | `$items = $this->entityManager->getRepository(RecommendationItem::class)->findBy(['run' => $run], ['position' => 'ASC']);` |
| `tests/Service/Recommendation/Run/RecommendationRunPurgerTest.php` | 50 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php` | 36 | `$this->user = (new UserFactory($this->entityManager, $hasher))->create('recommendation-settings-writer@example.test');` |
| `tests/Service/Refresh/FeedOutcomePersisterTest.php` | 84 | `$result = $this->persister($this->entityManager)->persist($feed, FetchOutcomeModel::failed($gone), $this->clock->now());` |
| `tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php` | 600 | `$failingEntityManager = new FlushFailingEntityManager($this->entityManager, thrown: DuplicateKeyViolation::exception());` |
| `tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php` | 1182 | `$failingEntityManager = new FlushFailingEntityManager($this->entityManager, thrown: new ForeignKeyConstraintViolationException(` |
| `tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php` | 1266 | `$failingEntityManager = new FlushFailingEntityManager($this->entityManager, thrown: DuplicateKeyViolation::exception());` |
| `tests/Service/Refresh/UserRefreshScopeTest.php` | 29 | `$this->entityManager->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | 75 | `$shared = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://shared.example.com/rss.xml']);` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | 76 | `$fresh = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://fresh.example.com/rss.xml']);` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | 94 | `$feed = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://due.example.com/rss.xml']);` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | 196 | `$this->entityManager->persist(new Subscription($user, $existingFeed, new \DateTimeImmutable('2026-07-01 00:00:00')));` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | 207 | `$this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://second.example.com/rss.xml']),` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | 271 | `$subscription = $this->entityManager->getRepository(Subscription::class)->findOneBy(['user' => $user, 'feed' => $feed]);` |
| `tests/Support/SavedSearchMatchFixture.php` | 50 | `$this->entityManager->persist(new SavedSearchEntry($search, $entry, new \DateTimeImmutable('2026-09-22T10:00:00Z')));` |

Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 7: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit && composer check`
Expected: PASS.
```bash
git add tests
git commit -m "refactor(#1172): the entity manager is \$entityManager in tests, and so is its helper"
```

---

### Finishing PR A

- [ ] **Step 1: The survey**

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src tests 2>&1 | grep -c 'Name reveals intent: \$em is'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src tests 2>&1 | grep -c 'Name reveals intent'
```
Expected: `0`; then `1489` at `0863e373` (the whole tree's 2337 sites less the 848 `$em` sites: the names PRs B–F rename).

- [ ] **Step 2: The gates on the whole branch** (Global Constraints, "Gates per PR").

- [ ] **Step 3: PhpStorm inspections** on every changed PHP file (in batches of 20).

- [ ] **Step 4: /simplify** over `git diff origin/develop...HEAD`; commit as `refactor(#1172): simplify pass` if anything changed.

- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **A pure rename:** no line changed but for `em` → `entityManager`, the two deleted helpers, the named argument and the wraps (`git diff origin/develop...HEAD -- src tests | grep '^[-+]' | grep -v -P 'entityManager|EntityManager' | grep -v -P '^(\+\+\+|---)'` shows only the wraps and the deleted helper lines).
2. **The inherited entity manager (D-1):** `MeControllerTest` and `MeDigestControllerTest` reach `ApiTestCase::entityManager()`, the same container fetch they did before; the five `DbTestCase` subclasses read `DbTestCase::$entityManager`, the instance their deleted helper fetched, and none of them reboots the kernel.
3. **The rule** has no false negative: a promoted property, a declared property, a closure `use`, a `catch`, a `foreach` key and a top-level script variable are all `Variable` or `PropertyItem` nodes `namedIn()` finds.
4. **Deletion checks:** re-run A1's first and third; quote both FAILs.

Fix each finding rated Important or above in its own commit (`refactor(#1172): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K) with `var/refactor-1172/pr-a-body.md`:
```markdown
Refs #1172 (PR A of six).

- `$em` is `$entityManager` everywhere: 48 src files, 258 test files, `DbTestCase`'s property and the test helpers (`em()` → `entityManager()`).
- Seven duplicate helpers go, because their class already inherits the same entity manager: `MeControllerTest` and `MeDigestControllerTest` use `ApiTestCase::entityManager()`; `WarmCatalogFaviconsCommandTest`, `CatalogSubscriberTest`, `CatalogFaviconWarmerTest`, `CatalogImporterTest` and `BulkSubscriberTest` read `DbTestCase::$entityManager`. The eight private copies left (seven `WebTestCase` tests and `AssertionVerifierTest`) share no base that holds one: a follow-up.
- `AbbreviatedNameRule` (not yet registered) reports a variable, parameter or property named with a single letter or a truncated word. The later PRs use it as their survey; the last one registers it.

Renamed method: `ApiTestCase::em()` → `entityManager()`, the container's entity manager for a web test (286 calls).

No behaviour change.
```
Title: `refactor(#1172): the entity manager is $entityManager`.

- [ ] **Step 7: Merge when green** (Global Constraints, "Merge"), then `gh issue view 1172 --json state --jq .state`. Expected: `OPEN`.

---

# PR B — Names outside `src/Service`

### Task B0: Preflight (PR A merged)

- [ ] **Step 1: The checkout, the branch, the tools (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --search 'refactor(#1172): the entity manager is' --json number --jq length
git switch -c refactor/1172-names-outside-services origin/develop
ls backend/var/refactor-1172/rename-names.php backend/var/refactor-1172/names.neon
```
Expected: a clean tree; `1`; the branch; both paths (recreate either from Appendix S or N if missing).

- [ ] **Step 2: The positive controls for this PR's survey (from `backend/`)**

```bash
SCOPE='src/Command src/Controller src/Doctrine src/Dto src/Entity src/EventListener src/Http src/Repository src/Security'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress $SCOPE 2>&1 | grep -c 'Name reveals intent'
```
Expected: `95` at `0863e373` (after PR A; the rule keeps the five vendor-named parameters of D-13). Run it in bash: zsh does not split `$SCOPE`.

---

### Task B1: Controllers, mappers, the entity, the repository, the commands

**Files:**
- Modify: the 15 files of the rows below

The `SubscriptionController` rows run in order: the service property becomes `$subscriber` before the repository property takes `$subscriptions` (D-10).

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/b1.rows`:
```text
src/Command/ImportCatalogCommand.php e exception 2
src/Command/ReaderAuditCommand.php e sampledEntry 2
src/Command/ReaderAuditCommand.php mine shardEntries 5
src/Command/ReaderAuditReportCommand.php m marker 4
src/Command/SearchReindexCommand.php e exception 2
src/Controller/Api/SavedSearchController.php s savedSearch 3
src/Controller/Api/SubscriptionController.php sub subscription 6
src/Controller/Api/TagController.php t tag 2
src/Entity/Subscription.php st subscriptionTag 5
src/Entity/Subscription.php a left 2
src/Entity/Subscription.php b right 2
src/Http/AdminUserJson.php a left 2
src/Http/AdminUserJson.php b right 2
src/Http/EntryJson.php e entry 15
src/Http/EntryPage.php r row 2
src/Http/FeedPreviewJson.php i item 9
src/Http/ReaderJson.php r result 12
src/Http/SubscriptionJson.php sub subscription 10
src/Repository/EntryScopePredicates.php a aliases 21
src/Controller/Api/SubscriptionController.php subscriptions subscriber 4
src/Controller/Api/SubscriptionController.php subscriptionRepo subscriptions 5
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/b1.rows
php var/refactor-1172/rename-names.php var/refactor-1172/b1.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/b1.rows 2>&1 | grep ' found' | grep -c -v ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `21`; `1` (the one row that re-finds hits: `SubscriptionController.php: 4 hits of subscriptions expected, 5 found`, because `$subscriptions` now names the repository).

- [ ] **Step 3: Wrap the five lines the rename lengthened**

In `src/Command/ReaderAuditCommand.php`, replace
```php
        $shardEntries = (new AuditShardModel($this->number($input, 'shard'), $this->number($input, 'shards')))->pick($sample);
```
with
```php
        $shard = new AuditShardModel($this->number($input, 'shard'), $this->number($input, 'shards'));
        $shardEntries = $shard->pick($sample);
```
and replace
```php
            \count(array_unique(array_map(static fn (SampledEntryModel $sampledEntry): int => $sampledEntry->feedId, $sample))),
```
with
```php
            \count(array_unique(array_map(
                static fn (SampledEntryModel $sampledEntry): int => $sampledEntry->feedId,
                $sample,
            ))),
```
In `src/Controller/Api/SavedSearchController.php`, replace
```php
                static fn (SavedSearch $savedSearch) => SavedSearchJson::one($savedSearch, $tallies[$savedSearch->requireId()]),
```
with
```php
                static fn (SavedSearch $savedSearch) => SavedSearchJson::one(
                    $savedSearch,
                    $tallies[$savedSearch->requireId()],
                ),
```
In `src/Entity/Subscription.php`, replace
```php
            static fn (SubscriptionTag $left, SubscriptionTag $right): int => $left->getPosition() <=> $right->getPosition(),
```
with
```php
            static fn (SubscriptionTag $left, SubscriptionTag $right): int
                => $left->getPosition() <=> $right->getPosition(),
```
In `src/Http/AdminUserJson.php`, replace
```php
        usort($ordered, static fn (Subscription $left, Subscription $right): int => $left->getPosition() <=> $right->getPosition());
```
with
```php
        usort(
            $ordered,
            static fn (Subscription $left, Subscription $right): int
                => $left->getPosition() <=> $right->getPosition(),
        );
```
Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 4: Read the controller**

```bash
git grep -n -P '\$(this->)?(subscriber|subscriptions)\b' -- src/Controller/Api/SubscriptionController.php
```
Expected: `SubscriptionService $subscriber` and `SubscriptionRepository $subscriptions` in the constructor; `$this->subscriber->subscribe(`, `->unsubscribeAll(` and `->unsubscribe(`; `$this->subscriptions->findForUserWithTags(` and three `$this->subscriptions->getOneForUser(`.

- [ ] **Step 5: Gates and commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Controller tests/Http tests/Entity tests/Repository tests/Command && composer check && composer md`
Expected: PASS.
```bash
git add src
git commit -m "refactor(#1172): controllers, mappers, the entity and the commands name what they hold"
```

---

### Task B2: The Doctrine listeners' event parameters

**Files:**
- Modify: `src/Doctrine/MySqlCollationSchemaListener.php`, `src/Doctrine/ViewedImpliesHiddenListener.php`

Both listeners are registered by `#[AsDoctrineListener]` and override nothing, so their `$args` become `$event`. The overrides D-13 keeps (`SqliteConnectionSetupDriver::connect(array $params)`, `AccountStatusException::__unserialize(array $data)`) are not touched.

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/b2.rows`:
```text
src/Doctrine/MySqlCollationSchemaListener.php args event 2
src/Doctrine/ViewedImpliesHiddenListener.php args event 2
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/b2.rows
php var/refactor-1172/rename-names.php var/refactor-1172/b2.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/b2.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `2`; `2`.

- [ ] **Step 3: The kept overrides are untouched (D-13)**

```bash
git grep -n -P 'function __unserialize\(array \$data\)|function connect\(#\[SensitiveParameter\] array \$params\)' -- src
```
Expected: two lines, `AccountStatusException.php:38` and `SqliteConnectionSetupDriver.php:34` (the positive control that the rule's skip, not a rename, handles them).

- [ ] **Step 4: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Doctrine && composer check && composer md`
Expected: PASS.
```bash
git add src
git commit -m "refactor(#1172): the Doctrine listeners name their event"
```

---

### Task B3: `RecommendationDrainCommand::refreshOrReacquireLock()`

**Files:**
- Modify: `src/Command/RecommendationDrainCommand.php`

- [ ] **Step 1: The rename (D-8)**

The three mentions: the `LOCK_TTL_SECONDS` docblock line `     * TTL alone. That's the same lapse, not a new failure: keepsHoldingTheLock()`, the call `            if (!$this->keepsHoldingTheLock($lock)) {` and the declaration `    private function keepsHoldingTheLock(LockInterface $lock): bool`.
```bash
perl -pi -e 's/\bkeepsHoldingTheLock\b/refreshOrReacquireLock/g' src/Command/RecommendationDrainCommand.php
git grep -c -P '\brefreshOrReacquireLock\b' -- src/Command/RecommendationDrainCommand.php
git grep -n -P 'keepsHoldingTheLock' -- src tests ../docs ':!../docs/superpowers'
```
Expected: `src/Command/RecommendationDrainCommand.php:3`; then nothing (the first grep is the positive control).

- [ ] **Step 2: Gates and commit**

Run: `php bin/phpunit tests/Command/RecommendationDrainCommandTest.php && composer check && composer md`
Expected: PASS.
```bash
git add src/Command/RecommendationDrainCommand.php
git commit -m "refactor(#1172): the drain names the lock renewal it performs"
```

---

### Task B4: `NormalizedLoginRateLimiter::normalizeLastUsername()`

**Files:**
- Modify: `src/Security/NormalizedLoginRateLimiter.php`

- [ ] **Step 1: The mutation gets a command's shape (D-7)**

Replace
```php
    public function consume(Request $request): RateLimit
    {
        return $this->inner->consume($this->normalize($request));
    }

    public function peek(Request $request): RateLimit
    {
        return $this->inner->peek($this->normalize($request));
    }

    public function reset(Request $request): void
    {
        $this->inner->reset($this->normalize($request));
    }

    private function normalize(Request $request): Request
    {
        $identifier = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);

        if (\is_string($identifier)) {
            $request->attributes->set(
                SecurityRequestAttributes::LAST_USERNAME,
                User::normalizeEmail($identifier),
            );
        }

        return $request;
    }
```
with
```php
    public function consume(Request $request): RateLimit
    {
        $this->normalizeLastUsername($request);

        return $this->inner->consume($request);
    }

    public function peek(Request $request): RateLimit
    {
        $this->normalizeLastUsername($request);

        return $this->inner->peek($request);
    }

    public function reset(Request $request): void
    {
        $this->normalizeLastUsername($request);
        $this->inner->reset($request);
    }

    private function normalizeLastUsername(Request $request): void
    {
        $identifier = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);
        if (!\is_string($identifier)) {
            return;
        }

        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, User::normalizeEmail($identifier));
    }
```
The class docblock ("MUTATING THE REQUEST IS DELIBERATE") stays: it is why the method exists.

- [ ] **Step 2: The pins hold**

Run: `php bin/phpunit tests/Controller/Api/LoginTest.php`
Expected: PASS, `testPaddedIdentifiersShareOneThrottleBucket`, `testPaddedIdentifierCannotEscapeAnExhaustedBucket` and `testPaddedIdentifierWithTheCorrectPasswordStillLogsIn` among them.

- [ ] **Step 3: Deletion check**

In `peek()`, delete the line `        $this->normalizeLastUsername($request);` (and the blank line after it). Run: `php bin/phpunit tests/Controller/Api/LoginTest.php --filter testPaddedIdentifierCannotEscapeAnExhaustedBucket`. Expected: FAIL, `Failed asserting that the Response status code is 429.` (the padded spelling peeks at a fresh bucket and gets a 401). Restore by hand.

- [ ] **Step 4: Gates and commit**

Run: `composer check && composer md`
Expected: PASS.
```bash
git add src/Security/NormalizedLoginRateLimiter.php
git commit -m "refactor(#1172): the login limiter normalises the last username in a method named for it"
```

---

### Task B5: `PasskeyChallengeProblems`

**Files:**
- Create: `src/Http/Problem/ExceptionProblems/PasskeyChallengeProblems.php`
- Modify: `src/Http/Problem/ExceptionProblems/PasskeyRegistrationProblems.php`

- [ ] **Step 1: The arm leaves the registration mapper (D-11)**

In `PasskeyRegistrationProblems.php`, delete the import `use App\Service\Passkey\Exception\UnknownChallengeException;` and the arm
```php
            $exception instanceof UnknownChallengeException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_challenge',
                'Unknown or expired passkey challenge',
                Response::HTTP_BAD_REQUEST,
            )),
```

- [ ] **Step 2: Watch the contract fail**

Run: `bin/console cache:clear && php bin/phpunit tests/Http/Problem/ProblemContractTest.php --filter 'unknown passkey challenge'`
Expected: FAIL, `Failed asserting that 500 is identical to 400.`

- [ ] **Step 3: Its own mapper**

`src/Http/Problem/ExceptionProblems/PasskeyChallengeProblems.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\Passkey\Exception\UnknownChallengeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PasskeyChallengeProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UnknownChallengeException => new ResolvedProblem(new ApiProblem(
                'unknown_passkey_challenge',
                'Unknown or expired passkey challenge',
                Response::HTTP_BAD_REQUEST,
            )),
            default => null,
        };
    }
}
```
Run: `bin/console cache:clear && php bin/phpunit tests/Http/Problem`
Expected: PASS. Step 2 was this mapper's deletion check: its FAIL is the one to quote.

- [ ] **Step 4: Gates and commit**

Run: `bin/console lint:container && composer check && composer md`
Expected: PASS (`ServiceRoleRule` accepts a mapper beside its interface, as the 19 others are).
```bash
git add src/Http/Problem/ExceptionProblems
git commit -m "refactor(#1172): an unknown passkey challenge has a problem mapper of its own"
```

---

### Finishing PR B

- [ ] **Step 1: The survey** (bash)

```bash
SCOPE='src/Command src/Controller src/Doctrine src/Dto src/Entity src/EventListener src/Http src/Repository src/Security'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress $SCOPE 2>&1 | grep -c 'Name reveals intent'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src/Service 2>&1 | grep -c 'Name reveals intent'
```
Expected: `0`; then `305` at `0863e373` (the positive control: PRs C and D's names).

- [ ] **Step 2: The gates on the whole branch** (Global Constraints).
- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 4: /simplify**; commit `refactor(#1172): simplify pass` if anything changed.
- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. Attack points:
1. **Each new name says what the value is** (D-2, D-3, D-10): read every renamed declaration in the diff, the `SubscriptionController` pair above all.
2. **`NormalizedLoginRateLimiter`** behaves as before on all three paths: `consume()`, `peek()` and `reset()` each normalise before delegating, on the same request object.
3. **The problem contract** is byte-identical for `UnknownChallengeException`, and no other exception changed mapper.
4. **Deletion checks:** re-run B4's; quote the FAIL.

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K) with `var/refactor-1172/pr-b-body.md`:
```markdown
Refs #1172 (PR B of six).

- Controllers, the `Http` mappers, the `Subscription` entity, `EntryScopePredicates` and the commands name what they hold: no one-letter closure parameter, comparator operand or caught exception is left outside `src/Service` (`$exception`, `$left`/`$right`, `$aliases`, `$subscriptionTag`, `$shardEntries`).
- `SubscriptionController` holds `SubscriptionService $subscriber` and `SubscriptionRepository $subscriptions` (was `$subscriptions` and `$subscriptionRepo`).
- The Doctrine listeners take `$event`. Overrides of methods declared outside `App` keep their parent's parameter names (`AccountStatusException::__unserialize(array $data)`, `SqliteConnectionSetupDriver::connect(array $params)`): named arguments bind to them, and `AbbreviatedNameRule` skips them.
- An unknown passkey challenge is answered by its own `PasskeyChallengeProblems` mapper; the response is unchanged.

Renamed methods:
- `RecommendationDrainCommand::keepsHoldingTheLock()` → `refreshOrReacquireLock()`: refreshes the drain lock, re-bids when the refresh fails, and answers whether this process still holds it.
- `NormalizedLoginRateLimiter::normalize()` → `normalizeLastUsername()`: writes the normalised identifier into the request's last-username attribute and returns nothing; `consume()`, `peek()` and `reset()` (Symfony's interface names) call it before delegating.

No behaviour change.
```
Title: `refactor(#1172): names outside the service layer say what they hold`.
- [ ] **Step 7: Merge when green**, then `gh issue view 1172 --json state --jq .state`. Expected: `OPEN`.

---

# PR C — The service modules A to M

The scope is the 21 module directories `src/Service/[A-M]*` (`Account` to `Maintenance`).

### Task C0: Preflight (PR B merged)

- [ ] **Step 1: The checkout, the branch, the tools (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --search 'refactor(#1172): names outside the service layer' --json number --jq length
git switch -c refactor/1172-service-names-a-m origin/develop
ls backend/var/refactor-1172/rename-names.php backend/var/refactor-1172/names.neon
```
Expected: a clean tree; `1`; the branch; both paths.

- [ ] **Step 2: The positive control (from `backend/`, in bash)**

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src/Service/[A-M]* 2>&1 | grep -c 'Name reveals intent'
```
Expected: `101` at `0863e373` (after PRs A and B).

---

### Task C1: Caught exceptions in the modules A–M

**Files:**
- Modify: the 21 files of the rows below

Every row but one renames a caught exception to `$exception` (D-2). `FetchException::from()` walks the `getPrevious()` chain, so its variable is `$cause`. In `FaviconFetcher` the two private helpers that take the caught `\Throwable $e` take `\Throwable $exception` too.

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/c1.rows`:
```text
src/Service/Ai/AiProviderConfigurator.php e exception 2
src/Service/Ai/Completion/ChatCompletionClient/OpenAiCompatibleChatClient.php e exception 2
src/Service/Ai/ModelCatalog/OpenAiCompatibleCatalog.php e exception 2
src/Service/Backup/Pass/RestoreEntryLoader.php e exception 4
src/Service/Backup/Pass/RestoreLoadPass.php e exception 2
src/Service/Catalog/CatalogDocument.php e exception 3
src/Service/Catalog/CatalogUrlChecker.php e exception 3
src/Service/Comments/CommentsLoader.php e exception 2
src/Service/Discovery/FeedDiscovery/FeedDiscovery.php e exception 2
src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php e exception 11
src/Service/Fetch/Exception/FetchException.php e cause 6
src/Service/Fetch/FailoverRequestSender.php e exception 3
src/Service/Fetch/FaviconResolver/FaviconResolver.php e exception 2
src/Service/Fetch/RedirectFollower.php e exception 9
src/Service/Fetch/ResponseClassifier.php e exception 4
src/Service/Image/FaviconFetcher/FaviconFetcher.php e exception 10
src/Service/Logging/Loki/LokiSpoolShipper.php e exception 2
src/Service/Mail/Digest/DigestImageEmbedder/DigestImageEmbedder.php e exception 2
src/Service/Mail/Digest/SendDueDigests.php e exception 3
src/Service/Mail/Settings/MailConnectionTester.php e exception 5
src/Service/Mail/Transport/DynamicMailTransport.php e exception 9
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/c1.rows
php var/refactor-1172/rename-names.php var/refactor-1172/c1.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/c1.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `21`; `21`.

- [ ] **Step 3: Wrap the three lines the rename lengthened**

In `src/Service/Mail/Transport/DynamicMailTransport.php`, replace
```php
            throw new TransportException('The mail configuration is incomplete: ' . $exception->getMessage(), previous: $exception);
```
with
```php
            throw new TransportException(
                'The mail configuration is incomplete: ' . $exception->getMessage(),
                previous: $exception,
            );
```
replace
```php
            throw new TransportException('The stored proxy password is unreadable: ' . $exception->getMessage(), previous: $exception);
```
with
```php
            throw new TransportException(
                'The stored proxy password is unreadable: ' . $exception->getMessage(),
                previous: $exception,
            );
```
and replace
```php
            throw new TransportException('The stored mail password is unreadable: ' . $exception->getMessage(), 0, $exception);
```
with
```php
            throw new TransportException(
                'The stored mail password is unreadable: ' . $exception->getMessage(),
                0,
                $exception,
            );
```
Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 4: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Ai tests/Service/Backup tests/Service/Catalog tests/Service/Comments tests/Service/Discovery tests/Service/Fetch tests/Service/Image tests/Service/Logging tests/Service/Mail && composer check && composer md`
Expected: PASS.
```bash
git add src/Service
git commit -m "refactor(#1172): caught exceptions are \$exception in the modules A to M"
```

---

### Task C2: Truncations in the modules A–M; `SubscriptionTagReference`

**Files:**
- Modify: `src/Service/Auth/AltchaService.php`, `src/Service/Backup/Pass/BackupTally.php`, `src/Service/Backup/Pass/RestoreLoadPass.php`, `src/Service/Fetch/FaviconResolver/FaviconResolver.php`, `src/Service/Mail/AccountMailer/AccountMailer.php`, `src/Service/Mail/Digest/SendDueDigests.php`, `src/Service/Backup/Dto/SubscriptionLine.php`
- Rename: `src/Service/Backup/Dto/SubscriptionTagRef.php` → `src/Service/Backup/Dto/SubscriptionTagReference.php`

`SendDueDigests` holds `DigestRecipientsInterface $preferences` and loops `as $prefs`; the interface property becomes `$recipients` (D-10: named for what it holds), which frees `$preferences` for the loop's `Preferences` row. The rows run in that order.

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/c2.rows`:
```text
src/Service/Auth/AltchaService.php params queryParameters 2
src/Service/Backup/Pass/BackupTally.php ref tagReference 3
src/Service/Backup/Pass/RestoreLoadPass.php ref tagReference 3
src/Service/Fetch/FaviconResolver/FaviconResolver.php dom document 3
src/Service/Fetch/FaviconResolver/FaviconResolver.php m matches 2
src/Service/Mail/AccountMailer/AccountMailer.php params translationParameters 3
src/Service/Mail/Digest/SendDueDigests.php preferences recipients 2
src/Service/Mail/Digest/SendDueDigests.php prefs preferences 12
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/c2.rows
php var/refactor-1172/rename-names.php var/refactor-1172/c2.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/c2.rows 2>&1 | grep ' found' | grep -c -v ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `8`; `1` (`SendDueDigests.php: 2 hits of preferences expected, 12 found`: `$preferences` now names the loop's row).

- [ ] **Step 3: `SubscriptionTagReference` (D-15)**

```bash
git mv src/Service/Backup/Dto/SubscriptionTagRef.php src/Service/Backup/Dto/SubscriptionTagReference.php
perl -pi -e 's/\bSubscriptionTagRef\b/SubscriptionTagReference/g' src/Service/Backup/Dto/SubscriptionTagReference.php src/Service/Backup/Dto/SubscriptionLine.php
git grep -c -P '\bSubscriptionTagReference\b' -- src tests
git grep -n -P '\bSubscriptionTagRef\b' -- src tests ../docs ':!../docs/superpowers'
```
Expected: `src/Service/Backup/Dto/SubscriptionLine.php:3` and `src/Service/Backup/Dto/SubscriptionTagReference.php:1`; then nothing.

- [ ] **Step 4: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Auth tests/Service/Backup tests/Service/Fetch tests/Service/Mail tests/Service/Maintenance && composer check && composer md`
Expected: PASS.
```bash
git add src/Service
git commit -m "refactor(#1172): truncated names in the modules A to M are spelled out"
```

---

### Task C3: Repository properties in the modules A–M (D-10)

**Files:**
- Modify: the 8 files of the rows below

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/c3.rows`:
```text
src/Service/Ai/AiConfigurationForUser.php repository aiProviderSettings 2
src/Service/Ai/AiProviderConfigurator.php repository aiProviderSettings 4
src/Service/Grafana/EffectiveGrafanaSettings.php repository storedSettings 2
src/Service/Grafana/GrafanaSettings.php repository storedSettings 2
src/Service/Image/ImageVerificationSweep.php repository pendingVerifications 2
src/Service/Ingest/EntryIngestor.php entryRepository entries 4
src/Service/Mail/MailSendingSettings/EffectiveMailSettings.php repository mailServerSettings 4
src/Service/Mail/Settings/MailSettings.php repository mailServerSettings 4
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/c3.rows
php var/refactor-1172/rename-names.php var/refactor-1172/c3.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/c3.rows 2>&1 | grep -c ' 0 found'
git grep -n -P '(private|protected|public)( readonly)? [\w\\]+ \$(repository|\w+Repo(sitory)?)\b' -- src/Service/[A-M]*
git grep -n -P 'private (readonly )?[\w\\]+ \$repository\b' -- src/Service/Passkey/PasskeyCredentials.php
```
Expected: `Every row holds; nothing written (--check).`; `8`; `8`; nothing; then `PasskeyCredentials.php:31` (the positive control: D5 renames it).

- [ ] **Step 3: Gates and commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Ai tests/Service/Grafana tests/Service/Image tests/Service/Ingest tests/Service/Mail && composer check && composer md`
Expected: PASS.
```bash
git add src/Service
git commit -m "refactor(#1172): repository properties in the modules A to M are named for what they hold"
```

---

### Finishing PR C

- [ ] **Step 1: The survey** (bash)

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src/Service/[A-M]* 2>&1 | grep -c 'Name reveals intent'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src/Service/[N-Z]* 2>&1 | grep -c 'Name reveals intent'
```
Expected: `0`; then `204` at `0863e373` (the positive control: PR D's names).

- [ ] **Step 2: The gates on the whole branch** (Global Constraints).
- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 4: /simplify**; commit `refactor(#1172): simplify pass` if anything changed.
- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff -M origin/develop...HEAD`. Attack points:
1. **Each new name says what the value is:** `$cause`, `$tagReference`, `$queryParameters`, `$translationParameters`, `$recipients`, `$storedSettings`, `$pendingVerifications`.
2. **No docblock sentence was made wrong** by the script's rename inside comments.
3. **`SubscriptionTagReference`** is the only change in its file but the name (`git diff -M` shows a rename with one changed line).

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K) with `var/refactor-1172/pr-c-body.md`:
```markdown
Refs #1172 (PR C of six).

- The modules `Account` to `Maintenance` name what they hold: every caught exception is `$exception` (`$cause` where `FetchException::from()` walks the chain), and `$params`, `$ref`, `$dom`, `$m` and `$prefs` are spelled out.
- `SendDueDigests` holds `DigestRecipientsInterface $recipients` (was `$preferences`), so its loop names each row `$preferences`.
- Eight properties that held a repository are named for what they hold (`$aiProviderSettings`, `$storedSettings`, `$pendingVerifications`, `$entries`, `$mailServerSettings`).
- `Backup\Dto\SubscriptionTagRef` is `SubscriptionTagReference`.

No behaviour change.
```
Title: `refactor(#1172): the service modules A to M name what they hold`.
- [ ] **Step 7: Merge when green**, then `gh issue view 1172 --json state --jq .state`. Expected: `OPEN`.

---

# PR D — The service modules N to Z

The scope is the 26 module directories `src/Service/[N-Z]*` (`OAuth` to `Worker`), plus the one SPA constant the marker's file name reaches (D3).

### Task D0: Preflight (PR C merged)

- [ ] **Step 1: The checkout, the branch, the tools (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --search 'refactor(#1172): the service modules A to M' --json number --jq length
git switch -c refactor/1172-service-names-n-z origin/develop
ls backend/var/refactor-1172/rename-names.php backend/var/refactor-1172/names.neon
```
Expected: a clean tree; `1`; the branch; both paths.

- [ ] **Step 2: The positive control (from `backend/`, in bash)**

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src/Service/[N-Z]* 2>&1 | grep -c 'Name reveals intent'
```
Expected: `204` at `0863e373` (after PRs A–C).

---

### Task D1: Caught exceptions and one-letter closures in the modules N–Z

**Files:**
- Modify: the 33 files of the rows below

`FeedPreviewService` names both a caught exception and three `ParsedEntryModel` closure parameters `$e`; Step 1 renames the closures by hand so the rows can rename the rest to `$exception`. `MeilisearchIndex`'s `$r` → `$result` is marked `shadow`: the arrow function's parameter lives beside a later closure's own `$result` parameter in the same method and reads nothing outside itself.

- [ ] **Step 1: The three entry closures in `FeedPreviewService`**

In `src/Service/Preview/FeedPreviewService.php`, replace
```php
        $tiers = array_map(fn (ParsedEntryModel $e): string => $this->tier($e), $sample);
```
with
```php
        $tiers = array_map(fn (ParsedEntryModel $entry): string => $this->tier($entry), $sample);
```
replace
```php
        $items = array_map(fn (ParsedEntryModel $e): FeedPreviewItemModel => $this->item($e), $displayed);
```
with
```php
        $items = array_map(fn (ParsedEntryModel $entry): FeedPreviewItemModel => $this->item($entry), $displayed);
```
and replace
```php
                fn (ParsedEntryModel $e): bool => $this->httpsImageUrl($e->media->image) !== null,
```
with
```php
                fn (ParsedEntryModel $entry): bool => $this->httpsImageUrl($entry->media->image) !== null,
```

- [ ] **Step 2: The rows**

Write to `var/refactor-1172/d1.rows`:
```text
src/Service/OAuth/Factory/AppleClientSecretFactory.php e exception 2
src/Service/OAuth/OAuthSignIn.php e exception 2
src/Service/OAuth/Oidc/Model/IdTokenClaimsModel.php e exception 2
src/Service/OAuth/Oidc/Pass/TokenEndpoint.php e exception 2
src/Service/Preview/FeedPreviewService.php e exception 5
src/Service/Proxy/ProxyConnectionTester.php e exception 4
src/Service/Reader/BodyCleaning/BodyCleaningStep/SubstackPosterLink.php m matches 2
src/Service/Reader/HtmlPageFetcher.php e exception 6
src/Service/Reader/Media/EmbedProvider/SoundCloudEmbedProvider.php m matches 2
src/Service/Reader/Media/EmbedProvider/YouTubeEmbedProvider.php m matches 2
src/Service/Reader/Media/MediaRelevance.php a left 2
src/Service/Reader/Media/MediaRelevance.php b right 2
src/Service/Reader/Media/MediaRelevance.php w word 3
src/Service/Reader/Media/Model/ArticleMediaModel.php c candidate 4
src/Service/Reader/Media/Model/MediaInsertionPlanModel.php c candidate 2
src/Service/Reader/Slideshow/Model/ContainerSignatureModel.php t token 2
src/Service/ReaderAudit/Model/AuditFindingModel.php m marker 2
src/Service/ReaderAudit/Model/AuditFindingsModel.php f finding 6
src/Service/ReaderAudit/Model/AuditFindingsModel.php a left 5
src/Service/ReaderAudit/Model/AuditFindingsModel.php b right 5
src/Service/ReaderAudit/Pass/AuditReportHtml.php m marker 2
src/Service/Recommendation/Run/ProviderPhase/BatchPhase.php e exception 2
src/Service/Recommendation/Run/RecommendationBatchWave.php e exception 3
src/Service/Recommendation/Run/RecommendationProviderCall.php e exception 3
src/Service/Recommendation/Run/RecommendationRunAdvancer.php e exception 5
src/Service/Recommendation/Run/TickPhases.php e exception 5
src/Service/Refresh/FeedBodyParser.php e exception 5
src/Service/Refresh/FeedOutcomePersister.php e exception 8
src/Service/Refresh/RefreshRunner/RefreshRunner.php e exception 2
src/Service/Scraper/HtmlItemExtractor.php e exception 2
src/Service/Search/EntryIndexer.php e exception 4
src/Service/Search/EntrySearch/EntrySearchWithFallback.php e exception 2
src/Service/Search/Index/SearchIndexReader/MeilisearchIndex.php r result 2 shadow
src/Service/Search/Index/SearchIndexReader/MeilisearchIndex.php e exception 2
src/Service/Search/Membership/SavedSearchMembershipSweep.php e exception 4
src/Service/Search/SavedSearchTallies.php s savedSearch 2
src/Service/Subscription/FeedTagMove.php a left 4
src/Service/Subscription/FeedTagMove.php b right 4
src/Service/Worker/WorkerRunSweep.php e exception 4
```

- [ ] **Step 3: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/d1.rows
php var/refactor-1172/rename-names.php var/refactor-1172/d1.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/d1.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `39`; `39`.

- [ ] **Step 4: Wrap the 13 lines the rename lengthened**

In `src/Service/Reader/Media/MediaRelevance.php`, replace
```php
            fn (string $left, string $right): int => $this->score($right, $slugTokens) <=> $this->score($left, $slugTokens),
```
with
```php
            fn (string $left, string $right): int
                => $this->score($right, $slugTokens) <=> $this->score($left, $slugTokens),
```
In `src/Service/Reader/Media/Model/ArticleMediaModel.php`, replace
```php
        return new self(array_values(
            array_filter($this->candidates, static fn (MediaCandidateModel $candidate): bool => $candidate->kind !== MediaKind::Embed)
        ));
```
with
```php
        return new self(array_values(array_filter(
            $this->candidates,
            static fn (MediaCandidateModel $candidate): bool => $candidate->kind !== MediaKind::Embed,
        )));
```
and replace
```php
        return new self(array_values(
            array_filter($this->candidates, static fn (MediaCandidateModel $candidate): bool => $candidate->kind !== MediaKind::Stream)
        ));
```
with
```php
        return new self(array_values(array_filter(
            $this->candidates,
            static fn (MediaCandidateModel $candidate): bool => $candidate->kind !== MediaKind::Stream,
        )));
```
In `src/Service/Reader/Media/Model/MediaInsertionPlanModel.php`, replace
```php
        return array_any($this->topPlaced, static fn (MediaCandidateModel $candidate): bool => $candidate->kind->readsAsLeadVisual());
```
with
```php
        return array_any(
            $this->topPlaced,
            static fn (MediaCandidateModel $candidate): bool => $candidate->kind->readsAsLeadVisual(),
        );
```
In `src/Service/Reader/Slideshow/Model/ContainerSignatureModel.php`, replace
```php
        return array_values(array_filter(explode(' ', $classAttribute), static fn (string $token): bool => $token !== ''));
```
with
```php
        return array_values(array_filter(
            explode(' ', $classAttribute),
            static fn (string $token): bool => $token !== '',
        ));
```
In `src/Service/ReaderAudit/Model/AuditFindingsModel.php`, replace
```php
        usort($flagged, static fn (AuditFindingModel $left, AuditFindingModel $right): int => $right->score() <=> $left->score());
```
with
```php
        usort(
            $flagged,
            static fn (AuditFindingModel $left, AuditFindingModel $right): int => $right->score() <=> $left->score(),
        );
```
replace
```php
        return \count(array_filter($this->findings, static fn (AuditFindingModel $finding): bool => $finding->extracted));
```
with
```php
        return \count(array_filter(
            $this->findings,
            static fn (AuditFindingModel $finding): bool => $finding->extracted,
        ));
```
and replace
```php
        return \count(array_unique(array_map(static fn (AuditFindingModel $finding): int => $finding->feedId, $this->findings)));
```
with
```php
        return \count(array_unique(array_map(
            static fn (AuditFindingModel $finding): int => $finding->feedId,
            $this->findings,
        )));
```
In `src/Service/ReaderAudit/Pass/AuditReportHtml.php`, replace
```php
        foreach ($findings->tally(static fn (CleanupMarkerModel $marker): string => $marker->suspect) as $suspect => $count) {
```
with
```php
        $bySuspect = $findings->tally(static fn (CleanupMarkerModel $marker): string => $marker->suspect);
        foreach ($bySuspect as $suspect => $count) {
```
In `src/Service/Refresh/FeedOutcomePersister.php`, replace
```php
        } catch (UniqueConstraintViolationException | ForeignKeyConstraintViolationException | ORMException $exception) {
```
with (the multi-line catch `FaviconFetcher` already uses)
```php
        } catch (
            UniqueConstraintViolationException |
            ForeignKeyConstraintViolationException |
            ORMException $exception
        ) {
```
In `src/Service/Search/Index/SearchIndexReader/MeilisearchIndex.php`, replace
```php
        $results = array_values(array_filter($decoded['results'], static fn (mixed $result): bool => \is_array($result)));
```
with
```php
        $results = array_values(array_filter(
            $decoded['results'],
            static fn (mixed $result): bool => \is_array($result),
        ));
```
In `src/Service/Subscription/FeedTagMove.php`, replace
```php
            static fn (SubscriptionTag $left, SubscriptionTag $right): int => $left->getPosition() <=> $right->getPosition(),
```
with
```php
            static fn (SubscriptionTag $left, SubscriptionTag $right): int
                => $left->getPosition() <=> $right->getPosition(),
```
and replace
```php
        usort($untagged, static fn (Subscription $left, Subscription $right): int => $left->getPosition() <=> $right->getPosition());
```
with
```php
        usort(
            $untagged,
            static fn (Subscription $left, Subscription $right): int
                => $left->getPosition() <=> $right->getPosition(),
        );
```
Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 5: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/OAuth tests/Service/Preview tests/Service/Proxy tests/Service/Reader tests/Service/ReaderAudit tests/Service/Recommendation tests/Service/Refresh tests/Service/Scraper tests/Service/Search tests/Service/Subscription tests/Service/Worker && composer check && composer md`
Expected: PASS.
```bash
git add src/Service
git commit -m "refactor(#1172): caught exceptions and closure parameters name what they hold in the modules N to Z"
```

---

### Task D2: `AbstractAtomParser` reads `namespaceUri()`; `$atomNamespace` (D-17)

**Files:**
- Modify: `src/Service/Parser/FeedFormatParser/AbstractAtomParser.php`, `src/Service/Parser/Support/AtomDiscussion.php`, `src/Service/Parser/ItemImageExtractor.php`

- [ ] **Step 1: The parser drops the threaded parameter**

Four substitutions, in this order: drop the `string $ns` parameter from the six private methods (`parseEntry`, `authorUri`, `firstDate`, `alternateLink`, `authorName`, `elementMarkup`); drop the `$ns` argument from the calls to them; delete the line `        $ns = $this->namespaceUri();` in `parse()`; and read `$this->namespaceUri()` wherever `$ns` was passed on (to `XmlHelper`, `FeedImageExtractor`, `$this->imageSelector->fromAtom()`, `AtomDiscussion`) or compared (`$child->namespaceURI`).
```bash
git grep -c -P '(?<![0-9\\$])\$ns\b' -- src/Service/Parser/FeedFormatParser/AbstractAtomParser.php
perl -pi -e 's/, string \$ns(?=[,)])//g; s/(\$this->(?:parseEntry|authorUri|firstDate|alternateLink|authorName|elementMarkup)\([^()]*?), \$ns(?=[,)])/$1/g; $_ = "" if /^\s+\$ns = \$this->namespaceUri\(\);\n$/; s/(?<![0-9\\\$])\$ns\b/\$this->namespaceUri()/g' src/Service/Parser/FeedFormatParser/AbstractAtomParser.php
git grep -c -P '(?<![0-9\\$])\$ns\b' -- src/Service/Parser/FeedFormatParser/AbstractAtomParser.php
git grep -c -F '$this->namespaceUri()' -- src/Service/Parser/FeedFormatParser/AbstractAtomParser.php
git grep -n -P 'private function \w+\(\\DOMElement \$\w+(, string \$localName)?\)' -- src/Service/Parser/FeedFormatParser/AbstractAtomParser.php | wc -l
```
Expected: `src/Service/Parser/FeedFormatParser/AbstractAtomParser.php:31` (the positive control); then nothing; then `…AbstractAtomParser.php:17`; then `6`. At `0863e373` the result reads, for `parse()`: `$title = XmlHelper::childText($root, 'title', $this->namespaceUri());`, `&& $child->namespaceURI === $this->namespaceUri()`, `$entry = $this->parseEntry($child);`, `$this->alternateLink($root),`; and for `parseEntry()`: `$contentHtml = $this->elementMarkup($entry, 'content');`, `$this->namespaceUri(),` as `fromAtom()`'s second argument, `[$contentHtml, $this->elementMarkup($entry, 'summary')],`, `author: $this->authorName($entry),`, `publishedAt: DateParser::parse($this->firstDate($entry)),`, `discussion: AtomDiscussion::from($entry, $this->namespaceUri()),`, `authorUrl: $this->authorUri($entry),`.

- [ ] **Step 2: Wrap the one line it lengthened**

In `authorUri()`, replace
```php
        return $author === null ? null : AbsoluteHttpUrl::orNull(XmlHelper::childText($author, 'uri', $this->namespaceUri()));
```
with
```php
        return $author === null
            ? null
            : AbsoluteHttpUrl::orNull(XmlHelper::childText($author, 'uri', $this->namespaceUri()));
```

- [ ] **Step 3: The static helpers' parameter**

Write to `var/refactor-1172/d2.rows`:
```text
src/Service/Parser/Support/AtomDiscussion.php ns atomNamespace 4
src/Service/Parser/ItemImageExtractor.php ns atomNamespace 2
```

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/d2.rows
php var/refactor-1172/rename-names.php var/refactor-1172/d2.rows | wc -l
git grep -n -P '(?<![0-9\\$])\$ns\b' -- src tests ':!tests/PhpStan/data'
git grep -c -F 'string $atomNamespace' -- src/Service/Parser/Support/FeedImageExtractor.php
```
Expected: `Every row holds; nothing written (--check).`; `2`; nothing; `src/Service/Parser/Support/FeedImageExtractor.php:1` (the name it matches, the positive control).

- [ ] **Step 4: Gates and commit**

Run: `composer cs && php bin/phpunit tests/Service/Parser tests/Service/Refresh && composer check && composer md`
Expected: PASS.
```bash
git add src/Service/Parser
git commit -m "refactor(#1172): the Atom parser reads its namespace instead of threading it"
```

---

### Task D3: The change marker: `$temporaryFile`, `$payload`, `change-marker.json` (D-5, D-6)

**Files:**
- Modify: `src/Service/Refresh/ContentChangeMarker/ContentChangeMarker.php`, `tests/Service/Refresh/ContentChangeMarker/ContentChangeMarkerTest.php`, `../frontend/src/app/reader/sidebar-counts-poll.service.ts`, `../frontend/src/app/reader/sidebar-counts-poll.service.spec.ts`

- [ ] **Step 1: The tests name the new file**

```bash
perl -pi -e 's/counts\.json/change-marker.json/g' tests/Service/Refresh/ContentChangeMarker/ContentChangeMarkerTest.php
git grep -c -F 'change-marker.json' -- tests/Service/Refresh/ContentChangeMarker/ContentChangeMarkerTest.php
```
Expected: `…ContentChangeMarkerTest.php:3` (lines 77, 100 and 120 at `0863e373`).

Run: `php bin/phpunit tests/Service/Refresh/ContentChangeMarker/ContentChangeMarkerTest.php`
Expected: FAIL; `testLeavesNoTempFileBesideTheMarker` fails with `Failed asserting that two arrays are identical.` (the directory holds `counts.json`), and the tests that read `markerPath()` error on the missing file. This is the backend's deletion check: the FAIL to quote.

- [ ] **Step 2: The writer**

In `src/Service/Refresh/ContentChangeMarker/ContentChangeMarker.php`, replace
```php
        $this->writeAtomically($directory, $directory . '/counts.json', $this->payload());
```
with
```php
        $this->writeAtomically($directory, $directory . '/change-marker.json', $this->payload());
```
and replace
```php
    private function writeAtomically(string $directory, string $target, string $token): void
    {
        $temp = @tempnam($directory, 'counts');
        if (false === $temp) {
            $this->logger->warning('Change marker: no temp file in {directory}', ['directory' => $directory]);

            return;
        }
        if (false !== @file_put_contents($temp, $token) && @chmod($temp, 0644) && @rename($temp, $target)) {
            return;
        }
        @unlink($temp);
        $this->logger->warning('Change marker: cannot write {target}', ['target' => $target]);
    }
```
with
```php
    private function writeAtomically(string $directory, string $target, string $payload): void
    {
        $temporaryFile = @tempnam($directory, 'change-marker');
        if (false === $temporaryFile) {
            $this->logger->warning('Change marker: no temp file in {directory}', ['directory' => $directory]);

            return;
        }
        if (
            false !== @file_put_contents($temporaryFile, $payload)
            && @chmod($temporaryFile, 0644)
            && @rename($temporaryFile, $target)
        ) {
            return;
        }
        @unlink($temporaryFile);
        $this->logger->warning('Change marker: cannot write {target}', ['target' => $target]);
    }
```
Run: `php bin/phpunit tests/Service/Refresh/ContentChangeMarker/ContentChangeMarkerTest.php`
Expected: PASS.

- [ ] **Step 3: The SPA reads the new path (from the repository root)**

In `frontend/src/app/reader/sidebar-counts-poll.service.spec.ts`, replace
```ts
      expect(fetchMock).toHaveBeenCalledWith('https://api.test/state/counts.json', {
```
with
```ts
      expect(fetchMock).toHaveBeenCalledWith('https://api.test/state/change-marker.json', {
```
Run: `docker compose exec -T frontend npm test -- sidebar-counts-poll`
Expected: FAIL in `reads the marker off disk, not through HttpClient`, `expect(jest.fn()).toHaveBeenCalledWith(...expected)`. This is the SPA's deletion check: the FAIL to quote.

In `frontend/src/app/reader/sidebar-counts-poll.service.ts`, replace
```ts
const CHANGE_MARKER_PATH = '/state/counts.json';
```
with
```ts
const CHANGE_MARKER_PATH = '/state/change-marker.json';
```
Run: `docker compose exec -T frontend npm test -- sidebar-counts-poll`, then (after it finishes) `docker compose exec -T frontend npm run check`.
Expected: PASS; PASS.

- [ ] **Step 4: No `counts.json` is left**

```bash
git grep -n -F 'counts.json' -- src tests ../frontend/src ../docs ':!../docs/superpowers'
git grep -c -F 'change-marker.json' -- src ../frontend/src
```
Expected: nothing; then `src/Service/Refresh/ContentChangeMarker/ContentChangeMarker.php:1`, `../frontend/src/app/reader/sidebar-counts-poll.service.spec.ts:1`, `../frontend/src/app/reader/sidebar-counts-poll.service.ts:1`.

- [ ] **Step 5: Gates and commit (from the repository root)**

Run (from `backend/`): `composer check && composer md`
Expected: PASS.
```bash
git add backend/src/Service/Refresh backend/tests/Service/Refresh frontend/src/app/reader
git commit -m "refactor(#1172): the change marker is change-marker.json, written from a payload through a temporary file"
```

---

### Task D4: Truncations in the modules N–Z; `extraAuthorizationParameters()`; `$flagCounts`; no `$_`

**Files:**
- Modify: the 15 files of the rows below; `src/Http/SubscriptionCountsJson.php`, `tests/Service/Subscription/SubscriptionTallyReaderTest.php`, `src/Service/Reader/ExtractionCoverageGate.php`

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/d4.rows`:
```text
src/Service/OAuth/OAuthProvider/AbstractOidcProvider.php params queryParameters 2
src/Service/Opml/OpmlExporter.php subs subscriptions 5
src/Service/Opml/OpmlExporter.php doc document 13
src/Service/Opml/OpmlExporter.php sub subscription 11
src/Service/Reader/Media/EmbedProvider/BrightcoveEmbedProvider.php params queryParameters 2
src/Service/Reader/Media/EmbedProvider/SoundCloudEmbedProvider.php params queryParameters 2
src/Service/Reader/Media/EmbedProvider/VimeoEmbedProvider.php params queryParameters 2
src/Service/Reader/Media/EmbedProvider/YouTubeEmbedProvider.php params queryParameters 2
src/Service/Reader/Slideshow/SlideshowRecognizer/TagesschauCarouselRecognizer.php data carouselConfig 7
src/Service/ReaderAudit/Model/AuditShardModel.php mine shardEntries 3
src/Service/Scraper/HtmlItemExtractor.php doc document 11
src/Service/Scraper/ScrapeLayer/ClusterLayer.php doc document 2
src/Service/Scraper/ScrapeLayer/JsonLdLayer.php doc document 2
src/Service/Scraper/ScrapeLayer/ScrapeLayerInterface.php doc document 1
src/Service/Scraper/ScrapeLayer/SemanticLayer.php doc document 2
src/Service/Subscription/Model/SubscriptionTalliesModel.php flags flagCounts 2
src/Service/OAuth/OAuthProvider/AbstractOidcProvider.php extraAuthorizationParams extraAuthorizationParameters 2
src/Service/OAuth/OAuthProvider/AppleOAuthProvider.php extraAuthorizationParams extraAuthorizationParameters 1
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/d4.rows
php var/refactor-1172/rename-names.php var/refactor-1172/d4.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/d4.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `18`; `18`.

- [ ] **Step 3: What the script does not reach**

In `src/Service/OAuth/OAuthProvider/AbstractOidcProvider.php`, replace the docblock line
```php
 *   extraAuthorizationParams() adds.
```
with
```php
 *   extraAuthorizationParameters() adds.
```
The model's readers (D-18):
```bash
perl -pi -e 's/->flags\[/->flagCounts[/g' src/Http/SubscriptionCountsJson.php
perl -pi -e 's/\$tallies->flags\)/\$tallies->flagCounts)/' tests/Service/Subscription/SubscriptionTallyReaderTest.php
git grep -n -P '->flags\b' -- src tests
git grep -c -F '->flagCounts' -- src/Http/SubscriptionCountsJson.php tests/Service/Subscription/SubscriptionTallyReaderTest.php
```
Expected: nothing; then `src/Http/SubscriptionCountsJson.php:3` and `tests/Service/Subscription/SubscriptionTallyReaderTest.php:1`.

In `src/Service/Reader/ExtractionCoverageGate.php`, replace
```php
        foreach ($feedShingles as $shingle => $_) {
```
with
```php
        foreach (array_keys($feedShingles) as $shingle) {
```
```bash
git grep -n -P 'extraAuthorizationParams\b|(?<![0-9\\$])\$_\b' -- src tests ':!tests/PhpStan/data'
git grep -c -P 'extraAuthorizationParameters\(' -- src
```
Expected: nothing; then `src/Service/OAuth/OAuthProvider/AbstractOidcProvider.php:3` and `src/Service/OAuth/OAuthProvider/AppleOAuthProvider.php:1`.

- [ ] **Step 4: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/OAuth tests/Service/Opml tests/Service/Reader tests/Service/ReaderAudit tests/Service/Scraper tests/Service/Subscription tests/Http tests/Command && composer check && composer md`
Expected: PASS.
```bash
git add src tests
git commit -m "refactor(#1172): truncated names in the modules N to Z are spelled out"
```

---

### Task D5: Repository properties in the modules N–Z; `$gitHubRepository` (D-10)

**Files:**
- Modify: the 9 files of the rows below; `config/services.yaml`

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/d5.rows`:
```text
src/Service/Passkey/PasskeyCredentials.php repository passkeys 3
src/Service/Proxy/ConfiguredProxySource/StoredProxy.php repository storedSettings 3
src/Service/Proxy/ProxySettings.php repository storedSettings 3
src/Service/Recommendation/Settings/RecommendationSettingsWriter.php repository recommendationSettings 2
src/Service/Refresh/FeedOutcomePersister.php feedRepository feeds 2
src/Service/Refresh/RefreshRunner/RefreshRunner.php feedRepository feeds 3
src/Service/Settings/InstanceSettings.php repository storedSettings 3
src/Service/Version/LatestReleaseReader/GitHubLatestReleaseReader.php repository gitHubRepository 3
tests/Service/Version/LatestReleaseReader/GitHubLatestReleaseReaderTest.php repository gitHubRepository 2
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/d5.rows
php var/refactor-1172/rename-names.php var/refactor-1172/d5.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/d5.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `9`; `9`.

- [ ] **Step 3: The GitHub slug's wiring and its one named argument**

In `config/services.yaml`, replace
```yaml
    # $repository is set here rather than as a `bind`: a binding matches by
    # parameter NAME, and `$repository` is a name a Doctrine-shaped service could
    # easily reuse, so a global bind would leak this string into unrelated code.
    App\Service\Version\LatestReleaseReader\GitHubLatestReleaseReader:
        arguments:
            $repository: '%github_release_repository%'
```
with
```yaml
    App\Service\Version\LatestReleaseReader\GitHubLatestReleaseReader:
        arguments:
            $gitHubRepository: '%github_release_repository%'
```
In `tests/Service/Version/LatestReleaseReader/GitHubLatestReleaseReaderTest.php`, replace
```php
        $latest = $this->reader($client, repository: '')->read();
```
with
```php
        $latest = $this->reader($client, gitHubRepository: '')->read();
```

- [ ] **Step 4: Wrap the two lines the rename lengthened**

In `src/Service/Proxy/ProxySettings.php`, replace
```php
        return ProxySettingsSnapshotModel::fromEntity($this->storedSettings->findSingleton() ?? new ProxyServerSettings());
```
with
```php
        return ProxySettingsSnapshotModel::fromEntity(
            $this->storedSettings->findSingleton() ?? new ProxyServerSettings(),
        );
```
In `tests/Service/Version/LatestReleaseReader/GitHubLatestReleaseReaderTest.php`, replace
```php
    private function reader(MockHttpClient $client, string $gitHubRepository = self::REPOSITORY): GitHubLatestReleaseReader
    {
```
with
```php
    private function reader(
        MockHttpClient $client,
        string $gitHubRepository = self::REPOSITORY,
    ): GitHubLatestReleaseReader {
```

- [ ] **Step 5: No property holds `repository` in its name**

```bash
git grep -n -P '(private|protected|public)( readonly)? [\w\\]+ \$(repository|\w+Repo(sitory)?)\b' -- src
git grep -n -P '(private|protected|public)( readonly)? [\w\\]+ \$(repository|\w+Repo(sitory)?)\b' -- tests/Repository/FeedRepositoryTest.php
```
Expected: one line, `src/Service/Version/LatestReleaseReader/GitHubLatestReleaseReader.php:35:        private string $gitHubRepository,` (a GitHub slug, D-10); then `tests/Repository/FeedRepositoryTest.php:16:    private FeedRepository $repository;` (the positive control: E2 renames it).

- [ ] **Step 6: Gates and commit**

Run: `composer cs && bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Passkey tests/Service/Proxy tests/Service/Recommendation/Settings tests/Service/Refresh tests/Service/Settings tests/Service/Version && composer check && composer md`
Expected: PASS.
```bash
git add src tests config/services.yaml
git commit -m "refactor(#1172): repository properties in the modules N to Z are named for what they hold"
```

---

### Task D6: `RelyingPartyChangeGuard` (D-9)

**Files:**
- Rename: `src/Service/Settings/RelyingPartyChange.php` → `src/Service/Settings/RelyingPartyChangeGuard.php`; `tests/Service/Settings/RelyingPartyChangeTest.php` → `tests/Service/Settings/RelyingPartyChangeGuardTest.php`
- Modify: `src/Controller/Admin/AdminSettingsController.php`, `src/Entity/InstanceSetting.php`, `src/Repository/UserPasskeyRepository.php`, `src/Service/Settings/PasskeyRelyingParty/PasskeyRelyingPartyInterface.php`

- [ ] **Step 1: The class and its test move**

```bash
git mv src/Service/Settings/RelyingPartyChange.php src/Service/Settings/RelyingPartyChangeGuard.php
git mv tests/Service/Settings/RelyingPartyChangeTest.php tests/Service/Settings/RelyingPartyChangeGuardTest.php
perl -pi -e 's/\bRelyingPartyChangeTest\b/RelyingPartyChangeGuardTest/g; s/\bRelyingPartyChange\b/RelyingPartyChangeGuard/g' src/Service/Settings/RelyingPartyChangeGuard.php tests/Service/Settings/RelyingPartyChangeGuardTest.php src/Controller/Admin/AdminSettingsController.php src/Entity/InstanceSetting.php src/Repository/UserPasskeyRepository.php src/Service/Settings/PasskeyRelyingParty/PasskeyRelyingPartyInterface.php
git grep -c -P '\bRelyingPartyChangeGuard(Test)?\b' -- src tests
```
Expected: `AdminSettingsController.php:2`, `InstanceSetting.php:1`, `UserPasskeyRepository.php:1`, `PasskeyRelyingPartyInterface.php:2`, `RelyingPartyChangeGuard.php:1`, `RelyingPartyChangeGuardTest.php:4`. `RelyingPartyChangeRequiresConfirmationException` is untouched: `\b` does not end inside a word.

- [ ] **Step 2: The property and the test helper**

Write to `var/refactor-1172/d6.rows`:
```text
src/Controller/Admin/AdminSettingsController.php relyingPartyChange relyingPartyChangeGuard 2
tests/Service/Settings/RelyingPartyChangeGuardTest.php change guard 16
```
```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/d6.rows
php var/refactor-1172/rename-names.php var/refactor-1172/d6.rows | wc -l
git grep -n -P '\bRelyingPartyChange\b|\$relyingPartyChange\b' -- src tests ../frontend/src ../docs ':!../docs/superpowers'
git grep -c -F 'RelyingPartyChangeRequiresConfirmationException' -- src tests ../frontend/src
git grep -n -P 'function change\(' -- tests/Service/Settings tests/Service/Reading/EntryStateUpdaterTest.php
```
Expected: `Every row holds; nothing written (--check).`; `2`; nothing; seven files still name the exception (five in `src` and `tests`, two in the SPA): the first grep's positive control; one line, `tests/Service/Reading/EntryStateUpdaterTest.php:59:    private function change(` (an unrelated helper that stays: the positive control that no `change()` is left in `tests/Service/Settings`).

- [ ] **Step 3: Gates and commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Settings tests/Controller/Admin/AdminSettingsControllerTest.php && composer check && composer md`
Expected: PASS.
```bash
git add src tests
git commit -m "refactor(#1172): the relying-party change guard is named for what it does"
```

---

### Finishing PR D

- [ ] **Step 1: The survey** (bash)

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src 2>&1 | grep -c 'Name reveals intent'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress tests 2>&1 | grep -c 'Name reveals intent'
```
Expected: `0` (all of `src` is done); then `1089` at `0863e373` (the positive control: PRs E and F).

- [ ] **Step 2: The gates on the whole branch** (Global Constraints), and the frontend gate, because this PR touches the SPA: `docker compose exec -T frontend npm run check` (ESLint, Prettier, Stylelint and Jest), run in the container, never natively. Expected: PASS.
- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 4: /simplify**; commit `refactor(#1172): simplify pass` if anything changed.
- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff -M origin/develop...HEAD`. Attack points:
1. **`AbstractAtomParser`** parses exactly as before: every former `$ns` reads `$this->namespaceUri()`, no call to `XmlHelper`, `FeedImageExtractor`, `fromAtom()` or `AtomDiscussion` lost its namespace argument, and the six private methods lost only theirs.
2. **The marker transition** (D-5): the backend writes and the SPA reads the same path; an old tab degrades to fetching every tick (`readMarker()` returns `null` on a 404), never to a stuck badge.
3. **`FeedPreviewService`**: the three closures take `$entry` and the two catches `$exception`; no closure reads a caught exception.
4. **Deletion checks:** re-run D3's backend and SPA checks; quote both FAILs.

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K) with `var/refactor-1172/pr-d-body.md`:
```markdown
Refs #1172 (PR D of six).

- The modules `OAuth` to `Worker` name what they hold: caught exceptions are `$exception`, closure parameters and comparator operands are named for the element (`$candidate`, `$finding`, `$marker`, `$left`/`$right`), and `$doc`, `$params`, `$data`, `$sub`, `$subs`, `$mine`, `$m` are spelled out.
- `AbstractAtomParser`'s private methods read `$this->namespaceUri()` instead of threading `$ns`; the static helpers take `$atomNamespace`.
- The change marker is `public/state/change-marker.json` (was `counts.json`, which held a timestamp), written from `$payload` through `$temporaryFile`; the SPA reads the new path.
- **Transition, for one deploy:** a tab opened before the deploy still polls `/state/counts.json`, gets a 404, and falls back to fetching the counts on every tick (its documented fallback, the behaviour before #720) until it reloads. Nothing breaks; that tab only costs one counts request per tick until then.
- `SubscriptionTalliesModel::$flags` is `$flagCounts`; `ExtractionCoverageGate` iterates `array_keys()` instead of binding `$_`.
- Eight properties that held a repository are named for what they hold; `GitHubLatestReleaseReader` takes `$gitHubRepository` (the `owner/name` slug).
- `Settings\RelyingPartyChange` is `RelyingPartyChangeGuard`.

Renamed method: `AbstractOidcProvider::extraAuthorizationParams()` → `extraAuthorizationParameters()`, the provider's extra query parameters on the authorization URL (Apple overrides it).
```
Title: `refactor(#1172): the service modules N to Z name what they hold; the change marker names its file`.
- [ ] **Step 7: Merge when green**, then `gh issue view 1172 --json state --jq .state`. Expected: `OPEN`.

---

# PR E — The tests outside `tests/Service`

The scope is every entry of `tests/` but `tests/Service`: `Command`, `Controller`, `Doctrine`, `Dto`, `E2e`, `Entity`, `EventListener`, `Http`, `PhpStan` (its `data/` fixtures stay out, as `composer stan` leaves them out), `Repository`, `Security`, `Support` and the top-level files (`bootstrap.php`, `DbTestCase.php` and the others).

### Task E0: Preflight (PR D merged)

- [ ] **Step 1: The checkout, the branch, the tools (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --search 'refactor(#1172): the service modules N to Z' --json number --jq length
git switch -c refactor/1172-test-names origin/develop
ls backend/var/refactor-1172/rename-names.php backend/var/refactor-1172/names.neon
```
Expected: a clean tree; `1`; the branch; both paths.

- [ ] **Step 2: The positive control (from `backend/`, in bash)**

```bash
SCOPE=$(ls -d tests/* | grep -v '^tests/Service$')
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress $SCOPE 2>&1 | grep -c 'Name reveals intent'
```
Expected: `466` at `0863e373` (after PRs A–D; the rule keeps `QueryRecorderDriver::connect(array $params)`, D-13).

---

### Task E1: Names in the tests outside `tests/Service`

**Files:**
- Modify: the 51 files of the rows below

Names follow D-2, D-3 and D-12: `$sub` → `$subscription`, `$repo` → `$repository` (and `repo()` → `repository()`), a `for` counter → `$index` (`$attempt` in `OAuthFlowTest`, where the loop counts start attempts; `$tick` in `RecommendationRunControllerTest`; `$number` in `DatabaseSavedSearchMatcherTest`, where it numbers the searches), a response body decoded from JSON → `$responseBody`, `MoveFeedToTagTest`'s `$x`/`$y` (subscriptions at the drop index's either side, not coordinates) → `$ahead`/`$behind`, `ReorderTest`'s tags `$a`/`$b`/`$c` → `$alpha`/`$beta`/`$gamma` (their names), fixture labels (`$s1`, `$t1`, `$e1`) → ordinals or roles.

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/e1.rows`:
```text
tests/Command/ReaderAuditCommandTest.php i index 4
tests/Command/SearchReindexCommandTest.php i index 4
tests/Controller/Admin/AdminUserControllerTest.php i index 22
tests/Controller/Api/AiSettingsControllerTest.php i index 3
tests/Controller/Api/ClientErrorControllerTest.php i index 3
tests/Controller/Api/EntryControllerTest.php sub subscription 49
tests/Controller/Api/EntryControllerTest.php i index 13
tests/Controller/Api/EntryControllerTest.php e entry 3
tests/Controller/Api/EntryControllerTest.php strangerSub strangerSubscription 4
tests/Controller/Api/EntrySearchControllerTest.php sub subscription 3
tests/Controller/Api/EntrySearchControllerTest.php i index 8
tests/Controller/Api/MoveFeedToTagTest.php x ahead 2
tests/Controller/Api/MoveFeedToTagTest.php y behind 2
tests/Controller/Api/MoveFeedToTagTest.php sub subscription 5
tests/Controller/Api/MoveFeedToTagTest.php data responseBody 4
tests/Controller/Api/MoveFeedToTagTest.php tagPos tagPosition 2
tests/Controller/Api/OAuthFlowTest.php i attempt 4
tests/Controller/Api/OnboardingControllerTest.php i index 4
tests/Controller/Api/PasskeyRegistrationTest.php data payload 4
tests/Controller/Api/RecommendationRunControllerTest.php i tick 3
tests/Controller/Api/RefreshControllerTest.php sub subscription 3
tests/Controller/Api/ReorderTest.php sub subscription 16
tests/Controller/Api/ReorderTest.php data responseBody 8
tests/Controller/Api/ReorderTest.php a alpha 10
tests/Controller/Api/ReorderTest.php b beta 8
tests/Controller/Api/ReorderTest.php c gamma 6
tests/Controller/Api/ReorderTest.php s1 firstSubscription 9
tests/Controller/Api/ReorderTest.php s2 secondSubscription 9
tests/Controller/Api/ReorderTest.php s3 thirdSubscription 9
tests/Controller/Api/ReorderTest.php tagPos tagPosition 2
tests/Controller/Api/SubscriptionBulkTest.php i index 12
tests/Controller/Api/SubscriptionBulkTest.php s subscription 6
tests/Controller/Api/SubscriptionControllerTest.php i index 4
tests/Controller/Api/SubscriptionControllerTest.php sub subscription 14
tests/E2e/ReaderJourneyE2eTest.php e entry 3
tests/E2e/ReaderJourneyE2eTest.php subId subscriptionId 10
tests/E2e/ReaderJourneyE2eTest.php readResp readResponse 3
tests/E2e/ReaderJourneyE2eTest.php unreadResp unreadResponse 3
tests/E2e/ReaderJourneyE2eTest.php favIds favoriteIds 2
tests/E2e/Support/E2eTestCase.php m matches 2
tests/Entity/SubscriptionTagsTest.php sub subscription 7
tests/Http/SubscriptionJsonTest.php sub subscription 14
tests/PhpStan/DataShapes.php data dataClass 5
tests/Repository/CatalogCategoryRepositoryTest.php c category 2
tests/Repository/CatalogCategoryRepositoryTest.php f feed 2
tests/Repository/CatalogFeedRepositoryTest.php f feed 6
tests/Repository/DatabaseSavedSearchMatcherTest.php i number 5
tests/Repository/DuplicateCollapseTest.php r row 2
tests/Repository/DuplicateCollapseTest.php repo repository 14
tests/Repository/DuplicateCollapseTest.php sub subscription 3
tests/Repository/EntryBatchInserterTest.php i index 5
tests/Repository/EntryListTest.php sub subscription 12
tests/Repository/EntryListTest.php e entry 9
tests/Repository/EntryListTest.php repo repository 65
tests/Repository/EntryListTest.php r row 5
tests/Repository/EntryListTest.php fav favorite 2
tests/Repository/EntryListTest.php favs favorites 4
tests/Repository/EntryListTest.php s1 favoriteState 3
tests/Repository/EntryListTest.php s2 keptState 3
tests/Repository/EntryListTest.php e1 firstTied 2
tests/Repository/EntryListTest.php e2 secondTied 2
tests/Repository/EntryListTest.php e3 thirdTied 2
tests/Repository/EntryListTest.php t1 firstEntry 16
tests/Repository/EntryListTest.php t2 secondEntry 14
tests/Repository/EntryListTest.php t3 thirdEntry 8
tests/Repository/EntryListTest.php t4 fourthEntry 6
tests/Repository/EntryListTest.php excludedSub excludedSubscription 5
tests/Repository/EntryListTest.php otherSub otherSubscription 8
tests/Repository/EntryRowsByIdsTest.php repo repository 8
tests/Repository/EntrySearchTest.php repo repository 5
tests/Repository/EntryStateRepositoryTest.php repo repository 21
tests/Repository/FeedRepositoryTagScopeTest.php repo repository 8
tests/Repository/FeedRepositoryTagScopeTest.php f feed 2
tests/Repository/FeedRepositoryTagScopeTest.php strangerSub strangerSubscription 3
tests/Repository/FeedRepositoryTagScopeTest.php taggedSub taggedSubscription 3
tests/Repository/FeedRepositoryUserFeedScopeTest.php sub subscription 4
tests/Repository/FeedRepositoryUserFeedScopeTest.php repo repository 9
tests/Repository/PreferencesRepositoryTest.php repo repository 5
tests/Repository/RecommendationFeedTest.php sub subscription 8
tests/Repository/RecommendationFeedTest.php repo repository 21
tests/Repository/RecommendationFeedTest.php customSub customSubscription 3
tests/Repository/RecommendationFeedTest.php excludedSub excludedSubscription 3
tests/Repository/RecommendationFeedTest.php untitledSub untitledSubscription 2
tests/Repository/RecommendationFeedTest.php entryFav favoriteEntry 3
tests/Repository/RecommendationFeedTest.php favState favoriteState 3
tests/Repository/RecommendationRunRepositoryTest.php i index 3
tests/Repository/RowIdsTest.php a first 2
tests/Repository/RowIdsTest.php b second 2
tests/Repository/SavedSearchMembershipReadsTest.php s savedSearch 2
tests/Repository/SavedSearchMembershipReadsTest.php repo repository 22
tests/Repository/SavedSearchMembershipSweepRepositoriesTest.php s savedSearch 2
tests/Repository/SavedSearchRepositoryTest.php repo repository 14
tests/Repository/StateCountsTest.php repo repository 9
tests/Repository/StateCountsTest.php g guid 3
tests/Repository/StateCountsTest.php e entry 3
tests/Repository/StateCountsTest.php fav favorite 3
tests/Repository/SubscriptionEntryCountsTest.php sub subscription 4
tests/Repository/SubscriptionEntryCountsTest.php repo repository 7
tests/Repository/SubscriptionEntryCountsTest.php firstSub firstSubscription 3
tests/Repository/SubscriptionEntryCountsTest.php secondSub secondSubscription 3
tests/Repository/SubscriptionPositionAndCountsTest.php s subscription 4
tests/Repository/SubscriptionRepositoryTest.php repo repository 7
tests/Repository/TagRepositoryTest.php repo repository 6
tests/Repository/UnreadCountsTest.php repo repository 8
tests/Repository/UnreadCountsTest.php sub subscription 7
tests/Repository/UnreadCountsTest.php g guid 4
tests/Repository/UnreadCountsTest.php d publishedDay 2
tests/Repository/UnreadCountsTest.php e entry 4
tests/Repository/UnreadCountsTest.php st entryState 3
tests/Repository/UnreadCountsTest.php subA subscriptionA 6
tests/Repository/UnreadCountsTest.php subAId subscriptionAId 3
tests/Repository/UnreadCountsTest.php subB subscriptionB 6
tests/Repository/UnreadCountsTest.php subBId subscriptionBId 2
tests/Repository/UnreadMatchingEntryIdsForUserTest.php repo repository 5
tests/Repository/ViewedAtSinceTest.php repo repository 9
tests/Support/BackupArchiveReader.php i index 4
tests/Support/PasskeyFixtures.php ec ellipticCurve 3
tests/Support/StubChatClient.php e exception 2
tests/bootstrap.php jwtDir jwtDirectory 7
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/e1.rows
php var/refactor-1172/rename-names.php var/refactor-1172/e1.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/e1.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `119`; `119`.

- [ ] **Step 3: Wrap the 36 lines the rename lengthened**

Wrap each line of the table (Appendix W rules). Line numbers are at `0863e373`, before this plan's earlier steps moved any line (an anchor, not a contract); the text is the line after the rename.

| File | Line at `0863e373` | The line after the rename |
|---|---|---|
| `tests/Controller/Api/EntryControllerTest.php` | 479 | `$entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 519 | `$entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 560 | `$entries = $entityManager->getRepository(Entry::class)->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 597 | `$entries = $entityManager->getRepository(Entry::class)->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 649 | `$entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 681 | `$entry = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed(), 'guid' => 'g1']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 712 | `$entries = $entityManager->getRepository(Entry::class)->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);` |
| `tests/Controller/Api/EntryControllerTest.php` | 792 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 818 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 847 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 874 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 905 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 1041 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 1139 | `$entryId = $entityManager->getRepository(Entry::class)->findOneBy(['feed' => $subscription->getFeed()])?->getId();` |
| `tests/Controller/Api/EntryControllerTest.php` | 1351 | `$entries = $entityManager->getRepository(Entry::class)->findBy(['feed' => $subscription->getFeed()], ['guid' => 'ASC']);` |
| `tests/Controller/Api/MoveFeedToTagTest.php` | 113 | `private function makeSub(User $user, string $url, int $position, ?Tag $tag = null, int $tagPosition = 0): Subscription` |
| `tests/Controller/Api/ReorderTest.php` | 50 | `private function makeSub(User $user, string $url, int $position, ?Tag $tag = null, int $tagPosition = 0): Subscription` |
| `tests/Controller/Api/ReorderTest.php` | 103 | `$this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$gamma->getId(), $alpha->getId(), $beta->getId()]]);` |
| `tests/Controller/Api/ReorderTest.php` | 131 | `$this->patch($client, $user, '/api/tags/reorder', ['tagIds' => [$gamma->getId(), $alpha->getId(), $beta->getId()]]);` |
| `tests/Controller/Api/ReorderTest.php` | 206 | `'subscriptionIds' => [$thirdSubscription->getId(), $firstSubscription->getId(), $secondSubscription->getId()],` |
| `tests/Controller/Api/ReorderTest.php` | 234 | `'subscriptionIds' => [$thirdSubscription->getId(), $firstSubscription->getId(), $secondSubscription->getId()],` |
| `tests/Controller/Api/ReorderTest.php` | 275 | `'subscriptionIds' => [$thirdSubscription->getId(), $firstSubscription->getId(), $secondSubscription->getId()],` |
| `tests/Controller/Api/SubscriptionBulkTest.php` | 290 | `'subscriptionIds' => array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $subscriptions),` |
| `tests/Controller/Api/SubscriptionBulkTest.php` | 340 | `'subscriptionIds' => array_map(static fn (Subscription $subscription): int => $subscription->requireId(), $subscriptions),` |
| `tests/E2e/ReaderJourneyE2eTest.php` | 250 | `$entries = $this->getJson('/api/entries?subscription=' . $subscriptionId, $token)->toArray()['entries'] ?? null;` |
| `tests/PhpStan/DataShapes.php` | 58 | `private static function dataViolations(ServiceRoleMap $map, ServiceRoleClass $dataClass, ServiceRoleCheck $check): array` |
| `tests/Repository/EntryListTest.php` | 47 | `$this->subscription = new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));` |
| `tests/Repository/EntryListTest.php` | 413 | `$favorites = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Favorites));` |
| `tests/Repository/EntryListTest.php` | 600 | `$excludedSubscription = new Subscription($this->user, $excludedFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));` |
| `tests/Repository/EntryListTest.php` | 634 | `$favorites = $this->repository()->listForUser(new EntryQuery($this->user->requireId(), view: EntryView::Favorites));` |
| `tests/Repository/FeedRepositoryTagScopeTest.php` | 67 | `$strangerSubscription = new Subscription($stranger, $strangerFeed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));` |
| `tests/Repository/RecommendationFeedTest.php` | 37 | `$this->subscription = new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));` |
| `tests/Repository/RecommendationFeedTest.php` | 320 | `$customSubscription = new Subscription($this->user, $customFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));` |
| `tests/Repository/RecommendationFeedTest.php` | 347 | `$untitledSubscription = new Subscription($this->user, $untitledFeed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));` |
| `tests/Repository/SavedSearchMembershipReadsTest.php` | 299 | `savedSearchIds: array_map(static fn (SavedSearch $savedSearch): int => $savedSearch->requireId(), $searches),` |
| `tests/Repository/UnreadCountsTest.php` | 36 | `foreach ([['a', '2026-07-05'], ['b', '2026-07-20'], ['c', '2026-07-21'], ['d', '2026-07-22']] as [$guid, $publishedDay]) {` |

Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 4: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Command tests/Controller tests/Entity tests/Http tests/PhpStan tests/Repository tests/Security && composer check`
Expected: PASS.
```bash
git add tests
git commit -m "refactor(#1172): the tests outside tests/Service name what they hold"
```

---

### Task E2: Test helpers and repository properties outside `tests/Service`

**Files:**
- Modify: `tests/Repository/EntryListTest.php`, `tests/Controller/Api/MoveFeedToTagTest.php`, `tests/Controller/Api/ReorderTest.php`, `tests/Controller/Api/SubscriptionBulkTest.php`, `tests/Repository/CategoryRepositoryTest.php`, `tests/Repository/FeedRepositoryTest.php`, `tests/Repository/SubscriptionPositionAndCountsTest.php`, `tests/Repository/UserIdentityRepositoryTest.php`

`repoWithWindow()` → `repositoryWithWindow()` and `makeSub()` → `makeSubscription()` (method rows: the script renames `function old(` and `$this->old(`); the four test properties that hold the repository under test take its plural (D-10), and so does the `setUp()` local that fills each.

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/e2.rows`:
```text
tests/Repository/EntryListTest.php repoWithWindow repositoryWithWindow 11
tests/Controller/Api/MoveFeedToTagTest.php makeSub makeSubscription 5
tests/Controller/Api/ReorderTest.php makeSub makeSubscription 15
tests/Controller/Api/SubscriptionBulkTest.php makeSub makeSubscription 22
tests/Repository/CategoryRepositoryTest.php repository categories 9
tests/Repository/FeedRepositoryTest.php repository feeds 23
tests/Repository/SubscriptionPositionAndCountsTest.php repository subscriptions 9
tests/Repository/UserIdentityRepositoryTest.php repository identities 14
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/e2.rows
php var/refactor-1172/rename-names.php var/refactor-1172/e2.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/e2.rows 2>&1 | grep -c ' 0 found'
git grep -n -P 'repoWithWindow|makeSub\b|(private|protected|public)( readonly)? [\w\\]+ \$(repository|repo|\w+Repo)\b' -- tests
git grep -n -P 'private RecommendationSettingsRepository \$settingsRepository' -- tests
```
Expected: `Every row holds; nothing written (--check).`; `8`; `8`; nothing; `tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php:28:…` (the positive control: F2 renames it).

- [ ] **Step 3: Gates and commit**

Run: `php bin/phpunit tests/Repository tests/Controller/Api/MoveFeedToTagTest.php tests/Controller/Api/ReorderTest.php tests/Controller/Api/SubscriptionBulkTest.php && composer check`
Expected: PASS.
```bash
git add tests
git commit -m "refactor(#1172): test helpers and repository properties outside tests/Service are spelled out"
```

---

### Finishing PR E

- [ ] **Step 1: The survey** (bash)

```bash
SCOPE=$(ls -d tests/* | grep -v '^tests/Service$')
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress $SCOPE 2>&1 | grep -c 'Name reveals intent'
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress tests/Service 2>&1 | grep -c 'Name reveals intent'
```
Expected: `0`; then `623` at `0863e373` (the positive control: PR F's names).

- [ ] **Step 2: The gates on the whole branch** (Global Constraints), and `composer e2e` when this checkout runs the Docker stack (`tests/E2e/ReaderJourneyE2eTest.php` and `tests/E2e/Support/E2eTestCase.php` changed).
- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 4: /simplify**; commit `refactor(#1172): simplify pass` if anything changed.
- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. Attack points:
1. **No test asserts anything new:** the diff changes names and wraps only (a changed string literal is a finding, except inside a renamed interpolation such as `"g$index"`).
2. **Every name says what the value is**, the ordinals and roles (`$firstTied`, `$favoriteState`, `$ahead`/`$behind`) above all.
3. **The four repository properties** hold the repository under test, and each test reads naturally (`$this->feeds->findDue(…)`).

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K) with `var/refactor-1172/pr-e-body.md`:
```markdown
Refs #1172 (PR E of six).

- The tests outside `tests/Service` name what they hold: no one-letter or numbered name is left (`$index`, `$entry`, `$row`, `$subscription`, `$firstSubscription`), and `$sub`, `$repo`, `$data`, `$fav`, `$st`, `$ec`, `$jwtDir` are spelled out; `QueryRecorderDriver::connect(array $params)` keeps Doctrine's parameter name (D-13).
- The test properties holding the repository under test take its plural (`$feeds`, `$categories`, `$subscriptions`, `$identities`).

Renamed test helpers: `repo()` → `repository()`, `repoWithWindow()` → `repositoryWithWindow()`, `makeSub()` → `makeSubscription()`: each returns what its new name says.

No behaviour change.
```
Title: `refactor(#1172): the tests outside tests/Service name what they hold`.
- [ ] **Step 7: Merge when green**, then `gh issue view 1172 --json state --jq .state`. Expected: `OPEN`.

---

# PR F — `tests/Service`; the guard is registered

### Task F0: Preflight (PR E merged)

- [ ] **Step 1: The checkout, the branch, the tools (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh pr list --state merged --search 'refactor(#1172): the tests outside tests/Service' --json number --jq length
git switch -c refactor/1172-service-test-names origin/develop
ls backend/var/refactor-1172/rename-names.php backend/var/refactor-1172/names.neon
```
Expected: a clean tree; `1`; the branch; both paths.

- [ ] **Step 2: The positive control (from `backend/`)**

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress tests/Service 2>&1 | grep -c 'Name reveals intent'
```
Expected: `623` at `0863e373` (after PRs A–E).

---

### Task F1: Names in `tests/Service`

**Files:**
- Modify: the 91 files of the rows below

`ClusterLayerTest` names both a scraped item and a loop counter `$i`; Step 1 renames the item by hand so the rows can make the rest `$index`. Two rows are marked `shadow`: `CommentsLoaderTest` and `HostAgnosticDiscoveryTest` have an arrow-function parameter `$c` in a method whose later `foreach` binds `$comment` or `$candidate`, and the arrow function reads nothing outside itself. `SendDueDigestsHandlerTest` already names its `DigestRecipientsInterface` stub `$preferences`, so its `Preferences` rows become `$duePreferences`. `AbstractOidcProviderTest`'s one `$sub` is in a docblock that quotes an old check on the subject claim; its row makes it `$subject`, the name `IdTokenVerifier` gives that value (D-22, D-reconcile-9).

- [ ] **Step 1: The scraped item in `ClusterLayerTest`**

In `tests/Service/Scraper/ClusterLayerTest.php`, replace
```php
        $withTeaser = array_filter($items, static fn ($i) => $i->teaser !== null);
```
with
```php
        $withTeaser = array_filter($items, static fn ($item) => $item->teaser !== null);
```

- [ ] **Step 2: The rows**

Write to `var/refactor-1172/f1.rows`:
```text
tests/Service/Admin/UserStatisticsTest.php i index 2
tests/Service/Ai/AiProviderConfiguratorTest.php i index 8
tests/Service/Ai/Completion/ChatCompletionClient/OpenAiCompatibleChatClientTest.php e exception 5
tests/Service/Ai/Completion/RateLimitedCompletionTest.php i index 9
tests/Service/Ai/ModelCatalog/OpenAiCompatibleCatalogTest.php m model 4
tests/Service/Backup/AccountBackupExporterTest.php i index 6
tests/Service/Backup/AccountRestorerTest.php e exception 2
tests/Service/Backup/BackupReaderTest.php i index 5
tests/Service/Backup/BackupReaderTest.php e exception 2
tests/Service/Backup/BackupReaderTest.php data contents 3
tests/Service/Backup/Pass/BackupPartBufferTest.php i index 3
tests/Service/Backup/Support/BackupSchemaCoverageTest.php doc documentation 11
tests/Service/Catalog/CatalogFaviconWarmerTest.php f feed 4
tests/Service/Category/CategoryNormalizerTest.php i index 4
tests/Service/Comments/CommentsLoaderTest.php c comment 8 shadow
tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php m method 1
tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php u url 2
tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php o options 1
tests/Service/Fetch/FailoverRequestSenderProxyTest.php m method 8
tests/Service/Fetch/FailoverRequestSenderProxyTest.php u url 8
tests/Service/Fetch/FailoverRequestSenderProxyTest.php o options 18
tests/Service/Fetch/FeedFetcher/HttpFeedFetcherTest.php e exception 5
tests/Service/Fetch/RedirectFollowerTest.php e exception 2
tests/Service/Fetch/ResponseClassifierTest.php e exception 2
tests/Service/Logging/Loki/LokiPushHandlerTest.php m method 5
tests/Service/Logging/Loki/LokiPushHandlerTest.php u url 5
tests/Service/Logging/Loki/LokiPushHandlerTest.php o options 10
tests/Service/Logging/Loki/LokiPushHandlerTest.php i index 3
tests/Service/Logging/Loki/LokiPushHandlerTest.php ts timestamp 2
tests/Service/Logging/Loki/LokiSpoolShipperTest.php i index 4
tests/Service/Mail/AccountMailer/AccountMailerTest.php dir translationsDirectory 3
tests/Service/Mail/AccountMailer/AccountMailerTest.php m mailer 8
tests/Service/Mail/Digest/DigestComposerTest.php i index 6
tests/Service/Mail/Digest/DigestComposerTest.php repo repository 6
tests/Service/Mail/Digest/DigestEnablementTest.php prefs preferences 39
tests/Service/Mail/Digest/DigestEntryFinderTest.php i index 4
tests/Service/Mail/Digest/DigestHtmlRendererTest.php dir translationsDirectory 3
tests/Service/Mail/Digest/DigestMailer/DigestMailerTest.php dir translationsDirectory 3
tests/Service/Mail/Digest/DigestScheduleTest.php prefs preferences 15
tests/Service/Mail/Digest/DigestTextRendererTest.php dir translationsDirectory 3
tests/Service/Mail/Digest/Factory/DigestMailFactoryTest.php dir translationsDirectory 3
tests/Service/Mail/Digest/Factory/DigestPageFactoryTest.php i index 2
tests/Service/Mail/Digest/SendDueDigestsHealthTest.php prefs preferences 10
tests/Service/Mail/Digest/SendDueDigestsHealthTest.php repo repository 6
tests/Service/Mail/Digest/SendDueDigestsTest.php prefs preferences 20
tests/Service/Mail/Digest/SendDueDigestsTest.php repo repository 6
tests/Service/Mail/Digest/SendDueDigestsTest.php duePrefs duePreferences 3
tests/Service/Mail/Digest/SendDueDigestsTest.php failingPrefs failingPreferences 3
tests/Service/Mail/Digest/SendDueDigestsTest.php healthyPrefs healthyPreferences 3
tests/Service/Mail/Digest/SendDueDigestsTest.php notDuePrefs notDuePreferences 3
tests/Service/Mail/Digest/SendTestDigestTest.php dir translationsDirectory 3
tests/Service/Mail/Digest/SendTestDigestTest.php repo repository 6
tests/Service/OAuth/Factory/AppleClientSecretFactoryTest.php e exception 3
tests/Service/OAuth/OAuthProvider/AbstractOidcProviderTest.php e exception 4
tests/Service/OAuth/OAuthProvider/AbstractOidcProviderTest.php data bytes 2
tests/Service/OAuth/OAuthProvider/AbstractOidcProviderTest.php sub subject 1
tests/Service/OAuth/OAuthProvider/GoogleOAuthProviderTest.php data bytes 2
tests/Service/OAuth/OAuthProviderRegistryTest.php e exception 4
tests/Service/OAuth/OAuthStateStoreTest.php a firstFlow 10
tests/Service/OAuth/OAuthStateStoreTest.php b secondFlow 8
tests/Service/OAuth/Oidc/Model/IdTokenClaimsModelTest.php e exception 3
tests/Service/OAuth/Oidc/Model/IdTokenClaimsModelTest.php data bytes 2
tests/Service/OAuth/Oidc/Pass/IdTokenVerifierTest.php e exception 2
tests/Service/OAuth/Oidc/Pass/IdTokenVerifierTest.php data bytes 2
tests/Service/OAuth/Oidc/Pass/TokenEndpointTest.php e exception 2
tests/Service/Opml/OpmlExporterTest.php svc exporter 3
tests/Service/Opml/OpmlExporterTest.php doc document 2
tests/Service/Opml/OpmlExporterTest.php s1 taggedSubscription 3
tests/Service/Opml/OpmlExporterTest.php s2 untaggedSubscription 2
tests/Service/Opml/OpmlImporterTest.php svc importer 3
tests/Service/Opml/OpmlImporterTest.php subs subscriptions 5
tests/Service/Opml/OpmlImporterTest.php i index 4
tests/Service/Parser/FeedFormatParser/Rss1ParserTest.php doc document 3
tests/Service/Parser/Support/DateParserTest.php d date 9
tests/Service/Parser/FeedItemImageSelectorTest.php doc document 6
tests/Service/Parser/Support/FeedMediaClassifierTest.php doc document 3
tests/Service/Parser/Support/ItemCategoryExtractorTest.php doc document 6
tests/Service/Parser/ItemImageExtractorTest.php doc document 3
tests/Service/Parser/ItemMediaExtractorTest.php doc document 6
tests/Service/Passkey/PasskeyOfferTest.php prefs preferences 11
tests/Service/Preview/FeedPreviewServiceTest.php i index 20
tests/Service/Reader/ExtractionCoverageGateTest.php i index 2
tests/Service/Reader/Media/HostAgnosticDiscoveryTest.php c candidate 12 shadow
tests/Service/Reader/Media/MediaUrlKindTest.php a mp3 4
tests/Service/Reader/Media/Model/ArticleMediaModelTest.php c candidate 2
tests/Service/Reader/Media/Model/ArticleMediaModelTest.php n number 2
tests/Service/Reader/Media/PageMediaScannerTest.php c candidate 2
tests/Service/Reader/Media/PageMediaScannerTest.php i index 9
tests/Service/Reader/Media/Sibling/SiblingIdRuleTest.php n number 3
tests/Service/Reader/Model/ImageIdentityModelTest.php a left 2
tests/Service/Reader/Model/ImageIdentityModelTest.php b right 2
tests/Service/ReaderAudit/AuditSamplerTest.php e entry 4
tests/Service/ReaderAudit/Model/AuditFindingsModelTest.php f finding 6
tests/Service/ReaderAudit/Model/AuditFindingsModelTest.php m marker 2
tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php b block 4
tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php l link 2
tests/Service/ReaderAudit/Pass/AuditReportHtmlTest.php f finding 2
tests/Service/ReaderAudit/ReaderAuditRunnerTest.php f finding 2
tests/Service/Reading/EntryStateResolverTest.php repo repository 10
tests/Service/Reading/EntryStateUpdaterTest.php repo repository 3
tests/Service/Reading/MarkReadServiceTest.php svc markReadService 3
tests/Service/Reading/MarkReadServiceTest.php sub subscription 17
tests/Service/Reading/MarkReadServiceTest.php excludedSub excludedSubscription 8
tests/Service/Reading/MarkReadServiceTest.php reloadedExcludedSub reloadedExcludedSubscription 3
tests/Service/Reading/MarkReadServiceTest.php reloadedIncludedSub reloadedIncludedSubscription 3
tests/Service/Reading/MarkReadServiceTest.php reloadedSub reloadedSubscription 6
tests/Service/Recommendation/Prompt/RecommendationCandidateLoaderTest.php l line 40
tests/Service/Recommendation/Prompt/RecommendationConsolidationParserTest.php p pick 6
tests/Service/Recommendation/Prompt/RecommendationHistoryLoaderTest.php l line 10
tests/Service/Recommendation/Prompt/RecommendationPickParserTest.php data reply 3
tests/Service/Recommendation/Prompt/RecommendationPromptBuilderTest.php i index 8
tests/Service/Recommendation/Run/ForYouSweepTest.php i index 5
tests/Service/Recommendation/Run/RecommendationEtaEstimatorTest.php i index 2
tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php i index 39
tests/Service/Refresh/RefreshRunner/RefreshRunnerTest.php i index 10
tests/Service/Retention/EntryPrunerTest.php i index 28
tests/Service/Retention/EntryPrunerTest.php n feedNumber 3
tests/Service/Scraper/ClusterLayerTest.php doc document 4
tests/Service/Scraper/ClusterLayerTest.php i index 5
tests/Service/Scraper/HtmlItemExtractorTest.php e entry 12
tests/Service/Scraper/HtmlItemExtractorTest.php t title 2
tests/Service/Scraper/HtmlItemExtractorTest.php i index 5
tests/Service/Scraper/JsonLdLayerTest.php doc document 8
tests/Service/Scraper/JsonLdLayerTest.php i index 6
tests/Service/Scraper/Pass/CardFieldsTest.php doc document 2
tests/Service/Scraper/Pass/CardFieldsTest.php c container 18
tests/Service/Scraper/Pass/CardFieldsTest.php a anchor 18
tests/Service/Scraper/Pass/CardFieldsTest.php c2 unsafeContainer 2
tests/Service/Scraper/Pass/CardFieldsTest.php a2 unsafeAnchor 2
tests/Service/Scraper/SemanticLayerTest.php doc document 4
tests/Service/Scraper/CardTitleTest.php doc document 2
tests/Service/Search/Membership/SavedSearchMembershipSweepTest.php i index 8
tests/Service/Subscription/FeedTagMoveTest.php x ahead 4
tests/Service/Subscription/FeedTagMoveTest.php y behind 4
tests/Service/Subscription/FirstFetchRecorderTest.php i index 5
tests/Service/Subscription/OwnedTagsCacheTest.php i index 3
tests/Service/Subscription/SubscriptionServiceTest.php t tag 4
tests/Service/Subscription/SubscriptionServiceTest.php repo repository 3
tests/Service/Subscription/SubscriptionTagSyncTest.php t tag 2
tests/Service/Worker/SendDueDigestsHandlerTest.php prefs duePreferences 8
tests/Service/Worker/SendDueDigestsHandlerTest.php repo repository 6
tests/Service/FillMissingImagesTest.php g5 parsedFeed 2
tests/Service/FillMissingImagesTest.php g6 parsedFeed 2
tests/Service/FillMissingImagesTest.php g7 parsedFeed 2
```

- [ ] **Step 3: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/f1.rows
php var/refactor-1172/rename-names.php var/refactor-1172/f1.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/f1.rows 2>&1 | grep -c ' 0 found'
```
Expected: `Every row holds; nothing written (--check).`; `144`; `144`.

- [ ] **Step 4: Wrap the 24 lines the rename lengthened**

Wrap each line of the table (Appendix W rules). Line numbers are at `0863e373`, before this plan's earlier steps moved any line (an anchor, not a contract); the text is the line after the rename.

| File | Line at `0863e373` | The line after the rename |
|---|---|---|
| `tests/Service/Backup/Support/BackupSchemaCoverageTest.php` | 479 | `$this->assertEveryEntityIsMentioned(self::ACCOUNT_SCOPED_WHOLLY_DROPPED, $documentation, self::SECTION_WHOLLY_DROPPED);` |
| `tests/Service/Backup/Support/BackupSchemaCoverageTest.php` | 488 | `private function assertEveryEntityIsMentioned(array $declarations, string $documentation, string $sectionMarker): void` |
| `tests/Service/Catalog/CatalogFaviconWarmerTest.php` | 200 | `self::assertContains($needle->getId(), array_map(static fn (CatalogFeed $feed): ?int => $feed->getId(), $feeds));` |
| `tests/Service/Catalog/CatalogFaviconWarmerTest.php` | 208 | `self::assertNotContains($needle->getId(), array_map(static fn (CatalogFeed $feed): ?int => $feed->getId(), $feeds));` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 26 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 46 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 73 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 98 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 119 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 186 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 202 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Fetch/FailoverRequestSenderProxyTest.php` | 229 | `$client = new MockHttpClient(function (string $method, string $url, array $options) use (&$calls): MockResponse {` |
| `tests/Service/Mail/Digest/DigestComposerTest.php` | 61 | `$entry = $this->member($rust, 'Entry ' . $index, 'Feed ' . $index, $effectiveDate->modify('-' . $index . ' minutes'));` |
| `tests/Service/Mail/Digest/SendDueDigestsTest.php` | 192 | `$this->preferencesRepository->method('findWithDigestEnabled')->willReturn([$duePreferences, $notDuePreferences]);` |
| `tests/Service/ReaderAudit/Model/AuditFindingsModelTest.php` | 46 | `self::assertSame([1], array_map(static fn (AuditFindingModel $finding): int => $finding->entryId, $findings->ranked()));` |
| `tests/Service/ReaderAudit/Model/AuditFindingsModelTest.php` | 57 | `self::assertSame([3, 1], array_map(static fn (AuditFindingModel $finding): int => $finding->entryId, $findings->ranked()));` |
| `tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php` | 101 | `self::assertSame(['eins', 'zwei'], array_map(static fn ($link): string => $link->text, $body->blocks[0]->links));` |
| `tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php` | 125 | `self::assertSame(['li', 'p'], array_map(static fn (BodyBlockModel $block): string => $block->tag, $body->blocks));` |
| `tests/Service/ReaderAudit/ReaderAuditRunnerTest.php` | 219 | `self::assertSame([1, 2], array_map(static fn (AuditFindingModel $finding): int => $finding->entryId, $findings));` |
| `tests/Service/Reading/MarkReadServiceTest.php` | 232 | `$reloadedIncludedSubscription = $this->entityManager->getRepository(Subscription::class)->find($subscription->getId());` |
| `tests/Service/Reading/MarkReadServiceTest.php` | 239 | `$reloadedExcludedSubscription = $this->entityManager->getRepository(Subscription::class)->find($excludedSubscription->getId());` |
| `tests/Service/Reading/MarkReadServiceTest.php` | 269 | `$reloadedSubscription = $this->entityManager->getRepository(Subscription::class)->find($excludedSubscription->getId());` |
| `tests/Service/Reading/MarkReadServiceTest.php` | 298 | `$reloadedSubscription = $this->entityManager->getRepository(Subscription::class)->find($excludedSubscription->getId());` |
| `tests/Service/Scraper/Pass/CardFieldsTest.php` | 122 | `[$unsafeContainer, $unsafeAnchor] = $this->card('<div data-card><a href="javascript:alert(1)"><h2>Bad link</h2></a></div>');` |

Run: `composer cs`. Expected: nothing printed.

- [ ] **Step 5: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Service && composer check`
Expected: PASS.
```bash
git add tests/Service
git commit -m "refactor(#1172): the tests in tests/Service name what they hold"
```

---

### Task F2: Test helpers and repository properties in `tests/Service`

**Files:**
- Modify: `tests/Service/Opml/OpmlImporterTest.php`, `tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php`

- [ ] **Step 1: The rows**

Write to `var/refactor-1172/f2.rows`:
```text
tests/Service/Opml/OpmlImporterTest.php subsOf subscriptionsOf 5
tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php settingsRepository recommendationSettings 7
```

- [ ] **Step 2: Check, rename, check again**

```bash
php var/refactor-1172/rename-names.php --check var/refactor-1172/f2.rows
php var/refactor-1172/rename-names.php var/refactor-1172/f2.rows | wc -l
php var/refactor-1172/rename-names.php --check var/refactor-1172/f2.rows 2>&1 | grep -c ' 0 found'
git grep -n -P 'subsOf|(private|protected|public)( readonly)? [\w\\]+ \$(repository|repo|\w+Repo(sitory)?)\b' -- src tests
git grep -c -P 'function subscriptionsOf\(' -- tests/Service/Opml/OpmlImporterTest.php
```
Expected: `Every row holds; nothing written (--check).`; `2`; `2`; one line, `src/Service/Version/LatestReleaseReader/GitHubLatestReleaseReader.php:35:        private string $gitHubRepository,` (a GitHub slug, D-10); `tests/Service/Opml/OpmlImporterTest.php:1` (the positive control).

- [ ] **Step 3: Gates and commit**

Run: `php bin/phpunit tests/Service/Opml tests/Service/Recommendation/Settings && composer check`
Expected: PASS.
```bash
git add tests/Service
git commit -m "refactor(#1172): the last test helper and repository property are spelled out"
```

---

### Task F3: `AbbreviatedNameRule` is registered

**Files:**
- Modify: `phpstan.dist.neon`
- Delete (uncommitted): `var/refactor-1172/names.neon`

- [ ] **Step 1: The whole tree is clean under the survey**

```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress src tests 2>&1 | grep -c 'Name reveals intent'
```
Expected: `0`. A report here names a site added to develop after `0863e373`: rename it per D-2, D-3 or D-10 with a one-row rows file (the Global Constraints' reconcile rule), and list it in the PR body.

- [ ] **Step 2: Register the rule**

Append to the `services:` list at the end of `phpstan.dist.neon`:
```neon
    -
        class: App\Tests\PhpStan\AbbreviatedNameRule
        tags:
            - phpstan.rules.rule
```
```bash
rm var/refactor-1172/names.neon
bin/console cache:clear && bin/console cache:warmup
composer stan
```
Expected: `[OK] No errors`.

- [ ] **Step 3: Deletion check**

In `tests/Http/Problem/ApiProblemTest.php`, `testSerializesTheRequiredMembers()`, replace
```php
        $problem = new ApiProblem('validation_error', 'Validation failed', 422);
```
with
```php
        $tmp = new ApiProblem('validation_error', 'Validation failed', 422);
        $problem = $tmp;
```
Run: `composer stan -- --error-format=raw --no-progress 2>&1 | grep 'Name reveals intent'`
Expected: FAIL, two lines ending `ApiProblemTest.php:14:Name reveals intent: $tmp is a single letter or a truncated word; name what it holds (#1172).` and the same at `:15`. Restore by hand; `composer stan` prints `[OK] No errors` again.

- [ ] **Step 4: Commit**

```bash
git add phpstan.dist.neon
git commit -m "refactor(#1172): the abbreviated-name rule runs in composer stan"
```

---

### Task F4: CLAUDE.md and the closing sweep

**Files:**
- Modify: `../CLAUDE.md`

- [ ] **Step 1: "Names reveal intent" states the rules**

In the "Names reveal intent" bullet, replace its last line (line 82 at `0863e373`, #1169 G8's D-19 sentence; D-reconcile-6)
```markdown
  container binds it by that name. If a name needs a comment to be understood, rename it.
```
with
```markdown
  container binds it by that name. A property holding a repository or a
  consumer-owned repository interface is named for what it holds (`$tags`,
  `$storedSettings`), never `$repository` or `$…Repo`. No name is a single letter
  or a truncated word, closure parameters, comparator operands, caught exceptions
  and loop counters included (`$tag`, `$left`/`$right`, `$exception`, `$index`);
  `$x`/`$y` stay for coordinates, `$qb` and `$io` for the Doctrine and Symfony
  idioms. If a name needs a comment to be understood, rename it.
```

- [ ] **Step 2: The rule joins the enforced list**

Insert before the line `- **\`EntityIdCoercionRule\`** (\`tests/PhpStan/EntityIdCoercionRule.php\`) — read a`:
```markdown
- **`AbbreviatedNameRule`** (`tests/PhpStan/AbbreviatedNameRule.php`, its names in
  `AbbreviatedNames`) — no variable, parameter or property is a single letter
  (numbered or not; `$x`/`$y` aside) or a listed truncation. An override of a
  method declared outside `App` keeps its parent's parameter names (named
  arguments bind to them), and the rule skips those; its one allow-listed wire key
  is `MarkSearchReadRequest::$q`.
```

- [ ] **Step 3: Every Scope row is done**

```bash
git grep -n -P '(?<![0-9\\$])\$(em|data|temp|sub|prefs|st|ns)\b|subscriptionRepo|joinsBySubId|keepsHoldingTheLock|extraAuthorizationParams\b|\bRelyingPartyChange\b|\bSubscriptionTagRef\b' -- src tests ':!tests/PhpStan/data' ':!src/Security/AccountStatusException.php'
git grep -c -P '(?<![0-9\\$])\$data\b' -- src/Security/AccountStatusException.php
git grep -n -P '\$mine\b' -- src
git grep -n -F 'UnknownChallengeException' -- src/Http/Problem/ExceptionProblems
git grep -n -F 'counts.json' -- src tests ../frontend/src
git grep -c -P '(?<![0-9\\$])\$qb\b' -- src | wc -l
```
Expected: nothing; `src/Security/AccountStatusException.php:3` (the `__unserialize(array $data)` override D-13 keeps, left out of the first grep: its positive control); nothing (`$mine`/`$theirs` pairs stay in tests, D-3); `PasskeyChallengeProblems.php` twice (the import and the arm) and no other mapper; nothing; a number above 0 (`$qb` stays: the positive control for the first pattern's syntax).

Walk this plan's "Survey and scope" table against develop plus this branch: a row that is neither done nor ruled is a gap: stop and report it.

- [ ] **Step 4: Commit**

```bash
git add ../CLAUDE.md
git commit -m "refactor(#1172): CLAUDE.md names the naming rules and the rule that keeps them"
```

---

### Finishing PR F

- [ ] **Step 1: The gates on the whole branch** (Global Constraints; the survey is now `composer stan` itself).
- [ ] **Step 2: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 3: /simplify**; commit `refactor(#1172): simplify pass` if anything changed.
- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue, the ledger's `#1172` lines and `git diff origin/develop...HEAD`. Attack points:
1. **The whole issue.** Walk the "Survey and scope" table: every issue bullet and every ledger line is done in PRs A–F or ruled with a reason.
2. **The rule** has no false positive on a sanctioned name (`$qb`, `$io`, `$url`, `$img`, `$x` in `GdImageResizerTest`) and no false negative on a declared property.
3. **CLAUDE.md** says what the rule enforces and nothing it does not.
4. **Deletion checks:** re-run F3's; quote the FAIL.
5. **The body says `Closes #1172`**, and no earlier commit on any #1172 branch closed it.

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 5: Open the PR** (Appendix K, closing variant) with `var/refactor-1172/pr-f-body.md`:
```markdown
Closes #1172 (PR F of six; PRs A to E are merged).

- The tests in `tests/Service` name what they hold: `$prefs`, `$doc`, `$repo`, `$dir`, `$svc` and every one-letter name are spelled out (`$index`, `$exception`, `$line`, `$candidate`, `$method`/`$url`/`$options` in the HTTP mock callbacks).
- `AbbreviatedNameRule` runs in `composer stan`: a variable, parameter or property named with a single letter (numbered or not; `$x`/`$y` stay for coordinates) or a listed truncation fails the build. It skips the parameters of a method overriding one declared outside `App` (they keep the parent's names), and its one allow-listed name is `MarkSearchReadRequest::$q`, the wire key.
- CLAUDE.md's "Names reveal intent" states the rules, including that a property holding a repository is named for what it holds.

Renamed test helper: `OpmlImporterTest::subsOf()` → `subscriptionsOf()`, the subscriptions a user holds.

Over the six PRs: `$em` is `$entityManager`; no one-letter or truncated name is left in `src` or `tests`; every repository property is named for what it holds; `keepsHoldingTheLock()` is `refreshOrReacquireLock()`; the login limiter normalises in `normalizeLastUsername()`; an unknown passkey challenge has its own problem mapper; `AbstractAtomParser` reads `namespaceUri()`; the change marker is `change-marker.json`; `RelyingPartyChange` is `RelyingPartyChangeGuard`.

No behaviour change.
```
Title: `refactor(#1172): the service tests name what they hold; the naming rule is enforced`.
- [ ] **Step 6: Merge when green**, then `gh issue view 1172 --json state --jq .state`. Expected: `CLOSED` (the merge into `develop`, the default branch, closes it). If it is still open, report it; do not close it by hand.

---

# Appendix S — `var/refactor-1172/rename-names.php`

Uncommitted scratch (A0 Step 3 writes it; `var/` is ignored). Run from `backend/`. It was run end to end against a copy of `0863e373` with every rows block of this plan, in task order (D-reconcile-1).

```php
<?php

declare(strict_types=1);

// php var/refactor-1172/rename-names.php [--check] <file.rows>, from backend/.
// A row: path old new hits [shadow]. Renames $old (docblocks and interpolated strings too), $this->old and a method
// named old, in that file. Refuses, before writing anything, a row whose hit count is not `hits` (`*`: at least one),
// and a row whose $new already occurs in a method that also uses $old, unless the row says `shadow` (an arrow-function
// parameter reviewed to shadow nothing it reads). --check validates every row and writes nothing.

/** @return list<string> the methods (or '(file)') in which both $old and $new occur */
function sharedScopes(string $code, string $old, string $new): array
{
    $uses = [];
    $depth = 0;
    $methodDepth = null;
    $pending = null;
    $scope = '(file)';
    $tokens = token_get_all($code);
    foreach ($tokens as $index => $token) {
        $text = is_array($token) ? $token[1] : $token;
        if (is_array($token) && T_FUNCTION === $token[0] && null === $methodDepth) {
            $pending = nameAfter($tokens, $index);
        }
        if ('{' === $text || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            ++$depth;
            if (null !== $pending && null === $methodDepth) {
                [$methodDepth, $scope, $pending] = [$depth, $pending, null];
            }
        } elseif ('}' === $text) {
            if ($depth === $methodDepth) {
                [$methodDepth, $scope] = [null, '(file)'];
            }
            --$depth;
        } elseif (';' === $text && null === $methodDepth) {
            $pending = null;
        }
        if (is_array($token) && T_VARIABLE === $token[0] && in_array($token[1], ['$' . $old, '$' . $new], true)) {
            $uses[null !== $pending && null === $methodDepth ? $pending : $scope][$token[1]] = true;
        }
    }

    return array_keys(array_filter($uses, static fn (array $names): bool => 2 === count($names)));
}

/** @param array<int, mixed> $tokens */
function nameAfter(array $tokens, int $index): ?string
{
    for ($next = $index + 1; isset($tokens[$next]); ++$next) {
        if (is_array($tokens[$next]) && T_WHITESPACE === $tokens[$next][0]) {
            continue;
        }

        return is_array($tokens[$next]) && T_STRING === $tokens[$next][0] ? $tokens[$next][1] : null;
    }

    return null;
}

$check = '--check' === $argv[1];
$contents = [];
$failures = [];
foreach (file($argv[$check ? 2 : 1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $row) {
    $fields = preg_split('/\s+/', trim($row));
    if (str_starts_with($fields[0], '#')) {
        continue;
    }
    [$path, $old, $new, $expected] = $fields;
    $code = $contents[$path] ??= (string) file_get_contents($path);
    $patterns = [
        '/(?<![0-9\\\\$])\$' . $old . '\b/' => '$' . $new,
        '/\$this->' . $old . '\b/' => '$this->' . $new,
        '/\bfunction ' . $old . '\(/' => 'function ' . $new . '(',
    ];
    $hits = array_sum(array_map(static fn (string $pattern): int => preg_match_all($pattern, $code), array_keys($patterns)));
    $shared = 'shadow' === ($fields[4] ?? '') ? [] : sharedScopes($code, $old, $new);
    $methodClash = 1 === preg_match('/\bfunction ' . $new . '\(/', $code)
        && 1 === preg_match('/\bfunction ' . $old . '\(/', $code);
    if ('*' === $expected ? 0 === $hits : $hits !== (int) $expected) {
        $failures[] = "$path: $expected hits of $old expected, $hits found";
    } elseif ([] !== $shared || $methodClash) {
        $failures[] = "$path: $new already exists beside $old in " . implode(', ', $shared ?: ['a method name']);
    } else {
        $contents[$path] = (string) preg_replace(array_keys($patterns), array_values($patterns), $code);
        echo "$path: \$$old -> \$$new ($hits)\n";
    }
}
if ([] !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\nNothing written.\n");
    exit(1);
}
if ($check) {
    echo "Every row holds; nothing written (--check).\n";
    exit(0);
}
foreach ($contents as $path => $code) {
    file_put_contents($path, $code);
}
```

# Appendix N — `var/refactor-1172/names.neon` and the survey

```neon
includes:
    - ../../phpstan.dist.neon

services:
    -
        class: App\Tests\PhpStan\AbbreviatedNameRule
        tags:
            - phpstan.rules.rule
```
The survey, from `backend/` (bash, so that a `$SCOPE` list splits):
```bash
vendor/bin/phpstan analyse -c var/refactor-1172/names.neon --memory-limit=512M --error-format=raw --no-progress <paths> 2>&1 | grep -c 'Name reveals intent'
```
Paths on the command line replace the config's `paths`; `excludePaths` (the rule fixtures in `tests/PhpStan/data`) still applies. Run `bin/console cache:warmup` first when the container changed. F3 deletes the file once `phpstan.dist.neon` registers the rule: both at once would register it twice.

Counts at `0863e373`, the same as at `021f9ec1` (distinct line-and-name sites, with the D-13 skip):

| Scope | Before PR A | After PR A |
|---|---|---|
| `src/Command src/Controller src/Doctrine src/Dto src/Entity src/EventListener src/Http src/Repository src/Security` (PR B) | 109 | 95 |
| `src/Service/[A-M]*` (PR C) | 121 | 101 |
| `src/Service/[N-Z]*` (PR D) | 218 | 204 |
| `tests/*` but `tests/Service` (PR E) | 1171 | 466 |
| `tests/Service` (PR F) | 718 | 623 |

# Appendix W — Wrapping a line the rename lengthened

PSR-12 at 120 columns, in the shape the file already uses:
1. **A call or `new` with arguments:** one argument per line, a trailing comma, the closing parenthesis on its own line at the call's indentation.
2. **An arrow function whose body overflows:** break before `=>` and indent the body one level (`AuditFindingsModel`'s `$worstFirst` comparator is the house example).
3. **A closure inside a call** (`array_map`, `array_filter`, `usort`): apply rule 1 to the outer call first; the closure then usually fits on its own line.
4. **A method chain:** break before `->`, one call per line, indented one level.
5. **A multi-type `catch`:** one type per line with a trailing `|`, the variable after the last, as `FaviconFetcher::download()` does.
6. **A comment or docblock line:** re-flow the sentence; do not shorten it by dropping words.

Never shorten a new name to make a line fit, and never introduce a variable to dodge a wrap unless the task says so (B1 and D1 each introduce one, named).

# Appendix K — The closing-keyword gate on `gh pr create`

From `backend/`, with `<x>` the PR's letter, its body in `var/refactor-1172/pr-<x>-body.md` and its title:
```bash
KEYWORDS='(^|[^a-z])(close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved)([^a-z]|$)'
printf 'this fixes it\n' | grep -iqE "$KEYWORDS" && echo 'guard control: a planted hit is seen'
printf 'fixture, MissingFaviconResolver, resolveFor\n' | grep -iqE "$KEYWORDS" || echo 'guard control: look-alikes pass'
git push -u origin "$(git branch --show-current)"
git log origin/develop..HEAD --format=%B > var/refactor-1172/commits.txt
! grep -inE "$KEYWORDS" var/refactor-1172/commits.txt \
  && ! grep -inE "$KEYWORDS" var/refactor-1172/pr-<x>-body.md \
  && gh pr create --base develop --title "<title>" --body-file var/refactor-1172/pr-<x>-body.md
```
Expected: both control lines print; then the PR URL. A keyword in a commit or the body prints its line and stops before `gh pr create`: reword it (an amended commit message on this unmerged branch, or the body file) and run the block again.

**Closing variant (PR F):** the body must say `Closes #1172` once and nothing else closing:
```bash
! grep -inE "$KEYWORDS" var/refactor-1172/commits.txt \
  && grep -c '^Closes #1172 ' var/refactor-1172/pr-f-body.md \
  && ! grep -v '^Closes #1172 ' var/refactor-1172/pr-f-body.md | grep -inE "$KEYWORDS" \
  && gh pr create --base develop --title "<title>" --body-file var/refactor-1172/pr-f-body.md
```
Expected: `1`, then the PR URL.

After creating any PR, `gh pr view --json body --jq .body | grep -inE "$KEYWORDS"` prints nothing (A–E) or only the `Closes #1172` line (F).
