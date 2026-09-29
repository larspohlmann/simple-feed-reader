# Make `final`/`readonly` and Injection Consistent (#1169) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1169 in eight PRs. Every persistence class is `final`, every stateless class `final readonly`, every file strict, every collaborator injected, every tuned heuristic an injected service, and the carry-forward items the refactoring series left on #1169 are done or ruled.
- **PR A** (A0–A9, `Refs #1169`): repositories, entities and embeddables are `final`. Eleven consumer-owned interfaces replace the concrete repositories tests used to double (the `SavedSearchMembershipWriterInterface` precedent). `AbstractEntryProjectionRepository` becomes a composed `EntryProjection`. `PersistenceClassesAreFinalRule` keeps it so.
- **PR B** (B0–B2, `Refs #1169`): `strict_types` in `Kernel.php` and `tests/bootstrap.php`, enforced by PHPCS's own `Generic.PHP.RequireStrictTypes`; the nine stateless classes outside `src/Service` become `final readonly`.
- **PR C** (C0–C3, `Refs #1169`): no collaborator defaults to `new` in a constructor (`RecipeFactsCleaner`, `EntrySanitizer`), and `NoCollaboratorDefaultRule` keeps it so; `ActionTokenService` injects its repository and `QueriesLiveInRepositoriesRule` forbids `getRepository()` outside `src/Repository`; `MailConnectionTester` sends through the transport it already holds.
- **PR D** (D0–D7, `Refs #1169`): the Reader module's policy classes leave `Support/` as injected root services (eleven classes, `LeadingEngagementRules` among them, plus two split off `BlockText` and `LeadingEngagementBlocks`); the audit's leading region becomes the `ReaderAudit\LeadingRegion` service, so no model calls a verdict; `ArticlePageReader` takes the page reads off `ArticleExtractor`; `docs/architecture.md` §10 states the boundary.
- **PR E** (E0–E5, `Refs #1169`): the policy classes of `Fetch`, `Html`, `Parser`, `ReaderAudit`, `Recommendation` and `Scraper` do the same (nine classes).
- **PR F** (F0–F8, `Refs #1169`): tests and rules: the invocation-matcher sweep and its rule, `ParsesHtml` and one `PROSE` home, `EmailVerifierTest`, the folded tag-lookup test, the reclaim-order pin, the cycle-rule pins, `ClassNameReferences::forbiddenInFile()`, `App\Doctrine` in `DomainKnowsNoHttpRule`, and `ServiceRoleClass` split below PHPMD's thresholds.
- **PR G** (G0–G9, `Refs #1169`): DRY and typed results: the reorder lookup, owner-first `OwnedSubscriptions`, the All-items filter in SQL, `HtmlDocumentParser::parseOrEmpty()`, `UrlResolver` without a cast, `BackupReader` without an Infection ignore, `FeedScheduler::recordNotModified()`, `…Factory` property names, the exception listener's explicit priority.
- **PR H** (H0–H6, `Closes #1169`): homes: the viewer time zone moves to `Service/Clock`, the shared enums and `SupportedLocale` are ruled and documented, repositories may name only Service values, and the three Recommendation PHPMD suppressions go.

**Architecture:**
- **"`Support/` computes, a service decides"** (D-1). A static helper stays in `Support/` when its answer is fixed by its arguments plus a standard, a wire or file format, or this app's own schema. A class that returns a verdict about third-party content that someone tuned against observed pages, feeds or model replies is policy: a curated list, a measured threshold or window, a precedence among candidate sources. Policy becomes an injected `final readonly` root service in its module. A class that calls a policy becomes a service too, or is handed the verdict. A `Model/` never calls a service.
- **Moves use #1202's committed scripts** (`docs/superpowers/plans/2026-09-28-1202-scripts/`): `move-classes.php` moves a class and its tests and rewrites every tracked file outside `docs/`; `compare-moves.php` proves a move changed no code; `stale-names.php` feeds the repo-wide stale-name grep. The static-to-instance conversion is a separate commit, so a reviewer sees the move and the design change apart.
- **Collaborators reach per-call objects through their builders.** A policy a `Pass/` object needs (`CardFields`, `SiblingSearch`, `LeadingFurniture`) is a constructor argument its creating service passes from its own injected field. The pass object reads it, so no parameter is tramp data (`phptramp`, `minClasses` 2).
- **Guards land with the change they guard.** Each consistency this plan establishes gets a PHPStan rule or a PHPCS sniff in the same PR, with a fixture, a test and a deletion check.

**Tech Stack:** PHP 8.4, Symfony 7.4 (autowiring over `src/`, interface aliases in `config/services.yaml`), Doctrine ORM 3 with native lazy objects, PHPStan 2 (`Rule<InClassNode>`, `Rule<FileNode>`, `RuleTestCase`), nikic/php-parser 5, PHPUnit 12, PHP_CodeSniffer 3.13.6, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1169 (`gh issue view 1169`), its comment listing the deferred items (`gh issue view 1169 --json comments -q '.comments[].body'`), and every `#1169` line of the planner's carry-forward ledger (`scratchpad/plans/carry-forward.md`), with the rulings marked there.
- CLAUDE.md, "PHP code style — Clean Code is mandatory"; `docs/architecture.md` §7–§10.
- The #1202 plan, `docs/superpowers/plans/2026-09-28-1202-class-roles.md`, with its "Execution rulings" sections: PRs G, H and I have landed, and #1202 is closed.

## Reconcile notes (5dbc55d3)

Written at `137d9631`; reconciled at `5dbc55d3` (origin/develop after #1202 G, H and I). Every path, anchor, count and grep below was re-read at `5dbc55d3`; the rest held.

- **D-reconcile-1:** R-2 failed. #1202 I-support moved only `isDateLine()` out (`Reader\DateLineRecognizer`, its planner ruling "option A"); `LeadingEngagementRules` stayed a static `Support/` helper. Its thresholds and curated lists (#770, #901) are policy by D-1, so D1 moves it and the new D6 injects it. Why: the class is still static at `5dbc55d3`.
- **D-reconcile-2:** `ReaderAudit\Model\BodyBlockModel::isProse()` and `ExtractedBodyModel::leadingBlocks()` apply that verdict from inside models. D-3's split applies: the model keeps the measurement (`linkedTextLength()`, now public), and the verdict becomes the root service `ReaderAudit\LeadingRegion::blocksOf()`, which its four readers inject. Why: a model never calls a service.
- **D-reconcile-3:** D5 (the split) now runs before the rules become a service, and `LeadingBlockJudge` calls them statically until D6 injects them. Why: until the split, the static `LeadingEngagementBlocks::isProse()` calls the rules, and a static helper cannot reach an injected service.
- **D-reconcile-4:** Two test builders: D5 adds `tests/Support/LeadingEngagementCleaners`, D6 adds `tests/Support/AuditMarkers`, and E4 edits `AuditMarkers` in place of four `new CleanupMarkers(` copies. Why: D5 and D6 would otherwise grow three copies of one wiring past 120 columns and edit four copies of another (DRY, third occurrence).
- **D-reconcile-5:** the new D6 makes the old D6 into D7. Counts: 20 policy classes become services (D 11, E 9). Why: `LeadingEngagementRules` joins PR D.
- **D-reconcile-6:** `ServiceRoleRule` now enforces all 16 checks, and `composer roles` is gone. A static class in a module root gets only `supportHome`, never `rootService`, so D1 and E1 expect `supportHome` alone, and a converted `final class` must also become `readonly`, including `CrossFamilyFailover` in E2, which the earlier draft missed. Every count greps `'Service role "supportHome"'`, because PHPStan's table also prints the identifier line. Why: the rule's real messages.
- **D-reconcile-7:** B2 names `InvalidatePasswordChangeTokensListener`, not `PasswordChangeTokenInvalidator`. It is still a `final class`. Why: #1202 I-listeners renamed it.
- **D-reconcile-8:** G4 keeps `HtmlDocumentParser`'s private constructor. It sits at the class's end, so only the two methods are replaced. Why: the earlier draft said "above" and replaced the whole body.
- **D-reconcile-9:** G8 also renames `DateLineRecognizer::$formatters` to `$formatterFactory`, and its survey now covers `…FactoryInterface` except Symfony's rate limiters: 20 properties in 19 files. Why: #1202 I added the property.
- **D-reconcile-10:** F1's ledger site is `tests/Security/TrialExpiryGuardTest.php`. Why: the earlier draft's `tests/Service/Auth` path never existed.
- **D-reconcile-11:** The D-10 ruling is applied in H5. `stampProvider()` keeps its name: it is a command, and renaming it `setProvider()` would game PHPMD's ignore pattern. That leaves 10 counted public methods, which is PHPMD's ceiling (it reports above 10). A grep proves the two parts the accessors hand out have no setters. The PR body lists each rename and what it does. Why: planner ruling.
- **D-reconcile-12:** The D-20 ruling is applied in G9. The test is functional: the real kernel handles an unrouted `/api` request, a Monolog `TestHandler` on `monolog.logger.request` records the log, and the response is problem+json. The deletion check sets the priority above 0. Why: planner ruling.
- **D-reconcile-13:** New F8 splits `tests/PhpStan/ServiceRoleClass.php` (27 public methods, class complexity 72) into `ServiceRoleClass`, `ClassShape`, `SuppliedConstructorTypes` and `EventListenerDeclarations`, gated by PHPMD on those files. Why: carry-forward from #1202 I. `composer md` reads only `src`, but tests are production code.
- **D-reconcile-14:** A0 Step 3 checks the real #1202 outcome. R-1, R-3, R-4 and R-5 held: the parsers and sanitizer are `final readonly`, `ClassNameReferences` keeps its three methods, the listeners end in `Listener`, and `ParsesHtml::document()`. F1 (71 calls in 24 files), F2 (56 files) and every construction count in D, E and H4 are unchanged. Why: re-read at `5dbc55d3`.
- **D-reconcile-15:** Global Constraints gain four process rules: the MySQL cache prep never runs beside the native leg, `gh pr create` is gated with `&&`, every deletion check has a quoted FAIL that the reviewer re-runs, and every grep has a positive control. Why: planner.

## Assumptions about #1202 (as found at `5dbc55d3`; A0 Step 3 re-checks them)

- **R-1 (#1202 G1):** every stateless root service is `final readonly`, `AbstractAtomParser` is `abstract readonly`, and `Atom03Parser`, `Atom10Parser`, `Rss1Parser`, `Rss2Parser`, `EntrySanitizer`, `TrailingBlankRemover`, `HeroImageSelector`, `ClusterLayer`, `SemanticLayer`, `JsonLdLayer`, `UrlNormalizer` and `RecommendationPromptBuilder` are among them. `EntrySanitizer`'s parameter reads `private TrailingBlankRemover $blankTail = new TrailingBlankRemover(),`.
- **R-2 (#1202 I-support):** every `Support/` class (71) has a private constructor at the class's end and no static property, and `supportShape` enforces it. `LeadingEngagementRules` is still `App\Service\Reader\Support\LeadingEngagementRules`, static, with ten public statics (`isProse`, `isEmojiOnly`, `isCounter`, `isByline`, `isReadingTime`, `isBareNumber`, `isSeparatorOnly`, `isNavigationLabel`, `isKicker`, `hasAuthor`). `Reader\DateLineRecognizer` (`final class`, `#[ProcessLifetimeState]`) takes `Reader\Factory\DateFormatterFactoryInterface $formatters`. `LeadingEngagementCleaner::__construct(DateLineRecognizer $dateLines)` builds `new LeadingFurniture($entryAuthor, $this->dateLines)`. `isProse` exists three times: `LeadingEngagementRules::isProse(string, int)`, `LeadingEngagementBlocks::isProse(LeadingBlockModel)` and `BodyBlockModel::isProse()`.
- **R-3 (#1202 I-simplify):** `ServiceRoleRule`'s helpers changed (`ServiceRoleMap::applicationClasses()`, `SupportShapes`), and only F8 edits them. `ClassNameReferences` keeps `namespacesIn()`, `forbiddenIn()` and `isInAnyOf()`.
- **R-4 (#1202 H, I-listeners):** every listener ends in `Listener`, `PasswordChangeTokenInvalidator` among them (now `InvalidatePasswordChangeTokensListener`).
- **R-5:** `tests/Support/ParsesHtml`'s method is `document()`.
- **R-6:** line numbers quoted from `137d9631` or `5dbc55d3` are anchors, not contracts: every edit names the text it replaces.

## Status

| Task | State |
|---|---|
| A0: Preflight, branch, plan copy, reconcile checks | ⬜ |
| A1: `PersistenceClassesAreFinalRule` and its test (not yet registered) | ⬜ |
| A2: Six repositories nothing doubles are `final` | ⬜ |
| A3: Passkey interfaces; `UserPasskeyRepository`, `UserIdentityRepository` final | ⬜ |
| A4: `UserByEmailInterface`; `UserRepository` final | ⬜ |
| A5: Digest interfaces; `PreferencesRepository`, `SavedSearchRepository` final | ⬜ |
| A6: Stored-settings interfaces; `GrafanaSettingsRepository`, `ProxyServerSettingsRepository` final | ⬜ |
| A7: Restore interfaces; `FeedRepository`, `EntryRepository`, `EntryStateRepository` final | ⬜ |
| A8: `EntryProjection` replaces `AbstractEntryProjectionRepository` | ⬜ |
| A9: Entities and embeddables final; the rule is registered | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: `strict_types` everywhere PHPCS looks, and the sniff | ⬜ |
| B2: Stateless classes outside `src/Service` are `final readonly` | ⬜ |
| C0: Preflight (PR B merged) | ⬜ |
| C1: No `new` in a constructor default; `NoCollaboratorDefaultRule` | ⬜ |
| C2: `ActionTokenService` injects its repository; `getRepository()` is a query access | ⬜ |
| C3: `MailConnectionTester` sends through its transport | ⬜ |
| D0: Preflight (PR C merged) | ⬜ |
| D1: Move the Reader policy classes out of `Support/` | ⬜ |
| D2: The paywall verdict is injected; `ArticlePageReader` | ⬜ |
| D3: Page furniture, narration and posters are injected | ⬜ |
| D4: `AuthorProfileLink` and `ArticleContentGate` are injected | ⬜ |
| D5: `LinkListDetector` and `LeadingBlockJudge` split the verdicts off the measurements | ⬜ |
| D6: `LeadingEngagementRules` is injected; the audit's leading region is a service | ⬜ |
| D7: `docs/architecture.md` §10 and CLAUDE.md state the boundary | ⬜ |
| E0: Preflight (PR D merged) | ⬜ |
| E1: Move the remaining policy classes out of `Support/` | ⬜ |
| E2: `CrossFamilyFailover` and `DesktopViewport` are injected | ⬜ |
| E3: The feed-item image and media policies are injected | ⬜ |
| E4: `SuspiciousPhrases`, `PlausibleDuplicateShare`, `RecommendationAnswerBudget` are injected | ⬜ |
| E5: `CardTitle` is injected | ⬜ |
| F0: Preflight (PR E merged) | ⬜ |
| F1: Invocation matchers on `$this`; `InvocationMatchersOnThisRule` | ⬜ |
| F2: `ParsesHtml` everywhere a reader test parses HTML; one `PROSE` home | ⬜ |
| F3: `EmailVerifierTest`; the tag-lookup test folds into `BulkSubscriberTest` | ⬜ |
| F4: The reclaim-before-due order is pinned | ⬜ |
| F5: `ServiceModuleCycleRule` pins (M1, M2) | ⬜ |
| F6: `ClassNameReferences::forbiddenInFile()` | ⬜ |
| F7: `App\Doctrine` in `DomainKnowsNoHttpRule` | ⬜ |
| F8: `ServiceRoleClass` below PHPMD's thresholds | ⬜ |
| G0: Preflight (PR F merged) | ⬜ |
| G1: `PositionReorderer::reorderFound()` | ⬜ |
| G2: `OwnedSubscriptions` takes the owner first | ⬜ |
| G3: The All-items filter runs in SQL | ⬜ |
| G4: `HtmlDocumentParser::parseOrEmpty()`; `parseOrNull()` goes | ⬜ |
| G5: `UrlResolver` without the cast | ⬜ |
| G6: `BackupReader::toDto()` without a default arm; the Infection ignore goes | ⬜ |
| G7: `FeedScheduler::recordNotModified()` | ⬜ |
| G8: Properties holding a factory end in `Factory` | ⬜ |
| G9: `ApiExceptionListener` runs after the exception is logged, by priority | ⬜ |
| H0: Preflight (PR G merged) | ⬜ |
| H1: `ViewerTimeZoneModel` moves to `Service/Clock`; `Reading → Recommendation` is a boundary | ⬜ |
| H2: The homes of shared enums and `SupportedLocale`, in §8 | ⬜ |
| H3: Repositories name only Service values | ⬜ |
| H4: `RecommendationHistoryCaps` and `RecommendationPoolLimits`; two suppressions go | ⬜ |
| H5: `RecommendationRun` below PHPMD's method ceiling; the suppression goes | ⬜ |
| H6: CLAUDE.md and the closing sweep | ⬜ |

## Scope

Every item of the issue body, its comment and the ledger, with where it lands.

| Item | Where |
|---|---|
| Body: 17 non-final repositories; 10 doubled by tests | A2–A8 |
| Body: entities need not be non-final; 11 embeddables | A9 |
| Body: `ArticleExtractor` should be `final readonly` | **Already done** at `137d9631` (`final readonly class ArticleExtractor`) |
| Body: `HeroImageSelector`, `ClusterLayer`, `SemanticLayer`, `JsonLdLayer`, `UrlNormalizer`, `TrailingBlankRemover` | **Done by #1202 G1** (Appendix A-G of its plan) |
| Body: `DisableHttpServerPushPass` | B2 |
| Body: `PaywallSignals` is `final readonly` but static-only | D1, D2 |
| Body: `Kernel.php` has no `strict_types`; consider the sniff | B1 |
| Body: 44 static-only policy classes (the Parser package, `ItemImageExtractor`, `ItemMediaExtractor`, `PaywallSignals`) | D1–D6, E1–E5, "Support/ classification" |
| Body: `LeadingEngagementRules` (15 statics) | D1, D6. #1202 I-support moved only the date-line memo out, as `DateLineRecognizer` (D-reconcile-1). The audit's use of the prose verdict becomes `LeadingRegion` (D-reconcile-2) |
| Body: `FeedDimensionStamper::stampInto` mutates the DOM statically | **Already done**: at `137d9631` it is an injected `BodyCleaningStepInterface` with an instance `cleanIn()` |
| Body: `RecipeFactsCleaner:16-17`, `EntrySanitizer:31` defaults | C1 |
| Body: `ActionTokenService::repository()` | C2 |
| Body: `MailConnectionTester:72` news up a `Mailer` | C3 |
| Body: `ReaderAuditReportCommand:63` news up `AuditReportHtml` | **Ruled by #1202 F** (overrides row): `AuditReportHtml` is a per-call object in `ReaderAudit/Pass/`, and building one with `new` is its contract (§10). No change; D-21 |
| Comment/#1163: `parseOrNull()`'s 8 non-pipeline callers | G4 |
| Comment/#1163: `Service/Reading` imports `ViewerTimeZone` | H1 |
| Comment/#1163: `MarkReadService` filters `includeInAllItems` in PHP | G3 |
| Comment/#1163: `ParsesHtml` adoption; 10 `PROSE` copies | F2 |
| Comment/#1163: the `Reader → Search` rule | Not #1169: #1161 B2 shipped `ServiceModuleBoundaryRule` |
| Comment/#1162 (Lars RULED): the three PHPMD suppressions | H4, H5 |
| Comment/#1162: `TickDriver::retryPlan()` returns an `Ai` type | H2 (it stays in its module, so the rule never sees it) |
| Comment/#1182: homes of `TickDriver`, `RecommendationDriverKind`, `ScrapeFailureReason`, `VisualMediaKind` | H2 |
| Comment/#1182: `SupportedLocale` | H2 |
| Comment/#1182: nine repositories import Service values | H3 |
| Comment/#1182: `App\Doctrine` in `DomainKnowsNoHttpRule` | F7 |
| Comment/#1182: the reorder lookup, third occurrence | G1 |
| Comment: `AbstractEntryProjectionRepository` → composed `EntryProjection` | A8 |
| Comment: `OwnedSubscriptions` owner-first; weigh a `UserScopedMethodOwnerFirstRule` | G2; D-16 (no rule) |
| Comment: `MatchArmRemoval` ignore on `BackupReader::toDto` | G6 |
| Comment + ledger: `self::once()`/`$this->once()` sweep, with its site list | F1 |
| Ledger: EA WARNING on `self::never()`/`self::once()` blocks the lint gate | F1 picks `$this->` |
| Ledger: `EmailVerifier` tests live in `RegistrationServiceTest` | F3 |
| Ledger: fold `BulkSubscriberTagLookupTest` into `BulkSubscriberTest` | F3 |
| Ledger: the reclaim-orphans-before-findDue order is unpinned | F4 |
| Ledger: `ServiceModuleCycleRule` M1 and M2 | F5 |
| Ledger: the 4th copy of "loop namespaces → scope check → map `forbiddenIn`" | F6 |
| Ledger: `UrlResolver.php:38` `(int) strrpos(...) + 1` | G5 |
| Ledger: equivalent mutant `recordSuccess($feed, 0)` in `FeedOutcomePersister::storeNotModified()` | G7 |
| Ledger: factory-property naming is inconsistent | G8 |
| Ledger: `/api` error logging relies on listener registration order | G9 |
| Ledger WATCH: `applyFlags` twice (`BulkSubscriptionUpdater`, `SubscriptionEditor`) | No third copy at `137d9631`; the two change values stay apart (D-23) |
| Ledger WATCH: catalog setters twice | No third copy; no change |
| Ledger: `R1` of the rule refactors | **Moved to #1202 I-simplify** (ledger line 163) |
| Ledger M5: every deferral is on #1169 | This table |
| #1202 I carry-forward: `tests/PhpStan/ServiceRoleClass.php` over PHPMD's thresholds | F8 |

## `Support/` classification (71 classes at `137d9631` and at `5dbc55d3`)

The boundary (D-1): **a `Support/` class computes; a service decides.** A static helper stays when its answer follows from its arguments plus a standard, a wire or file format, or this app's own schema. It is policy when it returns a verdict about third-party content that someone tuned against observed pages, feeds or model replies: a curated list, a measured threshold or window, or a precedence among candidate sources. The reviewer's test: do the class's constants or branches cite a publisher, a survey or a measurement, and would changing them change which content the product keeps, drops or picks? Constants-only classes stay: there is nothing to substitute.

Counts: **20 policy classes become services** (D 11, E 9); **2 are split**, their verdicts becoming 2 new services (`LinkListDetector`, `LeadingBlockJudge`) and their measurements staying; **1 is policy that stays static** by D-2 (`FeedWebsite`); **48 are value helpers and stay.** Every one of the 71 has the private constructor #1202 I-support added at its end; the conversion recipe deletes it. The same split reaches one model: `ReaderAudit\Model\BodyBlockModel` keeps its link-text measurement, and the prose verdict it applied becomes `ReaderAudit\LeadingRegion` (D6, D-reconcile-2).

| Class | Verdict | Why (one line) | Task |
|---|---|---|---|
| `Ai\Support\AiReadiness` | value | A predicate over the entity's own state | — |
| `Auth\Support\PasswordPolicy` | value | A product constant the DTOs' `Assert\Length` attributes read at compile time | — |
| `Backup\Support\BackupSchema` | value | Constants of this app's backup format | — |
| `Backup\Support\GzipLineReader` | value | Reads the gzip format; bounds are safety limits, not verdicts | — |
| `Backup\Support\LineField` | value | Typed accessors over this app's line format | — |
| `Backup\Support\LineFieldWithDefault` | value | Same, for keys an older file omits | — |
| `Fetch\Support\ContentTypeCharset` | value | Parses an RFC header parameter | — |
| `Fetch\Support\CrossFamilyFailover` | **policy** | Which failures justify another address family, tuned on heise and taz | E2 |
| `Fetch\Support\EgressOptions` | value | curl options derived from the proxy config; gains `freshConnectionAfter()` (E2) | — |
| `Fetch\Support\HostKey` | value | One normalisation of a host name | — |
| `Fetch\Support\ProxyHandshakeFailure` | value | Translates curl's SOCKS5 protocol messages | — |
| `Fetch\Support\ResponseHeader` | value | Reads a header without throwing | — |
| `Fetch\Support\UrlResolver` | value | RFC 3986 reference resolution | G5 edits it |
| `Html\Support\ClassTokenMatcher` | value | HTML's whole-token class semantics | — |
| `Html\Support\DesktopViewport` | **policy** | The 1280 px stand-in width, "every publisher's breakpoint seen so far" | E2 |
| `Html\Support\HtmlDocumentParser` | value | Wraps the HTML5 parser | G4 edits it |
| `Html\Support\HtmlTranscoder` | value | Charset transcoding | — |
| `Html\Support\ImageSourceUrl` | value | A scheme test the sanitizer's rules fix | — |
| `Html\Support\JsonLd` | value | JSON-LD's format | — |
| `Html\Support\Srcset` | value | The HTML `srcset` grammar (the issue's own example) | — |
| `Ingest\Support\EntryEffectiveDate` | value | This app's ordering definition, from the ingest context | — |
| `Ingest\Support\EntryMediaAssembler` | value | Maps parsed media onto the entity's lists | — |
| `Ingest\Support\EntrySnippet` | value | A length cut over `EntryPlainText` | — |
| `Mail\Transport\Support\CurlSmtpOptions` | value | curl options from the resolved transport | — |
| `Parser\Support\AtomDiscussion` | value | Atom's `rel="replies"` links | — |
| `Parser\Support\DateParser` | value | Date parsing, normalised to UTC | — |
| `Parser\Support\FeedImageExtractor` | value | Where RSS 1.0/2.0 and Atom put the feed's own image | — |
| `Parser\Support\FeedItemImageSelector` | **policy** | The precedence between Media RSS, enclosures, custom elements and body images | E3 |
| `Parser\Support\FeedMediaClassifier` | value | MRSS `medium`/`type` attributes, then the extension table | — |
| `Parser\Support\GuidFallback` | value | A stable synthetic id | — |
| `Parser\Support\ItemCategoryExtractor` | value | RSS, Atom and Dublin Core category elements | — |
| `Parser\Support\ItemImageExtractor` | **policy** | Widest-wins, the beacon filter, utopia.de's custom elements | E3 |
| `Parser\Support\ItemMediaExtractor` | **policy** | How a `<media:group>` collapses to one medium | E3 |
| `Parser\Support\MediaDuration` | value | MRSS and iTunes duration formats | — |
| `Parser\Support\MediaImageClassifier` | value | The image half of `FeedMediaClassifier` | — |
| `Parser\Support\XmlHelper` | value | DOM traversal helpers | — |
| `Reader\AuthorBio\Support\AuthorProfileLink` | **policy** | A curated list of author-profile path segments | D4 |
| `Reader\Media\Sibling\Support\KeyedOccurrences` | value | A lexical scan for `key: id`; its window bounds the scan, it chooses nothing | — |
| `Reader\Media\Sibling\Support\NearbyPoster` | **policy** | Largest-still-wins within 2000 bytes, tuned on ZDF (#952) | D3 |
| `Reader\Media\Support\NarrationSignals` | **policy** | Tokens measured per publisher (#903, #959) | D3 |
| `Reader\Media\Support\PageFurniture` | **policy** | Chrome selectors measured on twelve publishers (#748, #1058) | D3 |
| `Reader\Media\Support\PlayerPoster` | **policy** | First https picture within three ancestors (#796) | D3 |
| `Reader\Paywall\Support\MembershipCheckout` | **policy** | Curated checkout endpoints (#998) | D2 |
| `Reader\Paywall\Support\OutsideFurniture` | **policy** | Calls `PageFurniture` (contagion) | D2 |
| `Reader\Paywall\Support\PaywallBlocks` | **policy** | Curated gate fragments (#908) | D2 |
| `Reader\Paywall\Support\PaywallSignals` | **policy** | The paywall verdict (named by the issue) | D2 |
| `Reader\Paywall\Support\SchemaOrgAccess` | value | Reads schema.org's `isAccessibleForFree` | — |
| `Reader\Support\ArticleContentGate` | **policy** | The 200-character floor for an article (#748) | D4 |
| `Reader\Support\BlockText` | **split** | `collapsed()`, `linkTextLength()` measure and stay; `isLinkDominated()`'s 0.6 ratio (#779) moves to `LinkListDetector` | D5 |
| `Reader\Support\ImageProxyUrl` | value | Decodes three URL-wrapping formats; no publisher is named in code | — |
| `Reader\Support\LeadingEngagementBlocks` | **split** | `in()`, `isTimeOnly()` are HTML structure and stay; `isProse()`, `isProtectedContent()`, `isDecorativeIcon()` move to `LeadingBlockJudge` | D5, D6 |
| `Reader\Support\LeadingEngagementRules` | **policy** | The prose bar (120 characters, 0.8 link share), the curated counter nouns and the kicker limits, tuned on observed heads (#770, #901); its date-line memo is already `DateLineRecognizer` (#1202 I) | D1, D6 |
| `ReaderAudit\Support\DatabaseValue` | value | Typed reads of DBAL values | — |
| `ReaderAudit\Support\SuspiciousPhrases` | **policy** | Phrase lists that grow with every publisher (#744) | E4 |
| `Recommendation\Prompt\Support\PlausibleDuplicateShare` | **policy** | The 50 % ceiling measured in production (#396) | E4 |
| `Recommendation\Prompt\Support\RecommendationAnswerBudget` | **policy** | Token costs measured per phase (#327, #437, #493) | E4 |
| `Recommendation\Prompt\Support\RecommendationPromptText` | value | Constants only | — |
| `Recommendation\Run\Support\RunLogRetention` | value | A constant | — |
| `Recommendation\Settings\Support\RecommendationSettingsBounds` | value | Constants shared with the request validator | — |
| `Scraper\Support\CardTitle` | **policy** | The title candidate order of the scraper design | E5 |
| `Scraper\Support\TextNormalizer` | value | Whitespace and soft-hyphen normalisation | — |
| `Search\Support\LikePattern` | value | SQL `LIKE` escaping | — |
| `Search\Support\SavedSearchTerms` | value | Maps the stored row onto the search model | — |
| `Text\Support\EntryExcerpt` | value | A word-boundary cut | — |
| `Text\Support\EntryPlainText` | value | Text reduction; the junk tokens are a normalisation, not a choice between candidates | — |
| `Text\Support\PlainText` | value | Tag stripping and entity decoding (the issue's own example) | — |
| `Text\Support\Whitespace` | value | Whitespace collapse | — |
| `Url\Support\AbsoluteHttpUrl` | value | A scheme test | — |
| `Url\Support\FeedWebsite` | policy, **stays** | Tuned on a 111-feed library, but its only caller is the static `Http\SubscriptionJson` (D-2) | — |
| `Url\Support\HttpsImageUrl` | value | Mixed-content and column-length rules | — |
| `Url\Support\UrlOrigin` | value | A URL's origin | — |

## Planner rulings (2026-09-29), applied at the 5dbc55d3 reconcile

- **ACCEPTED as recommended:** D-1 to D-9, D-11 to D-19, and D-21 to D-23. **Applied:** the tasks each decision below points to.
- **D-10 ACCEPTED with a condition:** the guarded accessors must return objects that carry their own transitions and guard their own invariants (embeddables or aggregate parts with behaviour, such as `RunThrottle::recordBackoff()`), never raw state for callers to change field by field. Renaming a real query to `get…`/`is…` is fine. Renaming a command so that PHPMD's getter/setter ignore pattern stops counting it is gaming the metric, and is not allowed. Each rename must name what the method does. List every renamed method in the PR body, with what it does. **Applied:** H5. The accessors hand out `RunThrottle` (`deferUntil()`, `reduceConcurrency()` flooring at 1) and `RunCallAttempts` (`recordInvalidReply()` writes the count and the reply together), and Step 3's grep proves neither has a setter. The earlier `stampProvider()` → `setProvider()` rename is dropped: it renamed a command. The three query renames stay. PR H's body lists them with what each does.
- **D-20 ACCEPTED with a pin:** a functional test proves that an API exception is both logged by `ErrorListener::logKernelException` and answered as problem+json by our listener. Deletion check: set the priority above 0 and quote the FAIL showing the log line missing. **Applied:** G9 (`tests/EventListener/ApiExceptionIsLoggedAndAnsweredTest.php`, deletion check `priority: 64`).
- **Deletion checks:** each task implementer runs every deletion check and quotes its FAIL output in the task report, and the reviewer re-runs at least one. Every grep check gets a positive control. **Applied:** Global Constraints, and each grep step below that names its control.

## Planner decisions

All ruled; the plan is written as ruled.

- **D-1 (the boundary): RULED, accepted** (D1–D7, E1–E5, "Support/ classification"). "`Support/` computes; a service decides", with the reviewer's test above. It turns the issue's two examples (`Srcset`, `PlainText` stay; `ItemImageExtractor`, `PaywallSignals` go) into one test a reviewer can apply to a new class. It sends 22 classes to services instead of the issue's 44 (20 policies and the two split-off verdicts), plus the audit's `LeadingRegion` (D-reconcile-2): the issue counted the whole Parser package, and nine of its twelve `Support/` classes read a standard (RSS, Atom, MRSS, Dublin Core, iTunes).
- **D-2 (`FeedWebsite`): RULED, accepted** (D7 Step 1). Policy by D-1, but its only caller is `Http\SubscriptionJson`, a static mapper (§10: "its static `…Json` mappers are that layer's own role"). Injecting it would turn `SubscriptionJson` and its five controller call sites into a service. It stays in `Url/Support/`; §10 names it as the one exception, with that reason.
- **D-3 (splits): RULED, accepted** (D5, D6). `BlockText` and `LeadingEngagementBlocks` mix measurements (models call `LeadingEngagementBlocks::in()` from static factories) with tuned verdicts. The measurements stay; `LinkListDetector::isLinkDominated()` and `LeadingBlockJudge` (`isProse()`, `isProtectedContent()`, `isDecorativeIcon()`) become services. Moving the whole classes would force `PageTextBlocksModel`, `ExtractedBodyModel` and 30 test sites off static factories for no policy gain. The same split reaches `ReaderAudit\Model\BodyBlockModel` (D-reconcile-2): the model keeps `linkedTextLength()`, and `LeadingRegion` applies the prose verdict.
- **D-4 (`ArticlePageReader`): RULED, accepted** (D2). `ArticleExtractor` takes 9 constructor arguments; PHPMD's `ExcessiveParameterList` fires at 10, and D2/D4 add two policies. `readPage()` moves to a new root service `Reader\ArticlePageReader` with the normaliser, the three page scanners and `PaywallSignals`; `ArticleExtractor` drops to 7.
- **D-5 (matcher direction): RULED, accepted** (F1). `$this->once()`/`$this->never()`/… everywhere, because PhpStorm's EA WARNING on `self::never()` and `self::once()` blocks the lint gate (ledger), and #1202's Global Constraints already chose `$this->never()`. `InvocationMatchersOnThisRule` makes the next `self::once()` fail `composer stan` instead of waiting for a lint run on that file.
- **D-6 (constructor-default guard): RULED, accepted** (C1). `NoCollaboratorDefaultRule` over `App\Service` (outside `Model/`, `Dto/`, `Pass/`), `Controller`, `EventListener`, `Security`, `Command` and `Http`. Value defaults in models (`ParsedEntryModel`, `SlideModel`) and row values (`EntryListRow`) stay legal.
- **D-7 (service locator guard): RULED, accepted** (C2). `getRepository` joins `QueriesLiveInRepositoriesRule::QUERY_METHODS`. `ActionTokenService` is the only caller outside `src/Repository` at `5dbc55d3`.
- **D-8 (repository interfaces): RULED, accepted** (A3–A7). Eleven narrow, consumer-owned interfaces, each in a folder named after it in the consumer's module, aliased in `services.yaml` like `SavedSearchMembershipWriterInterface`, not one wide interface per repository: a consumer names only what it calls.
- **D-9 (`RestoreLoadPassTest`'s `never()->method('findOneBy')`): RULED, accepted** (A7 Step 4). An interface without `findOneBy()` cannot be configured to expect it, so the line goes. `RestoreLoadPass` now depends on `RestoreFeedsInterface`, which has no per-URL lookup, so the guarantee moved from a runtime expectation into the type.
- **D-10 (`RecommendationRun`, TooManyPublicMethods): RULED, accepted with a condition** (see "Planner rulings"; H5). 17 public methods (the constructor among them) count against PHPMD's 10. The three queries take honest `get…`/`is…` names (`getProgress()`, `isRetryDeferredAt()`, `getWaveConcurrencyCap()`). The throttle and call-attempt transitions go through two guarded accessors, `getRunningThrottle()` and `getRunningCallAttempts()`, which throw unless the run is running, so the aggregate's invariant holds. `stampProvider()` keeps its name. That leaves 10, PHPMD's ceiling.
- **D-11 (`ExcessiveParameterList` on the settings values): RULED, accepted** (H4). Two `App\Entity` values, `RecommendationHistoryCaps` (favorites, kept, viewed) and `RecommendationPoolLimits` (candidate pool size, lookback days, picks limit), shared by `RecommendationSettingsValues` (13 → 9) and `EffectiveRecommendationSettingsModel` (12 → 8). The JSON wire shape is unchanged.
- **D-12 (shared module enums): RULED, accepted** (H2). `TickDriver`, `RecommendationDriverKind`, `ScrapeFailureReason` and `VisualMediaKind` stay in the module that owns their meaning; §8's "for now" becomes the rule. `ServiceModuleCycleRule` already guarantees that sharing them closes no cycle, and `TickDriver` could not move to `App\Enum` anyway (`retryPlan()` returns an `Ai` model).
- **D-13 (`SupportedLocale`): RULED, accepted** (H2). It stays in `App\Enum`, justified in §8: it is the value set of the stored `User::$locale`, and `App\Enum` is the lowest home the request DTO, `translation.yaml` and the signup factory all reach.
- **D-14 (repositories importing Service classes): RULED, accepted** (H3). `PersistenceKnowsNoServiceRule` gains a scope for `App\Repository`: it may name a Service `Model/`, `Support/` or `Exception/` class and any `…Interface` (the inversion `SavedSearchMembershipWriterInterface` uses), nothing else. `EntryBatchInserter` (reads `Backup\Dto\EntryLine` and calls `UrlNormalizer` on the restore's measured hot path) is the rule's one allow-listed class, with that reason.
- **D-15 (viewer time zone): RULED, accepted** (H1). `ViewerTimeZoneModel` moves to `Service/Clock/Model/`, the leaf module both `Reading` and `Recommendation` already may use, and `ServiceModuleBoundaryRule` keeps `Reading → Recommendation` out.
- **D-16 (`UserScopedMethodOwnerFirstRule`): RULED, accepted: no rule** (G2). After G2 the survey finds 0 violations, the reordered pair differs in type (`int` and `list<int>`) so PHPStan catches a swapped call, and a name-based rule risks false positives.
- **D-17 (`parseOrNull()`): RULED, accepted** (G4). `parseOrEmpty()` returns `HTMLDocument::createEmpty()`, and `parseOrNull()` is deleted once its last caller is gone. Every non-pipeline caller already treats "nothing to read" like an empty document.
- **D-18 (the reorder lookup): RULED, accepted** (G1). `PositionReorderer::reorderFound(list<int>, \Closure(int): PositionedInterface)`; the two catalog editors pass `$this->categories->getById(...)`. `TagOrdering` keeps its owned-set map: it needs every owned id for the permutation check, not a lookup by the requested ids.
- **D-19 (factory properties): RULED, accepted** (G8). A property holding an application `…Factory` or `…FactoryInterface` ends in `Factory` (`$tagFactory`, not `$tags`, which elsewhere names a repository). Symfony's named rate limiters (`$…Limiter`) keep their names: autowiring binds them by parameter name.
- **D-20 (exception listener order): RULED, accepted with a pin** (see "Planner rulings"; G9). `#[AsEventListener(event: ExceptionEvent::class, priority: -64)]`: after `ErrorListener::logKernelException` (0), before `ErrorListener::onKernelException` (-128). `setResponse()` stops propagation, so today's order is registration order.
- **D-21 (`ReaderAuditReportCommand`): RULED, accepted: no change** (Scope table). #1202 F ruled `AuditReportHtml` a per-call object.
- **D-22 (`ParsesHtml` name): RULED, accepted** (F2): `document()`, the method #1202 left (R-5).
- **D-23 (`applyFlags` WATCH): RULED, accepted: no change** (Scope table). No third copy exists; `BulkSubscriptionChange` carries the id list `SubscriptionChange` does not, so the two values stay apart.

## Execution rulings (PR C)

- **F9: strict types everywhere (final review B, M-1; planner ruling, revised on Lars's word).** A new PR F tooling task. Symfony's own recipe files stay as shipped: the seven entry points (`public/index.php`, `bin/console` and the others from Flex recipes) get no declaration, and `bin/` and `public/` stay outside the sniff. `Generic.PHP.RequireStrictTypes` alone (not full PSR-12, which generated migrations would fail) is extended to `migrations/` (every migration already declares strict types) and to the `tests/PhpStan` fixtures unless that clashes with their deliberate PSR-12 exclusion. Control: before any edit, the sniff over the new paths prints nothing. Deletion check, FAIL quoted: drop the declaration from one migration. PR F's body says in one line that the recipe entry points are deliberately left as shipped.

## Execution rulings (PR G)

- **G6 dropped on Lars's objection.** `$lineClass::fromLine()` is a variable static call PhpStorm cannot find usages through; the real problem (kind strings, an `object` return, `instanceof` chains in four consumers) is #1227 (spike + refactoring, not scheduled in this series). 4689dce6 is reverted by 57ccc7ad: `BackupReader` keeps its `match` and its Infection ignore until #1227. PR G's body says "two equivalent mutants gone" (UrlResolver, the 304).
- **G3's pin holds two included subscriptions and compares ids.** With one row an `ArrayOneItem` mutant escaped; comparing entities made a `TrueValue` mutant hang the run while PHPUnit exported the Doctrine graph.
- **`FeedScheduler::grownInterval()` is `$minutes + intdiv($minutes + 1, 2)`.** It equals `round($minutes * 1.5)` for every non-negative int, and the floor guard absorbs the rest; `round` → `ceil` was an equivalent mutant on the `n.0`/`n.5` values.

## Global Constraints

- **Paths and commands are relative to `backend/`**, except steps marked "from the repository root", `docs/…` and `CLAUDE.md`. The #1202 scripts run as `php ../docs/superpowers/plans/2026-09-28-1202-scripts/<script>.php`.
- **Read before you write.** Every edit below names the exact text it replaces. If that text is not there (a #1202 PR or a concurrent merge changed it), stop and report the file and the text you found.
- **Clean Code (CLAUDE.md) is mandatory:** names reveal intent; `final readonly` by default; three parameters at most (constructors aside), no boolean flag parameters; guard clauses; no `new` on a collaborator inside a method; queries in `src/Repository`; typed exceptions in `Service/*/Exception`.
- **Comments:** default none; at most three lines; delete on sight what restates code. A moved class keeps its comments (#1202 D10). New files carry comments only at the CLAUDE.md bar.
- **pdepend 2.16.2 (`composer md`) cannot parse `public private(set)`, and `new Foo()->bar()` must be written `(new Foo())->bar()`.** Never use either in `src`.
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold, and add no suppression.
- **phptramp:** no chain of 4+ hops across 2+ classes forwarding an unread parameter. If only the tramp step fails in CI, run `composer show larspohlmann/phptramp` first.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`. Run `bin/console cache:clear && bin/console cache:warmup` after every move or new service.
- **Role folders (#1202):** `ServiceRoleRule`, `ServiceModuleCycleRule` and `ServiceModuleBoundaryRule` stay green. `ServiceRoleRule` enforces all 16 checks in `composer stan` (there is no `composer roles` and no `serviceRoleChecks` list any more). A new service is a `final readonly` class in its module root; a new interface ends in `Interface` and sits in a folder named after it; what stays in `Support/` is `final`, keeps its private constructor at the class's end and holds no static property (`supportShape`); a moved class takes its tests along. No class moves between role folders unless its role changes (policy becoming a service). Count a check's errors with `composer stan -- --error-format=raw --no-progress 2>&1 | grep -c 'Service role "<check>"'`: the raw format prints one `path:line:message` line per error, while the default table wraps messages and prints each identifier (`simpleFeedReader.serviceRole.<check>`) on a line of its own, so a bare check name there counts every error twice.
- **PhpStorm inspections** (`mcp__phpstorm__lint_files`) on every changed PHP file: ERROR and WARNING block.
- **PSR-12, 120 columns.**
- **Tests:** PHPUnit 12 attributes; `assertSame`, never `assertEquals` on values; persisted ids through `requireId()`; invocation matchers on `$this` (`$this->once()`, `$this->never()`), never `self::`.
- **Every new test or pin gets a deletion check** that only its one edit makes fail. The step names the exact edit and quotes the expected FAIL (PHPUnit's `Failed asserting that …`, the PHPStan or PHPCS message). Assert presence before absence, and never let the expected value be one the broken code also produces (`null`, `0`, `''`, `false`, a default, `strpos()`'s `false`). The task implementer runs **every** deletion check, restores each by hand with the Edit tool (never `git checkout --`), and quotes its FAIL output in the task report: a check without a quoted FAIL is not done. The reviewer re-runs at least one per task it reviews and at least one per PR, and quotes the FAIL too.
- **Every grep-based check gets a positive control**: the same pattern and pathspec must print a known hit, either one planted and then removed, or one the step names that exists on purpose (a count above 0 at `origin/develop`, a kept call). A grep that is expected to print nothing is worthless without it. A plain pathspec like `'src/Service/**/Factory/'` matches nothing; use `':(glob)…'` or a directory. `git grep -F` with backslashes matches nothing: use `-E` and escape the backslash.
- **Never run the MySQL leg's cache prep beside the native leg.** `docker compose exec php rm -rf var/cache/test*` (the prep before `composer test` in the container) deletes the native leg's `var/cache/test*` on the bind mount, so a native `php bin/phpunit` running at the same time fails for reasons unrelated to the code. Run the native leg, then the prep and the MySQL leg, one after the other. The two legs may overlap only when no cache prep runs.
- **Infection:** an escaped mutant on a touched line gets a killing test in the task that owns it. Never an `ignore`, never a lower `minMsi`. Renamed files are outside `infection:diff`'s `--git-diff-filter=AM`.
- **Gates for every task:** its tests, `composer check`, `composer md`, `bin/console lint:container`, and the PhpStorm inspections. The one exception: between a move (D1, E1) and the conversion that ends its last `supportHome` error (D6, E5), the stan step runs as the raw count in PR D's intro.
- **Gates per PR (Finishing):** `php bin/phpunit` (SQLite), then, after it finishes, `docker compose exec php composer test` (MySQL; check the containers are current first, never run two MySQL legs at once, and never beside the native leg when the cache prep runs, see above); `composer check`, `composer md`, `composer infection:diff`; today's dev log scan (`ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level >= 300)'`).
- **Move PRs (D, E, H1)** also run Appendix M's stale-name grep and worker health check.
- **Recommendation PRs (A, E, H)** also run Appendix R's real recommendation run.
- **Commits:** `refactor(#1169): <lower-case summary>`, one per task (a move commits separately from its conversion), no attribution lines.
- **PR bodies:** PRs A–G say `Refs #1169`. Neither their bodies nor any commit on their branches contains "close", "fix" or "resolve" in any form. `gh pr create` runs only as the last link of an `&&` chain behind the closing-keyword scan of the commits and the body (Appendix K), never on its own line and never after `;`: a hit must stop the command. Mind the method names: write "`OwnedSubscriptions` takes the owner first", never the `resolve…` method name, in a commit or a body. PR H's body says `Closes #1169`.
- **Branches**, each cut from `origin/develop` after the previous PR merges: `refactor/1169-final-persistence`, `refactor/1169-strict-and-readonly`, `refactor/1169-injected-collaborators`, `refactor/1169-reader-policy-services`, `refactor/1169-policy-services`, `refactor/1169-tests-and-rules`, `refactor/1169-dry-and-typed-results`, `refactor/1169-value-homes`.
- **Merge:** watch with the Monitor tool, one command: `gh pr checks <PR> --watch --fail-fast`. When it exits 0, `gh pr merge <PR> --merge`. Never `--auto`.
- **The checkout is shared.** Run `git status --short && git branch --show-current` before any `switch`, `reset` or `stash`. Work in place, no worktrees.

---

# PR A — Persistence classes are `final`

### Task A0: Preflight, branch, plan copy, reconcile checks

**Files:**
- Create: `docs/superpowers/plans/2026-09-29-1169-final-readonly-injection.md` (this plan)

- [ ] **Step 1: #1202 is closed and the checkout is free (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1202 --json state --jq .state
gh issue view 1169 --json state --jq .state
```
Expected: a clean tree (or only another session's files, which you leave alone); `CLOSED`, then `OPEN`. If #1202 is open, stop: this plan is queued behind it.

- [ ] **Step 2: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c refactor/1169-final-persistence origin/develop
cp <the plan file the planner handed you> docs/superpowers/plans/2026-09-29-1169-final-readonly-injection.md
git add docs/superpowers/plans/2026-09-29-1169-final-readonly-injection.md
git commit -m "refactor(#1169): the plan"
```

- [ ] **Step 3: The reconcile assumptions hold (from `backend/`)**

```bash
git grep -n -E '^(final|abstract) readonly class (Rss1Parser|Rss2Parser|AbstractAtomParser|EntrySanitizer|RecommendationPromptBuilder|ClusterLayer|SemanticLayer|PictureSources)( |$)' origin/develop -- src/Service | wc -l
git ls-tree --name-only origin/develop -- src/Service/Reader/LeadingEngagementRules.php src/Service/Reader/Support/LeadingEngagementRules.php src/Service/Reader/DateLineRecognizer.php
git grep -c -E 'public static function ' origin/develop -- src/Service/Reader/Support/LeadingEngagementRules.php
git grep -n -E 'function isProse\(' origin/develop -- src/Service/Reader src/Service/ReaderAudit
git grep -n -E 'public function __construct\(private DateLineRecognizer \$dateLines\)|new LeadingFurniture\(\$entryAuthor, \$this->dateLines\)' origin/develop -- src/Service/Reader/BodyCleaning/BodyCleaningStep/LeadingEngagementCleaner.php
git grep -n -E 'private function (document|parse)\(string' origin/develop -- tests/Support/ParsesHtml.php
git grep -L 'private function __construct' origin/develop -- 'src/Service/*/Support/*.php' 'src/Service/*/*/Support/*.php' 'src/Service/*/*/*/Support/*.php' | wc -l
git grep -l 'private function __construct' origin/develop -- 'src/Service/*/Support/*.php' 'src/Service/*/*/Support/*.php' 'src/Service/*/*/*/Support/*.php' | wc -l
git grep -n 'private TrailingBlankRemover \$blankTail = new TrailingBlankRemover(),' origin/develop -- src/Service/Sanitize/EntrySanitizer.php
git grep -c -E 'serviceRoleChecks|phpstan-service-roles' origin/develop -- phpstan.dist.neon composer.json
git ls-tree --name-only origin/develop -- src/Security/InvalidatePasswordChangeTokensListener.php tests/PhpStan/ServiceRoleClass.php
```
Expected (as at `5dbc55d3`):
- `8`: the eight classes are `final readonly` (`abstract readonly` for `AbstractAtomParser`) after #1202 G1 (R-1). `PictureSources` already was.
- `src/Service/Reader/DateLineRecognizer.php` and `src/Service/Reader/Support/LeadingEngagementRules.php`, not the root `LeadingEngagementRules.php` (R-2).
- `…LeadingEngagementRules.php:10`: ten public statics (R-2; D6 converts all ten).
- Three `isProse` lines: `ReaderAudit/Model/BodyBlockModel.php` (`public function isProse(): bool`), `Reader/Support/LeadingEngagementBlocks.php` (`public static function isProse(LeadingBlockModel $block)`) and `Reader/Support/LeadingEngagementRules.php` (`public static function isProse(string $text, int $linkTextLength)`). D5 and D6 read them.
- Two lines: the cleaner's one-parameter constructor and its `new LeadingFurniture($entryAuthor, $this->dateLines)` (D5 and D6 append to both).
- One line naming `document` (R-5). F2 uses that name.
- `0`, then `71`: every `Support/` class has a private constructor (R-2); the second count is the pathspec's positive control.
- One line (R-1).
- No output: `grep -c` prints nothing for a file with no match, and neither file names the retired role list (D-reconcile-6). Positive control: `git grep -c 'ServiceRoleRule' origin/develop -- phpstan.dist.neon` prints `origin/develop:phpstan.dist.neon:1`.
- Both paths (R-4, F8).

A different result is a reconcile gap: stop and report it with the output.

---

### Task A1: `PersistenceClassesAreFinalRule` and its test (not yet registered)

The rule lands first and is registered in A9, once every class it names is final.

**Files:**
- Create: `tests/PhpStan/PersistenceClassesAreFinalRule.php`, `tests/PhpStan/PersistenceClassesAreFinalRuleTest.php`, `tests/PhpStan/data/persistence-classes-are-final-fixtures.php`

**Interfaces:**
- Produces: `App\Tests\PhpStan\PersistenceClassesAreFinalRule`, a `Rule<InClassNode>` with no constructor argument, identifier `simpleFeedReader.persistenceClassesAreFinal`, message `Persistence classes are final: <FQCN> is not. A test doubles the interface its consumer owns, never the persistence class (#1169).`

- [ ] **Step 1: The fixture**

`tests/PhpStan/data/persistence-classes-are-final-fixtures.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for PersistenceClassesAreFinalRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    class OpenEntity
    {
    }

    final class ClosedEntity
    {
    }

    interface EntityContract
    {
    }

    enum EntityKind
    {
        case One;
    }
}

namespace App\Repository\Fixtures {
    abstract class AbstractBaseRepository
    {
    }

    final readonly class ClosedRepository
    {
    }
}

namespace App\Service\Fixtures {
    class OpenService
    {
    }
}
```

- [ ] **Step 2: The failing test**

`tests/PhpStan/PersistenceClassesAreFinalRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<PersistenceClassesAreFinalRule> */
final class PersistenceClassesAreFinalRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new PersistenceClassesAreFinalRule();
    }

    public function testItReportsEveryClassInTheEntitiesAndRepositoriesThatIsNotFinal(): void
    {
        $this->analyse(
            [__DIR__ . '/data/persistence-classes-are-final-fixtures.php'],
            [
                [self::message('App\Entity\Fixtures\OpenEntity'), 9],
                [self::message('App\Repository\Fixtures\AbstractBaseRepository'), 28],
            ],
        );
    }

    private static function message(string $className): string
    {
        return sprintf(
            'Persistence classes are final: %s is not. '
            . 'A test doubles the interface its consumer owns, never the persistence class (#1169).',
            $className,
        );
    }
}
```
Run: `php bin/phpunit tests/PhpStan/PersistenceClassesAreFinalRuleTest.php`
Expected: FAIL with `Class "App\Tests\PhpStan\PersistenceClassesAreFinalRule" not found`.

- [ ] **Step 3: The rule**

`tests/PhpStan/PersistenceClassesAreFinalRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Entities, embeddables and repositories are final: native lazy objects need no proxy subclass, and a test doubles
 * the interface a consumer owns instead (#1169).
 *
 * @implements Rule<InClassNode>
 */
final readonly class PersistenceClassesAreFinalRule implements Rule
{
    private const array PERSISTENCE_NAMESPACES = ['App\\Entity\\', 'App\\Repository\\'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        if (!$class->isClass() || $class->isAnonymous() || $class->isFinalByKeyword()) {
            return [];
        }
        if (!ClassNameReferences::isInAnyOf($class->getName(), self::PERSISTENCE_NAMESPACES)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Persistence classes are final: %s is not. '
                . 'A test doubles the interface its consumer owns, never the persistence class (#1169).',
                $class->getName(),
            ))
                ->identifier('simpleFeedReader.persistenceClassesAreFinal')
                ->line($node->getOriginalNode()->getStartLine())
                ->build(),
        ];
    }
}
```

- [ ] **Step 4: Run to watch it pass**

Run: `php bin/phpunit tests/PhpStan/PersistenceClassesAreFinalRuleTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

One at a time, restoring each by hand:
1. Delete ` || $class->isFinalByKeyword()` from the first guard. Expected: FAIL, `Failed asserting that two strings are identical.`, with the extra lines `13: Persistence classes are final: App\Entity\Fixtures\ClosedEntity is not. …` and `32: … App\Repository\Fixtures\ClosedRepository …`.
2. Replace the second guard's condition with `false`. Expected: FAIL, with the extra line `38: Persistence classes are final: App\Service\Fixtures\OpenService is not. …`.
3. Delete `!$class->isClass() || ` from the first guard. Expected: FAIL, with extra lines for `EntityContract` (17) and `EntityKind` (21).

- [ ] **Step 6: Gates and commit**

Run: `composer check`, then the PhpStorm inspections on the three files.
```bash
git add tests/PhpStan/PersistenceClassesAreFinalRule.php tests/PhpStan/PersistenceClassesAreFinalRuleTest.php tests/PhpStan/data/persistence-classes-are-final-fixtures.php
git commit -m "refactor(#1169): a rule that entities, embeddables and repositories are final"
```

---

### Task A2: Six repositories nothing doubles are `final`

**Files:**
- Modify: `src/Repository/ActionTokenRepository.php`, `CatalogCategoryRepository.php`, `CatalogFeedRepository.php`, `SubscriptionRepository.php`, `SubscriptionTagRepository.php`, `TagRepository.php`

(`EntryListRepository`, the issue's seventh, becomes final in A8 with its base class.)

- [ ] **Step 1: Nothing doubles them**

```bash
git grep -n -E '(createMock|createStub|getMockBuilder|createPartialMock|createConfiguredMock)\((ActionToken|CatalogCategory|CatalogFeed|Subscription|SubscriptionTag|Tag)Repository::class' -- tests
git grep -c -E '(createMock|createStub)\(PreferencesRepository::class' -- tests | head -1
```
Expected: the first grep prints nothing; the second (positive control: a repository A5 handles) prints a count above 0.

- [ ] **Step 2: `final`**

In each of the six files, replace `class <Name>Repository extends ServiceEntityRepository` with `final class <Name>Repository extends ServiceEntityRepository`:
```bash
for r in ActionToken CatalogCategory CatalogFeed Subscription SubscriptionTag Tag; do
  perl -pi -e "s/^class ${r}Repository extends ServiceEntityRepository/final class ${r}Repository extends ServiceEntityRepository/" "src/Repository/${r}Repository.php"
  grep -c "^final class ${r}Repository extends ServiceEntityRepository" "src/Repository/${r}Repository.php"
done
```
Expected: six lines reading `1`.

- [ ] **Step 3: Gates and commit**

Run: `bin/console cache:clear && php bin/phpunit tests/Repository tests/Service/Tag tests/Service/Catalog tests/Service/Subscription && composer check && composer md`
Expected: PASS.
```bash
git add src/Repository
git commit -m "refactor(#1169): the repositories no test doubles are final"
```

---

### Task A3: Passkey interfaces; `UserPasskeyRepository` and `UserIdentityRepository` final

`PasskeyRemovalPolicy` asks how many passkeys an account holds and whether it has a sign-in identity; `RelyingPartyChange` counts and deletes every passkey. Each consumer gets the interface it calls; the repositories implement them.

**Files:**
- Create: `src/Service/Passkey/PasskeyCount/PasskeyCountInterface.php`, `src/Service/Passkey/SignInIdentities/SignInIdentitiesInterface.php`, `src/Service/Settings/EnrolledPasskeys/EnrolledPasskeysInterface.php`
- Modify: `src/Repository/UserPasskeyRepository.php`, `src/Repository/UserIdentityRepository.php`, `src/Service/Passkey/PasskeyRemovalPolicy.php`, `src/Service/Settings/RelyingPartyChange.php`, `config/services.yaml`
- Test: `tests/Service/Passkey/PasskeyRemovalPolicyTest.php`, `tests/Service/Settings/RelyingPartyChangeTest.php`

**Interfaces:**
- Produces:
  - `PasskeyCountInterface::countForUser(User $user): int`
  - `SignInIdentitiesInterface::existsForUser(User $user): bool`
  - `EnrolledPasskeysInterface::countAll(): int`, `EnrolledPasskeysInterface::deleteAll(): void`

- [ ] **Step 1: The interfaces**

`src/Service/Passkey/PasskeyCount/PasskeyCountInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\PasskeyCount;

use App\Entity\User;

interface PasskeyCountInterface
{
    public function countForUser(User $user): int;
}
```

`src/Service/Passkey/SignInIdentities/SignInIdentitiesInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Passkey\SignInIdentities;

use App\Entity\User;

interface SignInIdentitiesInterface
{
    public function existsForUser(User $user): bool;
}
```

`src/Service/Settings/EnrolledPasskeys/EnrolledPasskeysInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Settings\EnrolledPasskeys;

interface EnrolledPasskeysInterface
{
    public function countAll(): int;

    public function deleteAll(): void;
}
```

- [ ] **Step 2: The repositories implement them and are final**

`src/Repository/UserPasskeyRepository.php`:
- Replace `class UserPasskeyRepository extends ServiceEntityRepository` with:
```php
final class UserPasskeyRepository extends ServiceEntityRepository implements
    PasskeyCountInterface,
    EnrolledPasskeysInterface
```
- Add, in the sorted `use` block: `use App\Service\Passkey\PasskeyCount\PasskeyCountInterface;` and `use App\Service\Settings\EnrolledPasskeys\EnrolledPasskeysInterface;`.

`src/Repository/UserIdentityRepository.php`:
- Replace `class UserIdentityRepository extends ServiceEntityRepository` with `final class UserIdentityRepository extends ServiceEntityRepository implements SignInIdentitiesInterface`.
- Add `use App\Service\Passkey\SignInIdentities\SignInIdentitiesInterface;` in order.

- [ ] **Step 3: The consumers take the interfaces**

`src/Service/Passkey/PasskeyRemovalPolicy.php`:
- Replace `private UserPasskeyRepository $passkeys,` with `private PasskeyCountInterface $passkeys,` and `private UserIdentityRepository $identities,` with `private SignInIdentitiesInterface $identities,`.
- Replace `use App\Repository\UserIdentityRepository;` and `use App\Repository\UserPasskeyRepository;` with `use App\Service\Passkey\PasskeyCount\PasskeyCountInterface;` and `use App\Service\Passkey\SignInIdentities\SignInIdentitiesInterface;`, in order.

`src/Service/Settings/RelyingPartyChange.php`:
- Replace `private UserPasskeyRepository $passkeys,` with `private EnrolledPasskeysInterface $passkeys,`.
- Replace `use App\Repository\UserPasskeyRepository;` with `use App\Service\Settings\EnrolledPasskeys\EnrolledPasskeysInterface;`, in order.

`config/services.yaml`, directly after the line `    App\Service\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface: '@App\Repository\SavedSearchEntryMembershipRepository'`, add:
```yaml
    App\Service\Passkey\PasskeyCount\PasskeyCountInterface: '@App\Repository\UserPasskeyRepository'
    App\Service\Passkey\SignInIdentities\SignInIdentitiesInterface: '@App\Repository\UserIdentityRepository'
    App\Service\Settings\EnrolledPasskeys\EnrolledPasskeysInterface: '@App\Repository\UserPasskeyRepository'
```

- [ ] **Step 4: The tests double the interfaces**

`tests/Service/Passkey/PasskeyRemovalPolicyTest.php`:
```bash
perl -pi -e 's/\bUserPasskeyRepository::class\b/PasskeyCountInterface::class/g; s/\bUserIdentityRepository::class\b/SignInIdentitiesInterface::class/g; s/^use App\\Repository\\UserIdentityRepository;/use App\\Service\\Passkey\\SignInIdentities\\SignInIdentitiesInterface;/; s/^use App\\Repository\\UserPasskeyRepository;/use App\\Service\\Passkey\\PasskeyCount\\PasskeyCountInterface;/' tests/Service/Passkey/PasskeyRemovalPolicyTest.php
```
`tests/Service/Settings/RelyingPartyChangeTest.php`:
```bash
perl -pi -e 's/\bUserPasskeyRepository::class\b/EnrolledPasskeysInterface::class/g; s/^use App\\Repository\\UserPasskeyRepository;/use App\\Service\\Settings\\EnrolledPasskeys\\EnrolledPasskeysInterface;/' tests/Service/Settings/RelyingPartyChangeTest.php
```
Then move each rewritten `use` line to its alphabetical place in the file's `use` block (case-insensitive, `\` sorting as a space, php-cs-fixer's `ordered_imports`).

```bash
git grep -n -E 'User(Passkey|Identity)Repository' -- tests/Service/Passkey/PasskeyRemovalPolicyTest.php tests/Service/Settings/RelyingPartyChangeTest.php
git grep -c 'UserPasskeyRepository' -- src/Repository/UserPasskeyRepository.php
```
Expected: the first grep prints nothing; the second (positive control) prints a count above 0.

- [ ] **Step 5: Run**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Passkey tests/Service/Settings tests/Controller/Api/PasskeyLoginTest.php`
Expected: PASS.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the changed files.
```bash
git add src/Service/Passkey src/Service/Settings src/Repository config/services.yaml tests/Service/Passkey tests/Service/Settings
git commit -m "refactor(#1169): the passkey consumers own the interfaces they call; two repositories are final"
```

---

### Task A4: `UserByEmailInterface`; `UserRepository` final

**Files:**
- Create: `src/Service/Auth/UserByEmail/UserByEmailInterface.php`
- Modify: `src/Repository/UserRepository.php`, `src/Service/Auth/RegistrationService.php`, `src/Security/LoginTimingEqualizer.php`, `config/services.yaml`
- Test: `tests/Service/Auth/RegistrationServiceTest.php`, `tests/Security/LoginTimingEqualizerTest.php`

**Interfaces:**
- Produces: `UserByEmailInterface::findOneByEmail(string $email): ?User`

- [ ] **Step 1: The interface**

`src/Service/Auth/UserByEmail/UserByEmailInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Auth\UserByEmail;

use App\Entity\User;

interface UserByEmailInterface
{
    public function findOneByEmail(string $email): ?User;
}
```

- [ ] **Step 2: The repository and the consumers**

`src/Repository/UserRepository.php`: replace `class UserRepository extends ServiceEntityRepository implements UserLoaderInterface` with:
```php
final class UserRepository extends ServiceEntityRepository implements UserLoaderInterface, UserByEmailInterface
```
and add `use App\Service\Auth\UserByEmail\UserByEmailInterface;` in order.

`src/Service/Auth/RegistrationService.php` and `src/Security/LoginTimingEqualizer.php`: replace `private UserRepository $users,` with `private UserByEmailInterface $users,`, and `use App\Repository\UserRepository;` with `use App\Service\Auth\UserByEmail\UserByEmailInterface;` in order. Both call `$this->users->findOneByEmail()` and nothing else:
```bash
git grep -n -E '\$this->users->' -- src/Service/Auth/RegistrationService.php src/Security/LoginTimingEqualizer.php
```
Expected: only `findOneByEmail(` calls. Any other method: stop and report.

`config/services.yaml`, after the lines A3 added:
```yaml
    App\Service\Auth\UserByEmail\UserByEmailInterface: '@App\Repository\UserRepository'
```

- [ ] **Step 3: The tests**

`tests/Service/Auth/RegistrationServiceTest.php`: replace `$blindRepository = $this->createStub(UserRepository::class);` with `$blindRepository = $this->createStub(UserByEmailInterface::class);` and add `use App\Service\Auth\UserByEmail\UserByEmailInterface;` in order. Keep `use App\Repository\UserRepository;`: `serviceUnderPolicy()` and `users()` fetch the real repository.

`tests/Security/LoginTimingEqualizerTest.php`:
```bash
perl -pi -e 's/\bUserRepository\b/UserByEmailInterface/g; s/^use App\\Repository\\UserByEmailInterface;/use App\\Service\\Auth\\UserByEmail\\UserByEmailInterface;/' tests/Security/LoginTimingEqualizerTest.php
git grep -n 'UserRepository' -- tests/Security/LoginTimingEqualizerTest.php
```
Expected: the grep prints nothing. Positive control: `git grep -c 'UserByEmailInterface' -- tests/Security/LoginTimingEqualizerTest.php` prints a count above 0. Move the `use` line into order.

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Auth tests/Security tests/Controller/Api && composer check && composer md`
Expected: PASS.
```bash
git add src/Service/Auth src/Security src/Repository/UserRepository.php config/services.yaml tests/Service/Auth tests/Security
git commit -m "refactor(#1169): registration and the login timing guard look users up through UserByEmailInterface; UserRepository is final"
```

---

### Task A5: Digest interfaces; `PreferencesRepository` and `SavedSearchRepository` final

**Files:**
- Create: `src/Service/Mail/Digest/DigestRecipients/DigestRecipientsInterface.php`, `src/Service/Mail/Digest/DigestSavedSearches/DigestSavedSearchesInterface.php`
- Modify: `src/Repository/PreferencesRepository.php`, `src/Repository/SavedSearchRepository.php`, `src/Service/Mail/Digest/SendDueDigests.php`, `src/Service/Mail/Digest/DigestComposer.php`, `config/services.yaml`
- Test: `tests/Service/Worker/SendDueDigestsHandlerTest.php`, `tests/Service/Maintenance/MaintenanceTickTest.php`, `tests/Service/Mail/Digest/SendTestDigestTest.php`, `tests/Service/Mail/Digest/SendDueDigestsTest.php`, `tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`, `tests/Service/Mail/Digest/DigestComposerTest.php`

**Interfaces:**
- Produces:
  - `DigestRecipientsInterface::findWithDigestEnabled(): array` (`@return list<Preferences>`)
  - `DigestSavedSearchesInterface::findIncludedInDigestForUser(int $userId): array` (`@return list<SavedSearch>`)

- [ ] **Step 1: The interfaces**

`src/Service/Mail/Digest/DigestRecipients/DigestRecipientsInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestRecipients;

use App\Entity\Preferences;

interface DigestRecipientsInterface
{
    /** @return list<Preferences> */
    public function findWithDigestEnabled(): array;
}
```

`src/Service/Mail/Digest/DigestSavedSearches/DigestSavedSearchesInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\DigestSavedSearches;

use App\Entity\SavedSearch;

interface DigestSavedSearchesInterface
{
    /** @return list<SavedSearch> */
    public function findIncludedInDigestForUser(int $userId): array;
}
```

- [ ] **Step 2: The repositories and consumers**

- `src/Repository/PreferencesRepository.php`: `class PreferencesRepository extends ServiceEntityRepository` → `final class PreferencesRepository extends ServiceEntityRepository implements DigestRecipientsInterface`; import it.
- `src/Repository/SavedSearchRepository.php`: `class SavedSearchRepository extends ServiceEntityRepository` → `final class SavedSearchRepository extends ServiceEntityRepository implements DigestSavedSearchesInterface`; import it.
- `src/Service/Mail/Digest/SendDueDigests.php`: `private PreferencesRepository $preferences,` → `private DigestRecipientsInterface $preferences,`; swap the import.
- `src/Service/Mail/Digest/DigestComposer.php`: `private SavedSearchRepository $savedSearches,` → `private DigestSavedSearchesInterface $savedSearches,`; swap the import.

```bash
git grep -n -E '\$this->(preferences|savedSearches)->' -- src/Service/Mail/Digest/SendDueDigests.php src/Service/Mail/Digest/DigestComposer.php
```
Expected: only `findWithDigestEnabled(` and `findIncludedInDigestForUser(`.

`config/services.yaml`, after A4's line:
```yaml
    App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface: '@App\Repository\PreferencesRepository'
    App\Service\Mail\Digest\DigestSavedSearches\DigestSavedSearchesInterface: '@App\Repository\SavedSearchRepository'
```

- [ ] **Step 3: The tests**

```bash
FILES="tests/Service/Worker/SendDueDigestsHandlerTest.php tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Mail/Digest/SendTestDigestTest.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Mail/Digest/DigestComposerTest.php"
perl -pi -e 's/\bPreferencesRepository\b/DigestRecipientsInterface/g; s/\bSavedSearchRepository\b/DigestSavedSearchesInterface/g; s/^use App\\Repository\\DigestRecipientsInterface;/use App\\Service\\Mail\\Digest\\DigestRecipients\\DigestRecipientsInterface;/; s/^use App\\Repository\\DigestSavedSearchesInterface;/use App\\Service\\Mail\\Digest\\DigestSavedSearches\\DigestSavedSearchesInterface;/' $FILES
git grep -n -w -E '(PreferencesRepository|SavedSearchRepository)' -- $FILES
git grep -c -w 'SavedSearchEntryRepository' -- tests/Service/Mail/Digest/DigestComposerTest.php
```
Expected: the first grep prints nothing; the second prints a count above 0 (the word-bounded pattern left `SavedSearchEntryRepository` alone). Move each rewritten `use` line into order. The `…&Stub` intersection types now read `DigestRecipientsInterface&Stub` and `DigestSavedSearchesInterface&Stub`.

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Mail tests/Service/Worker tests/Service/Maintenance && composer check && composer md`
Expected: PASS.
```bash
git add src/Service/Mail src/Repository config/services.yaml tests/Service/Mail tests/Service/Worker tests/Service/Maintenance
git commit -m "refactor(#1169): the digest reads recipients and saved searches through its own interfaces; two repositories are final"
```

---

### Task A6: Stored-settings interfaces; `GrafanaSettingsRepository` and `ProxyServerSettingsRepository` final

**Files:**
- Create: `src/Service/Grafana/StoredGrafanaSettings/StoredGrafanaSettingsInterface.php`, `src/Service/Proxy/StoredProxySettings/StoredProxySettingsInterface.php`
- Modify: `src/Repository/GrafanaSettingsRepository.php`, `src/Repository/ProxyServerSettingsRepository.php`, `src/Service/Grafana/EffectiveGrafanaSettings.php`, `src/Service/Grafana/GrafanaSettings.php`, `src/Service/Proxy/ProxySettings.php`, `src/Service/Proxy/ConfiguredProxySource/StoredProxy.php`, `config/services.yaml`
- Test: `tests/Service/Grafana/EffectiveGrafanaSettingsTest.php`, `tests/Service/Grafana/GrafanaSettingsTest.php`, `tests/Service/Proxy/ProxySettingsTest.php`, `tests/Support/StoredProxies.php`, `tests/Support/BuildsEffectiveGrafanaSettings.php`

**Interfaces:**
- Produces: `StoredGrafanaSettingsInterface::findSingleton(): ?GrafanaSettings`, `StoredProxySettingsInterface::findSingleton(): ?ProxyServerSettings`

- [ ] **Step 1: The interfaces**

`src/Service/Grafana/StoredGrafanaSettings/StoredGrafanaSettingsInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana\StoredGrafanaSettings;

use App\Entity\GrafanaSettings;

interface StoredGrafanaSettingsInterface
{
    public function findSingleton(): ?GrafanaSettings;
}
```

`src/Service/Proxy/StoredProxySettings/StoredProxySettingsInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy\StoredProxySettings;

use App\Entity\ProxyServerSettings;

interface StoredProxySettingsInterface
{
    public function findSingleton(): ?ProxyServerSettings;
}
```

- [ ] **Step 2: The repositories and consumers**

- `GrafanaSettingsRepository`: `class GrafanaSettingsRepository extends ServiceEntityRepository` → `final class GrafanaSettingsRepository extends ServiceEntityRepository implements StoredGrafanaSettingsInterface`; import it.
- `ProxyServerSettingsRepository`: `class ProxyServerSettingsRepository extends ServiceEntityRepository` → `final class ProxyServerSettingsRepository extends ServiceEntityRepository implements StoredProxySettingsInterface`; import it.
- `EffectiveGrafanaSettings`: `private readonly GrafanaSettingsRepository $repository,` → `private readonly StoredGrafanaSettingsInterface $repository,` (the class is stateful, so its properties keep `readonly`); swap the import.
- `GrafanaSettings`: `private GrafanaSettingsRepository $repository,` → `private StoredGrafanaSettingsInterface $repository,`; swap the import.
- `ProxySettings`, `ConfiguredProxySource/StoredProxy`: `private ProxyServerSettingsRepository $repository,` → `private StoredProxySettingsInterface $repository,`; swap the import.

```bash
git grep -n -E '\$this->repository->' -- src/Service/Grafana/EffectiveGrafanaSettings.php src/Service/Grafana/GrafanaSettings.php src/Service/Proxy/ProxySettings.php src/Service/Proxy/ConfiguredProxySource/StoredProxy.php
```
Expected: only `findSingleton(` calls.

`config/services.yaml`, after A5's lines:
```yaml
    App\Service\Grafana\StoredGrafanaSettings\StoredGrafanaSettingsInterface: '@App\Repository\GrafanaSettingsRepository'
    App\Service\Proxy\StoredProxySettings\StoredProxySettingsInterface: '@App\Repository\ProxyServerSettingsRepository'
```

- [ ] **Step 3: The tests**

```bash
FILES="tests/Service/Grafana/EffectiveGrafanaSettingsTest.php tests/Service/Grafana/GrafanaSettingsTest.php tests/Service/Proxy/ProxySettingsTest.php tests/Support/StoredProxies.php tests/Support/BuildsEffectiveGrafanaSettings.php"
perl -pi -e 's/\bGrafanaSettingsRepository\b/StoredGrafanaSettingsInterface/g; s/\bProxyServerSettingsRepository\b/StoredProxySettingsInterface/g; s/^use App\\Repository\\StoredGrafanaSettingsInterface;/use App\\Service\\Grafana\\StoredGrafanaSettings\\StoredGrafanaSettingsInterface;/; s/^use App\\Repository\\StoredProxySettingsInterface;/use App\\Service\\Proxy\\StoredProxySettings\\StoredProxySettingsInterface;/' $FILES
git grep -n -w -E '(GrafanaSettingsRepository|ProxyServerSettingsRepository)' -- $FILES
```
Expected: nothing. Positive control: the same grep at `origin/develop` (`git grep -n -w -E '(GrafanaSettingsRepository|ProxyServerSettingsRepository)' origin/develop -- $FILES`) prints the old names. Move each rewritten `use` line into order.

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Grafana tests/Service/Proxy tests/Service/Fetch && composer check && composer md`
Expected: PASS.
```bash
git add src/Service/Grafana src/Service/Proxy src/Repository config/services.yaml tests/Service/Grafana tests/Service/Proxy tests/Support
git commit -m "refactor(#1169): stored Grafana and proxy settings are read through interfaces; two repositories are final"
```

---

### Task A7: Restore interfaces; `FeedRepository`, `EntryRepository` and `EntryStateRepository` final

**Files:**
- Create: `src/Service/Backup/RestoreFeeds/RestoreFeedsInterface.php`, `src/Service/Backup/RestoreEntries/RestoreEntriesInterface.php`, `src/Service/Backup/RestoreEntryStates/RestoreEntryStatesInterface.php`
- Modify: `src/Repository/FeedRepository.php`, `EntryRepository.php`, `EntryStateRepository.php`, `src/Service/Backup/Pass/RestoreLoadPass.php`, `src/Service/Backup/Pass/RestoreFeedTargets.php`, `src/Service/Backup/Pass/RestoreEntryLoader.php`, `config/services.yaml`
- Test: `tests/Service/Backup/Pass/RestoreLoadPassTest.php`, `tests/Service/Backup/Pass/RestoreEntryLoaderTest.php`

**Interfaces:**
- Produces:
  - `RestoreFeedsInterface::findByUrlsIndexedByUrl(array $urls): array` (`@param list<string>`, `@return array<string, Feed>`), `RestoreFeedsInterface::isReadByAnotherUser(int $feedId, int $excludedUserId): bool`
  - `RestoreEntriesInterface::entriesAfterId(int $lastId, int $limit): array` (`@return list<Entry>`), `guidHashToIdMapForFeed(int $feedId): array` (`@return array<string, int>`), `entryIdsByGuidHash(int $feedId, array $guidHashes): array` (`@param list<string>`, `@return array<string, int>`)
  - `RestoreEntryStatesInterface::entryIdsWithStateForUser(int $userId, array $entryIds): array` (`@param list<int>`, `@return list<int>`)
- The Pass objects take the interfaces; `RestoreEntryLoaderFactory` and `RestoreLoader` keep passing the concrete repositories, which implement them.

- [ ] **Step 1: The interfaces**

`src/Service/Backup/RestoreFeeds/RestoreFeedsInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\RestoreFeeds;

use App\Entity\Feed;

interface RestoreFeedsInterface
{
    /**
     * @param list<string> $urls
     *
     * @return array<string, Feed>
     */
    public function findByUrlsIndexedByUrl(array $urls): array;

    public function isReadByAnotherUser(int $feedId, int $excludedUserId): bool;
}
```

`src/Service/Backup/RestoreEntries/RestoreEntriesInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\RestoreEntries;

use App\Entity\Entry;

interface RestoreEntriesInterface
{
    /** @return list<Entry> */
    public function entriesAfterId(int $lastId, int $limit): array;

    /** @return array<string, int> */
    public function guidHashToIdMapForFeed(int $feedId): array;

    /**
     * @param list<string> $guidHashes
     *
     * @return array<string, int>
     */
    public function entryIdsByGuidHash(int $feedId, array $guidHashes): array;
}
```

`src/Service/Backup/RestoreEntryStates/RestoreEntryStatesInterface.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\RestoreEntryStates;

interface RestoreEntryStatesInterface
{
    /**
     * @param list<int> $entryIds
     *
     * @return list<int>
     */
    public function entryIdsWithStateForUser(int $userId, array $entryIds): array;
}
```

- [ ] **Step 2: The repositories**

- `FeedRepository`: `class FeedRepository extends ServiceEntityRepository` → `final class FeedRepository extends ServiceEntityRepository implements RestoreFeedsInterface`.
- `EntryRepository`: `class EntryRepository extends ServiceEntityRepository` → `final class EntryRepository extends ServiceEntityRepository implements RestoreEntriesInterface`.
- `EntryStateRepository`: `class EntryStateRepository extends ServiceEntityRepository` → `final class EntryStateRepository extends ServiceEntityRepository implements RestoreEntryStatesInterface`.
Import each interface in order. The repositories' existing signatures already match (`findByUrlsIndexedByUrl` line 85, `isReadByAnotherUser` 137, `entriesAfterId` 106, `guidHashToIdMapForFeed` 185, `entryIdsByGuidHash` 198, `entryIdsWithStateForUser` 229 at `5dbc55d3`); PHPStan reports a mismatch.

- [ ] **Step 3: The Pass objects take the interfaces**

```bash
git grep -n -E '\$this->(feeds|entries|entryStates)->' -- src/Service/Backup/Pass/RestoreLoadPass.php src/Service/Backup/Pass/RestoreFeedTargets.php src/Service/Backup/Pass/RestoreEntryLoader.php
```
Expected: only the six interface methods. Any other: add it to the interface and report it.

- `RestoreLoadPass`: `private readonly FeedRepository $feeds,` → `private readonly RestoreFeedsInterface $feeds,`.
- `RestoreFeedTargets`: `private readonly FeedRepository $feeds,` → `private readonly RestoreFeedsInterface $feeds,`; `private readonly EntryRepository $entries,` → `private readonly RestoreEntriesInterface $entries,`.
- `RestoreEntryLoader`: `private readonly EntryRepository $entries,` → `private readonly RestoreEntriesInterface $entries,`; `private readonly EntryStateRepository $entryStates,` → `private readonly RestoreEntryStatesInterface $entryStates,`.
Swap the imports in each.

`config/services.yaml`, after A6's lines (only the factory builds these Pass objects, but a test container or a later consumer resolves the interface the same way):
```yaml
    App\Service\Backup\RestoreFeeds\RestoreFeedsInterface: '@App\Repository\FeedRepository'
    App\Service\Backup\RestoreEntries\RestoreEntriesInterface: '@App\Repository\EntryRepository'
    App\Service\Backup\RestoreEntryStates\RestoreEntryStatesInterface: '@App\Repository\EntryStateRepository'
```

- [ ] **Step 4: The tests**

`tests/Service/Backup/Pass/RestoreLoadPassTest.php`:
- Delete the line `        $feeds->expects($this->never())->method('findOneBy');` (D-9: the interface has no per-URL lookup, so the Pass cannot make one).
- Then:
```bash
perl -pi -e 's/\bFeedRepository\b/RestoreFeedsInterface/g; s/^use App\\Repository\\RestoreFeedsInterface;/use App\\Service\\Backup\\RestoreFeeds\\RestoreFeedsInterface;/' tests/Service/Backup/Pass/RestoreLoadPassTest.php
```

`tests/Service/Backup/Pass/RestoreEntryLoaderTest.php`:
```bash
perl -pi -e 's/\bEntryRepository\b/RestoreEntriesInterface/g; s/\bEntryStateRepository\b/RestoreEntryStatesInterface/g; s/\bFeedRepository\b/RestoreFeedsInterface/g; s/^use App\\Repository\\RestoreEntriesInterface;/use App\\Service\\Backup\\RestoreEntries\\RestoreEntriesInterface;/; s/^use App\\Repository\\RestoreEntryStatesInterface;/use App\\Service\\Backup\\RestoreEntryStates\\RestoreEntryStatesInterface;/; s/^use App\\Repository\\RestoreFeedsInterface;/use App\\Service\\Backup\\RestoreFeeds\\RestoreFeedsInterface;/' tests/Service/Backup/Pass/RestoreEntryLoaderTest.php
git grep -n -w -E '(FeedRepository|EntryRepository|EntryStateRepository)' -- tests/Service/Backup/Pass/RestoreLoadPassTest.php tests/Service/Backup/Pass/RestoreEntryLoaderTest.php
git grep -c 'EntryBatchInserter' -- tests/Service/Backup/Pass/RestoreEntryLoaderTest.php
```
Expected: the first grep prints nothing; the second prints a count above 0 (`EntryBatchInserter` is untouched). Move the rewritten `use` lines into order.

- [ ] **Step 5: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Backup tests/Repository tests/Service/Refresh && composer check && composer md`
Expected: PASS.
```bash
git add src/Service/Backup src/Repository config/services.yaml tests/Service/Backup
git commit -m "refactor(#1169): the restore passes read feeds, entries and entry states through interfaces; three repositories are final"
```

---

### Task A8: `EntryProjection` replaces `AbstractEntryProjectionRepository`

The last base repository (§7) becomes a composed collaborator. The helper names stay, so the two repositories change only in who they call.

**Files:**
- Create: `src/Repository/EntryProjection.php`
- Modify: `src/Repository/EntryListRepository.php`, `src/Repository/SavedSearchEntryRepository.php`, `docs/architecture.md`
- Delete: `src/Repository/AbstractEntryProjectionRepository.php`

**Interfaces:**
- Produces: `App\Repository\EntryProjection` (`final readonly`, takes `EntityManagerInterface`), with `newestFirst(QueryBuilder): QueryBuilder`, `orderedBy(QueryBuilder, EntryListOrdering): QueryBuilder`, `unreadEntriesQueryBuilder(int $userId): QueryBuilder`, `rowQueryBuilder(int $userId): QueryBuilder`, `applyCursor(QueryBuilder, ?EntryCursor, EntryListOrdering): void`, `scalarIds(QueryBuilder): list<int>`.

- [ ] **Step 1: `EntryProjection`**

`src/Repository/EntryProjection.php` (the bodies are `AbstractEntryProjectionRepository`'s, with `$this->createQueryBuilder('e')` replaced by `$this->entries()`, which builds the same `SELECT e FROM Entry e`):
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Subscription;
use App\Pagination\EntryCursor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The query construction EntryListRepository and SavedSearchEntryRepository share: the row-projection join set,
 * ordering, and keyset cursor.
 */
final readonly class EntryProjection
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function newestFirst(QueryBuilder $qb): QueryBuilder
    {
        return $this->orderedBy($qb, EntryListOrdering::byPublishedDate());
    }

    /** The sort's instant column, then id for the ties a refresh run leaves, both in the ordering's direction. */
    public function orderedBy(QueryBuilder $qb, EntryListOrdering $ordering): QueryBuilder
    {
        $direction = $ordering->order->sqlDirection();

        return $qb
            ->orderBy($ordering->sort->orderColumn(), $direction)
            ->addOrderBy('e.id', $direction);
    }

    /**
     * The caller's unread entries, left for the reader to narrow and project.
     * Deliberately not rowQueryBuilder: every caller reduces to a scalar, and
     * that builder joins `feed` to select a title and a url nobody reads here.
     */
    public function unreadEntriesQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entries()
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->setParameter('user', $userId)
            ->andWhere(UnreadDql::predicate())
            ->setParameter('notHidden', false, Types::BOOLEAN);
    }

    /**
     * The shared "entry list row" projection: the entry plus the caller's
     * subscription, feed, and optional per-entry state. listForUser adds
     * ordering/paging/filters; getRowForUser adds an id filter.
     */
    public function rowQueryBuilder(int $userId): QueryBuilder
    {
        return $this->entries()
            ->leftJoin('e.feed', 'f')->addSelect('f')
            // Unrelated-entity joins: the caller's subscription to this entry's
            // feed, and the caller's optional per-entry state row.
            ->join(Subscription::class, 's', 'ON', 's.feed = e.feed AND s.user = :user')
            ->leftJoin(EntryState::class, 'es', 'ON', 'es.entry = e AND es.user = :user')
            ->addSelect('s.id AS subscriptionId')
            ->addSelect('s.customTitle AS customTitle')
            ->addSelect('f.title AS feedTitle')
            ->addSelect('f.url AS feedUrl')
            ->addSelect('es.isHidden AS esHidden')
            ->addSelect('es.isFavorite AS esFavorite')
            ->addSelect('es.isKept AS esKept')
            ->addSelect('es.isViewed AS esViewed')
            ->addSelect('es.viewedAt AS esViewedAt')
            ->addSelect('s.markedReadUntil AS markedReadUntil')
            ->setParameter('user', $userId);
    }

    public function applyCursor(QueryBuilder $qb, ?EntryCursor $cursor, EntryListOrdering $ordering): void
    {
        if ($cursor === null) {
            return;
        }

        $qb->andWhere(\sprintf(
            '(%1$s %2$s :curInstant OR (%1$s = :curInstant AND e.id %2$s :curId))',
            $ordering->sort->orderColumn(),
            $ordering->order->strictlyAfter(),
        ))
            ->setParameter('curInstant', $cursor->sortInstant, Types::DATETIME_IMMUTABLE)
            ->setParameter('curId', $cursor->id);
    }

    /**
     * The distinct entry ids a match query selects, as a plain int list. The
     * shared tail of the unreadMember* readers: they differ only in their filter
     * and ordering, never in reducing `e.id` rows to ints.
     *
     * @return list<int>
     */
    public function scalarIds(QueryBuilder $queryBuilder): array
    {
        /** @var list<array{id: int}> $rows */
        $rows = $queryBuilder->getQuery()->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    private function entries(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()->select('e')->from(Entry::class, 'e');
    }
}
```

- [ ] **Step 2: The two repositories compose it**

`src/Repository/EntryListRepository.php`:
- Replace `class EntryListRepository extends AbstractEntryProjectionRepository` with `final class EntryListRepository extends ServiceEntityRepository`, and add ` * @extends ServiceEntityRepository<Entry>` as the last line of its class docblock, after a ` *` line.
- In the constructor, after `private readonly DateOrderedPage $dateOrderedPage,`, add `private readonly EntryProjection $projection,`.
- Add `use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;` in order.

`src/Repository/SavedSearchEntryRepository.php`:
- Replace `final class SavedSearchEntryRepository extends AbstractEntryProjectionRepository` with `final class SavedSearchEntryRepository extends ServiceEntityRepository`, and add ` * @extends ServiceEntityRepository<Entry>` to its class docblock the same way.
- In the constructor, after `private readonly DuplicateCollapseDql $collapse,`, add `private readonly EntryProjection $projection,`.
- Add `use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;` in order.

Then route the six helpers through the collaborator:
```bash
perl -pi -e 's/\$this->(newestFirst|orderedBy|unreadEntriesQueryBuilder|rowQueryBuilder|applyCursor|scalarIds)\(/\$this->projection->$1(/g' src/Repository/EntryListRepository.php src/Repository/SavedSearchEntryRepository.php
git grep -n -E '\$this->(newestFirst|orderedBy|unreadEntriesQueryBuilder|rowQueryBuilder|applyCursor|scalarIds)\(' -- src/Repository/EntryListRepository.php src/Repository/SavedSearchEntryRepository.php
git grep -c -E '\$this->projection->' -- src/Repository/EntryListRepository.php src/Repository/SavedSearchEntryRepository.php
git rm src/Repository/AbstractEntryProjectionRepository.php
git grep -n 'AbstractEntryProjectionRepository' -- . ':!docs/superpowers'
```
Expected: the first grep prints nothing; the second prints `12` for `EntryListRepository.php` and `7` for `SavedSearchEntryRepository.php` (matching lines at `5dbc55d3`; `$this->orderedBy($probe, …)` orders a builder the repository's own `createQueryBuilder('e')` made, and routes through the projection like the rest; report a different count); the last grep prints only `docs/architecture.md:153`.

`RecommendationItemRepository` has private helpers with the same names; the pattern ran on two files only, so it is untouched.

- [ ] **Step 3: §7**

`docs/architecture.md`, replace:
```markdown
  `DuplicateCollapseDql`, `NextPosition`, `RowIds`) that builds or runs a query a repository hands it, never an
  abstract base repository. `AbstractEntryProjectionRepository` is the last one left; #1169 replaces it with a
  collaborator.
```
with:
```markdown
  `DuplicateCollapseDql`, `NextPosition`, `RowIds`, `EntryProjection`) that builds or runs a query a repository
  hands it, never an abstract base repository. Every repository is `final` (`PersistenceClassesAreFinalRule`).
```
(If the first line of that paragraph reads differently, keep its start and replace from `abstract base repository.` on.)

- [ ] **Step 4: Run**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Repository tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/SavedSearchEntriesControllerTest.php tests/Service/Search tests/Service/Mail/Digest`
Expected: PASS. The query shapes are identical: `EntityManager::createQueryBuilder()->select('e')->from(Entry::class, 'e')` is what `EntityRepository::createQueryBuilder('e')` builds.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md && composer tramp`, then the PhpStorm inspections on the three PHP files.
```bash
git add src/Repository ../docs/architecture.md
git commit -m "refactor(#1169): EntryProjection is a composed collaborator; the last base repository is gone"
```

---

### Task A9: Entities and embeddables final; the rule is registered

**Files:**
- Modify: the 27 entity and 11 embeddable classes listed in Step 1, `phpstan.dist.neon`, `CLAUDE.md`
- Test: `tests/Entity/CategoryTest.php`, `tests/Service/Admin/SelfActionGuardTest.php`, `tests/Service/RateLimit/RateLimitGuardTest.php`

- [ ] **Step 1: Every non-final class in `src/Entity` becomes final**

```bash
git grep -l -E '^class [A-Za-z]+' -- src/Entity src/Repository
```
Expected at `5dbc55d3` after A2–A8, the 38 `src/Entity` files: `AccountLimits`, `ActionToken`, `AiProviderSettings`, `CatalogCategory`, `CatalogFeed`, `Category`, `Entry`, `EntryCategory`, `EntryDiscussion`, `EntryImage`, `EntryLocation`, `EntryMedia`, `EntryState`, `Feed`, `FetchSchedule`, `GrafanaSettings`, `InstanceSetting`, `MailSendFailure`, `MailServerSettings`, `Preferences`, `ProviderUsage`, `ProxyServerSettings`, `RecommendationRun`, `RecommendationRunLog`, `RecommendationSettings`, `RunCallAttempts`, `RunProfile`, `RunThrottle`, `RunTuning`, `SavedSearch`, `SavedSearchEntry`, `Subscription`, `SubscriptionTag`, `Tag`, `User`, `UserIdentity`, `UserPasskey`, `WorkerHeartbeat` (27 `#[ORM\Entity]`, 11 `#[ORM\Embeddable]`), and no `src/Repository` file. A repository in the list: stop, A2–A8 missed it.
```bash
for f in $(git grep -l -E '^class [A-Za-z]+' -- src/Entity); do perl -pi -e 's/^class ([A-Za-z]+)/final class $1/' "$f"; done
git grep -n -E '^class ' -- src/Entity src/Repository
git grep -c -E '^final class ' -- src/Entity/User.php
```
Expected: the second grep prints nothing; the third prints `1`.

- [ ] **Step 2: The three tests that stubbed an entity build a real one**

`tests/Entity/CategoryTest.php`: replace `        $entry = $this->createStub(Entry::class);` with:
```php
        $createdAt = new \DateTimeImmutable('2026-07-01 10:00:00');
        $entry = new Entry(new Feed('https://example.com/feed.xml'), 'guid-1', null, 'Title', $createdAt, $createdAt);
```
and add `use App\Entity\Feed;` in order.

`tests/Service/Admin/SelfActionGuardTest.php`: replace the body of `userWithId()`:
```php
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
```
with:
```php
        $user = new User(sprintf('user-%d@example.com', $id), new \DateTimeImmutable('2026-07-01 10:00:00'));
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
```

`tests/Service/RateLimit/RateLimitGuardTest.php`: replace
```php
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
```
with:
```php
        $user = new User('rate-limited@example.com', $this->clock->now());
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 42);
```

```bash
ENTITIES=$(git grep -l -E '^final (readonly )?class ' -- src/Entity | xargs -n1 basename | sed 's/\.php$//' | paste -sd'|' -)
git grep -n -E "(createMock|createStub|getMockBuilder|createPartialMock|createConfiguredMock)\((${ENTITIES})::class" -- tests
git grep -n -E "createStub\((${ENTITIES})::class" origin/develop -- tests/Entity/CategoryTest.php
```
Expected: the first grep prints nothing; the second (positive control, on develop before this edit) prints the `Entry` stub line. A hit in the first: build a real instance the same way.

- [ ] **Step 3: Register the rule**

`phpstan.dist.neon`, at the end of the `services:` list:
```yaml
    -
        class: App\Tests\PhpStan\PersistenceClassesAreFinalRule
        tags:
            - phpstan.rules.rule
```
Run: `bin/console cache:clear && bin/console cache:warmup && composer stan`
Expected: PASS.

Deletion check: in `src/Entity/Tag.php`, replace `final class Tag` with `class Tag`. Run `composer stan`. Expected: FAIL with `Persistence classes are final: App\Entity\Tag is not. A test doubles the interface its consumer owns, never the persistence class (#1169).` Restore by hand.

- [ ] **Step 4: The suites**

Run: `php bin/phpunit`
Expected: PASS. A `Class "App\Entity\…" is declared "final" and cannot be doubled` names a double Step 2's grep missed: replace it with a real instance.

- [ ] **Step 5: CLAUDE.md**

In "Enforced mechanically", after the `EntityIdCoercionRule` entry, add:
```markdown
- **`PersistenceClassesAreFinalRule`** (`tests/PhpStan/PersistenceClassesAreFinalRule.php`) — every class
  in `src/Entity` and `src/Repository` is `final`. A test that needs a repository double doubles the
  interface its consumer owns (`SavedSearchMembershipWriterInterface`, `UserByEmailInterface`, #1169).
```

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md && bin/console lint:container`, then the PhpStorm inspections on the changed PHP files.
```bash
git add src/Entity phpstan.dist.neon ../CLAUDE.md tests/Entity tests/Service/Admin tests/Service/RateLimit
git commit -m "refactor(#1169): entities and embeddables are final, and the rule keeps every persistence class so"
```

---

### Finishing PR A

- [ ] **Step 1: The gates on the whole branch**

```bash
composer check
composer md
php bin/phpunit
docker compose exec php bin/console cache:clear
docker compose exec php composer test
composer infection:diff
```
Expected: all green; today's dev log holds nothing new at level 300 or above.

- [ ] **Step 2: The real recommendation run** (Appendix R): the entities `RecommendationRun`, `RecommendationRunLog` and `RecommendationSettings` became final. Paste steps 3, 5, 6 and 7.

- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.

- [ ] **Step 4: /simplify** over `git diff origin/develop...HEAD`; commit as `refactor(#1169): simplify pass` if anything changed.

- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff origin/develop...HEAD`. It reports findings; it does not fix. Attack points:
1. **Each interface is what its consumer calls, no more:** every method on it has a caller in the consumer, and the consumer calls nothing else on the repository.
2. **No behaviour moved:** `EntryProjection`'s bodies equal the deleted base class's, and the query shapes are identical.
3. **The deleted `findOneBy` expectation (D-9)** is covered by the type.
4. **The rule** has no false negative: an abstract class, a trait or an enum in `App\Entity` behaves as the fixture says.
5. **Deletion checks:** re-run A1's first check and A9's; quote both FAILs.

Fix each finding rated Important or above in its own commit (`refactor(#1169): review — <finding>`), re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K)

`var/refactor-1169/pr-a-body.md`:
```markdown
Refs #1169 (PR A of eight).

- Every class in `src/Entity` and `src/Repository` is `final`; `PersistenceClassesAreFinalRule` keeps it so.
- Tests used to double ten concrete repositories. Their consumers now depend on eleven narrow interfaces they own, each in a folder named after it and aliased in `services.yaml`, as `SavedSearchMembershipWriterInterface` already was.
- `AbstractEntryProjectionRepository` is gone: `EntryListRepository` and `SavedSearchEntryRepository` compose `EntryProjection`.
- `RestoreLoadPassTest` no longer expects `findOneBy` never to run: the pass depends on `RestoreFeedsInterface`, which has no such method.

No behaviour change and no wire change. Real recommendation run: <paste the Appendix R summary line>.
```
Then run Appendix K with `pr-a-body.md` and the title `refactor(#1169): persistence classes are final`.

- [ ] **Step 7: Merge when green** (Global Constraints, "Merge"), then `gh issue view 1169 --json state --jq .state`. Expected: `OPEN`.

---

# PR B — Strict everywhere; stateless classes are `final readonly`

### Task B0: Preflight (PR A merged)

- [ ] **Step 1: PR A is on develop; branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "entities and embeddables are final"
gh issue view 1169 --json state --jq .state
git switch -c refactor/1169-strict-and-readonly origin/develop
```
Expected: one log line, `OPEN`.

---

### Task B1: `strict_types` everywhere PHPCS looks, and the sniff

PHP_CodeSniffer 3.13.6 (`composer.lock`) ships `Generic.PHP.RequireStrictTypes` (`vendor/squizlabs/php_codesniffer/src/Standards/Generic/Sniffs/PHP/RequireStrictTypesSniff.php`), so no dependency is added.

**Files:**
- Modify: `phpcs.xml.dist`, `src/Kernel.php`, `tests/bootstrap.php`

- [ ] **Step 1: The sniff first**

`phpcs.xml.dist`, after `    <rule ref="PSR12"/>`, add:
```xml
    <rule ref="Generic.PHP.RequireStrictTypes"/>
```
Run: `composer cs`
Expected: FAIL, `Missing required strict_types declaration` for `src/Kernel.php` and `tests/bootstrap.php`, and no other file. Another file: add the declaration to it in Step 2 as well, and name it in the report.

- [ ] **Step 2: The declarations**

`src/Kernel.php`: replace the first line `<?php` with:
```php
<?php

declare(strict_types=1);
```
`tests/bootstrap.php`: the same.

Run: `composer cs && php bin/phpunit tests/KernelTimezoneTest.php && bin/console lint:container`
Expected: PASS. (`KernelTimezoneTest` is the test that pins `Kernel::boot()`; if it lives elsewhere, run the file `git grep -l KernelTimezoneTest -- tests` names.)

- [ ] **Step 3: Deletion check**

Delete `declare(strict_types=1);` and the blank line after it from `src/Kernel.php`. Run `composer cs`. Expected: FAIL, `src/Kernel.php` with `Missing required strict_types declaration`. Restore by hand.

- [ ] **Step 4: CLAUDE.md and commit**

`CLAUDE.md`, "Enforced mechanically": replace `- **PSR-12** (`phpcs.xml.dist`), `declare(strict_types=1)` in every file.` with:
```markdown
- **PSR-12** (`phpcs.xml.dist`), and `declare(strict_types=1)` in every file of `src` and `tests`
  (`Generic.PHP.RequireStrictTypes`).
```
```bash
git add phpcs.xml.dist src/Kernel.php tests/bootstrap.php ../CLAUDE.md
git commit -m "refactor(#1169): every source and test file declares strict types, and PHPCS checks it"
```

---

### Task B2: Stateless classes outside `src/Service` are `final readonly`

`ServiceRoleRule` holds `src/Service` to `final readonly` (#1202 G). These nine classes sit outside it, hold only readonly collaborators or nothing, and extend no class.

**Files:**
- Modify: `src/Controller/Admin/AdminCatalogController.php`, `src/Controller/Api/HealthController.php`, `src/Controller/Api/OAuthController.php`, `src/Controller/Api/RefreshController.php`, `src/Controller/Api/VersionController.php`, `src/DependencyInjection/DisableHttpServerPushPass.php`, `src/Doctrine/SqliteConnectionSetupMiddleware.php`, `src/EventListener/CorsListener.php`, `src/Security/InvalidatePasswordChangeTokensListener.php`

- [ ] **Step 1: `readonly`**

```bash
for f in src/Controller/Admin/AdminCatalogController.php src/Controller/Api/HealthController.php src/Controller/Api/OAuthController.php src/Controller/Api/RefreshController.php src/Controller/Api/VersionController.php src/DependencyInjection/DisableHttpServerPushPass.php src/Doctrine/SqliteConnectionSetupMiddleware.php src/EventListener/CorsListener.php src/Security/InvalidatePasswordChangeTokensListener.php; do
  perl -0pi -e 's/^final class /final readonly class /m; s/\b(private|protected|public) readonly /$1 /g' "$f"
  grep -c '^final readonly class ' "$f"
done
```
Expected: nine lines reading `1`.

- [ ] **Step 2: Nothing writes a property after construction, and no static property exists**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Controller tests/EventListener tests/Security tests/Doctrine tests/DependencyInjection`
Expected: PASS. `Cannot modify readonly property` or `Readonly class … cannot declare static properties` names a class that holds state: revert that class and report it.

- [ ] **Step 3: The survey is empty**

```bash
for f in $(git grep -l -E '^final class [A-Za-z]+( implements [A-Za-z\\, ]+)?$' -- src ':!src/Service' ':!src/Entity' ':!src/Repository'); do
  if git grep -q -E 'function __construct|public function [a-z]' -- "$f" && ! git grep -q -E '(private|protected|public) (static )?[?A-Za-z\\|]+ \$[a-z][A-Za-z]*;|(private|protected|public) (static )?[?A-Za-z\\|]+ \$[a-z][A-Za-z]* = ' -- "$f"; then echo "$f"; fi
done
```
Expected: nothing. The loop prints a non-readonly final class that has a constructor or an instance method and no declared mutable property; `RequestProfilingListener` and `WorkerProfilingListener` declare mutable properties and stay out, static-only mappers have neither and stay out. A printed file is a stateless class Step 1 missed: make it `readonly` the same way and name it in the report. Positive control: remove `readonly ` from `src/Controller/Api/HealthController.php`'s declaration, re-run the loop, see it print, restore by hand.

- [ ] **Step 4: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections on the nine files.
```bash
git add src/Controller src/DependencyInjection src/Doctrine src/EventListener src/Security
git commit -m "refactor(#1169): stateless controllers, listeners and passes outside src/Service are final readonly"
```

---

### Finishing PR B

- [ ] **Step 1: The gates** (Global Constraints, "Gates per PR"). Expected: all green; nothing new in today's dev log.
- [ ] **Step 2: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 3: Final review (opus):** one fresh reviewer with `model: "opus"`, this plan, the issue and the diff. Attack points: the sniff runs on `src` and `tests` and nothing it should skip; no class made `readonly` keeps hidden state (a lazily built field, a memo); no closing keyword in any commit; re-run B1's deletion check and quote its FAIL.
- [ ] **Step 4: Open the PR** (Appendix K) with `var/refactor-1169/pr-b-body.md`:
```markdown
Refs #1169 (PR B of eight).

- `src/Kernel.php` and `tests/bootstrap.php` declare strict types; PHPCS's own `Generic.PHP.RequireStrictTypes` now fails a file that does not.
- Nine stateless classes outside `src/Service` become `final readonly`: five controllers, `DisableHttpServerPushPass`, `SqliteConnectionSetupMiddleware`, `CorsListener` and `InvalidatePasswordChangeTokensListener`. `src/Service` was done by #1202.

No behaviour change and no wire change.
```
Title: `refactor(#1169): strict types everywhere; stateless classes are final readonly`.
- [ ] **Step 5: Merge when green**, then `gh issue view 1169 --json state --jq .state`. Expected: `OPEN`.

---

# PR C — Collaborators are injected

### Task C0: Preflight (PR B merged)

- [ ] **Step 1: Branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "every source and test file declares strict types"
git switch -c refactor/1169-injected-collaborators origin/develop
```
Expected: one log line.

---

### Task C1: No `new` in a constructor default; `NoCollaboratorDefaultRule`

**Files:**
- Create: `tests/PhpStan/NoCollaboratorDefaultRule.php`, `tests/PhpStan/NoCollaboratorDefaultRuleTest.php`, `tests/PhpStan/data/no-collaborator-default-fixtures.php`
- Modify: `src/Service/Reader/BodyCleaning/BodyCleaningStep/RecipeFactsCleaner.php`, `src/Service/Sanitize/EntrySanitizer.php`, `phpstan.dist.neon`, `CLAUDE.md`
- Test: `tests/Service/Reader/BodyCleaning/BodyCleaningStep/RecipeFactsCleanerTest.php`, `tests/Service/Reader/ReaderBodyCleanerTest.php`, `tests/Service/Ingest/Factory/IngestedEntryFactoryTest.php`, `tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php`, `tests/Service/Reader/Slideshow/Model/SlideshowModelExtractionTest.php`, `tests/Service/Sanitize/EntrySanitizerTest.php`, `tests/Support/EntryIngestors.php`

**Interfaces:**
- Produces: `NoCollaboratorDefaultRule`, a `Rule<InClassNode>` with no constructor argument, identifier `simpleFeedReader.noCollaboratorDefault`, message `Inject the collaborator: <FQCN>::__construct() defaults $<name> with new (#1169).` It covers `App\Command`, `App\Controller`, `App\EventListener`, `App\Http`, `App\Security` and `App\Service`, except namespaces with a `\Dto\`, `\Model\` or `\Pass\` segment.
- `RecipeFactsCleaner::__construct(RecipeFactsRecognizer $recognizer, RecipeFactsMarkup $markup)`, `EntrySanitizer::__construct(TrailingBlankRemover $blankTail)`: no defaults.

- [ ] **Step 1: The fixture and the failing test**

`tests/PhpStan/data/no-collaborator-default-fixtures.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for NoCollaboratorDefaultRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures {
    final readonly class Helper
    {
    }

    final readonly class DefaultsItsHelper
    {
        public function __construct(
            private Helper $helper = new Helper(),
            private int $limit = 3,
        ) {
        }
    }

    final readonly class TakesItsHelper
    {
        public function __construct(private Helper $helper)
        {
        }
    }
}

namespace App\Service\Fixtures\Model {
    final readonly class DefaultsAValue
    {
        public function __construct(public \App\Service\Fixtures\Helper $part = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Controller\Fixtures {
    final readonly class ControllerDefaults
    {
        public function __construct(private \App\Service\Fixtures\Helper $helper = new \App\Service\Fixtures\Helper())
        {
        }
    }
}

namespace App\Entity\Fixtures {
    final class EntityDefaults
    {
        public function __construct(private \App\Service\Fixtures\Helper $helper = new \App\Service\Fixtures\Helper())
        {
        }
    }
}
```

`tests/PhpStan/NoCollaboratorDefaultRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoCollaboratorDefaultRule> */
final class NoCollaboratorDefaultRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoCollaboratorDefaultRule();
    }

    public function testItReportsAServiceThatDefaultsACollaboratorWithNew(): void
    {
        $this->analyse(
            [__DIR__ . '/data/no-collaborator-default-fixtures.php'],
            [
                [self::message('App\Service\Fixtures\DefaultsItsHelper', 'helper'), 16],
                [self::message('App\Controller\Fixtures\ControllerDefaults', 'helper'), 42],
            ],
        );
    }

    private static function message(string $className, string $parameter): string
    {
        return sprintf('Inject the collaborator: %s::__construct() defaults $%s with new (#1169).', $className, $parameter);
    }
}
```
Run: `php bin/phpunit tests/PhpStan/NoCollaboratorDefaultRuleTest.php`
Expected: FAIL, `Class "App\Tests\PhpStan\NoCollaboratorDefaultRule" not found`.

- [ ] **Step 2: The rule**

`tests/PhpStan/NoCollaboratorDefaultRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A service takes its collaborators from the container, never from a `new` in a constructor parameter's default
 * (CLAUDE.md "Depend on interfaces, inject them", #1169). Models, DTOs and per-call objects may default a value.
 *
 * @implements Rule<InClassNode>
 */
final readonly class NoCollaboratorDefaultRule implements Rule
{
    private const array SERVICE_NAMESPACES = [
        'App\\Command\\',
        'App\\Controller\\',
        'App\\EventListener\\',
        'App\\Http\\',
        'App\\Security\\',
        'App\\Service\\',
    ];

    private const array VALUE_SEGMENTS = ['\\Dto\\', '\\Model\\', '\\Pass\\'];

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->getClassReflection()->getName();
        $constructor = $node->getOriginalNode()->getMethod('__construct');
        if (null === $constructor || !self::isService($className)) {
            return [];
        }

        $defaultedWithNew = array_filter(
            $constructor->params,
            static fn (Param $parameter): bool => $parameter->default instanceof New_,
        );

        return array_values(array_map(
            static fn (Param $parameter): IdentifierRuleError => self::error($className, $parameter),
            $defaultedWithNew,
        ));
    }

    private static function isService(string $className): bool
    {
        return ClassNameReferences::isInAnyOf($className, self::SERVICE_NAMESPACES)
            && !array_any(self::VALUE_SEGMENTS, static fn (string $segment): bool => str_contains($className, $segment));
    }

    private static function error(string $className, Param $parameter): IdentifierRuleError
    {
        $variable = $parameter->var;
        $name = $variable instanceof Variable && \is_string($variable->name) ? $variable->name : '';

        return RuleErrorBuilder::message(sprintf(
            'Inject the collaborator: %s::__construct() defaults $%s with new (#1169).',
            $className,
            $name,
        ))
            ->identifier('simpleFeedReader.noCollaboratorDefault')
            ->line($parameter->getStartLine())
            ->build();
    }
}
```
Run: `php bin/phpunit tests/PhpStan/NoCollaboratorDefaultRuleTest.php`
Expected: PASS.

- [ ] **Step 3: Deletion checks (the rule test)**

One at a time, restoring each by hand:
1. Replace `$parameter->default instanceof New_` with `$parameter->default !== null`. Expected: FAIL, `Failed asserting that two strings are identical.`, with the extra line `17: Inject the collaborator: App\Service\Fixtures\DefaultsItsHelper::__construct() defaults $limit with new (#1169).`
2. Delete `&& !array_any(…)` (the second line of `isService()`'s expression, keeping the `;`). Expected: FAIL, with the extra line `33: Inject the collaborator: App\Service\Fixtures\Model\DefaultsAValue::__construct() defaults $part with new (#1169).`

- [ ] **Step 4: The two classes take their collaborators**

`src/Service/Reader/BodyCleaning/BodyCleaningStep/RecipeFactsCleaner.php`: replace
```php
        private RecipeFactsRecognizer $recognizer = new RecipeFactsRecognizer(),
        private RecipeFactsMarkup $markup = new RecipeFactsMarkup(),
```
with
```php
        private RecipeFactsRecognizer $recognizer,
        private RecipeFactsMarkup $markup,
```

`src/Service/Sanitize/EntrySanitizer.php`: replace
```php
    /**
     * The blank-tail trimmer is optional so the many tests that construct this
     * barrier directly keep working; the container still injects the service.
     */
    public function __construct(
        private TrailingBlankRemover $blankTail = new TrailingBlankRemover(),
    ) {
```
with
```php
    public function __construct(private TrailingBlankRemover $blankTail)
    {
```
(The text as it reads at `5dbc55d3`: #1202 G1 dropped the parameter's own `readonly`, R-1.)

The tests build them explicitly:
```bash
perl -pi -e 's/new EntrySanitizer\(\)/new EntrySanitizer(new TrailingBlankRemover())/g' tests/Service/Ingest/Factory/IngestedEntryFactoryTest.php tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/RecipeFactsCleanerTest.php tests/Service/Reader/Slideshow/Model/SlideshowModelExtractionTest.php tests/Service/Sanitize/EntrySanitizerTest.php tests/Support/EntryIngestors.php
perl -pi -e 's/new RecipeFactsCleaner\(\)/new RecipeFactsCleaner(new RecipeFactsRecognizer(), new RecipeFactsMarkup())/g' tests/Service/Reader/BodyCleaning/BodyCleaningStep/RecipeFactsCleanerTest.php tests/Service/Reader/ReaderBodyCleanerTest.php
git grep -n -E 'new (EntrySanitizer|RecipeFactsCleaner)\(\)' -- tests src
git grep -c 'new EntrySanitizer(new TrailingBlankRemover())' -- tests/Service/Sanitize/EntrySanitizerTest.php
```
Expected: the first grep prints nothing; the second prints `3`. Add `use App\Service\Sanitize\TrailingBlankRemover;` to each file the first perl changed (except `EntrySanitizerTest`, whose namespace is `App\Tests\Service\Sanitize`: it needs the import too), and `use App\Service\Reader\RecipeFacts\RecipeFactsMarkup;` and `use App\Service\Reader\RecipeFacts\RecipeFactsRecognizer;` to the two files the second changed, each in order.

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Sanitize tests/Service/Reader tests/Service/Ingest`
Expected: PASS.

- [ ] **Step 5: Register the rule**

`phpstan.dist.neon`, at the end of `services:`:
```yaml
    -
        class: App\Tests\PhpStan\NoCollaboratorDefaultRule
        tags:
            - phpstan.rules.rule
```
Run: `composer stan`. Expected: PASS.

Deletion check: in `EntrySanitizer`, restore the default (`private TrailingBlankRemover $blankTail = new TrailingBlankRemover()`). Run `composer stan`. Expected: FAIL, `Inject the collaborator: App\Service\Sanitize\EntrySanitizer::__construct() defaults $blankTail with new (#1169).` Restore by hand.

- [ ] **Step 6: CLAUDE.md, gates, commit**

`CLAUDE.md`, "Enforced mechanically", after the `PersistenceClassesAreFinalRule` entry:
```markdown
- **`NoCollaboratorDefaultRule`** (`tests/PhpStan/NoCollaboratorDefaultRule.php`) — a service, controller,
  listener, command or HTTP class never defaults a constructor parameter with `new`; tests pass the
  collaborator in. Models, DTOs and per-call objects may default a value.
```
Run: `composer check && composer md`, then the PhpStorm inspections.
```bash
git add tests/PhpStan src/Service/Reader/BodyCleaning/BodyCleaningStep/RecipeFactsCleaner.php src/Service/Sanitize/EntrySanitizer.php phpstan.dist.neon ../CLAUDE.md tests/Service tests/Support
git commit -m "refactor(#1169): no service defaults a collaborator with new, and a rule keeps it so"
```

---

### Task C2: `ActionTokenService` injects its repository; `getRepository()` is a query access

**Files:**
- Modify: `src/Service/Auth/ActionTokenService.php`, `tests/PhpStan/QueriesLiveInRepositoriesRule.php`, `tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`, `tests/PhpStan/data/queries-live-in-repositories-fixtures.php`
- Test: `tests/Service/Auth/ActionTokenServiceTest.php`

**Interfaces:**
- Produces: `ActionTokenService::__construct(EntityManagerInterface $em, ClockInterface $clock, ActionTokenRepository $tokens)`.

- [ ] **Step 1: The rule's fixture and test first**

```bash
wc -l < tests/PhpStan/data/queries-live-in-repositories-fixtures.php
```
Expected: `135`. Append to the fixture:
```php

namespace App\Service\LocatorFixtures {
    use App\Entity\Feed;
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class LocatesARepository
    {
        public function __construct(private EntityManagerInterface $em)
        {
        }

        public function repository(): object
        {
            return $this->em->getRepository(Feed::class);
        }
    }
}
```
The `getRepository` call is line 149 of a 135-line file (a different count shifts it by the same amount). In `tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`, after `[self::message('App\Controller\Fixtures\ReachesForTheConnection', '->getConnection()'), 80],` add:
```php
                [self::message('App\Service\LocatorFixtures\LocatesARepository', '->getRepository()'), 149],
```
Run: `php bin/phpunit tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`
Expected: FAIL, `Failed asserting that two strings are identical.`, missing `149: Queries live in src/Repository: App\Service\LocatorFixtures\LocatesARepository uses ->getRepository(). …`.

- [ ] **Step 2: The rule knows `getRepository()`**

`tests/PhpStan/QueriesLiveInRepositoriesRule.php`:
- Replace `private const array QUERY_METHODS = ['createNativeQuery', 'createQuery', 'createQueryBuilder', 'getConnection'];` with:
```php
    private const array QUERY_METHODS = [
        'createNativeQuery',
        'createQuery',
        'createQueryBuilder',
        'getConnection',
        'getRepository',
    ];
```
- In the class docblock, replace `QueryBuilder or holds the DBAL connection.` with `QueryBuilder, holds the DBAL connection or pulls a repository out of the EntityManager.`

Run: `php bin/phpunit tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`
Expected: PASS. Then `composer stan`. Expected: FAIL, exactly one error: `Queries live in src/Repository: App\Service\Auth\ActionTokenService uses ->getRepository(). …` Step 3 removes it.

- [ ] **Step 3: `ActionTokenService` takes the repository**

`src/Service/Auth/ActionTokenService.php`:
- Replace
```php
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }
```
with
```php
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private ActionTokenRepository $tokens,
    ) {
    }
```
- Replace `$this->repository()->findUnconsumedFor(` with `$this->tokens->findUnconsumedFor(` and `$this->repository()->findOneByHashAndPurpose(` with `$this->tokens->findOneByHashAndPurpose(`.
- Delete the method `repository()` (from `    private function repository(): ActionTokenRepository` to its closing `    }`) and the blank line before it.

`tests/Service/Auth/ActionTokenServiceTest.php`: replace
```php
        $this->service = new ActionTokenService($this->em, $this->clock);
```
with
```php
        $repository = $this->em->getRepository(ActionToken::class);
        self::assertInstanceOf(ActionTokenRepository::class, $repository);
        $this->service = new ActionTokenService($this->em, $this->clock, $repository);
```
and add `use App\Entity\ActionToken;` (if absent) and `use App\Repository\ActionTokenRepository;` in order. (Tests are exempt from the rule.)

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Auth tests/Controller/Api && composer stan`
Expected: PASS.

- [ ] **Step 4: Deletion check (the rule)**

Remove `'getRepository',` from `QUERY_METHODS`. Run `php bin/phpunit tests/PhpStan/QueriesLiveInRepositoriesRuleTest.php`. Expected: FAIL, the `149:` line missing from the actual errors. Restore by hand.

- [ ] **Step 5: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections.
```bash
git add src/Service/Auth/ActionTokenService.php tests/PhpStan tests/Service/Auth/ActionTokenServiceTest.php
git commit -m "refactor(#1169): ActionTokenService injects its repository; getRepository() counts as a query access"
```

---

### Task C3: `MailConnectionTester` sends through its transport

`Mailer::send()` without a message bus is `$transport->send($message, $envelope)` (`vendor/symfony/mailer/Mailer.php`), so the wrapper built per call adds nothing.

**Files:**
- Modify: `src/Service/Mail/Settings/MailConnectionTester.php`

- [ ] **Step 1: Send through the transport**

Replace
```php
            $mailer = new Mailer($transport);
            $mailer->send(
```
with
```php
            $transport->send(
```
and delete `use Symfony\Component\Mailer\Mailer;`.

- [ ] **Step 2: Run, gates, commit**

Run: `php bin/phpunit tests/Service/Mail/Settings tests/Controller/Admin && composer check && composer md`
Expected: PASS. `MailConnectionTesterTest`'s success, failure and config-guard tests all still pass: the send path is the same call.
```bash
git add src/Service/Mail/Settings/MailConnectionTester.php
git commit -m "refactor(#1169): the mail connection test sends through the transport it holds"
```

---

### Finishing PR C

- [ ] **Step 1: The gates** (Global Constraints). Expected: all green; nothing new in the dev log. `infection:diff` mutates `ActionTokenService`'s two changed lines and `MailConnectionTester`'s send; the existing tests kill them.
- [ ] **Step 2: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 3: /simplify** over the diff; commit `refactor(#1169): simplify pass` if anything changed.
- [ ] **Step 4: Final review (opus):** attack points: `NoCollaboratorDefaultRule`'s scope (a `Pass/` object that defaults a vendor value is legal, a root service that does is not); `getRepository` in the query rule has no false positive in `src/Repository` or `src/Doctrine`; `MailConnectionTester` still records failures and success exactly as before; no closing keyword in a commit; re-run C1's real-tree deletion check and quote its FAIL.
- [ ] **Step 5: Open the PR** (Appendix K) with `var/refactor-1169/pr-c-body.md`:
```markdown
Refs #1169 (PR C of eight).

- `RecipeFactsCleaner` and `EntrySanitizer` no longer default a collaborator with `new`; tests pass it in. `NoCollaboratorDefaultRule` keeps every service, controller, listener, command and HTTP class that way.
- `ActionTokenService` injects `ActionTokenRepository` instead of pulling it out of the EntityManager, and `QueriesLiveInRepositoriesRule` now counts `getRepository()` as a query access.
- `MailConnectionTester` sends through the transport it already holds instead of wrapping it in a `Mailer` per call.
- `ReaderAuditReportCommand` still builds `AuditReportHtml` with `new`: #1202 made it a per-call object, which is built that way.

No behaviour change and no wire change.
```
Title: `refactor(#1169): collaborators are injected, never defaulted or located`.
- [ ] **Step 6: Merge when green**, then check #1169 is `OPEN`.

---

# PR D — The Reader module's policies are injected services

The eleven Reader classes D-1 rules policy move out of `Support/` in one scripted commit (D1); D2–D4 turn ten of them into instance services and inject them, one family per task; D5 splits the two mixed classes (D-3); D6 turns `LeadingEngagementRules` into a service and takes its prose verdict out of the audit's models (D-reconcile-1, -2); D7 writes the boundary down.

**Conversion recipe** (D2–D6, E2–E5), applied to one class at a time and spelled out per class below:
1. Delete the class's `private function __construct()` with its `{` and `}` lines (I-support added one to every `Support/` class as its last member, at `5dbc55d3`) and the blank line before it.
2. `final class` → `final readonly class` (a `final readonly class` stays). Every converted class must end `final readonly`: `ServiceRoleRule`'s `rootService` reports a stateless service that is not.
3. Each `public static function` becomes `public function`. A `private static function` that calls one of the class's now-instance methods, or a collaborator, becomes `private function`; one that calls neither stays static.
4. Inside the class, `self::<now-instance method>(` becomes `$this-><method>(`; `self::CONSTANT` stays; a `static fn` whose body now uses `$this` loses its `static`.
5. A collaborator the class called statically becomes a promoted constructor parameter.

**The stan gate between D1 and D6.** D1 leaves one `supportHome` error per moved class, and each conversion task ends its own. Until D6, `composer check` stops at its `stan` step on the errors still waiting for a later task, so a task's gate is `composer cs`, `composer tramp` and the raw stan run of D1 Step 3: its two counts are equal (nothing but `supportHome` errors), and the second has dropped by the classes the task converted (D2 −5, D3 −3, D4 −2, D6 −1). From D6 on, `composer check` passes whole. The same holds in PR E between E1 and E5.

### Task D0: Preflight (PR C merged)

- [ ] **Step 1: Branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "collaborators are injected\|no service defaults a collaborator"
git switch -c refactor/1169-reader-policy-services origin/develop
mkdir -p backend/var/refactor-1169
```
Expected: one log line.

---

### Task D1: Move the Reader policy classes out of `Support/`

A pure move: namespaces, imports and paths change, no code. D2–D4 and D6 change the code.

**Files:**
- Move (by the script): the eleven classes and nine tests in the map below

**Interfaces:**
- Produces: `App\Service\Reader\AuthorBio\AuthorProfileLink`, `…\Reader\Media\Sibling\NearbyPoster`, `…\Reader\Media\NarrationSignals`, `…\Reader\Media\PageFurniture`, `…\Reader\Media\PlayerPoster`, `…\Reader\Paywall\MembershipCheckout`, `…\Reader\Paywall\OutsideFurniture`, `…\Reader\Paywall\PaywallBlocks`, `…\Reader\Paywall\PaywallSignals`, `…\Reader\ArticleContentGate`, `…\Reader\LeadingEngagementRules` (still static until D2–D4 and D6).

- [ ] **Step 1: The map**

`var/refactor-1169/reader-policies.php`:
```php
<?php

declare(strict_types=1);

return [
    'App\Service\Reader\AuthorBio\Support\AuthorProfileLink' => 'App\Service\Reader\AuthorBio\AuthorProfileLink',
    'App\Service\Reader\Media\Sibling\Support\NearbyPoster' => 'App\Service\Reader\Media\Sibling\NearbyPoster',
    'App\Service\Reader\Media\Support\NarrationSignals' => 'App\Service\Reader\Media\NarrationSignals',
    'App\Service\Reader\Media\Support\PageFurniture' => 'App\Service\Reader\Media\PageFurniture',
    'App\Service\Reader\Media\Support\PlayerPoster' => 'App\Service\Reader\Media\PlayerPoster',
    'App\Service\Reader\Paywall\Support\MembershipCheckout' => 'App\Service\Reader\Paywall\MembershipCheckout',
    'App\Service\Reader\Paywall\Support\OutsideFurniture' => 'App\Service\Reader\Paywall\OutsideFurniture',
    'App\Service\Reader\Paywall\Support\PaywallBlocks' => 'App\Service\Reader\Paywall\PaywallBlocks',
    'App\Service\Reader\Paywall\Support\PaywallSignals' => 'App\Service\Reader\Paywall\PaywallSignals',
    'App\Service\Reader\Support\ArticleContentGate' => 'App\Service\Reader\ArticleContentGate',
    'App\Service\Reader\Support\LeadingEngagementRules' => 'App\Service\Reader\LeadingEngagementRules',
    'App\Tests\Service\Reader\Media\Sibling\Support\NearbyPosterTest'
        => 'App\Tests\Service\Reader\Media\Sibling\NearbyPosterTest',
    'App\Tests\Service\Reader\Media\Support\NarrationSignalsTest' => 'App\Tests\Service\Reader\Media\NarrationSignalsTest',
    'App\Tests\Service\Reader\Media\Support\PageFurnitureTest' => 'App\Tests\Service\Reader\Media\PageFurnitureTest',
    'App\Tests\Service\Reader\Media\Support\PlayerPosterTest' => 'App\Tests\Service\Reader\Media\PlayerPosterTest',
    'App\Tests\Service\Reader\Paywall\Support\MembershipCheckoutTest'
        => 'App\Tests\Service\Reader\Paywall\MembershipCheckoutTest',
    'App\Tests\Service\Reader\Paywall\Support\PaywallBlocksTest' => 'App\Tests\Service\Reader\Paywall\PaywallBlocksTest',
    'App\Tests\Service\Reader\Paywall\Support\PaywallSignalsTest' => 'App\Tests\Service\Reader\Paywall\PaywallSignalsTest',
    'App\Tests\Service\Reader\Support\ArticleContentGateTest' => 'App\Tests\Service\Reader\ArticleContentGateTest',
    'App\Tests\Service\Reader\Support\LeadingEngagementRulesTest'
        => 'App\Tests\Service\Reader\LeadingEngagementRulesTest',
];
```
Check the sources exist and no target does:
```bash
php -r '$m = require "var/refactor-1169/reader-policies.php"; foreach ($m as $old => $new) { $p = static fn (string $c): string => str_starts_with($c, "App\\Tests\\") ? "tests/" . str_replace("\\", "/", substr($c, 10)) . ".php" : "src/" . str_replace("\\", "/", substr($c, 4)) . ".php"; echo (is_file($p($old)) ? "ok " : "MISSING ") . $p($old), (is_file($p($new)) ? " TAKEN" : ""), "\n"; }'
```
Expected: twenty `ok` lines, no `MISSING`, no `TAKEN`.

- [ ] **Step 2: Move and prove no code changed**

```bash
php ../docs/superpowers/plans/2026-09-28-1202-scripts/move-classes.php var/refactor-1169/reader-policies.php
find src tests -type d -empty -delete
bin/console cache:clear && bin/console cache:warmup
php ../docs/superpowers/plans/2026-09-28-1202-scripts/compare-moves.php var/refactor-1169/reader-policies.php
```
Expected: `Moved 20 classes (0 renamed); …`; `0 of 20 moved files differ in code.` (a test whose relative path depth changed may be listed: report it) and `0 of 20 moved files declare the wrong namespace.` `LeadingEngagementBlocks` (still in `Support/`) now imports `App\Service\Reader\LeadingEngagementRules`; D5 removes that import.

- [ ] **Step 3: The suites and the role rule**

```bash
php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit
composer stan -- --error-format=raw --no-progress > var/refactor-1169/stan-d1.txt 2>&1 || true
grep -c -E '\.php:[0-9]+:' var/refactor-1169/stan-d1.txt
grep -c -E '\.php:[0-9]+:Service role "supportHome"' var/refactor-1169/stan-d1.txt
```
Expected: `php bin/phpunit` PASS, then `11` and `11`: every error `composer stan` reports is a `supportHome` error, one per moved class, each reading `Service role "supportHome": App\Service\Reader\<…>\<Class> is static-only. Its home is App\Service\Reader\<…>\Support\<Class>.` The classes are static-only in a module root, and `ServiceShapes` skips a static-only class, so no `rootService` error appears yet. The `11` is also the positive control for the later counts (D4 expects `1`, D6 `0`). D2–D4 and D6 end those errors; they are the ratchet showing the work left.

- [ ] **Step 4: Commit**

```bash
git add -A -- . ../docker ../.github ../CLAUDE.md ../docs/architecture.md
git status --short | grep -v '^[RMAD] ' && echo 'STOP: an unstaged or untracked change' || true
git commit -m "refactor(#1169): the reader's policy classes leave Support/"
```
(This one commit leaves `composer stan` red on purpose; PR D merges only after D2–D6, and Finishing runs the gates on the whole branch.)

---

### Task D2: The paywall verdict is injected; `ArticlePageReader`

**Files:**
- Create: `src/Service/Reader/ArticlePageReader.php`
- Modify: `src/Service/Reader/Paywall/PaywallSignals.php`, `PaywallBlocks.php`, `MembershipCheckout.php`, `OutsideFurniture.php`, `src/Service/Reader/Media/PageFurniture.php`, `src/Service/Reader/ArticleExtractor/ArticleExtractor.php`
- Test: `tests/Service/Reader/Paywall/PaywallSignalsTest.php`, `PaywallBlocksTest.php`, `MembershipCheckoutTest.php`, `tests/Service/Reader/Media/PageFurnitureTest.php`, `tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php`

**Interfaces:**
- Produces:
  - `PageFurniture::holds(Element $element): bool` (instance, no constructor)
  - `OutsideFurniture::__construct(PageFurniture $furniture)`, `holdsMatchFor(HTMLDocument $document, string $xpath): bool`
  - `PaywallBlocks::__construct(OutsideFurniture $outsideFurniture)`, `foundOutsideFurnitureIn(HTMLDocument $document): bool`
  - `MembershipCheckout::__construct(OutsideFurniture $outsideFurniture)`, `foundOutsideFurnitureIn(HTMLDocument $document): bool`
  - `PaywallSignals::__construct(PaywallBlocks $blocks, MembershipCheckout $checkout)`, `isPreview(HTMLDocument $rawDocument, HTMLDocument $normalized): bool`
  - `ArticlePageReader::__construct(FetchedPageNormalizer $normalizer, PageMediaScanner $mediaScanner, SlideshowScanner $slideshowScanner, TeaserPlayerScanner $teaserScanner, PaywallSignals $paywall)`, `read(PageResponseModel $page, FeedMediaModel $feedMedia): ArticlePageModel`
  - `ArticleExtractor::__construct(HtmlPageFetcher $fetcher, ArticlePageReader $pageReader, ReaderBodyCleaner $bodyCleaner, EntrySanitizer $sanitizer, BodyMediaResolver $bodyMedia, ArticleReadability $readability)` (D4 appends `ArticleContentGate $contentGate`)

- [ ] **Step 1: The four paywall classes and `PageFurniture` become services (the recipe)**

`src/Service/Reader/Media/PageFurniture.php`: delete the private constructor; `public static function holds(` → `public function holds(`. (It is already `final readonly`.)

`src/Service/Reader/Paywall/OutsideFurniture.php`: delete the private constructor; add after `private const array DOCUMENT_ROOTS = ['html', 'body'];` and its blank line:
```php
    public function __construct(private PageFurniture $furniture)
    {
    }

```
`public static function holdsMatchFor(` → `public function holdsMatchFor(`; `!PageFurniture::holds($element)` → `!$this->furniture->holds($element)`.

`src/Service/Reader/Paywall/PaywallBlocks.php` and `MembershipCheckout.php`: delete the private constructor; add after the last constant and its blank line:
```php
    public function __construct(private OutsideFurniture $outsideFurniture)
    {
    }

```
`public static function foundOutsideFurnitureIn(` → `public function foundOutsideFurnitureIn(`; `OutsideFurniture::holdsMatchFor(` → `$this->outsideFurniture->holdsMatchFor(`. The private static query builders stay static.

`src/Service/Reader/Paywall/PaywallSignals.php`: delete the private constructor; add after the class's opening brace:
```php
    public function __construct(
        private PaywallBlocks $blocks,
        private MembershipCheckout $checkout,
    ) {
    }

```
Then: `public static function isPreview(` → `public function isPreview(`; `self::gatedInBody($normalized)` → `$this->gatedInBody($normalized)`; `private static function gatedInBody(` → `private function gatedInBody(`; `return PaywallBlocks::foundOutsideFurnitureIn($normalized)` → `return $this->blocks->foundOutsideFurnitureIn($normalized)`; `|| MembershipCheckout::foundOutsideFurnitureIn($normalized);` → `|| $this->checkout->foundOutsideFurnitureIn($normalized);`. `SchemaOrgAccess::declaredIn(` stays static (value, `Paywall\Support`).

- [ ] **Step 2: `ArticlePageReader` takes the page reads off `ArticleExtractor` (D-4)**

`src/Service/Reader/ArticlePageReader.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Media\Model\RawPageModel;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\Media\Teaser\TeaserPlayerScanner;
use App\Service\Reader\Model\ArticlePageModel;
use App\Service\Reader\Model\FeedMediaModel;
use App\Service\Reader\Model\LeadFigureCaptionsModel;
use App\Service\Reader\Model\PageImageInventoryModel;
use App\Service\Reader\Model\PageResponseModel;
use App\Service\Reader\Paywall\PaywallSignals;
use App\Service\Reader\Slideshow\SlideshowScanner;

/** Every read of a fetched page, taken before readability consumes the normalised document (#684, #748). */
final readonly class ArticlePageReader
{
    public function __construct(
        private FetchedPageNormalizer $normalizer,
        private PageMediaScanner $mediaScanner,
        private SlideshowScanner $slideshowScanner,
        private TeaserPlayerScanner $teaserScanner,
        private PaywallSignals $paywall,
    ) {
    }

    public function read(PageResponseModel $page, FeedMediaModel $feedMedia): ArticlePageModel
    {
        $normalized = $this->normalizer->normalize($page->html);
        $pageImages = PageImageInventoryModel::fromDocument($normalized);
        $leadCaptions = LeadFigureCaptionsModel::fromDocument($normalized);
        $rawPage = RawPageModel::parse($page->html, $page->finalUrl);

        return new ArticlePageModel(
            page: $page,
            normalized: $normalized,
            pageImages: $pageImages,
            leadCaptions: $leadCaptions,
            paywalled: $this->paywall->isPreview($rawPage->document, $normalized),
            media: $this->mediaScanner->scan($rawPage, $feedMedia),
            slideshows: $this->slideshowScanner->scan($normalized),
            teasers: $this->teaserScanner->scan($normalized, $page->finalUrl),
        );
    }
}
```
The body is `ArticleExtractor::readPage()` verbatim, in the same evaluation order.

`src/Service/Reader/ArticleExtractor/ArticleExtractor.php`:
- Replace the constructor with:
```php
    public function __construct(
        private HtmlPageFetcher $fetcher,
        private ArticlePageReader $pageReader,
        private ReaderBodyCleaner $bodyCleaner,
        private EntrySanitizer $sanitizer,
        private BodyMediaResolver $bodyMedia,
        private ArticleReadability $readability,
    ) {
    }
```
- In `extractPage()`, replace `$articlePage = $this->readPage($page, $hints->feedMedia);` with `$articlePage = $this->pageReader->read($page, $hints->feedMedia);`.
- Delete the method `readPage()` with the blank line before it.
- Delete the now-unused imports: `FetchedPageNormalizer`, `PageMediaScanner`, `RawPageModel`, `TeaserPlayerScanner`, `FeedMediaModel`, `LeadFigureCaptionsModel`, `PageImageInventoryModel`, `PaywallSignals` (its `Support` or moved name), `SlideshowScanner`; add `use App\Service\Reader\ArticlePageReader;` in order. PhpStorm's unused-import warning lists anything left.

- [ ] **Step 3: The tests**

The four moved paywall/furniture tests call the classes statically. In each, replace the static call with a call on an instance built in the test:
- `tests/Service/Reader/Media/PageFurnitureTest.php`: `PageFurniture::holds(` → `(new PageFurniture())->holds(`.
- `tests/Service/Reader/Paywall/PaywallBlocksTest.php`: `PaywallBlocks::foundOutsideFurnitureIn(` → `(new PaywallBlocks(new OutsideFurniture(new PageFurniture())))->foundOutsideFurnitureIn(`; import `App\Service\Reader\Media\PageFurniture` and `App\Service\Reader\Paywall\OutsideFurniture`.
- `tests/Service/Reader/Paywall/MembershipCheckoutTest.php`: `MembershipCheckout::foundOutsideFurnitureIn(` → `(new MembershipCheckout(new OutsideFurniture(new PageFurniture())))->foundOutsideFurnitureIn(`; same imports.
- `tests/Service/Reader/Paywall/PaywallSignalsTest.php`: `PaywallSignals::isPreview(` → `self::paywallSignals()->isPreview(`, and add at the end of the class:
```php
    private static function paywallSignals(): PaywallSignals
    {
        $outsideFurniture = new OutsideFurniture(new PageFurniture());

        return new PaywallSignals(new PaywallBlocks($outsideFurniture), new MembershipCheckout($outsideFurniture));
    }
```
with the four imports in order.

`tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php`: every `new ArticleExtractor(` (two at `5dbc55d3`, in `extractor()` and near line 480) passes, in this order, the `HtmlPageFetcher`, the `FetchedPageNormalizer`, the body cleaner, the `EntrySanitizer`, the media scanner, the `BodyMediaResolver`, the `SlideshowScanner`, the `TeaserPlayerScanner` and the readability. Rewrite each call so the normalizer, the media scanner and the two page scanners become one `ArticlePageReader`, in this order:
```php
        return new ArticleExtractor(
            <the HtmlPageFetcher argument, unchanged>,
            new ArticlePageReader(
                new FetchedPageNormalizer(FetchedPageNormalizerTest::repairs()),
                $this->mediaScanner(),
                $slideshowScanner ?? new SlideshowScanner([]),
                new TeaserPlayerScanner($this->urlKind()),
                self::paywallSignals(),
            ),
            $this->bodyCleaner(),
            new EntrySanitizer(new TrailingBlankRemover()),
            <the BodyMediaResolver argument, unchanged>,
            $this->articleReadability(),
        );
```
(The second call site keeps its own normalizer, scanners and slideshow argument; only their grouping changes.) Add the same `paywallSignals()` helper as `PaywallSignalsTest`'s, and the imports.

`ArticleExtractorTest::testFlagsAPaywalledArticleDeclaredInJsonLd()` and its three siblings already pin that the extraction asks the paywall verdict; Step 5 re-proves it through `ArticlePageReader`.

- [ ] **Step 4: Run**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Reader tests/Controller/Api/EntryReaderControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

One at a time, restoring each by hand:
1. In `ArticlePageReader::read()`, replace `paywalled: $this->paywall->isPreview($rawPage->document, $normalized),` with `paywalled: false,`. Expected: `ArticleExtractorTest::testFlagsAPaywalledArticleDeclaredInJsonLd` fails, `Failed asserting that false is true.`
2. In `PaywallSignals::gatedInBody()`, replace the body with `return false;`. Expected: `PaywallSignalsTest::testAnAbsentDeclarationWithAGateBlockFlagsAPreview` fails, `Failed asserting that false is true.`

- [ ] **Step 6: Gates and commit**

Run: `composer cs && composer md && composer tramp` and the stan gate (PR D's intro: two equal counts, down by 5 from D1's), then the PhpStorm inspections on every changed file.
```bash
git add src/Service/Reader tests/Service/Reader
git commit -m "refactor(#1169): the paywall verdict is an injected service; ArticlePageReader takes the page reads"
```

---

### Task D3: Page furniture, narration and posters are injected

**Files:**
- Modify: `src/Service/Reader/Media/NarrationSignals.php`, `PlayerPoster.php`, `src/Service/Reader/Media/Sibling/NearbyPoster.php`, `src/Service/Reader/Media/Sibling/SiblingIdRule.php`, `src/Service/Reader/Media/Sibling/Pass/SiblingSearch.php`, `src/Service/Reader/Media/MediaCandidateSource/AttributeMediaSource.php`, `SemanticMediaSource.php`, `JsonLdMediaSource.php`, `PageEmbedSource.php`, `ScriptEmbedSource.php`, `YouTubeIdAttributeSource.php`, `ZdfPlayerConfigSource.php`, `src/Service/Reader/Media/Teaser/TeaserPlayerScanner.php`, `src/Service/Reader/PageRepair/ImageWrapperClassRemover.php`, `src/Service/Reader/BodyCleaning/BodyCleaningStep/PlayerChromeCleaner.php`
- Test: the moved `NarrationSignalsTest`, `PlayerPosterTest`, `NearbyPosterTest`; and every test that builds one of the changed classes (Step 3 lists them)

**Interfaces:**
- Produces: `NarrationSignals::narrates(string $fileUrl, ?Element $holder): bool`, `NarrationSignals::declaredOn(Element $element): bool`, `PlayerPoster::near(Element $holder): ?string`, `NearbyPoster::after(string $html, int $position): ?string`, all instance methods on no-argument services.
- New constructor parameters, appended last:

| Class | Appended parameters |
|---|---|
| `AttributeMediaSource` | `PageFurniture $furniture, NarrationSignals $narration, PlayerPoster $playerPoster` |
| `SemanticMediaSource` | `PageFurniture $furniture, NarrationSignals $narration` |
| `JsonLdMediaSource`, `PageEmbedSource`, `ScriptEmbedSource`, `YouTubeIdAttributeSource` | `PageFurniture $furniture` |
| `ZdfPlayerConfigSource` | `NearbyPoster $nearbyPoster` |
| `TeaserPlayerScanner` | `PageFurniture $furniture, PlayerPoster $playerPoster` |
| `ImageWrapperClassRemover` (new constructor) | `PageFurniture $furniture` |
| `PlayerChromeCleaner` (new constructor) | `NarrationSignals $narration` |
| `SiblingIdRule` (new constructor) | `NearbyPoster $nearbyPoster` |
| `Pass\SiblingSearch` | `NearbyPoster $nearbyPoster` (after `string $pageHtml`) |

- [ ] **Step 1: The three policies become services (the recipe)**

`src/Service/Reader/Media/NarrationSignals.php`: delete the private constructor; `public static function narrates(` → `public function narrates(`; `public static function declaredOn(` → `public function declaredOn(`; `private static function holderChainDeclaresNarration(` → `private function holderChainDeclaresNarration(`; in `narrates()`, `self::holderChainDeclaresNarration($holder)` → `$this->holderChainDeclaresNarration($holder)`; in `holderChainDeclaresNarration()`, `self::declaredOn($element)` → `$this->declaredOn($element)`. `declaresNarration()` and `tokenPattern()` stay private static.

`src/Service/Reader/Media/PlayerPoster.php`: delete the private constructor; `public static function near(` → `public function near(`. `firstImageIn()` stays private static.

`src/Service/Reader/Media/Sibling/NearbyPoster.php`: delete the private constructor; `public static function after(` → `public function after(`. `area()` stays private static.

- [ ] **Step 2: The callers take them**

Each constructor gains its parameters from the Interfaces table, promoted `private`, appended after the existing ones (a class without a constructor gets `public function __construct(<parameters>)` followed by `{` and `}` on their own lines, placed after its constants). Then:

| File | Replace | With |
|---|---|---|
| `AttributeMediaSource.php` | `PageFurniture::holds($element)` | `$this->furniture->holds($element)` |
| `AttributeMediaSource.php` | `NarrationSignals::narrates(` | `$this->narration->narrates(` |
| `AttributeMediaSource.php` | `PlayerPoster::near(` | `$this->playerPoster->near(` |
| `SemanticMediaSource.php` | `PageFurniture::holds($element)` | `$this->furniture->holds($element)` |
| `SemanticMediaSource.php` | `NarrationSignals::narrates(` | `$this->narration->narrates(` |
| `JsonLdMediaSource.php`, `ScriptEmbedSource.php` | `PageFurniture::holds($script)` | `$this->furniture->holds($script)` |
| `PageEmbedSource.php`, `YouTubeIdAttributeSource.php` | `PageFurniture::holds($element)` | `$this->furniture->holds($element)` |
| `ZdfPlayerConfigSource.php` | `NearbyPoster::after(` | `$this->nearbyPoster->after(` |
| `TeaserPlayerScanner.php` | `PageFurniture::holds($element)` | `$this->furniture->holds($element)` |
| `TeaserPlayerScanner.php` | `PlayerPoster::near(` | `$this->playerPoster->near(` |
| `ImageWrapperClassRemover.php` | `!PageFurniture::holds($image)` | `!$this->furniture->holds($image)` |
| `PlayerChromeCleaner.php` | `NarrationSignals::declaredOn($element)` | `$this->narration->declaredOn($element)` |
| `Pass/SiblingSearch.php` | `NearbyPoster::after(` | `$this->nearbyPoster->after(` |
| `SiblingIdRule.php` | `new SiblingSearch($pageHtml)` | `new SiblingSearch($pageHtml, $this->nearbyPoster)` |

Every call site sits in an instance method at `5dbc55d3`; none is inside a `static fn`. `SiblingSearch` reads the poster it is handed, so no parameter is tramp data. Each file keeps (or gains) the import of the moved class it now type-hints.

```bash
git grep -n -E '(PageFurniture|NarrationSignals|PlayerPoster|NearbyPoster)::' -- src
git grep -c -E 'KeyedOccurrences::' -- src/Service/Reader/Media/Sibling/Pass/SiblingSearch.php
```
Expected: the first grep prints nothing; the second prints `2` (`KeyedOccurrences` is a value helper and stays static: positive control).

- [ ] **Step 3: The tests build them**

Replace the static calls in the three moved tests: `NarrationSignals::` → `(new NarrationSignals())->`, `PlayerPoster::` → `(new PlayerPoster())->`, `NearbyPoster::` → `(new NearbyPoster())->` (in `tests/Service/Reader/Media/NarrationSignalsTest.php`, `PlayerPosterTest.php`, `Sibling/NearbyPosterTest.php`).

Then every construction of a class whose constructor grew gets the new arguments, last, in the table's order, as `new PageFurniture()`, `new NarrationSignals()`, `new PlayerPoster()`, `new NearbyPoster()`:
```bash
git grep -n -E 'new (AttributeMediaSource|SemanticMediaSource|JsonLdMediaSource|PageEmbedSource|ScriptEmbedSource|YouTubeIdAttributeSource|ZdfPlayerConfigSource|TeaserPlayerScanner|ImageWrapperClassRemover|PlayerChromeCleaner|SiblingIdRule)\(' -- tests src
```
At `5dbc55d3` this lists `ArticleExtractorTest` (the six sources, `TeaserPlayerScanner`, `SiblingIdRule`), `DurableEmissionTest`, the seven source tests, `TeaserPlayerScannerTest`, `SiblingIdRuleTest`, `SiblingMediaExtenderTest`, `FetchedPageNormalizerTest::repairs()`, `ImageWrapperClassRemoverTest`, `PlayerChromeCleanerTest` and `ReaderBodyCleanerTest::steps()` (no `src` hit: the container builds them). Edit each hit by hand: a call with arguments gains `, new …()` before its closing parenthesis; a call without (`new ImageWrapperClassRemover()`) gets the argument alone; a multi-line call gains a line. Add the imports.

- [ ] **Step 4: Run**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Reader`
Expected: PASS. An `ArgumentCountError` or `Too few arguments` names a construction Step 3 missed.

- [ ] **Step 5: Gates and commit**

Run: `composer cs && composer md && composer tramp` and the stan gate (down by 3 more), then the PhpStorm inspections.
```bash
git add src/Service/Reader tests/Service/Reader
git commit -m "refactor(#1169): page furniture, narration and poster policies are injected services"
```

---

### Task D4: `AuthorProfileLink` and `ArticleContentGate` are injected

**Files:**
- Modify: `src/Service/Reader/AuthorBio/AuthorProfileLink.php`, `src/Service/Reader/ArticleContentGate.php`, `src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php`, `src/Service/Reader/ArticleReadability.php`, `src/Service/Reader/ArticleExtractor/ArticleExtractor.php`
- Test: `tests/Service/Reader/ArticleContentGateTest.php` (moved), `tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php`, `tests/Service/Reader/ReaderBodyCleanerTest.php`, `tests/Service/Reader/ArticleReadabilityTest.php`, `tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php`

**Interfaces:**
- Produces: `AuthorProfileLink::isPresentIn(Element $element): bool`; `ArticleContentGate::contentOf(Article $article, ArticleMediaModel $media): string`, `ArticleContentGate::textLength(Article $article): int`; `AuthorBioSeparator::__construct(AuthorProfileLink $authorProfileLink)` (D5 appends `LinkListDetector $linkLists`); `ArticleReadability` appends `ArticleContentGate $contentGate`; `ArticleExtractor` appends `ArticleContentGate $contentGate` (7 parameters).

- [ ] **Step 1: The two policies become services (the recipe)**

`AuthorProfileLink.php`: delete the private constructor; `final class` → `final readonly class`; `public static function isPresentIn(` → `public function isPresentIn(`. `pointsToProfile()` stays private static.

`ArticleContentGate.php`: delete the private constructor; `final class` → `final readonly class`; `public static function contentOf(` → `public function contentOf(`; `public static function textLength(` → `public function textLength(`; in `contentOf()`, `self::textLength($article)` → `$this->textLength($article)`.

- [ ] **Step 2: The callers**

`AuthorBioSeparator.php`: after `private const int SUBSTANTIAL_PROSE_LENGTH = 200;` and its blank line, add
```php
    public function __construct(private AuthorProfileLink $authorProfileLink)
    {
    }

```
and replace `return array_any($tail, static fn (Element $block): bool => AuthorProfileLink::isPresentIn($block));` with `return array_any($tail, $this->authorProfileLink->isPresentIn(...));`.

`ArticleReadability.php`: append `private ArticleContentGate $contentGate,` to the constructor's parameters; replace `return ArticleContentGate::textLength($collapsed) > ArticleContentGate::textLength($conservative)` with `return $this->contentGate->textLength($collapsed) > $this->contentGate->textLength($conservative)`.

`ArticleExtractor.php`: append `private ArticleContentGate $contentGate,` after `private ArticleReadability $readability,`; replace `$content = ArticleContentGate::contentOf($article, $articlePage->media);` with `$content = $this->contentGate->contentOf($article, $articlePage->media);`; the import now names `App\Service\Reader\ArticleContentGate`.

- [ ] **Step 3: The tests**

- `ArticleContentGateTest`: `ArticleContentGate::` → `(new ArticleContentGate())->`.
- `new AuthorBioSeparator()` (in `AuthorBioSeparatorTest` and `ReaderBodyCleanerTest::steps()`) → `new AuthorBioSeparator(new AuthorProfileLink())`.
- Every `new ArticleReadability(` (in `ArticleReadabilityTest` and `ArticleExtractorTest::articleReadability()`) gains `, new ArticleContentGate()` last.
- Every `new ArticleExtractor(` gains `new ArticleContentGate(),` as its last argument.

```bash
git grep -n -E '(AuthorProfileLink|ArticleContentGate)::' -- src tests
```
Expected: nothing. Positive control: `git grep -c -E '(AuthorProfileLink|ArticleContentGate)::' origin/develop -- src tests | wc -l` prints a number above 0.

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Reader && composer cs && composer md && composer tramp`, and the stan gate
Expected: PASS. Of D1's eleven `supportHome` errors one is left: `composer stan -- --error-format=raw --no-progress 2>&1 | grep 'Service role "supportHome"'` prints the single line for `App\Service\Reader\LeadingEngagementRules` (D6 ends it; D1's `11` was this grep's positive control).
```bash
git add src/Service/Reader tests/Service/Reader
git commit -m "refactor(#1169): the author-profile and content-floor policies are injected services"
```

---

### Task D5: `LinkListDetector` and `LeadingBlockJudge` split the verdicts off the measurements (D-3)

`BlockText` keeps `collapsed()` and `linkTextLength()`; its tuned ratio moves to `LinkListDetector`. `LeadingEngagementBlocks` keeps `in()` and `isTimeOnly()` (models call them from static factories); its three verdicts move to `LeadingBlockJudge`. Until D6, `LeadingBlockJudge::isProse()` calls `LeadingEngagementRules::isProse()` statically, as `LeadingEngagementBlocks` did: the split must come first, because the static `LeadingEngagementBlocks::isProse()` could not reach an injected rules service (D-reconcile-3).

**Files:**
- Create: `src/Service/Reader/LinkListDetector.php`, `src/Service/Reader/LeadingBlockJudge.php`, `tests/Service/Reader/LinkListDetectorTest.php`, `tests/Service/Reader/LeadingBlockJudgeTest.php`, `tests/Support/LeadingEngagementCleaners.php`
- Modify: `src/Service/Reader/Support/BlockText.php`, `src/Service/Reader/Support/LeadingEngagementBlocks.php`, `src/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparator.php`, `EdgeBoilerplateTrimmer.php`, `LeadingEngagementCleaner.php`, `src/Service/Reader/BoilerplateVerdict.php`, `src/Service/Reader/Pass/LeadingFurniture.php`
- Test: every test that builds `AuthorBioSeparator`, `EdgeBoilerplateTrimmer`, `BoilerplateVerdict`, `LeadingEngagementCleaner` or `LeadingFurniture` (Step 5 lists them)

**Interfaces:**
- Produces:
  - `LinkListDetector::isLinkDominated(Element $block): bool` (no constructor)
  - `LeadingBlockJudge::isProse(LeadingBlockModel $block): bool`, `isProtectedContent(Element $element): bool`, `isDecorativeIcon(Element $image): bool` (no constructor until D6 gives it `LeadingEngagementRules $rules`)
  - Appended constructor parameters: `AuthorBioSeparator`, `EdgeBoilerplateTrimmer`, `BoilerplateVerdict` (new constructor): `LinkListDetector $linkLists`; `LeadingEngagementCleaner::__construct(DateLineRecognizer $dateLines, LeadingBlockJudge $judge)`; `Pass\LeadingFurniture::__construct(?string $entryAuthor, DateLineRecognizer $dateLines, LeadingBlockJudge $judge)`.
- Consumes: `App\Service\Reader\LeadingEngagementRules::isProse(string $text, int $linkTextLength): bool`, still static (D1 moved it; D6 converts it).

- [ ] **Step 1: The failing tests**

`tests/Service/Reader/LinkListDetectorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LinkListDetector;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class LinkListDetectorTest extends TestCase
{
    use ParsesHtml;

    public function testThreeFifthsLinkTextMakesALinkList(): void
    {
        self::assertTrue((new LinkListDetector())->isLinkDominated($this->paragraph('<a href="/a">abcdef</a>ghij')));
    }

    public function testHalfLinkTextIsProse(): void
    {
        self::assertFalse((new LinkListDetector())->isLinkDominated($this->paragraph('<a href="/a">abcde</a>fghij')));
    }

    public function testAnEmptyBlockIsNoLinkList(): void
    {
        self::assertFalse((new LinkListDetector())->isLinkDominated($this->paragraph('')));
    }

    private function paragraph(string $inner): Element
    {
        $paragraph = $this->document('<body><p>' . $inner . '</p></body>')->querySelector('p');
        self::assertInstanceOf(Element::class, $paragraph);

        return $paragraph;
    }
}
```
(`abcdef` is 6 of 10 collapsed characters, exactly the 0.6 bar; `abcde` is 5 of 10. If R-5 made the trait's method `parse()`, call that.)

`tests/Service/Reader/LeadingBlockJudgeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingBlockJudge;
use App\Service\Reader\Model\LeadingBlockModel;
use App\Tests\Support\ParsesHtml;
use Dom\Element;
use PHPUnit\Framework\TestCase;

final class LeadingBlockJudgeTest extends TestCase
{
    use ParsesHtml;

    public function testAnIconAssetIsDecorative(): void
    {
        self::assertTrue($this->judge()->isDecorativeIcon($this->element('<img src="/assets/icons/share.svg">', 'img')));
    }

    public function testAPhotoNamedLikeAnIconIsNot(): void
    {
        self::assertFalse($this->judge()->isDecorativeIcon($this->element('<img src="/silicon-valley.jpg">', 'img')));
    }

    public function testAnImageInsideAFigureIsProtectedContent(): void
    {
        self::assertTrue(
            $this->judge()->isProtectedContent($this->element('<figure><img src="/a.jpg"></figure>', 'img')),
        );
    }

    public function testAHeadingIsProtectedContent(): void
    {
        self::assertTrue($this->judge()->isProtectedContent($this->element('<h2>Section</h2>', 'h2')));
    }

    public function testAPlainParagraphIsNotProtected(): void
    {
        self::assertFalse($this->judge()->isProtectedContent($this->element('<p>Text</p>', 'p')));
    }

    public function testALongLinkFreeBlockIsProse(): void
    {
        $text = str_repeat('Ein Satz mit Worten. ', 8);
        $element = $this->element('<p>' . $text . '</p>', 'p');

        self::assertTrue($this->judge()->isProse(new LeadingBlockModel($element, trim($text))));
    }

    private function judge(): LeadingBlockJudge
    {
        return new LeadingBlockJudge();
    }

    private function element(string $bodyHtml, string $selector): Element
    {
        $element = $this->document('<body>' . $bodyHtml . '</body>')->querySelector($selector);
        self::assertInstanceOf(Element::class, $element);

        return $element;
    }
}
```
(`LeadingBlockModel`'s constructor is `(Element $element, string $text)` at `5dbc55d3`; 8 × 21 characters trimmed is 167, over `PROSE_CHARS` 120. D6 changes `judge()` to pass the rules.)

Run: `php bin/phpunit tests/Service/Reader/LinkListDetectorTest.php tests/Service/Reader/LeadingBlockJudgeTest.php`
Expected: FAIL, `Class "App\Service\Reader\LinkListDetector" not found` and the same for `LeadingBlockJudge`.

- [ ] **Step 2: The two services**

`src/Service/Reader/LinkListDetector.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Support\BlockText;
use Dom\Element;

final readonly class LinkListDetector
{
    /** A block whose link text is at least this share of its text is a link list (#779). */
    private const float LINK_TEXT_RATIO = 0.6;

    public function isLinkDominated(Element $block): bool
    {
        $blockTextLength = mb_strlen(BlockText::collapsed($block));
        if ($blockTextLength === 0) {
            return false;
        }

        return BlockText::linkTextLength($block) / $blockTextLength >= self::LINK_TEXT_RATIO;
    }
}
```

`src/Service/Reader/LeadingBlockJudge.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Model\LeadingBlockModel;
use App\Service\Reader\Support\BlockText;
use Dom\Element;

/** The tuned verdicts on a block above the article body; LeadingEngagementBlocks only finds the blocks. */
final readonly class LeadingBlockJudge
{
    /** Tags that are article content in their own right and are never furniture. */
    private const array CONTENT_TAGS = ['figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    public function isProse(LeadingBlockModel $block): bool
    {
        return LeadingEngagementRules::isProse($block->text, BlockText::linkTextLength($block->element));
    }

    /** A caption, a heading or anything inside a <figure> is content, never furniture. */
    public function isProtectedContent(Element $element): bool
    {
        return \in_array($element->localName, self::CONTENT_TAGS, true)
            || self::hasFigureAncestor($element);
    }

    /**
     * A UI glyph rather than content: its URL carries the icon asset convention
     * — a path segment "icons" or an "icon" token in the file name. Matched as a
     * token so a content image like "silicon-valley.jpg" is left alone.
     */
    public function isDecorativeIcon(Element $image): bool
    {
        return preg_match('~(?:^|[^a-z])icons?(?:[^a-z]|$)~i', $image->getAttribute('src') ?? '') === 1;
    }

    private static function hasFigureAncestor(Element $element): bool
    {
        for ($ancestor = $element->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if ($ancestor->localName === 'figure') {
                return true;
            }
        }

        return false;
    }
}
```
The three method bodies and the icon docblock are `LeadingEngagementBlocks`' at `5dbc55d3`; `LeadingEngagementRules` is the root class D1 moved (same namespace, no import).

Run the Step 1 tests. Expected: PASS.

- [ ] **Step 3: The measurements lose the verdicts**

`src/Service/Reader/Support/BlockText.php`: delete the constant `LINK_TEXT_RATIO` with its docblock, and the method `isLinkDominated()` with the blank line before it. In `linkTextLength()`'s docblock, replace `the share isLinkDominated weighs.` with `the share LinkListDetector weighs.` In the class docblock, replace `The two text measurements the edge trimmer reads off a block` with `The two text measurements read off a block`.

`src/Service/Reader/Support/LeadingEngagementBlocks.php`: delete the constant `CONTENT_TAGS` with its docblock and the methods `isProse()`, `isProtectedContent()`, `isDecorativeIcon()` and `hasFigureAncestor()`, each with its docblock and the blank line before it. Delete the two imports that no longer have a use: `use App\Service\Reader\LeadingEngagementRules;` (D1's move added it) and `use App\Service\Reader\Support\BlockText;` if present (at `5dbc55d3` `BlockText` shares the namespace and has no import). It keeps `BLOCK_TAGS`, `in()`, `isTimeOnly()`, `isLeafTextBlock()`, `hasBlockDescendant()`, the `LeadingBlockModel`, `Whitespace` and `Element` imports and its private constructor at the end.

- [ ] **Step 4: The callers take the services**

Constructors (appended, promoted `private`; a class without one gets one after its constants):
- `AuthorBioSeparator`: `private LinkListDetector $linkLists` after `private AuthorProfileLink $authorProfileLink`.
- `EdgeBoilerplateTrimmer`: `private LinkListDetector $linkLists,` after `private BoilerplateVerdict $verdict,`.
- `BoilerplateVerdict`: new `public function __construct(private LinkListDetector $linkLists)`.
- `LeadingEngagementCleaner`: replace `public function __construct(private DateLineRecognizer $dateLines)` and its `{`/`}` lines with
```php
    public function __construct(
        private DateLineRecognizer $dateLines,
        private LeadingBlockJudge $judge,
    ) {
    }
```
- `Pass\LeadingFurniture`: replace `public function __construct(private ?string $entryAuthor, private DateLineRecognizer $dateLines)` and its `{`/`}` lines with
```php
    public function __construct(
        private ?string $entryAuthor,
        private DateLineRecognizer $dateLines,
        private LeadingBlockJudge $judge,
    ) {
    }
```

| File | Replace | With |
|---|---|---|
| `AuthorBioSeparator.php` | `&& !BlockText::isLinkDominated($paragraph);` | `&& !$this->linkLists->isLinkDominated($paragraph);` |
| `EdgeBoilerplateTrimmer.php` | `&& !BlockText::isLinkDominated($block);` | `&& !$this->linkLists->isLinkDominated($block);` |
| `BoilerplateVerdict.php` | `&& BlockText::isLinkDominated($block);` | `&& $this->linkLists->isLinkDominated($block);` |
| `LeadingEngagementCleaner.php` | `&& LeadingEngagementBlocks::isDecorativeIcon($image)` | `&& $this->judge->isDecorativeIcon($image)` |
| `LeadingEngagementCleaner.php` | `&& !LeadingEngagementBlocks::isProtectedContent($image)` | `&& !$this->judge->isProtectedContent($image)` |
| `LeadingEngagementCleaner.php` | `&& !LeadingEngagementBlocks::isProse($block)` | `&& !$this->judge->isProse($block)` |
| `LeadingEngagementCleaner.php` | `$furniture = new LeadingFurniture($entryAuthor, $this->dateLines);` | `$furniture = new LeadingFurniture($entryAuthor, $this->dateLines, $this->judge);` |
| `Pass/LeadingFurniture.php` | `if (LeadingEngagementBlocks::isProtectedContent($block->element)) {` | `if ($this->judge->isProtectedContent($block->element)) {` |
| `Pass/LeadingFurniture.php` | `if (LeadingEngagementBlocks::isProse($blocks[$index])) {` | `if ($this->judge->isProse($blocks[$index])) {` |

`BlockText::collapsed(`, `BlockText::linkTextLength(`, `LeadingEngagementBlocks::in(` and `LeadingEngagementBlocks::isTimeOnly(` stay static, and so do the `LeadingEngagementRules::` calls in `LeadingEngagementCleaner` and `LeadingFurniture` until D6. Fix each file's imports (`BlockText` stays where `collapsed()` or `linkTextLength()` is still called; `LeadingFurniture` and `LeadingEngagementCleaner` import `App\Service\Reader\LeadingBlockJudge`).

```bash
git grep -n -E 'BlockText::isLinkDominated|LeadingEngagementBlocks::is(Prose|ProtectedContent|DecorativeIcon)' -- src tests
git grep -c -E 'LeadingEngagementBlocks::(in|isTimeOnly)\(' -- src
```
Expected: the first grep prints nothing; the second prints counts above 0 (the structural calls stay: positive control).

- [ ] **Step 5: The tests build them**

```bash
git grep -n -E 'new (AuthorBioSeparator|EdgeBoilerplateTrimmer|BoilerplateVerdict|LeadingEngagementCleaner|LeadingFurniture)\(' -- tests src
```
At `5dbc55d3` this lists eight lines: `AuthorBioSeparatorTest:29`, `EdgeBoilerplateTrimmerTest:29` (`new EdgeBoilerplateTrimmer(new BoilerplateVerdict())`), `LeadingEngagementCleanerTest:26`, `ReaderBodyCleanerTest:69`, `:71` and `:78`, `LeadingEngagementMarkersTest:108`, and `src/…/LeadingEngagementCleaner.php` (the `LeadingFurniture` call, done in Step 4). No test builds a `LeadingFurniture`. Append the new arguments by hand: `new LinkListDetector()` to `AuthorBioSeparator`, `EdgeBoilerplateTrimmer` and `BoilerplateVerdict` (which had none: `new BoilerplateVerdict(new LinkListDetector())`). Add the imports.

The three `new LeadingEngagementCleaner(` calls would each grow past 120 columns, and D6 grows them again: one builder takes them. `tests/Support/LeadingEngagementCleaners.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\LeadingEngagementCleaner;
use App\Service\Reader\DateLineRecognizer;
use App\Service\Reader\Factory\StrictDateFormatterFactory;
use App\Service\Reader\LeadingBlockJudge;

/** The leading-engagement cleaner over the real date and block rules, wired as the container wires it. */
final class LeadingEngagementCleaners
{
    private function __construct()
    {
    }

    public static function cleaner(): LeadingEngagementCleaner
    {
        return new LeadingEngagementCleaner(
            new DateLineRecognizer(new StrictDateFormatterFactory()),
            new LeadingBlockJudge(),
        );
    }
}
```
Then:
| File | Replace | With |
|---|---|---|
| `tests/Service/Reader/BodyCleaning/BodyCleaningStep/LeadingEngagementCleanerTest.php` | `$this->cleaner = new LeadingEngagementCleaner(new DateLineRecognizer(new StrictDateFormatterFactory()));` | `$this->cleaner = LeadingEngagementCleaners::cleaner();` |
| `tests/Service/Reader/ReaderBodyCleanerTest.php` | `new LeadingEngagementCleaner(new DateLineRecognizer(new StrictDateFormatterFactory())),` | `LeadingEngagementCleaners::cleaner(),` |
| `tests/Service/ReaderAudit/LeadingEngagementMarkersTest.php` | `(new LeadingEngagementCleaner(new DateLineRecognizer(new StrictDateFormatterFactory())))->cleanIn(` | `LeadingEngagementCleaners::cleaner()->cleanIn(` |

Add `use App\Tests\Support\LeadingEngagementCleaners;` to each, and delete the `DateLineRecognizer`, `StrictDateFormatterFactory` and (in the last two) `LeadingEngagementCleaner` imports PhpStorm then reports unused.
```bash
git grep -n -E 'new LeadingEngagementCleaner\(' -- tests src
```
Expected: one line, `tests/Support/LeadingEngagementCleaners.php` (the builder: the grep's positive control).

- [ ] **Step 6: Run**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit`
Expected: PASS.

- [ ] **Step 7: Deletion checks**

One at a time, restoring each by hand:
1. In `LinkListDetector`, replace `>= self::LINK_TEXT_RATIO` with `> self::LINK_TEXT_RATIO`. Expected: `testThreeFifthsLinkTextMakesALinkList` fails, `Failed asserting that false is true.`
2. In `LinkListDetector`, delete the `if ($blockTextLength === 0) {` guard with its `return false;` and `}`. Expected: `testAnEmptyBlockIsNoLinkList` errors with `DivisionByZeroError: Division by zero`.
3. In `LeadingBlockJudge::isProtectedContent()`, delete `|| self::hasFigureAncestor($element)` (keep the `;`). Expected: `testAnImageInsideAFigureIsProtectedContent` fails, `Failed asserting that false is true.`
4. In `LeadingBlockJudge::isDecorativeIcon()`, change `=== 1` to `=== 0`. Expected: `testAnIconAssetIsDecorative` fails, `Failed asserting that false is true.` (and `testAPhotoNamedLikeAnIconIsNot` fails, `Failed asserting that true is false.`: both are this edit's).

- [ ] **Step 8: Gates and commit**

Run: `composer cs && composer md && composer tramp` and the stan gate (still `1`: D5 converts no moved class), then the PhpStorm inspections on every changed file.
```bash
git add src/Service/Reader tests/Service/Reader tests/Service/ReaderAudit tests/Support/LeadingEngagementCleaners.php
git commit -m "refactor(#1169): the link-list and leading-block verdicts are services; the block measurements stay in Support/"
```

---

### Task D6: `LeadingEngagementRules` is injected; the audit's leading region is a service (D-reconcile-1, -2)

#1202 I-support took only the date-line memo out of `LeadingEngagementRules` (`DateLineRecognizer`), and the rest stayed static. Its prose bar, counter nouns and kicker limits are tuned verdicts (D-1), so the class becomes a service like the ten before it. Two of its callers could not take a service: `ReaderAudit\Model\BodyBlockModel::isProse()` and `ExtractedBodyModel::leadingBlocks()`, which apply the prose verdict from inside models. D-3's split applies. `BodyBlockModel` keeps the measurement (`linkedTextLength()`, now public), and the verdict "where does the article start" becomes the root service `ReaderAudit\LeadingRegion`, which the three marker rules and the runner inject. (The static `LeadingEngagementBlocks::isProse()` left `Support/` in D5.)

**Files:**
- Create: `src/Service/ReaderAudit/LeadingRegion.php`, `tests/Service/ReaderAudit/LeadingRegionTest.php`, `tests/Support/AuditMarkers.php`
- Modify: `src/Service/Reader/LeadingEngagementRules.php` (moved in D1), `src/Service/Reader/LeadingBlockJudge.php`, `src/Service/Reader/BodyCleaning/BodyCleaningStep/LeadingEngagementCleaner.php`, `src/Service/Reader/Pass/LeadingFurniture.php`, `src/Service/ReaderAudit/Model/BodyBlockModel.php`, `src/Service/ReaderAudit/Model/ExtractedBodyModel.php`, `src/Service/ReaderAudit/LeadingChromeMarkers.php`, `src/Service/ReaderAudit/LeadingEngagementMarkers.php`, `src/Service/ReaderAudit/PhraseMarkers.php`, `src/Service/ReaderAudit/ReaderAuditRunner.php`
- Test: `tests/Service/Reader/LeadingEngagementRulesTest.php` (moved in D1), `tests/Service/Reader/LeadingBlockJudgeTest.php`, `tests/Support/LeadingEngagementCleaners.php`, `tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php`, `CleanupMarkersTest.php`, `ConfirmedGoodArticlesTest.php`, `LeadingChromeMarkersTest.php`, `LeadingEngagementMarkersTest.php`, `PhraseMarkersTest.php`, `ReaderAuditRunnerTest.php`

**Interfaces:**
- Produces:
  - `App\Service\Reader\LeadingEngagementRules`: `final readonly`, no constructor, instance methods `isProse(string $text, int $linkTextLength)`, `isEmojiOnly(string)`, `isCounter(string)`, `isByline(string)`, `isReadingTime(string)`, `isBareNumber(string)`, `isSeparatorOnly(string)`, `isNavigationLabel(string, int)`, `isKicker(string, int)`, `hasAuthor(?string)`, each `bool`
  - `LeadingBlockJudge::__construct(LeadingEngagementRules $rules)`
  - `LeadingEngagementCleaner::__construct(DateLineRecognizer $dateLines, LeadingBlockJudge $judge, LeadingEngagementRules $rules)`
  - `Pass\LeadingFurniture::__construct(?string $entryAuthor, DateLineRecognizer $dateLines, LeadingBlockJudge $judge, LeadingEngagementRules $rules)`
  - `App\Service\ReaderAudit\LeadingRegion::__construct(LeadingEngagementRules $rules)`, `blocksOf(ExtractedBodyModel $body): list<BodyBlockModel>`
  - `BodyBlockModel::linkedTextLength(): int` (public); `BodyBlockModel::isProse()` and `ExtractedBodyModel::leadingBlocks()` are gone
  - `LeadingChromeMarkers::__construct(LeadingRegion $leadingRegion)`, `PhraseMarkers::__construct(LeadingRegion $leadingRegion)` (E4 appends `SuspiciousPhrases $phrases`), `LeadingEngagementMarkers::__construct(LeadingEngagementRules $rules, LeadingRegion $leadingRegion)`; `ReaderAuditRunner` appends `LeadingRegion $leadingRegion`
  - `App\Tests\Support\AuditMarkers::cleanupMarkers()`, `leadingRegion()`, `leadingChrome()`, `leadingEngagement()`, `phrases()`

- [ ] **Step 1: The failing test for `LeadingRegion`**

The five leading-region tests leave `ExtractedBodyModelTest` for the service that now owns the verdict, with the model test's `PROSE` constant (it has no other use there). `tests/Service/ReaderAudit/LeadingRegionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\ReaderAudit;

use App\Service\ReaderAudit\LeadingRegion;
use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;
use App\Tests\Support\AuditMarkers;
use PHPUnit\Framework\TestCase;

final class LeadingRegionTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle fuer einen '
        . 'Prosa-Block sicher ueberschreitet und damit die Stelle markiert, an der der '
        . 'Artikel beginnt und die Kopfzone endet, und zwar mit genug Zeichen dafuer. ';

    private LeadingRegion $region;

    protected function setUp(): void
    {
        $this->region = AuditMarkers::leadingRegion();
    }

    public function testTheLeadingRegionEndsAtTheFirstRealParagraph(): void
    {
        $body = ExtractedBodyModel::fromHtml(
            '<p><a href="/a">Politik</a></p><p><a href="/b">Wirtschaft</a></p>'
            . '<p>' . self::PROSE . '</p><p><a href="/c">Mehr dazu</a></p>',
        );

        self::assertSame(['Politik', 'Wirtschaft'], array_map(
            static fn (BodyBlockModel $block): string => $block->text,
            $this->region->blocksOf($body),
        ));
    }

    public function testABodyThatNeverReachesAParagraphIsLeadingRegionThroughout(): void
    {
        // Such a body is chrome from top to bottom, which is what the rules
        // should then see rather than an empty region they cannot judge.
        $body = ExtractedBodyModel::fromHtml('<p><a href="/a">Politik</a></p><p>Kurz</p>');

        self::assertCount(2, $this->region->blocksOf($body));
    }

    public function testALongParagraphOfNothingButLinksDoesNotStartTheArticle(): void
    {
        $linked = '<p><a href="/a">' . self::PROSE . '</a></p>';

        self::assertCount(2, $this->region->blocksOf(ExtractedBodyModel::fromHtml($linked . '<p>Kurz</p>')));
    }

    public function testAParagraphOfExactlyOneHundredAndTwentyCharactersStartsTheArticle(): void
    {
        $atLimit = '<p>' . str_repeat('a', 120) . '</p><p>Kurz</p>';
        $justUnder = '<p>' . str_repeat('a', 119) . '</p><p>Kurz</p>';

        self::assertSame([], $this->region->blocksOf(ExtractedBodyModel::fromHtml($atLimit)));
        self::assertCount(2, $this->region->blocksOf(ExtractedBodyModel::fromHtml($justUnder)));
    }

    public function testProseLengthCountsCharactersNotBytes(): void
    {
        // These umlauts are twice as many bytes as characters; counting bytes
        // would call a short caption the start of the article and empty the
        // leading region.
        $umlauts = '<p>' . str_repeat('ä', 119) . '</p><p>Kurz</p>';

        self::assertCount(2, $this->region->blocksOf(ExtractedBodyModel::fromHtml($umlauts)));
    }

    public function testAnUnparseableBodyHasNoLeadingRegion(): void
    {
        self::assertSame([], $this->region->blocksOf(ExtractedBodyModel::fromHtml('')));
    }
}
```
(The bodies, comments and `PROSE` are `ExtractedBodyModelTest`'s at `5dbc55d3`, lines 13–16, 46–100 and 194, with `$body->leadingBlocks()` read through the service. If F2 later finds this `PROSE` equal to a shared fixture, it reports it; it is not one of F2's ten.)

Run: `php bin/phpunit tests/Service/ReaderAudit/LeadingRegionTest.php`
Expected: FAIL, `Class "App\Tests\Support\AuditMarkers" not found`.

- [ ] **Step 2: The rules become a service (the recipe)**

`src/Service/Reader/LeadingEngagementRules.php`:
- Delete the private constructor (its last member) with the blank line before it.
- `final class LeadingEngagementRules` → `final readonly class LeadingEngagementRules`.
- Every `public static function ` → `public function ` (ten).
- In `isKicker()`, `|| self::isByline($text)) {` → `|| $this->isByline($text)) {`.
- `private static function withoutWhitespace(` stays static: it calls nothing of the class's own. `self::withoutWhitespace(` in `isEmojiOnly()` stays, and so does every `self::<CONSTANT>`.

```bash
git grep -n -E 'static|self::is' -- src/Service/Reader/LeadingEngagementRules.php
```
Expected: exactly one line, `private static function withoutWhitespace(string $text): string`. It is also the grep's positive control, since the pattern does match in this file. No `public static` and no `self::is…` call is left.

- [ ] **Step 3: `LeadingRegion`; the audit's models keep only the measurement**

`src/Service/ReaderAudit/LeadingRegion.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

use App\Service\Reader\LeadingEngagementRules;
use App\Service\ReaderAudit\Model\BodyBlockModel;
use App\Service\ReaderAudit\Model\ExtractedBodyModel;

/**
 * Everything above the article's first real paragraph, judged by the reader's own prose rule. A body that never
 * reaches one is leading region throughout: it is all chrome, which is exactly what the rules should then see.
 */
final readonly class LeadingRegion
{
    public function __construct(private LeadingEngagementRules $rules)
    {
    }

    /** @return list<BodyBlockModel> */
    public function blocksOf(ExtractedBodyModel $body): array
    {
        $leading = [];
        foreach ($body->blocks as $block) {
            if ($this->rules->isProse($block->text, $block->linkedTextLength())) {
                return $leading;
            }
            $leading[] = $block;
        }

        return $leading;
    }
}
```
The loop is `ExtractedBodyModel::leadingBlocks()`'s, and its docblock carries that method's, with `$block->isProse()` inlined as the verdict `BodyBlockModel::isProse()` computed.

`src/Service/ReaderAudit/Model/BodyBlockModel.php`:
- Delete `use App\Service\Reader\LeadingEngagementRules;` (D1's move wrote it) and the blank line after it.
- Delete the method `isProse()` with its docblock `/** The first block that answers true is where the article begins. */` and the blank line before it.
- `private function linkedTextLength(): int` → `public function linkedTextLength(): int`.

`src/Service/ReaderAudit/Model/ExtractedBodyModel.php`: delete the method `leadingBlocks()` with its docblock (from `    /**` over `     * Everything above the article's first real paragraph. A body that never` to the method's closing `    }`) and the blank line before it.

- [ ] **Step 4: The callers take the services**

`src/Service/Reader/LeadingBlockJudge.php`: after `private const array CONTENT_TAGS = [...]` and its blank line add
```php
    public function __construct(private LeadingEngagementRules $rules)
    {
    }

```
and replace `return LeadingEngagementRules::isProse($block->text, BlockText::linkTextLength($block->element));` with `return $this->rules->isProse($block->text, BlockText::linkTextLength($block->element));`.

`src/Service/Reader/BodyCleaning/BodyCleaningStep/LeadingEngagementCleaner.php`:
- In the constructor, after `private LeadingBlockJudge $judge,` add `private LeadingEngagementRules $rules,`.
- `$furniture = new LeadingFurniture($entryAuthor, $this->dateLines, $this->judge);` → `$furniture = new LeadingFurniture($entryAuthor, $this->dateLines, $this->judge, $this->rules);`
- In `isDuplicateByline()`, `return LeadingEngagementRules::hasAuthor($entryAuthor)` → `return $this->rules->hasAuthor($entryAuthor)` and `&& LeadingEngagementRules::isByline($block->text);` → `&& $this->rules->isByline($block->text);`.

`src/Service/Reader/Pass/LeadingFurniture.php`:
- In the constructor, after `private LeadingBlockJudge $judge,` add `private LeadingEngagementRules $rules,`.
- `perl -pi -e 's/\bLeadingEngagementRules::/\$this->rules->/g' src/Service/Reader/Pass/LeadingFurniture.php` rewrites the nine calls in `isNavigationalChrome()`, `isEngagementMeta()` and `hasAuthor()`, all instance methods.

`src/Service/ReaderAudit/LeadingEngagementMarkers.php`: after the class's opening brace add
```php
    public function __construct(
        private LeadingEngagementRules $rules,
        private LeadingRegion $leadingRegion,
    ) {
    }

```
replace `$body->leadingBlocks(),` with `$this->leadingRegion->blocksOf($body),`, and rewrite the four calls: `perl -pi -e 's/\bLeadingEngagementRules::/\$this->rules->/g' src/Service/ReaderAudit/LeadingEngagementMarkers.php`. (`isEngagement()` is an instance method, and `detect()`'s `fn` is not `static`.)

`src/Service/ReaderAudit/LeadingChromeMarkers.php`:
- After `private const int MIN_LEADING_BLOCKS_FOR_WALL = 6;` and its blank line add
```php
    public function __construct(private LeadingRegion $leadingRegion)
    {
    }

```
- `$leading = $body->leadingBlocks();` → `$leading = $this->leadingRegion->blocksOf($body);`
- In the class docblock, ` * Every rule here reads ExtractedBodyModel::leadingBlocks() and nothing else, so no` → ` * Every rule here reads LeadingRegion::blocksOf() and nothing else, so no`.

`src/Service/ReaderAudit/PhraseMarkers.php`: after the class's opening brace add
```php
    public function __construct(private LeadingRegion $leadingRegion)
    {
    }

```
and replace `PhraseScope::AboveTheArticle => $body->leadingBlocks(),` with `PhraseScope::AboveTheArticle => $this->leadingRegion->blocksOf($body),`.

`src/Service/ReaderAudit/ReaderAuditRunner.php`: after `private CleanupMarkers $markers,` add `private LeadingRegion $leadingRegion,`, and replace `'leadingBlocks' => \count($body->leadingBlocks()),` with `'leadingBlocks' => \count($this->leadingRegion->blocksOf($body)),`.

`LeadingRegion` shares the namespace of the four ReaderAudit classes, so they need no import for it; `LeadingEngagementMarkers` keeps its `LeadingEngagementRules` import.

```bash
git grep -n -E 'LeadingEngagementRules::|->leadingBlocks\(|->isProse\(\)' -- src tests
git grep -n -E 'LeadingEngagementRules::' origin/develop -- src | wc -l
```
Expected: the first grep prints nothing (a constant read such as `LeadingEngagementRules::PROSE_CHARS` would also print: at `5dbc55d3` none exists outside the class). The second is the positive control and prints `16` (lines on develop before PR D: one each in `LeadingEngagementBlocks` and `BodyBlockModel`, two in the cleaner, nine in `LeadingFurniture`, three in `LeadingEngagementMarkers`).

- [ ] **Step 5: The tests build them**

`tests/Service/Reader/LeadingEngagementRulesTest.php`:
```bash
perl -pi -e 's/\bLeadingEngagementRules::(is|has)/(new LeadingEngagementRules())->$1/g' tests/Service/Reader/LeadingEngagementRulesTest.php
git grep -c 'LeadingEngagementRules::' -- tests/Service/Reader/LeadingEngagementRulesTest.php
```
Expected: the grep prints nothing (`-c` stays silent for a file without a hit). Positive control: `git grep -c 'new LeadingEngagementRules()' -- tests/Service/Reader/LeadingEngagementRulesTest.php` prints a count above 0.

`tests/Service/Reader/LeadingBlockJudgeTest.php`: `return new LeadingBlockJudge();` → `return new LeadingBlockJudge(new LeadingEngagementRules());`, and add `use App\Service\Reader\LeadingEngagementRules;` in order.

`tests/Support/LeadingEngagementCleaners.php` (D5's builder): replace `cleaner()`'s body with
```php
        $rules = new LeadingEngagementRules();

        return new LeadingEngagementCleaner(
            new DateLineRecognizer(new StrictDateFormatterFactory()),
            new LeadingBlockJudge($rules),
            $rules,
        );
```
add `use App\Service\Reader\LeadingEngagementRules;` in order, and change the class docblock to `/** The leading-engagement cleaner over the real date, block and text rules, wired as the container wires it. */`.

`tests/Support/AuditMarkers.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\LeadingEngagementRules;
use App\Service\ReaderAudit\BodyShapeMarkers;
use App\Service\ReaderAudit\CleanupMarkers;
use App\Service\ReaderAudit\LeadingChromeMarkers;
use App\Service\ReaderAudit\LeadingEngagementMarkers;
use App\Service\ReaderAudit\LeadingRegion;
use App\Service\ReaderAudit\PhraseMarkers;
use App\Service\ReaderAudit\SocialWidgetMarkers;

/** The audit's marker rules over the reader's real leading-block rules, wired as the container wires them. */
final class AuditMarkers
{
    private function __construct()
    {
    }

    public static function cleanupMarkers(): CleanupMarkers
    {
        return new CleanupMarkers(
            self::leadingChrome(),
            self::leadingEngagement(),
            new SocialWidgetMarkers(),
            new BodyShapeMarkers(),
            self::phrases(),
        );
    }

    public static function leadingRegion(): LeadingRegion
    {
        return new LeadingRegion(new LeadingEngagementRules());
    }

    public static function leadingChrome(): LeadingChromeMarkers
    {
        return new LeadingChromeMarkers(self::leadingRegion());
    }

    public static function leadingEngagement(): LeadingEngagementMarkers
    {
        return new LeadingEngagementMarkers(new LeadingEngagementRules(), self::leadingRegion());
    }

    public static function phrases(): PhraseMarkers
    {
        return new PhraseMarkers(self::leadingRegion());
    }
}
```

The four copies of the `CleanupMarkers` wiring take the builder:
```bash
FILES="tests/Service/ReaderAudit/CleanupMarkersTest.php tests/Service/ReaderAudit/ConfirmedGoodArticlesTest.php tests/Service/ReaderAudit/ReaderAuditRunnerTest.php"
perl -0pi -e 's/new CleanupMarkers\(\s*new LeadingChromeMarkers\(\),\s*new LeadingEngagementMarkers\(\),\s*new SocialWidgetMarkers\(\),\s*new BodyShapeMarkers\(\),\s*new PhraseMarkers\(\),\s*\)/AuditMarkers::cleanupMarkers()/g' $FILES
git grep -c 'AuditMarkers::cleanupMarkers()' -- $FILES
git grep -n -E 'new (CleanupMarkers|LeadingChromeMarkers|LeadingEngagementMarkers|PhraseMarkers|ReaderAuditRunner)\(' -- tests
```
Expected: the first grep prints `CleanupMarkersTest.php:1`, `ConfirmedGoodArticlesTest.php:1` and `ReaderAuditRunnerTest.php:2`. The second lists what the perl did not cover: `AuditMarkers.php` (four lines: its positive control), `LeadingChromeMarkersTest.php:23`, `LeadingEngagementMarkersTest.php:29`, `PhraseMarkersTest.php:25` and `ReaderAuditRunnerTest.php:84`, `:175`, `:201` and `:242`. Rewrite each by hand:

| File | Replace | With |
|---|---|---|
| `LeadingChromeMarkersTest.php` | `$this->markers = new LeadingChromeMarkers();` | `$this->markers = AuditMarkers::leadingChrome();` |
| `LeadingEngagementMarkersTest.php` | `$this->markers = new LeadingEngagementMarkers();` | `$this->markers = AuditMarkers::leadingEngagement();` |
| `PhraseMarkersTest.php` | `$this->markers = new PhraseMarkers();` | `$this->markers = AuditMarkers::phrases();` |
| `ReaderAuditRunnerTest.php` (three times) | `$runner = new ReaderAuditRunner($extractor, new ExtractionCoverageGate(), $this->markers());` | the five lines below |

```php
        $runner = new ReaderAuditRunner(
            $extractor,
            new ExtractionCoverageGate(),
            AuditMarkers::cleanupMarkers(),
            AuditMarkers::leadingRegion(),
        );
```
In `ReaderAuditRunnerTest::auditOne()`, after the argument `AuditMarkers::cleanupMarkers(),` the perl wrote, add the line `            AuditMarkers::leadingRegion(),`. Delete `ReaderAuditRunnerTest::markers()` (now unused) with the blank line before it.

`tests/Service/ReaderAudit/ConfirmedGoodArticlesTest.php`: `self::assertCount(1, $body->leadingBlocks());` → `self::assertCount(1, AuditMarkers::leadingRegion()->blocksOf($body));`.

`tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php`: delete the five tests Step 1 moved (`testTheLeadingRegionEndsAtTheFirstRealParagraph`, `testABodyThatNeverReachesAParagraphIsLeadingRegionThroughout`, `testALongParagraphOfNothingButLinksDoesNotStartTheArticle`, `testAParagraphOfExactlyOneHundredAndTwentyCharactersStartsTheArticle`, `testProseLengthCountsCharactersNotBytes`), each with the blank line before it; the `PROSE` constant with the blank line after it; and, in `testAnUnparseableBodyMeasuresAsEmptyRatherThanFailing()`, the line `self::assertSame([], $body->leadingBlocks());`.

In every changed test file add `use App\Tests\Support\AuditMarkers;` in order and delete the imports PhpStorm reports unused (the marker classes, `CleanupMarkers`).
```bash
git grep -n -E 'leadingBlocks\(\)|const string PROSE' -- tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php
git grep -c -E 'function test' -- tests/Service/ReaderAudit/Model/ExtractedBodyModelTest.php tests/Service/ReaderAudit/LeadingRegionTest.php
```
Expected: the first grep prints nothing; the second prints `ExtractedBodyModelTest.php:14` and `LeadingRegionTest.php:6` (19 tests at `5dbc55d3`, five moved; the sixth region test is the unparseable body's).

- [ ] **Step 6: Run**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit tests/Command`
Expected: PASS. An `ArgumentCountError` names a construction Step 5 missed.

- [ ] **Step 7: Deletion checks**

One at a time, restoring each by hand, and quote each FAIL:
1. In `LeadingRegion::blocksOf()`, replace `if ($this->rules->isProse($block->text, $block->linkedTextLength())) {` with `if (false) {`. Expected: `LeadingRegionTest::testTheLeadingRegionEndsAtTheFirstRealParagraph` fails, `Failed asserting that two arrays are identical.`, the actual array also holding the prose paragraph and `'Mehr dazu'`.
2. In `BodyBlockModel::linkedTextLength()`, replace the method's body with `return 0;`. Expected: `LeadingRegionTest::testALongParagraphOfNothingButLinksDoesNotStartTheArticle` fails, `Failed asserting that actual size 0 matches expected size 2.` (the all-link paragraph now counts as prose).
3. In `LeadingEngagementRules::isKicker()`, replace `|| $this->isByline($text)) {` with `) {`. Expected: `LeadingEngagementRulesTest::testKickerNeverSwallowsAByline` fails, `Failed asserting that true is false.`

- [ ] **Step 8: Gates and commit**

Run: `composer check && composer md && composer tramp` (stan is whole again: `composer stan -- --error-format=raw --no-progress 2>&1 | grep -c 'Service role "supportHome"'` prints `0`; D4's `1` was its positive control), then the PhpStorm inspections on every changed file.
```bash
git add src/Service/Reader src/Service/ReaderAudit tests/Service/Reader tests/Service/ReaderAudit tests/Support
git commit -m "refactor(#1169): the leading-engagement rules are an injected service; the audit's leading region is one too"
```

---

### Task D7: `docs/architecture.md` §10 and CLAUDE.md state the boundary

**Files:**
- Modify: `docs/architecture.md`, `CLAUDE.md`

- [ ] **Step 1: §10**

In `docs/architecture.md` §10, after the bullet that starts `- **Per call or process lifetime.**`, add:
```markdown
- **`Support/` computes; a service decides.** A static helper answers from its arguments plus a standard, a wire
  or file format, or this app's own schema (`Srcset`, `PlainText`, `LineField`). A verdict about third-party
  content tuned against observed pages, feeds or model replies (a curated list, a measured threshold or window,
  a precedence among candidate sources) is an injected root service (`PaywallSignals`, `PageFurniture`), and so
  is a class that calls one. A model keeps the measurement and a service applies the verdict to it
  (`BodyBlockModel::linkedTextLength()`, `LeadingRegion`). `Url\Support\FeedWebsite` is the one exception: its only
  caller is the static `Http\SubscriptionJson` (#1169).
```
(At `5dbc55d3` that bullet starts at line 246 of `docs/architecture.md`.)

- [ ] **Step 2: CLAUDE.md**

In the "One role per folder" bullet, the text wraps at `5dbc55d3`. Replace the line
```markdown
  `new` to `Pass/`, static-only helpers to `Support/`, and each interface with
```
with the two lines
```markdown
  `new` to `Pass/`, static-only helpers that compute (never a tuned verdict,
  which is an injected service, §10) to `Support/`, and each interface with
```

- [ ] **Step 3: Commit**

```bash
git add ../docs/architecture.md ../CLAUDE.md
git commit -m "refactor(#1169): §10 and CLAUDE.md say Support/ computes and a service decides"
```

---

### Finishing PR D

- [ ] **Step 1: The gates on the whole branch** (Global Constraints), including `composer tramp`. Expected: all green; the worker is untouched by Reader code, but run Appendix M with `reader-policies` (a move PR).
- [ ] **Step 2: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 3: /simplify** over `git diff origin/develop...HEAD`; commit `refactor(#1169): simplify pass` if anything changed.
- [ ] **Step 4: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue and `git diff -M origin/develop...HEAD`. Attack points:
1. **D-1 applied honestly:** each class moved is a tuned verdict, and each Reader class that stayed in `Support/` computes (the classification table).
2. **No behaviour change:** every converted body equals its static original; `ArticlePageReader::read()` evaluates in `readPage()`'s order.
3. **Wiring:** `lint:container` passes and every new constructor parameter is a service the container builds; no policy reaches a `Model/`.
4. **Tramp:** no collaborator is threaded unread (`SiblingSearch`, `LeadingFurniture` read theirs; `LeadingEngagementCleaner` reads the rules it hands on).
5. **Deletion checks:** re-run D5's first, D6's second and D2's first; quote each FAIL.
6. **No closing keyword** in a commit.
7. **D-reconcile-2:** no `Model/` calls `LeadingEngagementRules` or any other service, and `LeadingRegion::blocksOf()` returns what `ExtractedBodyModel::leadingBlocks()` returned for every body (`LeadingRegionTest` carries the five moved tests unchanged in meaning).

Fix each Important-or-above finding in its own commit; record the rest in the PR body.

- [ ] **Step 5: Open the PR** (Appendix K) with `var/refactor-1169/pr-d-body.md`:
```markdown
Refs #1169 (PR D of eight).

Rule (docs/architecture.md §10): `Support/` computes; a service decides. A tuned verdict about third-party content is an injected root service.

- Eleven Reader policy classes leave `Support/` as injected services: the paywall family (`PaywallSignals`, `PaywallBlocks`, `MembershipCheckout`, `OutsideFurniture`), `PageFurniture`, `NarrationSignals`, `PlayerPoster`, `NearbyPoster`, `AuthorProfileLink`, `ArticleContentGate` and `LeadingEngagementRules` (#1202 had moved only its date-line memo out, as `DateLineRecognizer`).
- `BlockText` and `LeadingEngagementBlocks` keep their measurements; their tuned verdicts become `LinkListDetector` and `LeadingBlockJudge`.
- The audit's models keep their measurements too: `BodyBlockModel::isProse()` and `ExtractedBodyModel::leadingBlocks()` go, and `ReaderAudit\LeadingRegion` applies the prose rule for the three marker rules and the runner.
- `ArticlePageReader` takes the page reads off `ArticleExtractor`, which drops from nine collaborators to seven.
- The per-call objects (`SiblingSearch`, `LeadingFurniture`) receive the policy from the service that builds them.

No behaviour change and no wire change.
```
Title: `refactor(#1169): the reader's policies are injected services`.
- [ ] **Step 6: Merge when green**, then check #1169 is `OPEN`.

---

# PR E — The remaining policies are injected services

Same shape as PR D: one scripted move (E1), then one conversion per family, each by PR D's conversion recipe.

### Task E0: Preflight (PR D merged)

- [ ] **Step 1: Branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "Support/ computes and a service decides"
git switch -c refactor/1169-policy-services origin/develop
mkdir -p backend/var/refactor-1169
```
Expected: one log line.

---

### Task E1: Move the remaining policy classes out of `Support/`

**Files:**
- Move (by the script): the nine classes and seven tests in the map below

- [ ] **Step 1: The map**

`var/refactor-1169/policies.php`:
```php
<?php

declare(strict_types=1);

return [
    'App\Service\Fetch\Support\CrossFamilyFailover' => 'App\Service\Fetch\CrossFamilyFailover',
    'App\Service\Html\Support\DesktopViewport' => 'App\Service\Html\DesktopViewport',
    'App\Service\Parser\Support\FeedItemImageSelector' => 'App\Service\Parser\FeedItemImageSelector',
    'App\Service\Parser\Support\ItemImageExtractor' => 'App\Service\Parser\ItemImageExtractor',
    'App\Service\Parser\Support\ItemMediaExtractor' => 'App\Service\Parser\ItemMediaExtractor',
    'App\Service\ReaderAudit\Support\SuspiciousPhrases' => 'App\Service\ReaderAudit\SuspiciousPhrases',
    'App\Service\Recommendation\Prompt\Support\PlausibleDuplicateShare'
        => 'App\Service\Recommendation\Prompt\PlausibleDuplicateShare',
    'App\Service\Recommendation\Prompt\Support\RecommendationAnswerBudget'
        => 'App\Service\Recommendation\Prompt\RecommendationAnswerBudget',
    'App\Service\Scraper\Support\CardTitle' => 'App\Service\Scraper\CardTitle',
    'App\Tests\Service\Fetch\Support\CrossFamilyFailoverTest' => 'App\Tests\Service\Fetch\CrossFamilyFailoverTest',
    'App\Tests\Service\Html\Support\DesktopViewportTest' => 'App\Tests\Service\Html\DesktopViewportTest',
    'App\Tests\Service\Parser\Support\FeedItemImageSelectorTest' => 'App\Tests\Service\Parser\FeedItemImageSelectorTest',
    'App\Tests\Service\Parser\Support\ItemImageExtractorTest' => 'App\Tests\Service\Parser\ItemImageExtractorTest',
    'App\Tests\Service\Parser\Support\ItemMediaExtractorTest' => 'App\Tests\Service\Parser\ItemMediaExtractorTest',
    'App\Tests\Service\Recommendation\Prompt\Support\RecommendationAnswerBudgetTest'
        => 'App\Tests\Service\Recommendation\Prompt\RecommendationAnswerBudgetTest',
    'App\Tests\Service\Scraper\Support\CardTitleTest' => 'App\Tests\Service\Scraper\CardTitleTest',
];
```
Run D1 Step 1's existence check with this map. Expected: sixteen `ok` lines, no `MISSING`, no `TAKEN`.

- [ ] **Step 2: Move and prove no code changed**

```bash
php ../docs/superpowers/plans/2026-09-28-1202-scripts/move-classes.php var/refactor-1169/policies.php
find src tests -type d -empty -delete
bin/console cache:clear && bin/console cache:warmup
php ../docs/superpowers/plans/2026-09-28-1202-scripts/compare-moves.php var/refactor-1169/policies.php
```
Expected: `Moved 16 classes (0 renamed); …`, `0 of 16 moved files differ in code.` (depth-only test changes may be listed), `0 of 16 moved files declare the wrong namespace.` `infection.json5` names no moved class.

- [ ] **Step 3: Suites; commit**

```bash
php bin/phpunit tests/Service/Fetch tests/Service/Html tests/Service/Parser tests/Service/ReaderAudit tests/Service/Recommendation tests/Service/Scraper
composer stan -- --error-format=raw --no-progress > var/refactor-1169/stan-e1.txt 2>&1 || true
grep -c -E '\.php:[0-9]+:' var/refactor-1169/stan-e1.txt
grep -c -E '\.php:[0-9]+:Service role "supportHome"' var/refactor-1169/stan-e1.txt
```
Expected: PHPUnit PASS, then `9` and `9`: one `supportHome` error per moved class (static-only in a module root) and nothing else; no `rootService` error until a class holds an instance method. E2–E5 end them (E2 −2, E3 −3, E4 −3, E5 −1), and each of those tasks gates on these two counts as PR D's intro describes; this `9` is the positive control for E5's `0`.
```bash
git add -A -- . ../docker ../.github ../CLAUDE.md ../docs/architecture.md
git status --short | grep -v '^[RMAD] ' && echo 'STOP: an unstaged or untracked change' || true
git commit -m "refactor(#1169): the fetch, parser, audit, prompt and scraper policies leave Support/"
```

---

### Task E2: `CrossFamilyFailover` and `DesktopViewport` are injected

`CrossFamilyFailover::freshConnectionAfter()` is curl mechanics, not a verdict: it moves into `EgressOptions`, its only caller, as a private static helper.

**Files:**
- Modify: `src/Service/Fetch/CrossFamilyFailover.php`, `src/Service/Fetch/Support/EgressOptions.php`, `src/Service/Fetch/FailoverRequestSender.php`, `src/Service/Fetch/FetchRetryPolicy.php`, `src/Service/Html/DesktopViewport.php`, `src/Service/Html/PictureSources.php`
- Test: `tests/Service/Fetch/CrossFamilyFailoverTest.php`, `tests/Service/Fetch/Support/EgressOptionsTest.php`, `tests/Service/Html/DesktopViewportTest.php`, and every test that builds `FailoverRequestSender`, `FetchRetryPolicy` or `PictureSources` (Step 4)

**Interfaces:**
- Produces: `CrossFamilyFailover::isWarranted(?\Throwable $transportError): bool`, `CrossFamilyFailover::isRetryableStatus(int $statusCode): bool` (instance); `DesktopViewport::admits(?string $media): bool` (instance); `FailoverRequestSender` and `FetchRetryPolicy` append `CrossFamilyFailover $failover`; `PictureSources::__construct(DesktopViewport $viewport)`.

- [ ] **Step 1: The failing test for `EgressOptions`' first attempt**

In `tests/Service/Fetch/Support/EgressOptionsTest.php`, after `testPinnedOnASecondAttemptAddsTheFreshConnectionExtra()`, add:
```php
    public function testPinnedOnTheFirstAttemptAddsNoFreshConnectionExtra(): void
    {
        $guarded = new GuardedUrlModel('dual.example.com', ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34']);

        $options = EgressOptions::pinned($guarded, 0);

        self::assertArrayHasKey('resolve', $options);
        self::assertArrayNotHasKey('extra', $options);
    }
```
Run it: `php bin/phpunit --filter testPinnedOnTheFirstAttemptAddsNoFreshConnectionExtra tests/Service/Fetch/Support/EgressOptionsTest.php`. Expected: PASS (it pins today's behaviour before the helper moves; the deletion check in Step 5 proves it bites). `CrossFamilyFailoverTest::testForcesAFreshConnectionOnlyAfterTheFirstAttempt` covered the helper directly; Step 4 deletes it, and this test with the existing second-attempt one takes over.

- [ ] **Step 2: The two policies become services; `EgressOptions` owns its curl option**

`src/Service/Fetch/CrossFamilyFailover.php`: delete the private constructor; `final class CrossFamilyFailover` → `final readonly class CrossFamilyFailover` (`rootService` reports a stateless service that is not `readonly`); `public static function isWarranted(` → `public function isWarranted(`; `public static function isRetryableStatus(` → `public function isRetryableStatus(`; cut the method `freshConnectionAfter()` with its docblock (from `    /**\n     * The `extra.curl` option that forces a failover retry` to its closing `    }`) and the blank line before it.

`src/Service/Fetch/Support/EgressOptions.php`: replace `...CrossFamilyFailover::freshConnectionAfter($pinAttempt),` with `...self::freshConnectionAfter($pinAttempt),`; delete its `use` of `CrossFamilyFailover`; paste the cut method after `pinned()` (a blank line before it) and change its first line to `    private static function freshConnectionAfter(int $attemptIndex): array`. Its docblock and body are unchanged.

`src/Service/Html/DesktopViewport.php`: delete the private constructor; `public static function admits(` → `public function admits(`. The three private statics stay.

- [ ] **Step 3: The callers**

`src/Service/Fetch/FailoverRequestSender.php`: append `private CrossFamilyFailover $failover,` after `private EgressProxySourceInterface $egressProxySource,`; replace `CrossFamilyFailover::isWarranted(` with `$this->failover->isWarranted(` and `CrossFamilyFailover::isRetryableStatus(` with `$this->failover->isRetryableStatus(` (two each).

`src/Service/Fetch/FetchRetryPolicy.php`: replace `public function __construct(private UrlGuard $urlGuard)` with `public function __construct(private UrlGuard $urlGuard, private CrossFamilyFailover $failover)`; replace `return CrossFamilyFailover::isWarranted($failure->getPrevious());` with `return $this->failover->isWarranted($failure->getPrevious());`.

`src/Service/Html/PictureSources.php`: after `private const array SRCSET_ATTRIBUTES = [...]` and its blank line, add
```php
    public function __construct(private DesktopViewport $viewport)
    {
    }

```
and replace `if (!DesktopViewport::admits($source->getAttribute('media'))) {` with `if (!$this->viewport->admits($source->getAttribute('media'))) {`.

Fix the imports (`App\Service\Fetch\CrossFamilyFailover`, `App\Service\Html\DesktopViewport`).

- [ ] **Step 4: The tests**

- `tests/Service/Fetch/CrossFamilyFailoverTest.php`: delete `testForcesAFreshConnectionOnlyAfterTheFirstAttempt()` with the blank line before it; `CrossFamilyFailover::` → `(new CrossFamilyFailover())->`.
- `tests/Service/Html/DesktopViewportTest.php`: `DesktopViewport::` → `(new DesktopViewport())->`.
- Every construction:
```bash
git grep -n -E 'new (FailoverRequestSender|FetchRetryPolicy|PictureSources)\(' -- tests
```
At `5dbc55d3`: `FailoverRequestSender` in `FailoverRequestSenderProxyTest`, `FailoverRequestSenderTest`, `RedirectFollowerTest`, `FaviconFetcherTest`, `ArticleExtractorTest`, `HtmlPageFetcherTest`, `MediaLandingTest`, `SiblingMediaExtenderTest`, `StreamLocationResolverTest` (19 calls); `FetchRetryPolicy` in `ConcurrentFeedFetcherProxyTest`, `ConcurrentFeedFetcherTest`, `HttpFeedFetcherTest`, `RefreshRunnerConcurrentFetchTest` (5); `PictureSources` in `FetchedPageNormalizerTest`, `LazyImageSourcesTest`, `ReaderLeadImageTest` (3). Append `new CrossFamilyFailover()` (or, for `PictureSources()`, pass `new DesktopViewport()` as its only argument) by hand, with the imports.

- [ ] **Step 5: Run and the deletion check**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Fetch tests/Service/Html tests/Service/Reader tests/Service/Image tests/Service/Refresh`
Expected: PASS.

Deletion check: in `EgressOptions::freshConnectionAfter()`, replace `$attemptIndex > 0` with `$attemptIndex >= 0`. Expected: `testPinnedOnTheFirstAttemptAddsNoFreshConnectionExtra` fails, `Failed asserting that an array does not have the key 'extra'.` Restore by hand.

- [ ] **Step 6: Gates and commit**

Run: `composer cs && composer md && composer tramp` and E1's two counts (`7` and `7`), then the PhpStorm inspections.
```bash
git add src/Service/Fetch src/Service/Html tests
git commit -m "refactor(#1169): the address-family and desktop-viewport policies are injected services"
```

---

### Task E3: The feed-item image and media policies are injected

**Files:**
- Create: `tests/Support/FeedFormatParsers.php`
- Modify: `src/Service/Parser/FeedItemImageSelector.php`, `ItemImageExtractor.php`, `ItemMediaExtractor.php`, `src/Service/Parser/FeedFormatParser/AbstractAtomParser.php`, `Rss1Parser.php`, `Rss2Parser.php`, `src/Service/Parser/WordPressJsonParser.php`
- Test: the three moved parser tests; `FeedParserFactoryTest`, `FeedParserTest`, `Rss1ParserTest`, `Rss2ParserTest`, `Atom03ParserTest`, `Atom10ParserTest`, `ItemCategoryExtractorTest`, `RedditEntryRuleTest`, `tests/Support/RefreshRunners.php`, `WordPressJsonParserTest`, `FeedPreviewServiceTest`

**Interfaces:**
- Produces:
  - `ItemImageExtractor::fromMedia(\DOMElement)`, `fromRssEnclosure(\DOMElement)`, `fromAtomEnclosure(\DOMElement, string)`, `fromCustomImageElement(\DOMElement)`, `fromHtml(?string)`, each `?DeclaredImageModel`, instance, no constructor
  - `ItemMediaExtractor::extract(\DOMElement $item): ParsedMediaBundleModel`, instance, no constructor
  - `FeedItemImageSelector::__construct(ItemImageExtractor $extractor)`, `fromRss2(\DOMElement $item, ?string $bodyHtml)`, `fromAtom(\DOMElement $entry, string $namespace, array $bodyHtmlCandidates)`
  - `AbstractAtomParser` (so `Atom03Parser`, `Atom10Parser`) and `Rss2Parser`: `__construct(FeedItemImageSelector $imageSelector, ItemMediaExtractor $mediaExtractor)`; `Rss1Parser`: `__construct(ItemImageExtractor $imageExtractor, ItemMediaExtractor $mediaExtractor)`; `WordPressJsonParser`: `__construct(ItemImageExtractor $imageExtractor)`
  - `App\Tests\Support\FeedFormatParsers::rss2()`, `rss1()`, `atom10()`, `atom03()`

- [ ] **Step 1: The extractors become services (the recipe)**

`ItemImageExtractor.php` and `ItemMediaExtractor.php`: delete the private constructor; `final class` → `final readonly class`; every `public static function` → `public function`. Their private statics call no public method, so they stay static.

`FeedItemImageSelector.php`: replace the class body (everything between the class's braces) with:
```php
    public function __construct(private ItemImageExtractor $extractor)
    {
    }

    public function fromRss2(\DOMElement $item, ?string $bodyHtml): ?DeclaredImageModel
    {
        $image = $this->extractor->fromMedia($item) ?? $this->extractor->fromRssEnclosure($item);

        return $image
            ?? $this->extractor->fromCustomImageElement($item)
            ?? $this->extractor->fromHtml($bodyHtml);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    public function fromAtom(
        \DOMElement $entry,
        string $namespace,
        array $bodyHtmlCandidates,
    ): ?DeclaredImageModel {
        $image = $this->extractor->fromMedia($entry) ?? $this->extractor->fromAtomEnclosure($entry, $namespace);

        return $image
            ?? $this->extractor->fromCustomImageElement($entry)
            ?? $this->firstBodyImage($bodyHtmlCandidates);
    }

    /** @param list<?string> $bodyHtmlCandidates */
    private function firstBodyImage(array $bodyHtmlCandidates): ?DeclaredImageModel
    {
        foreach ($bodyHtmlCandidates as $bodyHtml) {
            $image = $this->extractor->fromHtml($bodyHtml);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }
```
and its declaration `final class FeedItemImageSelector` → `final readonly class FeedItemImageSelector`.

- [ ] **Step 2: The parsers take them**

`AbstractAtomParser.php`: after the class's opening brace, add
```php
    public function __construct(
        private FeedItemImageSelector $imageSelector,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

```
Replace `$image = FeedItemImageSelector::fromAtom(` with `$image = $this->imageSelector->fromAtom(` and `$mediaBundle = ItemMediaExtractor::extract($entry);` with `$mediaBundle = $this->mediaExtractor->extract($entry);`. `Atom03Parser` and `Atom10Parser` inherit the constructor.

`Rss2Parser.php`: add the same constructor after its constants; replace `$image = FeedItemImageSelector::fromRss2($item, $contentEncoded ?? $description);` with `$image = $this->imageSelector->fromRss2($item, $contentEncoded ?? $description);` and `$mediaBundle = ItemMediaExtractor::extract($item);` with `$mediaBundle = $this->mediaExtractor->extract($item);`.

`Rss1Parser.php`: after its constants add
```php
    public function __construct(
        private ItemImageExtractor $imageExtractor,
        private ItemMediaExtractor $mediaExtractor,
    ) {
    }

```
and replace
```php
        $image = ItemImageExtractor::fromMedia($item)
            ?? ItemImageExtractor::fromCustomImageElement($item)
            ?? ItemImageExtractor::fromHtml($contentEncoded ?? $description);
        $mediaBundle = ItemMediaExtractor::extract($item);
```
with
```php
        $image = $this->imageExtractor->fromMedia($item)
            ?? $this->imageExtractor->fromCustomImageElement($item)
            ?? $this->imageExtractor->fromHtml($contentEncoded ?? $description);
        $mediaBundle = $this->mediaExtractor->extract($item);
```

`WordPressJsonParser.php`: after the class's opening brace add
```php
    public function __construct(private ItemImageExtractor $imageExtractor)
    {
    }

```
and replace
```php
        return ItemImageExtractor::fromHtml($this->rendered($post, 'content'))
            ?? ItemImageExtractor::fromHtml($this->rendered($post, 'excerpt'));
```
with
```php
        return $this->imageExtractor->fromHtml($this->rendered($post, 'content'))
            ?? $this->imageExtractor->fromHtml($this->rendered($post, 'excerpt'));
```
Fix the imports to the moved names.

- [ ] **Step 3: `FeedFormatParsers` and the tests**

`tests/Support/FeedFormatParsers.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Parser\FeedFormatParser\Atom03Parser;
use App\Service\Parser\FeedFormatParser\Atom10Parser;
use App\Service\Parser\FeedFormatParser\Rss1Parser;
use App\Service\Parser\FeedFormatParser\Rss2Parser;
use App\Service\Parser\FeedItemImageSelector;
use App\Service\Parser\ItemImageExtractor;
use App\Service\Parser\ItemMediaExtractor;

/** The format parsers over the real image and media policies, wired as the container wires them. */
final class FeedFormatParsers
{
    public static function rss2(): Rss2Parser
    {
        return new Rss2Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function rss1(): Rss1Parser
    {
        return new Rss1Parser(new ItemImageExtractor(), new ItemMediaExtractor());
    }

    public static function atom10(): Atom10Parser
    {
        return new Atom10Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    public static function atom03(): Atom03Parser
    {
        return new Atom03Parser(self::imageSelector(), new ItemMediaExtractor());
    }

    private static function imageSelector(): FeedItemImageSelector
    {
        return new FeedItemImageSelector(new ItemImageExtractor());
    }
}
```

```bash
FILES=$(git grep -l -E 'new (Rss2Parser|Rss1Parser|Atom10Parser|Atom03Parser)\(\)' -- tests)
perl -pi -e 's/\(new Rss2Parser\(\)\)/FeedFormatParsers::rss2()/g; s/\(new Rss1Parser\(\)\)/FeedFormatParsers::rss1()/g; s/\(new Atom10Parser\(\)\)/FeedFormatParsers::atom10()/g; s/\(new Atom03Parser\(\)\)/FeedFormatParsers::atom03()/g; s/new Rss2Parser\(\)/FeedFormatParsers::rss2()/g; s/new Rss1Parser\(\)/FeedFormatParsers::rss1()/g; s/new Atom10Parser\(\)/FeedFormatParsers::atom10()/g; s/new Atom03Parser\(\)/FeedFormatParsers::atom03()/g' $FILES
git grep -n -E 'new (Rss2Parser|Rss1Parser|Atom10Parser|Atom03Parser)\(' -- tests
echo "$FILES"
```
Expected: the grep prints only `tests/Support/FeedFormatParsers.php`; `$FILES` listed the nine files of this task's Test line. Add `use App\Tests\Support\FeedFormatParsers;` to each and delete parser imports PhpStorm reports unused.

The three moved tests: `ItemImageExtractor::` → `(new ItemImageExtractor())->` in `ItemImageExtractorTest`; `ItemMediaExtractor::` → `(new ItemMediaExtractor())->` in `ItemMediaExtractorTest`; `FeedItemImageSelector::` → `(new FeedItemImageSelector(new ItemImageExtractor()))->` in `FeedItemImageSelectorTest` (import `ItemImageExtractor`). `new WordPressJsonParser()` → `new WordPressJsonParser(new ItemImageExtractor())` in `WordPressJsonParserTest` and `FeedPreviewServiceTest`.

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Parser tests/Service/Ingest tests/Service/Refresh tests/Service/Preview tests/Service/Discovery && composer cs && composer md && composer tramp`, and E1's two counts (`4` and `4`)
Expected: PASS.
```bash
git add src/Service/Parser tests
git commit -m "refactor(#1169): the feed-item image and media policies are injected into the parsers"
```

---

### Task E4: `SuspiciousPhrases`, `PlausibleDuplicateShare` and `RecommendationAnswerBudget` are injected

**Files:**
- Modify: `src/Service/ReaderAudit/SuspiciousPhrases.php`, `src/Service/ReaderAudit/PhraseMarkers.php`, `src/Service/Recommendation/Prompt/PlausibleDuplicateShare.php`, `RecommendationAnswerBudget.php`, `RecommendationConsolidationParser.php`, `RecommendationPromptBuilder.php`, `src/Service/Recommendation/Prompt/Factory/RecommendationCompletionRequestFactory.php`
- Test: `tests/Service/ReaderAudit/PhraseMarkersTest.php`, `tests/Support/AuditMarkers.php` (D6's builder: every other test builds `PhraseMarkers` through it), `tests/Service/Recommendation/Prompt/RecommendationAnswerBudgetTest.php`, `RecommendationConsolidationParserTest.php`, `RecommendationPromptBuilderTest.php`, `Factory/RecommendationCompletionRequestFactoryTest.php`, `tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php`

**Interfaces:**
- Produces: `SuspiciousPhrases::families(): list<PhraseFamilyModel>`, `PlausibleDuplicateShare::exceededBy(int $namedCount, int $shownCount): bool`, `RecommendationAnswerBudget::answerBoundTokens(int, RecommendationResponseSchema): int`, `RecommendationAnswerBudget::outputBoundTokens(int, RecommendationResponseSchema, Reasoning): int` (instance, no constructors); `PhraseMarkers::__construct(LeadingRegion $leadingRegion, SuspiciousPhrases $phrases)` (D6 gave it the first); `RecommendationConsolidationParser` appends `PlausibleDuplicateShare $duplicateShare`; `RecommendationPromptBuilder::__construct(RecommendationAnswerBudget $answerBudget)`; `RecommendationCompletionRequestFactory::__construct(RecommendationAnswerBudget $answerBudget)`.

- [ ] **Step 1: The three policies become services (the recipe)**

- `SuspiciousPhrases.php`: delete the private constructor; `public static function families(` → `public function families(`. `walls()` and `chrome()` stay private static.
- `PlausibleDuplicateShare.php`: delete the private constructor; `public static function exceededBy(` → `public function exceededBy(`. `maximumFor()` stays private static.
- `RecommendationAnswerBudget.php`: delete the private constructor; `public static function answerBoundTokens(` → `public function answerBoundTokens(`; `public static function outputBoundTokens(` → `public function outputBoundTokens(`; in `outputBoundTokens()`, `self::answerBoundTokens(` → `$this->answerBoundTokens(`. `reasoningHeadroomTokens()` stays private static.

- [ ] **Step 2: The callers**

`PhraseMarkers.php`: replace D6's constructor
```php
    public function __construct(private LeadingRegion $leadingRegion)
    {
    }
```
with
```php
    public function __construct(
        private LeadingRegion $leadingRegion,
        private SuspiciousPhrases $phrases,
    ) {
    }
```
and replace `foreach (SuspiciousPhrases::families() as $family) {` with `foreach ($this->phrases->families() as $family) {`.

`RecommendationConsolidationParser.php`: append `private PlausibleDuplicateShare $duplicateShare,` after `private RecommendationPickSalvager $salvager,`; replace `if (PlausibleDuplicateShare::exceededBy(\count($duplicateIds), \count($shownIds))) {` with `if ($this->duplicateShare->exceededBy(\count($duplicateIds), \count($shownIds))) {`.

`RecommendationCompletionRequestFactory.php`: after the class's opening brace add
```php
    public function __construct(private RecommendationAnswerBudget $answerBudget)
    {
    }

```
and replace `RecommendationAnswerBudget::outputBoundTokens($prompt->replyItemCount, $prompt->schema, $reasoning),` with `$this->answerBudget->outputBoundTokens($prompt->replyItemCount, $prompt->schema, $reasoning),`.

`RecommendationPromptBuilder.php`:
- In the class docblock, replace
```php
 * the candidate pool into batches that fit the model's context window. Pure
 * computation: no collaborators, so every method is a straight function of
 * its arguments.
```
with
```php
 * the candidate pool into batches that fit the model's context window. Its one
 * collaborator is the answer budget, so a batch's reserve and its request's
 * output bound come from one place.
```
- After the last constant and its blank line, add
```php
    public function __construct(private RecommendationAnswerBudget $answerBudget)
    {
    }

```
- Replace `$responseReserve = RecommendationAnswerBudget::answerBoundTokens(` with `$responseReserve = $this->answerBudget->answerBoundTokens(` and `+ RecommendationAnswerBudget::outputBoundTokens(` with `+ $this->answerBudget->outputBoundTokens(`.

Fix the imports to the moved names.

- [ ] **Step 3: The tests**

- `PhraseMarkersTest`: `SuspiciousPhrases::` → `(new SuspiciousPhrases())->` (it builds its markers through `AuditMarkers::phrases()` since D6).
- `tests/Support/AuditMarkers.php`: `return new PhraseMarkers(self::leadingRegion());` → `return new PhraseMarkers(self::leadingRegion(), new SuspiciousPhrases());`, and add `use App\Service\ReaderAudit\SuspiciousPhrases;` in order.
- `RecommendationAnswerBudgetTest`, `RecommendationCompletionRequestFactoryTest`, `RecommendationRunAdvancerTest`: `RecommendationAnswerBudget::` → `(new RecommendationAnswerBudget())->`.
- `RecommendationCompletionRequestFactoryTest`: `new RecommendationCompletionRequestFactory()` → `new RecommendationCompletionRequestFactory(new RecommendationAnswerBudget())`.
- `RecommendationPromptBuilderTest`: `new RecommendationPromptBuilder()` → `new RecommendationPromptBuilder(new RecommendationAnswerBudget())`.
- `RecommendationConsolidationParserTest`: its `new RecommendationConsolidationParser(` gains `new PlausibleDuplicateShare()` as its last argument.

```bash
git grep -n -E '(SuspiciousPhrases|PlausibleDuplicateShare|RecommendationAnswerBudget)::' -- src tests
git grep -n -E 'new (PhraseMarkers|RecommendationPromptBuilder|RecommendationCompletionRequestFactory)\(\)' -- tests src
git grep -n -E 'new PhraseMarkers\(' -- tests
```
Expected: the first two print nothing; the third prints the one `AuditMarkers.php` line (positive control for the second).

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/ReaderAudit tests/Service/Recommendation tests/Command && composer cs && composer md && composer tramp`, and E1's two counts (`1` and `1`)
Expected: PASS.
```bash
git add src/Service/ReaderAudit src/Service/Recommendation tests
git commit -m "refactor(#1169): the phrase lists, duplicate share and answer budget are injected services"
```

---

### Task E5: `CardTitle` is injected

**Files:**
- Modify: `src/Service/Scraper/CardTitle.php`, `src/Service/Scraper/Pass/CardFields.php`, `src/Service/Scraper/ScrapeLayer/ClusterLayer.php`, `SemanticLayer.php`
- Test: `tests/Service/Scraper/CardTitleTest.php`, `Pass/CardFieldsTest.php`, `ClusterLayerTest.php`, `SemanticLayerTest.php`, `HtmlItemExtractorTest.php`

**Interfaces:**
- Produces: `CardTitle::of(Element $container, Element $anchor): ?string` (instance); `CardFields::__construct(PageUrls $pageUrls, CardTitle $cardTitle)`; `ClusterLayer::__construct(CardTitle $cardTitle)`, `SemanticLayer::__construct(CardTitle $cardTitle)`.

- [ ] **Step 1: `CardTitle` becomes a service; the pass object is handed it**

`CardTitle.php`: delete the private constructor; `final class` → `final readonly class`; `public static function of(` → `public function of(`. Its private statics stay.

`Pass/CardFields.php`: replace `public function __construct(private PageUrls $pageUrls)` with `public function __construct(private PageUrls $pageUrls, private CardTitle $cardTitle)`; `$title = self::title($container, $anchor);` → `$title = $this->title($container, $anchor);`; `private static function title(Element $container, Element $anchor): ?string` → `private function title(Element $container, Element $anchor): ?string`; `$title = CardTitle::of($container, $anchor);` → `$title = $this->cardTitle->of($container, $anchor);`.

`ScrapeLayer/ClusterLayer.php` and `SemanticLayer.php`: after their constants add
```php
    public function __construct(private CardTitle $cardTitle)
    {
    }

```
and replace `$cardFields = new CardFields(new PageUrls($baseUrl));` with `$cardFields = new CardFields(new PageUrls($baseUrl), $this->cardTitle);`. `CardFields` reads the title policy it is handed.

- [ ] **Step 2: The tests**

- `CardTitleTest`: `CardTitle::of(` → `(new CardTitle())->of(`.
- `Pass/CardFieldsTest`: `new CardFields(new PageUrls('https://site.test/'))` → `new CardFields(new PageUrls('https://site.test/'), new CardTitle())`.
- `new ClusterLayer()` and `new SemanticLayer()` (in `ClusterLayerTest`, `SemanticLayerTest`, `HtmlItemExtractorTest`) → `new ClusterLayer(new CardTitle())`, `new SemanticLayer(new CardTitle())`.

- [ ] **Step 3: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit tests/Service/Scraper tests/Service/Refresh && composer check && composer md && composer tramp`, then `composer stan -- --error-format=raw --no-progress 2>&1 | grep -c 'Service role "supportHome"'`
Expected: PASS, and `0`: no policy class is left static in a module root (E1's `9` was this grep's positive control).
```bash
git add src/Service/Scraper tests/Service/Scraper
git commit -m "refactor(#1169): the card title heuristic is an injected service"
```

---

### Finishing PR E

- [ ] **Step 1: The gates on the whole branch** (Global Constraints), `composer tramp` included; Appendix M with `policies`.
- [ ] **Step 2: The real recommendation run** (Appendix R): the prompt builder, the request factory and the consolidation parser changed. Paste steps 3, 5, 6 and 7.
- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 4: /simplify**; commit `refactor(#1169): simplify pass` if anything changed.
- [ ] **Step 5: Final review (opus)**, attack points: D-1 applied to each of the nine; `freshConnectionAfter()` is curl mechanics and belongs to `EgressOptions`; the parsers' output is byte-identical (the parser suite and `RefreshRunnerTest`); the answer budget is one instance wherever the container builds the prompt builder and the request factory; no tramp; re-run E2's deletion check and quote its FAIL; no closing keyword in a commit.
- [ ] **Step 6: Open the PR** (Appendix K) with `var/refactor-1169/pr-e-body.md`:
```markdown
Refs #1169 (PR E of eight).

Nine more policy classes leave `Support/` as injected services, by the rule PR D wrote into §10:
- `Fetch`: `CrossFamilyFailover` (its curl fresh-connection option moves into `EgressOptions`, its only caller).
- `Html`: `DesktopViewport`.
- `Parser`: `ItemImageExtractor`, `ItemMediaExtractor`, `FeedItemImageSelector`, injected into the format parsers and the WordPress parser. The other nine Parser helpers read a feed standard and stay in `Support/`.
- `ReaderAudit`: `SuspiciousPhrases`.
- `Recommendation\Prompt`: `PlausibleDuplicateShare`, `RecommendationAnswerBudget`.
- `Scraper`: `CardTitle`, handed to `CardFields` by the layers that build it.

No behaviour change and no wire change. Real recommendation run: <paste the Appendix R summary line>.
```
Title: `refactor(#1169): the remaining policies are injected services`.
- [ ] **Step 7: Merge when green**, then check #1169 is `OPEN`.

---

# PR F — Tests and rules

### Task F0: Preflight (PR E merged)

- [ ] **Step 1: Branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "the card title heuristic is an injected service"
git switch -c refactor/1169-tests-and-rules origin/develop
```
Expected: one log line.

---

### Task F1: Invocation matchers on `$this`; `InvocationMatchersOnThisRule` (D-5)

**Files:**
- Create: `tests/PhpStan/InvocationMatchersOnThisRule.php`, `tests/PhpStan/InvocationMatchersOnThisRuleTest.php`, `tests/PhpStan/data/invocation-matchers-on-this-fixtures.php`
- Modify: every test file with a `self::`/`static::` invocation matcher (24 files, 71 calls at `5dbc55d3`), `phpstan.dist.neon`

**Interfaces:**
- Produces: `InvocationMatchersOnThisRule`, a `Rule<StaticCall>` with no constructor argument, identifier `simpleFeedReader.invocationMatchersOnThis`, message `Call invocation matchers on $this: <self|static>::<matcher>() is $this-><matcher>() (#1169).`

- [ ] **Step 1: The fixture and the failing test**

`tests/PhpStan/data/invocation-matchers-on-this-fixtures.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for InvocationMatchersOnThisRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Tests\Fixtures\Matchers;

use PHPUnit\Framework\TestCase;

final class MatcherSpellings extends TestCase
{
    public function testSpellings(): void
    {
        $this->once();
        self::once();
        static::never();
        self::exactly(2);
        self::assertTrue(true);
    }
}

final class NotATest
{
    public static function once(): void
    {
    }

    public function call(): void
    {
        self::once();
    }
}
```

`tests/PhpStan/InvocationMatchersOnThisRuleTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<InvocationMatchersOnThisRule> */
final class InvocationMatchersOnThisRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InvocationMatchersOnThisRule();
    }

    public function testItReportsAMatcherCalledStaticallyInATestCase(): void
    {
        $this->analyse(
            [__DIR__ . '/data/invocation-matchers-on-this-fixtures.php'],
            [
                [self::message('self', 'once'), 17],
                [self::message('static', 'never'), 18],
                [self::message('self', 'exactly'), 19],
            ],
        );
    }

    private static function message(string $receiver, string $matcher): string
    {
        return sprintf(
            'Call invocation matchers on $this: %s::%s() is $this->%s() (#1169).',
            $receiver,
            $matcher,
            $matcher,
        );
    }
}
```
Run: `php bin/phpunit tests/PhpStan/InvocationMatchersOnThisRuleTest.php`
Expected: FAIL, `Class "App\Tests\PhpStan\InvocationMatchersOnThisRule" not found`.

- [ ] **Step 2: The rule**

`tests/PhpStan/InvocationMatchersOnThisRule.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPUnit\Framework\TestCase;

/**
 * One spelling for PHPUnit's invocation matchers: PhpStorm's EA inspection warns on `self::once()` and
 * `self::never()`, and a warning blocks the lint gate (#1169).
 *
 * @implements Rule<StaticCall>
 */
final readonly class InvocationMatchersOnThisRule implements Rule
{
    private const array MATCHERS = ['any', 'atLeast', 'atLeastOnce', 'atMost', 'exactly', 'never', 'once'];

    private const array STATIC_RECEIVERS = ['self', 'static'];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || !$node->name instanceof Identifier || !self::isInATestCase($scope)) {
            return [];
        }

        $receiver = $node->class->toLowerString();
        $matcher = $node->name->toString();
        if (!\in_array($receiver, self::STATIC_RECEIVERS, true) || !\in_array($matcher, self::MATCHERS, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Call invocation matchers on $this: %s::%s() is $this->%s() (#1169).',
                $receiver,
                $matcher,
                $matcher,
            ))
                ->identifier('simpleFeedReader.invocationMatchersOnThis')
                ->build(),
        ];
    }

    private static function isInATestCase(Scope $scope): bool
    {
        $class = $scope->getClassReflection();

        return $class !== null && $class->isSubclassOf(TestCase::class);
    }
}
```
Run: `php bin/phpunit tests/PhpStan/InvocationMatchersOnThisRuleTest.php`. Expected: PASS.

- [ ] **Step 3: Deletion checks (the rule test)**

One at a time, restoring each by hand:
1. Replace `|| !self::isInATestCase($scope)` with nothing (keep the rest of the condition). Expected: FAIL, with the extra line `32: Call invocation matchers on $this: self::once() is $this->once() (#1169).`
2. Remove `'exactly', ` from `MATCHERS`. Expected: FAIL, the `19:` line missing.

- [ ] **Step 4: The sweep**

```bash
PATTERN='(^|[^A-Za-z_$>])(self|static)::(once|never|exactly|atLeastOnce|atLeast|atMost|any)\('
git grep -c -E "$PATTERN" -- tests | awk -F: '{ s += $NF } END { print s }'
perl -pi -e 's/\b(?:self|static)::(once|never|exactly|atLeastOnce|atLeast|atMost|any)\(/\$this->$1(/g' $(git grep -l -E "$PATTERN" -- tests ':!tests/PhpStan/data')
git grep -n -E "$PATTERN" -- tests ':!tests/PhpStan/data'
```
Expected: the count is `71` (in 24 files at `137d9631` and at `5dbc55d3`; any number above 0 proves the pattern matches), then the second grep prints nothing. (This git's `grep -E` has no `\b`; the pattern spells the boundary out, and `-w` does it elsewhere in this plan.) A `$this` inside a `static fn` or a static method now fails `composer stan` with "Using $this in static context": drop that closure's `static`, or report the site.

- [ ] **Step 5: Register the rule; run**

`phpstan.dist.neon`, at the end of `services:`:
```yaml
    -
        class: App\Tests\PhpStan\InvocationMatchersOnThisRule
        tags:
            - phpstan.rules.rule
```
Run: `composer stan && php bin/phpunit`
Expected: PASS.

Deletion check: in `tests/Security/TrialExpiryGuardTest.php` (a ledger site; four `self::` matchers at `5dbc55d3`), change one `$this->never()` back to `self::never()`. Run `composer stan`. Expected: FAIL, `Call invocation matchers on $this: self::never() is $this->never() (#1169).` Restore by hand.

- [ ] **Step 6: CLAUDE.md, gates, commit**

CLAUDE.md "Enforced mechanically", after the `NoCollaboratorDefaultRule` entry:
```markdown
- **`InvocationMatchersOnThisRule`** — PHPUnit's invocation matchers are called on `$this`
  (`$this->once()`, `$this->never()`); PhpStorm's EA warning on `self::once()` blocks the lint gate.
```
Run: `composer check`, then the PhpStorm inspections on the rule files and three swept files (`TrialExpiryGuardTest`, `PasskeyRemovalPolicyTest`, `UnsubscribeAllTest`).
```bash
git add tests phpstan.dist.neon ../CLAUDE.md
git commit -m "refactor(#1169): invocation matchers are called on \$this, and a rule keeps it so"
```

---

### Task F2: `ParsesHtml` everywhere a reader test parses HTML; one `PROSE` home

`tests/Support/ParsesHtml` gives a test `document(string $html): HTMLDocument` (R-5: use the method name #1202 left). The reader, audit and scraper tests that parse inline switch to it. Tests of the parser itself (`HtmlDocumentParserTest`, `HtmlTranscoderTest`) and static helpers (`tests/Support/BodyCleaningInputs.php`) keep their calls.

**Files:**
- Create: `tests/Support/ProseParagraphs.php`
- Modify: the test files the Step 1 grep lists; the ten `PROSE` files in Step 3

- [ ] **Step 1: The inline parses**

```bash
git grep -l -E 'HTMLDocument::createFromString\(|HtmlDocumentParser::parse(OrNull)?\(' -- tests ':!tests/Service/Html/Support/HtmlDocumentParserTest.php' ':!tests/Service/Html/Support/HtmlTranscoderTest.php' ':!tests/Support'
```
Expected: 56 files at `5dbc55d3` (as at `137d9631`), under `tests/Service/Reader`, `tests/Service/ReaderAudit`, `tests/Service/Scraper`, plus `tests/Service/Html/Support/ClassTokenMatcherTest.php`. Save the list: re-run the command with `> var/refactor-1169/parses-html.txt`.

Three shapes, rewritten in each listed file:
```bash
FILES=$(cat var/refactor-1169/parses-html.txt)
perl -0pi -e 's/(\$\w+) = HtmlDocumentParser::parseOrNull\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*)\);\n[ \t]*self::assertNotNull\(\1\);/$1 = \$this->document($2);/g' $FILES
perl -0pi -e 's/HtmlDocumentParser::parse\(/\$this->document(/g' $FILES
perl -0pi -e 's/\\?Dom\\HTMLDocument::createFromString\(|HTMLDocument::createFromString\(/HTMLDOC_CREATE(/g; s/HTMLDOC_CREATE\(((?:[^()]|\((?:[^()]|\([^()]*\))*\))*?),\s*\\?LIBXML_NOERROR,?\s*\)/\$this->document($1)/g' $FILES
git grep -n -E 'HTMLDOC_CREATE\(|HtmlDocumentParser::parseOrNull\(' -- $FILES
```
The last grep lists what the three shapes did not cover: a `createFromString` with another flag (restore `HTMLDocument::createFromString(` by hand and leave it), a `parseOrNull()` whose null the test asserts or tolerates (leave it; G4 handles the rest), a call in a `static` method (the trait method is an instance method: leave it). Name each leftover in the report.

In each changed file: add `use App\Tests\Support\ParsesHtml;` in order and `use ParsesHtml;` as the class's first line (skip files that have it: `JsonLdTest`, `PageTextBlocksModelTest`, `TeaserPlayerScannerTest`, `SchemaOrgAccessTest`, `LeadingEngagementBlocksTest`); delete the `HtmlDocumentParser` and `Dom\HTMLDocument` imports PhpStorm reports unused. A file that already had its own `private function document(string $html)` helper: delete that helper, the trait replaces it.

- [ ] **Step 2: Run**

Run: `php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit tests/Service/Scraper tests/Service/Html && composer stan`
Expected: PASS. A test that fed blank HTML to `createFromString` now throws `UnparseableHtmlException` from `document()`: restore that one call and report it.

- [ ] **Step 3: One home for the two shared `PROSE` fixtures**

`tests/Support/ProseParagraphs.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Paragraphs several reader tests share; each clears a length bar the code under test measures. */
final class ProseParagraphs
{
    /** Clears the body cleaners' substantial-prose length and holds no link. */
    public const string SUBSTANTIAL = 'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    /** The block a player follows on its page, long enough for PageTextBlocksModel to anchor to. */
    public const string BEFORE_A_PLAYER =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private function __construct()
    {
    }
}
```
Prove the ten copies equal the two constants before deleting them:
```bash
php -r 'require "vendor/autoload.php";
$german = ["BodyCleaning\\BodyCleaningStep\\AuthorBioSeparatorTest", "BodyCleaning\\BodyCleaningStep\\EdgeBoilerplateTrimmerTest", "BodyCleaning\\BodyCleaningStep\\NavigationChromeTrimmerTest", "BodyCleaning\\BodyCleaningStep\\PageMediaPlacementTest"];
$english = ["Media\\MediaCandidateSource\\AttributeMediaSourceTest", "Media\\MediaCandidateSource\\JsonLdMediaSourceTest", "Media\\MediaCandidateSource\\PageEmbedSourceTest", "Media\\MediaCandidateSource\\SemanticMediaSourceTest", "Media\\MediaCandidateSource\\YouTubeIdAttributeSourceTest", "Media\\PageMediaInserterTest"];
foreach ([[$german, App\Tests\Support\ProseParagraphs::SUBSTANTIAL], [$english, App\Tests\Support\ProseParagraphs::BEFORE_A_PLAYER]] as [$classes, $expected]) {
    foreach ($classes as $short) {
        $class = "App\\Tests\\Service\\Reader\\" . $short;
        echo (new ReflectionClassConstant($class, "PROSE"))->getValue() === $expected ? "same " : "DIFFERENT ", $short, "\n";
    }
}'
```
Expected: ten `same` lines. A `DIFFERENT` one keeps its own constant (report it).

In the four German files: delete the `private const string PROSE =` declaration (all its lines, to the `;`) and the blank line after it; replace `self::PROSE` with `ProseParagraphs::SUBSTANTIAL`. In the six English files: the same with `ProseParagraphs::BEFORE_A_PLAYER`. Add `use App\Tests\Support\ProseParagraphs;` in order.
```bash
git grep -n -E 'const string PROSE' -- tests/Service/Reader/BodyCleaning/BodyCleaningStep/AuthorBioSeparatorTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/EdgeBoilerplateTrimmerTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/NavigationChromeTrimmerTest.php tests/Service/Reader/BodyCleaning/BodyCleaningStep/PageMediaPlacementTest.php tests/Service/Reader/Media
git grep -c -E 'const string PROSE' -- tests/Service/ReaderAudit/PhraseMarkersTest.php
```
Expected: the first grep prints nothing; the second prints `1` (a different text, kept: positive control).

- [ ] **Step 4: Run, gates, commit**

Run: `php bin/phpunit tests/Service/Reader tests/Service/ReaderAudit tests/Service/Scraper && composer check`
Expected: PASS.
```bash
git add tests
git commit -m "refactor(#1169): reader tests parse HTML through ParsesHtml and share one home for their prose fixtures"
```

---

### Task F3: `EmailVerifierTest`; the tag-lookup test folds into `BulkSubscriberTest`

**Files:**
- Create: `tests/Service/Auth/EmailVerifierTest.php`
- Modify: `tests/Service/Auth/RegistrationServiceTest.php`, `tests/Service/Subscription/BulkSubscriberTest.php`
- Delete: `tests/Service/Subscription/BulkSubscriberTagLookupTest.php`

- [ ] **Step 1: `EmailVerifierTest`**

The verifier's three tests leave `RegistrationServiceTest` and stop registering through `RegistrationService`: a pending account and its token are made directly.

`tests/Service/Auth/EmailVerifierTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Entity\User;
use App\Enum\RegistrationMethod;
use App\Enum\TokenPurpose;
use App\Enum\UserStatus;
use App\Event\UserAwaitingApproval;
use App\Repository\UserRepository;
use App\Service\Auth\ActionTokenService;
use App\Service\Auth\EmailVerifier;
use App\Service\Auth\Exception\InvalidTokenException;
use App\Service\Auth\RegistrationPolicy;
use App\Tests\DbTestCase;
use App\Tests\Support\RegistrationPolicies;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class EmailVerifierTest extends DbTestCase
{
    use RegistrationPolicies;

    public function testWithApprovalOnTheVerifiedAccountQueuesForApprovalAndDispatches(): void
    {
        $policy = $this->registrationPolicy(confirm: true, approve: true);
        $token = $this->pendingAccountToken('verifier-approval-on@example.com');
        $captured = [];

        $status = $this->verifier($policy, $this->recordingDispatcher($captured))->verify($token);

        self::assertSame(UserStatus::PendingApproval, $status);
        $user = $this->users()->findOneByEmail('verifier-approval-on@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::PendingApproval, $user->getStatus());
        self::assertNull($user->getApprovedAt());
        self::assertTrue($user->isEmailVerified());
        self::assertCount(1, $captured);
        self::assertSame($user, $captured[0]->user);
        self::assertSame(RegistrationMethod::EmailPassword, $captured[0]->method);
    }

    public function testWithApprovalOffTheVerifiedAccountIsActiveWithoutAnEvent(): void
    {
        $policy = $this->registrationPolicy(confirm: true, approve: false);
        $token = $this->pendingAccountToken('verifier-approval-off@example.com');
        $captured = [];

        $status = $this->verifier($policy, $this->recordingDispatcher($captured))->verify($token);

        self::assertSame(UserStatus::Active, $status);
        $user = $this->users()->findOneByEmail('verifier-approval-off@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertSame(UserStatus::Active, $user->getStatus());
        self::assertNotNull($user->getApprovedAt());
        self::assertTrue($user->isEmailVerified());
        self::assertSame([], $captured);

        // Reads past the identity map: proves the approval was flushed, not only set in memory.
        $this->em->clear();
        $reloaded = $this->users()->findOneByEmail('verifier-approval-off@example.com');
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(UserStatus::Active, $reloaded->getStatus());
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $verifier = $this->verifier($this->registrationPolicy(confirm: true, approve: false), new EventDispatcher());

        $this->expectException(InvalidTokenException::class);

        $verifier->verify('never-issued');
    }

    private function pendingAccountToken(string $email): string
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01 10:00:00'));
        self::assertSame(UserStatus::PendingVerification, $user->getStatus());
        $this->em->persist($user);
        $this->em->flush();

        return $this->tokens()->issue($user, TokenPurpose::VerifyEmail);
    }

    private function verifier(RegistrationPolicy $policy, EventDispatcherInterface $events): EmailVerifier
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return new EmailVerifier($this->tokens(), $policy, $this->em, $events, $clock);
    }

    /**
     * @param list<UserAwaitingApproval> $captured
     *
     * @param-out list<UserAwaitingApproval> $captured
     */
    private function recordingDispatcher(array &$captured): EventDispatcherInterface
    {
        $events = new EventDispatcher();
        $events->addListener(
            UserAwaitingApproval::class,
            static function (UserAwaitingApproval $event) use (&$captured): void {
                $captured[] = $event;
            },
        );

        return $events;
    }

    private function tokens(): ActionTokenService
    {
        /** @var ActionTokenService $tokens */
        $tokens = self::getContainer()->get(ActionTokenService::class);

        return $tokens;
    }

    private function users(): UserRepository
    {
        /** @var UserRepository $repository */
        $repository = self::getContainer()->get(UserRepository::class);

        return $repository;
    }
}
```
(If a new `User`'s default status is not `PendingVerification`, the presence assertion in `pendingAccountToken()` fails first: apply `NewUserStatus::apply($user, UserStatus::PendingVerification, …)`'s equivalent and report it.)

In `tests/Service/Auth/RegistrationServiceTest.php`: delete `testVerifyEmailWithApprovalOnQueuesForApprovalAndDispatches()`, `testVerifyEmailWithApprovalOffActivatesDirectlyWithoutEvent()`, `testVerifyingWithAnUnknownTokenIsRefused()`, the helper `verifierUnderPolicy()` (each with its blank line), and `use App\Service\Auth\EmailVerifier;`.

Run: `php bin/phpunit tests/Service/Auth`. Expected: PASS.

Deletion checks (the moved assertions stand on their own), one at a time, restoring by hand:
1. In `src/Service/Auth/EmailVerifier.php`, delete `$user->markEmailVerified($now);`. Expected: `testWithApprovalOffTheVerifiedAccountIsActiveWithoutAnEvent` fails, `Failed asserting that false is true.`
2. Delete the line `$this->events->dispatch(new UserAwaitingApproval($user, RegistrationMethod::EmailPassword));`. Expected: `testWithApprovalOnTheVerifiedAccountQueuesForApprovalAndDispatches` fails, `Failed asserting that actual size 0 matches expected size 1.`

- [ ] **Step 2: The tag-lookup test joins `BulkSubscriberTest`**

In `tests/Service/Subscription/BulkSubscriberTest.php`, before `    private function subscriptionTo(User $user, string $feedUrl): Subscription`, add:
```php
    public function testATagTheBatchAlreadyKnowsIsNotLookedUpAgain(): void
    {
        $user = $this->user('lookup@example.com');
        $subscriber = $this->subscriber();
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $result = $subscriber->subscribeAll($user, [
            new BulkSubscribeItemModel('https://a.example.com/rss.xml', 'A Feed', 'Technology', null),
            new BulkSubscribeItemModel('https://b.example.com/rss.xml', 'B Feed', 'TECHNOLOGY', null),
            new BulkSubscribeItemModel('https://c.example.com/rss.xml', 'C Feed', 'technology', null),
        ]);

        self::assertSame(3, $result->imported);
        self::assertCount(1, $result->tagsCreated);
        $tag = $result->tagsCreated[0];
        foreach (['a', 'b', 'c'] as $letter) {
            $subscription = $this->subscriptionTo($user, sprintf('https://%s.example.com/rss.xml', $letter));
            self::assertTrue(
                $subscription->getTags()->contains($tag),
                sprintf('subscription "%s" carries the tag', $letter),
            );
        }

        self::assertCount(
            1,
            $recorder->queriesMatching('lower('),
            'one tag lookup for three items naming one tag: the batch answers the other two',
        );
    }

```
Add `use App\Tests\Support\QueryRecorder;` in order, then:
```bash
git rm tests/Service/Subscription/BulkSubscriberTagLookupTest.php
php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php
```
Expected: PASS, one more test than before.

- [ ] **Step 3: Gates and commit**

Run: `composer check`, then the PhpStorm inspections on the three test files.
```bash
git add tests/Service/Auth tests/Service/Subscription
git commit -m "refactor(#1169): EmailVerifier has its own test; the tag-lookup test lives with BulkSubscriber's"
```

---

### Task F4: The reclaim-before-due order is pinned

`RefreshRunner::refresh()` reclaims orphaned feeds before it asks for due feeds, so an unsubscribed feed costs no request. Only a call-site comment says so today.

**Files:**
- Modify: `src/Service/Refresh/RefreshRunner/RefreshRunner.php`
- Test: `tests/Service/Refresh/RefreshRunner/RefreshRunnerOrphanSweepTest.php`

- [ ] **Step 1: The test**

In `RefreshRunnerOrphanSweepTest`, after `testAPruningRefreshDeletesAnOrphanedFeed()`, add:
```php
    public function testAPruningRefreshSpendsNoRequestOnAnOrphanedFeed(): void
    {
        $orphan = new Feed('https://orphan-3.example.com/rss');
        $this->em->persist($orphan);
        $this->em->flush();
        $this->fetcher->willThrow($orphan->getUrl(), new FeedUnreachableException('never asked'));

        $this->runner()->run(RefreshRequestModel::allDue(budgetSeconds: 30));

        self::assertSame([], $this->fetcher->fetchedUrls);
    }
```
and `use App\Service\Fetch\Exception\FeedUnreachableException;` in order. (The stub answers the orphan's URL, so a request, if made, lands in `fetchedUrls` instead of throwing.)

Run: `php bin/phpunit tests/Service/Refresh/RefreshRunner/RefreshRunnerOrphanSweepTest.php`. Expected: PASS.

- [ ] **Step 2: Deletion check**

In `RefreshRunner::refresh()`, move the line `$this->housekeeping->reclaimOrphanedFeeds($request);` to directly after `$feeds = $this->feedRepository->findDue($criteria, self::BATCH_LIMIT);`. Expected: `testAPruningRefreshSpendsNoRequestOnAnOrphanedFeed` fails, `Failed asserting that two arrays are identical.`, the actual array holding `'https://orphan-3.example.com/rss'`. (If the run throws while persisting to the reclaimed row instead, quote that error: the order still made it fail.) Restore by hand.

- [ ] **Step 3: The comment goes; commit**

In `RefreshRunner::refresh()`, delete `        // Before the due query: a feed nobody subscribes to must not cost the run an HTTP request.`: the test names the invariant.

Run: `php bin/phpunit tests/Service/Refresh && composer check && composer md`. Expected: PASS.
```bash
git add src/Service/Refresh/RefreshRunner/RefreshRunner.php tests/Service/Refresh/RefreshRunner/RefreshRunnerOrphanSweepTest.php
git commit -m "refactor(#1169): a test pins that a pruning refresh reclaims orphans before it fetches"
```

---

### Task F5: `ServiceModuleCycleRule` pins (M1, M2)

**Files:**
- Create: `tests/PhpStan/data/service-module-cycle/two-cycles.php`, `beta-first.php`, `two-ways-back.php`, `earlier-file.php`, `later-file.php`
- Modify: `tests/PhpStan/ServiceModuleCycleRuleTest.php`

- [ ] **Step 1: The fixtures** (the line numbers matter; each file starts with the same six lines as `two-module-cycle.php`)

`two-cycles.php`:
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    final class AlphaThing
    {
        public function beta(): string
        {
            return \App\Service\Beta\BetaThing::class;
        }
    }
}

namespace App\Service\Beta {
    final class BetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}

namespace App\Service\Gamma {
    final class GammaThing
    {
        public function delta(): string
        {
            return \App\Service\Delta\DeltaThing::class;
        }
    }
}

namespace App\Service\Delta {
    final class DeltaThing
    {
        public function gamma(): string
        {
            return \App\Service\Gamma\GammaThing::class;
        }
    }
}
```

`beta-first.php` (Beta's namespace first, so without `ksort($sites)` the walk starts at Beta):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Beta {
    final class BetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}

namespace App\Service\Alpha {
    final class AlphaThing
    {
        public function beta(): string
        {
            return \App\Service\Beta\BetaThing::class;
        }
    }
}
```

`two-ways-back.php` (Alpha names Gamma before Beta on one line; both name Alpha back):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Alpha {
    final class AlphaThing
    {
        /** @return list<string> */
        public function neighbours(): array
        {
            return [\App\Service\Gamma\GammaThing::class, \App\Service\Beta\BetaThing::class];
        }
    }
}

namespace App\Service\Beta {
    final class BetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}

namespace App\Service\Gamma {
    final class GammaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}
```

`earlier-file.php`: the same content as `two-cycles.php`'s first two namespaces (lines 1–26 of `two-cycles.php`).

`later-file.php` (a second site for the same `Beta → Alpha` edge, in a file whose name sorts later):
```php
<?php

declare(strict_types=1);

// Fixtures for ServiceModuleCycleRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Beta {
    final class OtherBetaThing
    {
        public function alpha(): string
        {
            return \App\Service\Alpha\AlphaThing::class;
        }
    }
}
```

- [ ] **Step 2: The tests**

In `tests/PhpStan/ServiceModuleCycleRuleTest.php`, after `testAClassLooseInTheServiceRootIsAModuleOfItsOwn()`, add:
```php
    public function testEveryDistinctCycleIsReported(): void
    {
        $this->analyse(
            [self::fixture('two-cycles')],
            [
                [self::message('Alpha -> Beta -> Alpha'), 23],
                [self::message('Gamma -> Delta -> Gamma'), 43],
            ],
        );
    }

    public function testModulesAreWalkedInNameOrder(): void
    {
        $this->analyse([self::fixture('beta-first')], [[self::message('Alpha -> Beta -> Alpha'), 13]]);
    }

    public function testAModulesDependenciesAreSearchedInNameOrder(): void
    {
        $this->analyse(
            [self::fixture('two-ways-back')],
            [
                [self::message('Gamma -> Alpha -> Gamma'), 14],
                [self::message('Alpha -> Beta -> Alpha'), 24],
            ],
        );
    }

    public function testAnEdgeSeenInTwoFilesReportsTheSiteInTheFileFirstByName(): void
    {
        $this->analyse(
            [self::fixture('later-file'), self::fixture('earlier-file')],
            [[self::message('Alpha -> Beta -> Alpha'), 23]],
        );
    }
```
Run: `php bin/phpunit tests/PhpStan/ServiceModuleCycleRuleTest.php`. Expected: PASS (the graph already sorts; these pin it).

- [ ] **Step 3: Deletion checks** (in `tests/PhpStan/ServiceModuleGraph.php`, one at a time, restoring each by hand)

1. **M1:** in `cycles()`, after `$covered += array_fill_keys($cycle->modules, true);`, add `break;`. Expected: `testEveryDistinctCycleIsReported` fails, `Failed asserting that two strings are identical.`, the `43: … Gamma -> Delta -> Gamma …` line missing.
2. **M2, module order:** delete `ksort($sites);`. Expected: `testModulesAreWalkedInNameOrder` fails, the actual line reading `23: … Beta -> Alpha -> Beta …`.
3. **M2, dependency order:** delete the loop `foreach (array_keys($sites) as $module) {` … `}` that sorts each module's dependencies. Expected: `testAModulesDependenciesAreSearchedInNameOrder` fails, actual lines `14: … Beta -> Alpha -> Beta …` and `34: … Alpha -> Gamma -> Alpha …`.
4. **M2, file order:** delete `ksort($collected);`. Expected: `testAnEdgeSeenInTwoFilesReportsTheSiteInTheFileFirstByName` fails, the actual line `13: … Alpha -> Beta -> Alpha …`. If it passes, PHPStan already hands the collected data over in file-name order: report it, keep the test (it still pins the site), and say the sort is redundant.

- [ ] **Step 4: Commit**

Run: `composer check`.
```bash
git add tests/PhpStan
git commit -m "refactor(#1169): the cycle rule's every-cycle report and its name orders are pinned"
```

---

### Task F6: `ClassNameReferences::forbiddenInFile()`

Four places loop over a file's namespaces, check the namespace's scope and map `forbiddenIn()` (the ledger's fourth copy). One method does it; `ForbiddenReference` carries its namespace.

**Files:**
- Modify: `tests/PhpStan/ClassNameReferences.php`, `ForbiddenReference.php`, `DomainKnowsNoHttpRule.php`, `PersistenceKnowsNoServiceRule.php`, `ServiceModuleBoundaryRule.php`, `ServiceModuleDependencyCollector.php`

**Interfaces:**
- Produces: `ClassNameReferences::forbiddenInFile(FileNode $file, list<string> $scope): list<ForbiddenReference>`; `ForbiddenReference::__construct(string $name, int $line, string $matchedRule, string $inNamespace)`. `namespacesIn()` and `forbiddenIn()` become private.

- [ ] **Step 1: `ForbiddenReference` and `ClassNameReferences`**

`ForbiddenReference.php`: replace the constructor with
```php
    public function __construct(
        public string $name,
        public int $line,
        public string $matchedRule,
        public string $inNamespace,
    ) {
    }
```

`ClassNameReferences.php`:
- Before `public function namespacesIn(`, add:
```php
    /**
     * Every forbidden name the file's namespaces under $scope mention, each with the namespace that mentions it.
     *
     * @param list<string> $scope
     *
     * @return list<ForbiddenReference>
     */
    public function forbiddenInFile(FileNode $file, array $scope): array
    {
        $forbidden = [];
        foreach ($this->namespacesIn($file) as $namespace) {
            if (self::isInAnyOf($namespace->name?->toString() ?? '', $scope)) {
                $forbidden = [...$forbidden, ...$this->forbiddenIn($namespace)];
            }
        }

        return $forbidden;
    }

```
- `public function namespacesIn(` → `private function namespacesIn(`; `public function forbiddenIn(` → `private function forbiddenIn(`.
- In `forbiddenIn()`, replace `$forbidden[] = new ForbiddenReference($name, $line, $matchedRule);` with `$forbidden[] = new ForbiddenReference($name, $line, $matchedRule, $namespace->name?->toString() ?? '');`.

- [ ] **Step 2: The four callers**

`DomainKnowsNoHttpRule.php`: replace `processNode()`'s body with
```php
        return array_map(
            self::error(...),
            $this->references->forbiddenInFile($node, self::DOMAIN_NAMESPACES),
        );
```
delete `errorsIn()`; change `error()` to `private static function error(ForbiddenReference $reference): IdentifierRuleError` and its first `sprintf` argument `$namespaceName` to `$reference->inNamespace`; delete `use PhpParser\Node\Stmt\Namespace_;`.

`PersistenceKnowsNoServiceRule.php`: the same, with `self::PERSISTENCE_NAMESPACES`.

`ServiceModuleBoundaryRule.php`: replace `processNode()`'s body with
```php
        $errors = [];
        foreach ($this->referencesByModule as $module => $references) {
            foreach ($references->forbiddenInFile($node, [$module]) as $reference) {
                $errors[] = self::error($module, $reference);
            }
        }

        return $errors;
```
delete `errorsIn()`; change `error()` to `private static function error(string $module, ForbiddenReference $reference): IdentifierRuleError` and its `$namespaceName` to `$reference->inNamespace`; delete the `Namespace_` import.

`ServiceModuleDependencyCollector.php`: replace `processNode()`'s body with
```php
        $fileClassName = basename($scope->getFile(), '.php');
        $dependencies = [];
        foreach ($this->references->forbiddenInFile($node, [self::SERVICE_NAMESPACE]) as $reference) {
            $module = self::moduleOf($reference->inNamespace . '\\' . $fileClassName);
            $dependency = self::moduleOf($reference->name);
            if ('' !== $dependency && $dependency !== $module) {
                $dependencies[] = [$module, $dependency, $reference->line];
            }
        }

        return [] === $dependencies ? null : $dependencies;
```
delete `dependenciesOf()` and the `Namespace_` import.

```bash
git grep -n -E 'namespacesIn\(|->forbiddenIn\(' -- tests/PhpStan ':!tests/PhpStan/ClassNameReferences.php'
```
Expected: nothing. Positive control: the same grep at `origin/develop` (`git grep -n -E 'namespacesIn\(|->forbiddenIn\(' origin/develop -- tests/PhpStan ':!tests/PhpStan/ClassNameReferences.php'`) prints the eight call lines of the four callers.

- [ ] **Step 3: The rule tests pass unchanged; deletion check**

Run: `php bin/phpunit tests/PhpStan && composer stan`
Expected: PASS, with no rule test edited: the refactor keeps every message and line.

Deletion check: in `forbiddenInFile()`, replace `if (self::isInAnyOf($namespace->name?->toString() ?? '', $scope)) {` with `if (true) {`. Expected: `DomainKnowsNoHttpRuleTest` fails with extra lines for the fixture's non-domain namespaces, and `PersistenceKnowsNoServiceRuleTest` likewise. Restore by hand.

- [ ] **Step 4: Commit**

Run: `composer check`.
```bash
git add tests/PhpStan
git commit -m "refactor(#1169): ClassNameReferences scans a file's scoped namespaces in one place"
```

---

### Task F7: `App\Doctrine` in `DomainKnowsNoHttpRule`

**Files:**
- Modify: `tests/PhpStan/DomainKnowsNoHttpRule.php`, `tests/PhpStan/DomainKnowsNoHttpRuleTest.php`, `tests/PhpStan/data/domain-knows-no-http-fixtures.php`, `CLAUDE.md`, `docs/architecture.md`

- [ ] **Step 1: The fixture and the failing test**

```bash
wc -l < tests/PhpStan/data/domain-knows-no-http-fixtures.php
```
Expected: `168`. Append:
```php

namespace App\Doctrine\Fixtures {
    use Symfony\Component\HttpFoundation\Request;

    final class ReadsTheRequest
    {
        public function locale(Request $request): string
        {
            return $request->getLocale();
        }
    }
}
```
(The import is line 171 and the parameter line 175 of a 168-line file.) In `DomainKnowsNoHttpRuleTest`, add `private const string DOCTRINE = 'App\Doctrine\Fixtures';` after the `GAPS` constant, and after the expectation `[self::message(self::GAPS, strtolower(self::ACCESS_DENIED)), 165],` add:
```php
                [self::message(self::DOCTRINE, self::FOUNDATION . 'Request'), 171],
                [self::message(self::DOCTRINE, self::FOUNDATION . 'Request'), 175],
```
Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php`. Expected: FAIL, the `171:` and `175:` lines missing from the actual errors.

- [ ] **Step 2: The scope**

`DomainKnowsNoHttpRule.php`: in `DOMAIN_NAMESPACES`, after `'App\\Enum\\',` add `'App\\Doctrine\\',`.

Run: `php bin/phpunit tests/PhpStan/DomainKnowsNoHttpRuleTest.php && composer stan`
Expected: PASS: `src/Doctrine` names no HTTP class today.

Deletion check: remove `'App\\Doctrine\\',` again. Expected: the rule test fails with the `171:` and `175:` lines missing. Restore by hand.

- [ ] **Step 3: Docs and commit**

`CLAUDE.md`, "Domain code knows no HTTP": replace `in `Service`, `Repository`, `Entity`, `Enum`,\n  `Exception` and `Pagination`.` with `in `Service`, `Repository`, `Entity`, `Enum`,\n  `Doctrine`, `Exception` and `Pagination`.` (the same two lines, `Doctrine` added). `docs/architecture.md` §8: replace `` `DomainKnowsNoHttpRule` (no `App\Http` or `App\Dto` in domain code)`` with `` `DomainKnowsNoHttpRule` (no `App\Http` or `App\Dto` in domain code, `App\Doctrine` included)``.
```bash
git add tests/PhpStan ../CLAUDE.md ../docs/architecture.md
git commit -m "refactor(#1169): the ORM extensions know no HTTP either"
```

---

### Task F8: `ServiceRoleClass` below PHPMD's thresholds (#1202 carry-forward)

`tests/PhpStan/ServiceRoleClass.php` grew through #1202 I to 27 public methods and a class complexity of 72: PHPMD's `TooManyPublicMethods` (more than 10 outside `get`/`set`/`is`/`has`/`with`), `TooManyMethods` (more than 25) and `ExcessiveClassComplexity` (50) all fire. `composer md` reads only `src`, so nothing fails, but tests are production code. It holds four concerns, and each becomes its own class: where a class sits (it stays `ServiceRoleClass`), what its declaration says about state and construction (`ClassShape`), which constructor types the container would supply (`SuppliedConstructorTypes`), and whether it is an event listener (`EventListenerDeclarations`). `invokedMessage()` has one caller and moves into it (`MessagingNames`). No rule, message or line changes.

**Files:**
- Create: `tests/PhpStan/ClassShape.php`, `tests/PhpStan/SuppliedConstructorTypes.php`, `tests/PhpStan/EventListenerDeclarations.php`
- Modify: `tests/PhpStan/ServiceRoleClass.php`, `ServiceShapes.php`, `SupportShapes.php`, `DataShapes.php`, `RootPlacement.php`, `ServiceRoleMap.php`, `MessagingNames.php`, `ServiceRoleClassCollector.php`
- Test: `tests/PhpStan/ServiceRoleRuleTest.php` and `tests/PhpStan/data/service-role-fixtures.php`, both unchanged

**Interfaces:**
- Produces:
  - `ServiceRoleClass`: `public ClassShape $shape` (built in the constructor), `name()`, `namespace()`, `shortName()`, `role()`, `area()`, `movedTo(string)`, `roleHome(string)`, `isInterface()`, `isEnum()`, `isPlainClass()`, `isOfFamily(string)`, `interfaceNames()`; the constructor is unchanged
  - `ClassShape::__construct(ClassReflection $reflection)`, `isAbstract()`, `isFinal()`, `isReadonly()`, `isFinalReadonly()`, `mayBeReadonly()`, `isStaticOnly()`, `declaresStaticMethod()`, `hasPrivateConstructor()`, `staticProperties(): list<string>`, `mutableProperties(): list<string>`, `isStateful()`, `resetsItsState()`
  - `SuppliedConstructorTypes::of(ClassReflection $reflection): list<string>`
  - `EventListenerDeclarations::isEventListener(ClassReflection $reflection): bool`
- Gone from `ServiceRoleClass`: every method `ClassShape` now has, `suppliedConstructorTypes()`, `isEventListener()`, `invokedMessage()`

- [ ] **Step 1: The findings first (the gate's FAIL)**

From `backend/`:
```bash
php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd tests/PhpStan/ServiceRoleClass.php text phpmd.xml.dist; echo "exit $?"
```
(`composer md` runs `@php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd src text phpmd.xml.dist`; this is the same command on one file.) Expected: a non-zero exit and at least `TooManyPublicMethods` and `ExcessiveClassComplexity` (`… has an overall complexity of 72 …`) for `App\Tests\PhpStan\ServiceRoleClass`. Paste the output into the task report: it is this task's deletion check, run backwards.

- [ ] **Step 2: The three new classes**

`tests/PhpStan/ClassShape.php` (the bodies are `ServiceRoleClass`'s at `5dbc55d3`; `$this->name()` reads `$this->reflection->getName()`, `isPlainClass()` is inlined in `isStaticOnly()`, and the four "declared by this class" tests share `isOwn()`):
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use App\DependencyInjection\ProcessLifetimeState;
use PHPStan\Reflection\ClassReflection;

/** What a class's own declaration says about its state and how it is built (#1202). */
final readonly class ClassShape
{
    private const array RESET_CONTRACTS = [
        'Symfony\\Contracts\\Service\\ResetInterface',
        'Monolog\\ResettableInterface',
    ];

    public function __construct(private ClassReflection $reflection)
    {
    }

    public function isAbstract(): bool
    {
        return $this->reflection->isAbstract();
    }

    public function isFinal(): bool
    {
        return $this->reflection->isFinalByKeyword();
    }

    public function isReadonly(): bool
    {
        return $this->reflection->isReadOnly();
    }

    public function isFinalReadonly(): bool
    {
        return $this->isFinal() && $this->isReadonly();
    }

    public function mayBeReadonly(): bool
    {
        $parent = $this->reflection->getParentClass();

        return null === $parent || $parent->isReadOnly();
    }

    /** Pure functions over values: no instance method, no instance property, no constructor argument. */
    public function isStaticOnly(): bool
    {
        if (!$this->reflection->isClass() || $this->reflection->isEnum() || $this->isAbstract()) {
            return false;
        }
        $native = $this->reflection->getNativeReflection();
        foreach ($native->getMethods() as $method) {
            if ($this->isOwn($method) && !$method->isStatic() && !$method->isConstructor()) {
                return false;
            }
        }
        $constructor = $native->getConstructor();

        return [] === $this->instanceProperties()
            && (null === $constructor || 0 === $constructor->getNumberOfParameters());
    }

    public function declaresStaticMethod(): bool
    {
        foreach ($this->reflection->getNativeReflection()->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if ($this->isOwn($method)) {
                return true;
            }
        }

        return false;
    }

    public function hasPrivateConstructor(): bool
    {
        $constructor = $this->reflection->getNativeReflection()->getConstructor();

        return null !== $constructor && $constructor->isPrivate();
    }

    /** @return list<string> the names of the class's own static properties */
    public function staticProperties(): array
    {
        $static = [];
        foreach ($this->reflection->getNativeReflection()->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
            if ($this->isOwn($property)) {
                $static[] = $property->getName();
            }
        }

        return $static;
    }

    /** @return list<string> the names of the class's own properties that are neither static nor readonly */
    public function mutableProperties(): array
    {
        $mutable = [];
        foreach ($this->instanceProperties() as $property) {
            if (!$property->isReadOnly()) {
                $mutable[] = $property->getName();
            }
        }

        return $mutable;
    }

    public function isStateful(): bool
    {
        return [] !== $this->mutableProperties();
    }

    public function resetsItsState(): bool
    {
        foreach (self::RESET_CONTRACTS as $contract) {
            if ($this->reflection->implementsInterface($contract)) {
                return true;
            }
        }

        return [] !== $this->reflection->getNativeReflection()->getAttributes(ProcessLifetimeState::class);
    }

    /** @return list<\ReflectionProperty> */
    private function instanceProperties(): array
    {
        $own = [];
        foreach ($this->reflection->getNativeReflection()->getProperties() as $property) {
            if (!$property->isStatic() && $this->isOwn($property)) {
                $own[] = $property;
            }
        }

        return $own;
    }

    private function isOwn(\ReflectionMethod|\ReflectionProperty $member): bool
    {
        return $member->getDeclaringClass()->getName() === $this->reflection->getName();
    }
}
```

`tests/PhpStan/SuppliedConstructorTypes.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

/** The class types of the constructor arguments the container would have to supply (#1202). */
final class SuppliedConstructorTypes
{
    private const string DEPENDENCY_INJECTION_ATTRIBUTES = 'Symfony\\Component\\DependencyInjection\\Attribute\\';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function of(ClassReflection $reflection): array
    {
        $constructor = $reflection->getNativeReflection()->getConstructor();
        if (null === $constructor) {
            return [];
        }
        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable() || self::isConfiguredByAttribute($parameter)) {
                continue;
            }
            $types = [...$types, ...self::classNamesOf($parameter->getType())];
        }

        return $types;
    }

    private static function isConfiguredByAttribute(\ReflectionParameter $parameter): bool
    {
        foreach ($parameter->getAttributes() as $attribute) {
            if (ClassNameReferences::isInAnyOf($attribute->getName(), [self::DEPENDENCY_INJECTION_ATTRIBUTES])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function classNamesOf(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                $names = [...$names, ...self::classNamesOf($member)];
            }

            return $names;
        }

        return [];
    }
}
```

`tests/PhpStan/EventListenerDeclarations.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

/** In App\EventListener, or declared a Symfony or Doctrine listener by attribute, or a subscriber (#1202). */
final class EventListenerDeclarations
{
    private const string LISTENER_ATTRIBUTE = 'Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener';

    private const array CLASS_LISTENER_ATTRIBUTES = [
        self::LISTENER_ATTRIBUTE,
        'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsDoctrineListener',
        'Doctrine\\Bundle\\DoctrineBundle\\Attribute\\AsEntityListener',
    ];

    private const string SUBSCRIBER_INTERFACE = 'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface';

    private function __construct()
    {
    }

    public static function isEventListener(ClassReflection $reflection): bool
    {
        return ServiceRoleNames::isListener($reflection->getName())
            || $reflection->implementsInterface(self::SUBSCRIBER_INTERFACE)
            || self::declaresListenerAttribute($reflection);
    }

    private static function declaresListenerAttribute(ClassReflection $reflection): bool
    {
        $native = $reflection->getNativeReflection();
        $onClass = array_any(
            self::CLASS_LISTENER_ATTRIBUTES,
            static fn (string $attribute): bool => [] !== $native->getAttributes($attribute),
        );

        return $onClass || array_any(
            $native->getMethods(),
            static fn (\ReflectionMethod $method): bool => [] !== $method->getAttributes(self::LISTENER_ATTRIBUTE),
        );
    }
}
```

- [ ] **Step 3: `ServiceRoleClass` keeps where a class sits**

Replace `tests/PhpStan/ServiceRoleClass.php` with:
```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Reflection\ClassReflection;

final readonly class ServiceRoleClass
{
    public ClassShape $shape;

    /** @param list<string> $dtoReferences */
    public function __construct(
        public ClassReflection $reflection,
        public string $file,
        public int $line,
        public array $dtoReferences,
    ) {
        $this->shape = new ClassShape($reflection);
    }

    public function name(): string
    {
        return $this->reflection->getName();
    }

    public function namespace(): string
    {
        return ServiceRoleNames::namespaceOf($this->name());
    }

    public function shortName(): string
    {
        return ServiceRoleNames::shortNameOf($this->name());
    }

    public function role(): ?string
    {
        return ServiceRoleNames::roleOfClass($this->name());
    }

    public function area(): string
    {
        return ServiceRoleNames::areaOf($this->namespace());
    }

    public function movedTo(string $namespace): string
    {
        return $namespace . '\\' . $this->shortName();
    }

    public function roleHome(string $role): string
    {
        return $this->movedTo($this->area() . '\\' . $role);
    }

    public function isInterface(): bool
    {
        return $this->reflection->isInterface();
    }

    public function isEnum(): bool
    {
        return $this->reflection->isEnum();
    }

    public function isPlainClass(): bool
    {
        return $this->reflection->isClass() && !$this->reflection->isEnum();
    }

    /** The interface itself, or a class or interface that implements or extends it. */
    public function isOfFamily(string $interface): bool
    {
        return $this->name() === $interface || $this->reflection->implementsInterface($interface);
    }

    /** @return list<string> every interface the class implements, through its parents too */
    public function interfaceNames(): array
    {
        return array_values(array_map(
            static fn (ClassReflection $interface): string => $interface->getName(),
            $this->reflection->getInterfaces(),
        ));
    }
}
```
Counted by PHPMD's pattern: `ServiceRoleClass` has 9 public methods outside `is…` (the constructor among them) and 13 methods in all; `ClassShape` 6 and 15.

- [ ] **Step 4: The callers**

```bash
P=tests/PhpStan
perl -pi -e 's/->(isStaticOnly|isAbstract|isFinal|isReadonly|isFinalReadonly|mayBeReadonly|declaresStaticMethod|hasPrivateConstructor|staticProperties|mutableProperties|isStateful|resetsItsState)\(/->shape->$1(/g' $P/ServiceShapes.php $P/SupportShapes.php $P/DataShapes.php $P/RootPlacement.php $P/ServiceRoleMap.php
perl -pi -e 's/\$(\w+)->suppliedConstructorTypes\(\)/SuppliedConstructorTypes::of(\$$1->reflection)/g' $P/ServiceShapes.php $P/DataShapes.php $P/RootPlacement.php
perl -pi -e 's/ServiceRoleClass::isEventListener\(/EventListenerDeclarations::isEventListener(/g' $P/MessagingNames.php $P/ServiceRoleClassCollector.php
```
In `ServiceRoleMap.php` the perl only touches `$class->isStateful()` in `isPerCall()`: its `$reflection->isEnum()` and `isInterface()` calls are not in the list.

`MessagingNames.php`: replace `$message = ServiceRoleNames::HANDLER === $class->role() ? $class->invokedMessage() : null;` with `$message = ServiceRoleNames::HANDLER === $class->role() ? self::invokedMessageOf($class->reflection) : null;`, add as the class's last method
```php

    private static function invokedMessageOf(ClassReflection $reflection): ?string
    {
        if (!$reflection->hasNativeMethod('__invoke')) {
            return null;
        }
        $parameters = $reflection->getNativeMethod('__invoke')->getOnlyVariant()->getParameters();
        $classes = [] === $parameters ? [] : $parameters[0]->getType()->getObjectClassNames();

        return $classes[0] ?? null;
    }
```
(the body is `ServiceRoleClass::invokedMessage()`'s at `5dbc55d3`), and add `use PHPStan\Reflection\ClassReflection;` after the namespace line.

```bash
PATTERN='\$[a-z][A-Za-z]*->(isStaticOnly|isAbstract|isFinal|isReadonly|isFinalReadonly|mayBeReadonly|declaresStaticMethod|hasPrivateConstructor|staticProperties|mutableProperties|isStateful|resetsItsState|suppliedConstructorTypes|invokedMessage)\(|ServiceRoleClass::isEventListener'
git grep -n -E "$PATTERN" -- tests/PhpStan ':!tests/PhpStan/data' ':!tests/PhpStan/ClassShape.php'
git grep -c -E "$PATTERN" origin/develop -- tests/PhpStan ':!tests/PhpStan/data' | awk -F: '{ s += $NF } END { print s }'
```
Expected: the first grep prints nothing; the second (positive control: the old call sites on develop, `ServiceRoleClass`'s own `$this->…` calls among them) prints `27`. `ClassShape.php` is excluded because its own `$this->isFinal()`-style calls are the methods' new home; `$x->shape->isStateful(` does not match, since no `$` stands before `shape`.

- [ ] **Step 5: The gate: PHPMD on the four classes, the rule unchanged**

```bash
php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd tests/PhpStan/ServiceRoleClass.php,tests/PhpStan/ClassShape.php,tests/PhpStan/SuppliedConstructorTypes.php,tests/PhpStan/EventListenerDeclarations.php text phpmd.xml.dist; echo "exit $?"
php bin/phpunit tests/PhpStan
git diff --exit-code origin/develop -- tests/PhpStan/ServiceRoleRuleTest.php tests/PhpStan/data/service-role-fixtures.php && echo 'rule test and fixtures unchanged'
bin/console cache:clear && bin/console cache:warmup && composer stan
```
Expected: PHPMD prints no finding and `exit 0`, where Step 1 printed findings for the same command's first file; `ServiceRoleRuleTest` PASS with every other rule test; `rule test and fixtures unchanged`; `composer stan` `[OK] No errors`, as before the split. Step 1's output and this one go into the task report together.

- [ ] **Step 6: Deletion checks**

One at a time, restoring each by hand, and quote each FAIL:
1. In `ClassShape::hasPrivateConstructor()`, replace the body with `return true;`. Expected: `ServiceRoleRuleTest::testEveryClassOutsideItsRoleIsReportedWithItsHome` fails, `Failed asserting that two strings are identical.`, the expected line `674: Service role "supportShape": … Coins is a helper, so it is never instantiated; declare a private constructor.` missing from the actual errors.
2. In `MessagingNames::invokedMessageOf()`, replace the body with `return null;`. Expected: the same test fails, the `handles App\Service\Shop\Message\RestockShelves, so its name is RestockShelvesHandler` line missing.

- [ ] **Step 7: Gates and commit**

Run: `composer check`, then the PhpStorm inspections on the eleven files.
```bash
git add tests/PhpStan
git commit -m "refactor(#1169): ServiceRoleClass splits its shape, constructor types and listener test into their own classes"
```

---

### Finishing PR F

- [ ] **Step 1: The gates** (Global Constraints). Expected: all green. `infection:diff` mutates `RefreshRunner`'s deleted comment line only (nothing) and no other `src` change but `EmailVerifier`'s untouched lines: expect no mutants.
- [ ] **Step 2: PhpStorm inspections** on the changed rule and test files.
- [ ] **Step 3: Final review (opus)**, attack points: the matcher sweep changed no test's meaning (`$this->exactly(2)` is `self::exactly(2)`); every `ParsesHtml` rewrite parses the same markup with the same flag; the moved `EmailVerifier` tests assert what the old ones did without registering; F5's four orderings each fail for their own line; `forbiddenInFile()` keeps every message byte-identical; F8's split changed no rule outcome (`ServiceRoleRuleTest` and its fixture untouched, `composer stan` clean) and each new class has one concern, with PHPMD clean on the four; re-run F4's, F7's and F8's first deletion checks and quote each FAIL; no closing keyword in a commit.
- [ ] **Step 4: Open the PR** (Appendix K) with `var/refactor-1169/pr-f-body.md`:
```markdown
Refs #1169 (PR F of eight).

- Invocation matchers are called on `$this` across the suite (71 calls in 24 files), and `InvocationMatchersOnThisRule` keeps it so: PhpStorm's EA warning on `self::once()` blocked the lint gate.
- Reader, audit and scraper tests parse HTML through `ParsesHtml`; the two prose fixtures ten tests copied live in `tests/Support/ProseParagraphs`.
- `EmailVerifier` has its own test; the tag-lookup test joined `BulkSubscriberTest`.
- A test pins that a pruning refresh reclaims orphaned feeds before it fetches.
- `ServiceModuleCycleRule`: a two-cycle fixture pins that every cycle is reported, and three more pin its name orders.
- `ClassNameReferences::forbiddenInFile()` replaces four copies of the namespace scan, and `DomainKnowsNoHttpRule` now covers `App\Doctrine`.
- `tests/PhpStan/ServiceRoleClass` (27 public methods, class complexity 72) splits into `ClassShape`, `SuppliedConstructorTypes` and `EventListenerDeclarations`; PHPMD is clean on all four, and `ServiceRoleRuleTest` passes unchanged.

No production behaviour change.
```
Title: `refactor(#1169): test and rule consistency`.
- [ ] **Step 5: Merge when green**, then check #1169 is `OPEN`.

---

# PR G — DRY and typed results

### Task G0: Preflight (PR F merged)

- [ ] **Step 1: Branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "the ORM extensions know no HTTP either"
git switch -c refactor/1169-dry-and-typed-results origin/develop
```
Expected: one log line.

---

### Task G1: `PositionReorderer::reorderFound()` (D-18)

The catalog editors each build an id → row map by looking every requested id up; `PositionReorderer` does it once. `TagOrdering` keys all of a user's tags, which its permutation check needs, and keeps its own map.

**Files:**
- Modify: `src/Service/Ordering/PositionReorderer.php`, `src/Service/Catalog/CatalogCategoryEditor.php`, `src/Service/Catalog/CatalogFeedEditor.php`
- Test: `tests/Service/Ordering/PositionReordererTest.php`

**Interfaces:**
- Produces: `PositionReorderer::reorderFound(array $orderedIds, \Closure $find): void` (`@param list<int>`, `@param \Closure(int): PositionedInterface`).

- [ ] **Step 1: The failing test**

In `PositionReordererTest`, after the existing test, add:
```php
    public function testReorderFoundLooksEachIdUpAndPositionsItInTheRequestedOrder(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('flush');
        $items = [10 => new RecordingPositioned(), 20 => new RecordingPositioned(), 30 => new RecordingPositioned()];

        (new PositionReorderer($entityManager))->reorderFound(
            [30, 10, 20],
            static fn (int $id): RecordingPositioned => $items[$id],
        );

        self::assertSame([1, 2, 0], [$items[10]->position, $items[20]->position, $items[30]->position]);
    }
```
Run: `php bin/phpunit tests/Service/Ordering/PositionReordererTest.php`. Expected: FAIL, `Call to undefined method App\Service\Ordering\PositionReorderer::reorderFound()`.

- [ ] **Step 2: The method and the two callers**

`PositionReorderer.php`, after `reorder()`:
```php

    /**
     * @param list<int>                          $orderedIds
     * @param \Closure(int): PositionedInterface $find throws for an id it does not know
     */
    public function reorderFound(array $orderedIds, \Closure $find): void
    {
        $byId = [];
        foreach ($orderedIds as $id) {
            $byId[$id] = $find($id);
        }
        $this->reorder($orderedIds, $byId);
    }
```

`CatalogCategoryEditor::reorder()`: replace its body with `        $this->reorderer->reorderFound($orderedCategoryIds, $this->categories->getById(...));`.
`CatalogFeedEditor::reorder()`: replace its body with `        $this->reorderer->reorderFound($orderedFeedIds, $this->feeds->getById(...));`.

Run: `php bin/phpunit tests/Service/Ordering tests/Service/Catalog tests/Controller/Admin && composer stan`. Expected: PASS. An unknown id still throws `RecordNotFoundException` from `getById()`.

- [ ] **Step 3: Deletion check**

In `reorderFound()`, delete `$this->reorder($orderedIds, $byId);`. Expected: `testReorderFoundLooksEachIdUpAndPositionsItInTheRequestedOrder` fails: first `Expectation failed for method name is "flush" when invoked 1 time(s).` or `Failed asserting that two arrays are identical.` (the positions stay `null`); quote the one you get. Restore by hand.

- [ ] **Step 4: Gates and commit**

Run: `composer check && composer md`.
```bash
git add src/Service/Ordering src/Service/Catalog tests/Service/Ordering
git commit -m "refactor(#1169): the reorder lookup by id lives once, in PositionReorderer"
```

---

### Task G2: `OwnedSubscriptions` takes the owner first (D-16)

§7's order: the owner first. Both methods reorder `(list<int> $ids, int $userId)` to `(int $userId, list<int> $ids)`; the types differ, so PHPStan catches a call left in the old order.

**Files:**
- Modify: `src/Service/Subscription/OwnedSubscriptions.php`, `src/Controller/Api/SubscriptionController.php`, `src/Service/Subscription/BulkSubscriptionUpdater.php`, `src/Service/Subscription/SubscriptionEditor.php`
- Test: `tests/Service/Subscription/OwnedSubscriptionsTest.php`

- [ ] **Step 1: The signatures**

`OwnedSubscriptions.php`: replace `public function resolve(array $ids, int $userId): array` with `public function resolve(int $userId, array $ids): array`, and `public function resolveWithAssociations(array $ids, int $userId): array` with `public function resolveWithAssociations(int $userId, array $ids): array`.

- [ ] **Step 2: The callers**

| File | Replace | With |
|---|---|---|
| `SubscriptionController.php` | `->resolve($request->subscriptionIds, $user->requireId());` | `->resolve($user->requireId(), $request->subscriptionIds);` |
| `BulkSubscriptionUpdater.php` | `->resolveWithAssociations($change->subscriptionIds, $userId);` | `->resolveWithAssociations($userId, $change->subscriptionIds);` |
| `SubscriptionEditor.php` | `->resolve($orderedSubscriptionIds, $user->requireId()),` | `->resolve($user->requireId(), $orderedSubscriptionIds),` |

`OwnedSubscriptionsTest.php`: in each of the six calls, the owner id moves in front:
- `[$second->requireId(), $first->requireId()],` followed by `$user->requireId(),` (twice, in `resolve(` and `resolveWithAssociations(`) → `$user->requireId(),` followed by `[$second->requireId(), $first->requireId()],`.
- `[$ours->requireId(), $foreign->requireId()],` followed by `$mine->requireId(),` (twice) → `$mine->requireId(),` followed by `[$ours->requireId(), $foreign->requireId()],`.
- `$this->owned->resolve([999_999], $user->requireId());` → `$this->owned->resolve($user->requireId(), [999_999]);`
- `$this->owned->resolve([$id, $id], $user->requireId());` → `$this->owned->resolve($user->requireId(), [$id, $id]);`

- [ ] **Step 3: Run, gates, commit**

Run: `composer stan && php bin/phpunit tests/Service/Subscription tests/Controller/Api && composer check && composer md`
Expected: PASS. (A caller left in the old order fails `composer stan` with `Parameter #1 $userId … expects int, array given`.)
```bash
git add src/Service/Subscription src/Controller/Api/SubscriptionController.php tests/Service/Subscription
git commit -m "refactor(#1169): OwnedSubscriptions takes the owner first, like every user-scoped lookup"
```

---

### Task G3: The All-items filter runs in SQL

`MarkReadService` with scope "all" fetch-joins every subscription's feed and tags, then drops the ones hidden from All items in PHP. The repository selects only what it marks.

**Files:**
- Modify: `src/Repository/SubscriptionRepository.php`, `src/Service/Reading/MarkReadService.php`
- Test: `tests/Repository/SubscriptionRepositoryTest.php`

**Interfaces:**
- Produces: `SubscriptionRepository::findIncludedInAllItemsForUser(int $userId): array` (`@return list<Subscription>`), ordered by id.

- [ ] **Step 1: The failing test**

In `SubscriptionRepositoryTest`, after the last test, add:
```php
    public function testFindIncludedInAllItemsForUserSkipsHiddenFeedsAndOtherUsers(): void
    {
        $owner = $this->userFactory()->create('all-items-owner@example.com');
        $included = $this->subscriptionToFeed($owner, 'https://example.com/included.xml');
        $hidden = $this->subscriptionToFeed($owner, 'https://example.com/hidden.xml');
        $hidden->setIncludeInAllItems(false);
        $this->subscriptionToFeed($this->userFactory()->create('all-items-other@example.com'), 'https://example.com/foreign.xml');
        $this->em->flush();

        self::assertSame([$included], $this->repo()->findIncludedInAllItemsForUser($owner->requireId()));
    }
```
and a helper at the end of the class:
```php
    private function subscriptionToFeed(User $owner, string $feedUrl): Subscription
    {
        $feed = new Feed($feedUrl);
        $this->em->persist($feed);
        $subscription = new Subscription($owner, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }
```
(Build the subscription as the file's existing `subscription()` helper does; if it also sets a position, do the same.) The foreign line is 121 columns if written on one line: break its arguments onto their own lines.

Run: `php bin/phpunit --filter testFindIncludedInAllItemsForUserSkipsHiddenFeedsAndOtherUsers tests/Repository/SubscriptionRepositoryTest.php`. Expected: FAIL, `Call to undefined method App\Repository\SubscriptionRepository::findIncludedInAllItemsForUser()`.

- [ ] **Step 2: The query and its caller**

`SubscriptionRepository.php`, after `findForUserWithTags()`:
```php

    /**
     * The subscriptions the All-items list shows: scope "all" of mark-read reads exactly these.
     *
     * @return list<Subscription>
     */
    public function findIncludedInAllItemsForUser(int $userId): array
    {
        /** @var list<Subscription> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->andWhere('s.includeInAllItems = :included')->setParameter('included', true, Types::BOOLEAN)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
```
and `use Doctrine\DBAL\Types\Types;` in order.

`MarkReadService.php`: replace `ReadScopeKind::All => $this->includedInAllItems($this->subscriptions->findForUserWithTags($userId)),` with `ReadScopeKind::All => $this->subscriptions->findIncludedInAllItemsForUser($userId),`; delete `includedInAllItems()` with its docblock and the blank line before it.

Run: `php bin/phpunit tests/Repository/SubscriptionRepositoryTest.php tests/Service/Reading tests/Controller/Api && composer stan`
Expected: PASS. `MarkReadServiceTest`'s hidden-subscription case (its `setIncludeInAllItems(false)` at `5dbc55d3` line 195) still passes: the rule moved, it did not change.

- [ ] **Step 3: Deletion checks**

One at a time, restoring each by hand:
1. Delete `->andWhere('s.includeInAllItems = :included')->setParameter('included', true, Types::BOOLEAN)`. Expected: the new test fails, `Failed asserting that two arrays are identical.` (the hidden subscription appears).
2. Delete `->andWhere('s.user = :userId')->setParameter('userId', $userId)`. Expected: the same failure (the foreign subscription appears).

- [ ] **Step 4: Gates and commit**

Run: `composer check && composer md`, and on the MySQL leg later (Finishing).
```bash
git add src/Repository/SubscriptionRepository.php src/Service/Reading/MarkReadService.php tests/Repository/SubscriptionRepositoryTest.php
git commit -m "refactor(#1169): mark-all-read selects only the All-items subscriptions in SQL"
```

---

### Task G4: `HtmlDocumentParser::parseOrEmpty()`; `parseOrNull()` goes (D-17)

Every caller outside the extraction pipeline treats "nothing to read" like a document with nothing in it. An empty `HTMLDocument` (no `documentElement`, no `body`) is that typed result.

**Files:**
- Modify: `src/Service/Html/Support/HtmlDocumentParser.php`, `src/Service/Discovery/FeedLinkScanner.php`, `src/Service/Discovery/WordPressRestProbe.php`, `src/Service/Parser/ItemImageExtractor.php`, `src/Service/Reader/ExtractionCoverageGate.php`, `src/Service/Reader/HeroImageSelector.php`, `src/Service/Reader/Media/Model/RawPageModel.php`, `src/Service/Reader/MetaRefreshTarget.php`, `src/Service/ReaderAudit/Model/ExtractedBodyModel.php`
- Test: `tests/Service/Html/Support/HtmlDocumentParserTest.php`, `tests/Service/Html/Support/HtmlTranscoderTest.php`, and any test F2 left on `parseOrNull()`

**Interfaces:**
- Produces: `HtmlDocumentParser::parseOrEmpty(string $html): HTMLDocument`. `parseOrNull()` is deleted.

- [ ] **Step 1: The failing test**

In `HtmlDocumentParserTest`:
- Replace `testBlankInputYieldsNull()` with:
```php
    public function testBlankInputYieldsAnEmptyDocument(): void
    {
        self::assertNull(HtmlDocumentParser::parseOrEmpty('')->documentElement);
        self::assertNull(HtmlDocumentParser::parseOrEmpty('   ')->documentElement);
    }

    public function testParseOrEmptyHandsBackAParsedDocument(): void
    {
        $document = HtmlDocumentParser::parseOrEmpty('<html lang="en"><body><p>Body</p></body></html>');

        self::assertSame('Body', $document->querySelector('p')?->textContent);
    }
```
- In `testParsesHtmlIntoADocument()` and `testKeepsNonAsciiAsUtf8()`, replace `HtmlDocumentParser::parseOrNull(` with `HtmlDocumentParser::parse(` and delete their `self::assertNotNull($document);` lines.

Run: `php bin/phpunit tests/Service/Html/Support/HtmlDocumentParserTest.php`. Expected: FAIL, `Call to undefined method App\Service\Html\Support\HtmlDocumentParser::parseOrEmpty()`.

- [ ] **Step 2: The parser**

In `HtmlDocumentParser`, replace everything from `    public static function parse(string $html): HTMLDocument` through the closing `    }` of `parseOrNull()` with:
```php
    public static function parse(string $html): HTMLDocument
    {
        return self::parsed($html)
            ?? throw new UnparseableHtmlException('The HTML is blank or could not be parsed.');
    }

    /** A document with nothing in it when the HTML is blank or unreadable: nothing to read is not a failure here. */
    public static function parseOrEmpty(string $html): HTMLDocument
    {
        return self::parsed($html) ?? HTMLDocument::createEmpty();
    }

    private static function parsed(string $html): ?HTMLDocument
    {
        if (trim($html) === '') {
            return null;
        }

        try {
            return HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\Throwable) {
            return null;
        }
    }
```
(The private constructor I-support added is the class's last member at `5dbc55d3` and stays there, after `parsed()` and a blank line.)

- [ ] **Step 3: The eight callers**

| File | Replace | With |
|---|---|---|
| `FeedLinkScanner.php` | `$document = HtmlDocumentParser::parseOrNull($html);` and the three lines `if (null === $document) {`, `return [];`, `}` after it (with the blank line) | `$document = HtmlDocumentParser::parseOrEmpty($html);` |
| `WordPressRestProbe.php` | `$document = HtmlDocumentParser::parseOrNull($body);` and its `if (null === $document) { return null; }` (with the blank line) | `$document = HtmlDocumentParser::parseOrEmpty($body);` |
| `ItemImageExtractor.php` | `$document = HtmlDocumentParser::parseOrNull($html);` and its `if ($document === null) { return null; }` | `$document = HtmlDocumentParser::parseOrEmpty($html);` |
| `ExtractionCoverageGate.php` | `HtmlDocumentParser::parseOrNull($html)?->body` | `HtmlDocumentParser::parseOrEmpty($html)->body` |
| `HeroImageSelector.php` | `HtmlDocumentParser::parseOrNull($bodyHtml)?->body` | `HtmlDocumentParser::parseOrEmpty($bodyHtml)->body` |
| `RawPageModel.php` | `HtmlDocumentParser::parseOrNull($html) ?? HTMLDocument::createEmpty()` | `HtmlDocumentParser::parseOrEmpty($html)` |
| `MetaRefreshTarget.php` | `$document = HtmlDocumentParser::parseOrNull($html);` and its `if ($document === null) { return null; }` | `$document = HtmlDocumentParser::parseOrEmpty($html);` |
| `ExtractedBodyModel.php` | `$document = HtmlDocumentParser::parseOrNull($html);` and `if ($document === null \|\| $document->body === null) {` | `$document = HtmlDocumentParser::parseOrEmpty($html);` and `if ($document->body === null) {` |

Each empty-document path returns what its null path returned: the scanners find no element, the gates read no body. (`WordPressRestProbe`: an unreadable, non-blank body now reaches the fingerprint check on its raw text; `createFromString()` throws only on input it cannot read at all, so no known page takes that path. The PR body says so.) `HeroImageSelector`'s comment `// Blank or unparsable html leaves no body to judge, …` stays true.

- [ ] **Step 4: The tests off `parseOrNull()`**

```bash
git grep -n 'parseOrNull' -- src tests
```
Expected at this point: only tests (positive control: `git grep -c parseOrNull origin/develop -- src | wc -l` prints the source files that still called it before this step). For each: a call followed by `self::assertNotNull($x);` becomes `HtmlDocumentParser::parse(` with the assertion deleted (`HtmlTranscoderTest` at `5dbc55d3` has two); a call whose `null` the test asserts becomes `parseOrEmpty(` asserting `->documentElement` is null. Re-run the grep. Expected: nothing (`docs/` is not searched).

- [ ] **Step 5: Run and the deletion check**

Run: `bin/console cache:clear && php bin/phpunit tests/Service/Html tests/Service/Discovery tests/Service/Parser tests/Service/Reader tests/Service/ReaderAudit && composer stan`
Expected: PASS.

Deletion check: in `parseOrEmpty()`, replace `return self::parsed($html) ?? HTMLDocument::createEmpty();` with `return HTMLDocument::createEmpty();`. Expected: `testParseOrEmptyHandsBackAParsedDocument` fails, `Failed asserting that null is identical to 'Body'.` Restore by hand.

- [ ] **Step 6: Gates and commit**

Run: `composer check && composer md`, then the PhpStorm inspections.
```bash
git add src tests
git commit -m "refactor(#1169): an unreadable page is an empty document; parseOrNull() is gone"
```

---

### Task G5: `UrlResolver` without the cast

`substr($path, 0, (int) strrpos($path, '/') + 1)` leaves an equivalent `CastInt` mutant (`false + 1` coerces). Dropping the last path segment says the same without a cast.

**Files:**
- Modify: `src/Service/Fetch/Support/UrlResolver.php`

- [ ] **Step 1: The rewrite**

Replace
```php
        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $location;
```
with
```php
        $segments = explode('/', $parts['path'] ?? '/');
        array_pop($segments);

        return $origin . implode('/', $segments) . '/' . $location;
```
For every path a URL with a host can have (it starts with `/`): `/a/b/c` → `/a/b/`, `/a/` → `/a/`, `/` → `/`, as before.

- [ ] **Step 2: Run, mutate, commit**

Run: `php bin/phpunit tests/Service/Fetch/Support/UrlResolverTest.php && composer check && composer md && composer infection:diff`
Expected: PASS; no escaped mutant on the three lines (an escape: add the data-provider case that kills it, e.g. `['https://h.test/a/b/c', 'x', 'https://h.test/a/b/x']` if it is missing, and report it).
```bash
git add src/Service/Fetch/Support/UrlResolver.php tests/Service/Fetch/Support/UrlResolverTest.php
git commit -m "refactor(#1169): UrlResolver drops the last path segment instead of casting strrpos()"
```

---

### Task G6: `BackupReader::toDto()` without a default arm; the Infection ignore goes

The `match`'s `default` arm was unreachable and needed an Infection ignore. A map from body kind to line class has no arm to remove.

**Files:**
- Modify: `src/Service/Backup/BackupReader.php`, `infection.json5`

- [ ] **Step 1: The map**

In `BackupReader`, after `COUNTED_KINDS`, add:
```php

    private const array BODY_LINES = [
        BackupSchema::KIND_ACCOUNT => AccountLine::class,
        BackupSchema::KIND_TAG => TagLine::class,
        BackupSchema::KIND_SAVED_SEARCH => SavedSearchLine::class,
        BackupSchema::KIND_FEED => FeedLine::class,
        BackupSchema::KIND_SUBSCRIPTION => SubscriptionLine::class,
        BackupSchema::KIND_ENTRY => EntryLine::class,
        BackupSchema::KIND_ENTRY_STATE => EntryStateLine::class,
    ];
```
Replace `toDto()` (its docblock and body) with:
```php
    /**
     * read() takes the header and the footer itself, and BackupLineOrderModel refuses any other kind first.
     *
     * @param array<string, mixed> $decoded
     */
    private function toDto(string $kind, array $decoded): object
    {
        $lineClass = self::BODY_LINES[$kind];

        return $lineClass::fromLine($decoded);
    }
```

`infection.json5`: delete the two lines
```json5
        // toDto()'s default arm is unreachable: BackupLineOrderModel refuses every other kind first.
        MatchArmRemoval: { ignore: ['App\\Service\\Backup\\BackupReader::toDto'] },
```

- [ ] **Step 2: Run, mutate, commit**

Run: `php bin/phpunit tests/Service/Backup && composer stan && composer check && composer md && composer infection:diff`
Expected: PASS; no escaped mutant in `BackupReader` (an `ArrayItemRemoval` on `BODY_LINES` is killed by the restore tests that read each kind; if one escapes, add a read of that kind to `BackupReaderTest` and report it). `git grep -n 'BackupReader::toDto' -- infection.json5` prints nothing, and the same grep at `origin/develop` prints the ignore line (positive control).
```bash
git add src/Service/Backup/BackupReader.php infection.json5 tests/Service/Backup
git commit -m "refactor(#1169): BackupReader maps body kinds to line classes; its Infection ignore goes"
```

---

### Task G7: `FeedScheduler::recordNotModified()`

`FeedOutcomePersister::storeNotModified()` calls `recordSuccess($feed, 0)`; the `0 → -1` mutant is equivalent (`FeedScheduler` only tests `> 0`). A named transition takes the literal away.

**Files:**
- Modify: `src/Service/Feed/FeedScheduler.php`, `src/Service/Refresh/FeedOutcomePersister.php`
- Test: `tests/Service/Feed/FeedSchedulerTest.php`

**Interfaces:**
- Produces: `FeedScheduler::recordNotModified(Feed $feed): void`

- [ ] **Step 1: The failing test**

In `FeedSchedulerTest`, after `testASuccessWithNoNewEntriesLeavesTheLastNewEntryTimeUntouched()`, add:
```php
    public function testANotModifiedFetchGrowsTheIntervalAndAddsNoNewEntryTime(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->recordSuccessfulFetch(new \DateTimeImmutable(self::EARLIER), 60);
        $feed->recordNewEntries(new \DateTimeImmutable('2026-07-20 08:00:00'));

        $this->scheduler->recordNotModified($feed);

        self::assertSame(90, $feed->getFetchIntervalMinutes());
        self::assertSame('2026-07-21 12:00:00', $feed->getLastSuccessfulFetchAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-20 08:00:00', $feed->getLastNewEntryAt()?->format('Y-m-d H:i:s'));
    }
```
Run: `php bin/phpunit --filter testANotModifiedFetchGrowsTheIntervalAndAddsNoNewEntryTime tests/Service/Feed/FeedSchedulerTest.php`. Expected: FAIL, `Call to undefined method App\Service\Feed\FeedScheduler::recordNotModified()`.

- [ ] **Step 2: The transition**

In `FeedScheduler`, replace `recordSuccess()`'s interval expression
```php
        $interval = $newEntryCount > 0
            ? self::FLOOR_MINUTES
            : max(
                self::FLOOR_MINUTES,
                min(self::CEILING_MINUTES, (int) round($feed->getFetchIntervalMinutes() * 1.5)),
            );
```
with
```php
        $interval = $newEntryCount > 0 ? self::FLOOR_MINUTES : $this->grownInterval($feed);
```
and add after `recordSuccess()`:
```php

    /**
     * A 304: a successful fetch that carried nothing new.
     *
     * @throws \DateMalformedStringException
     */
    public function recordNotModified(Feed $feed): void
    {
        $feed->recordSuccessfulFetch($this->clock->now(), $this->grownInterval($feed));
    }
```
and, as the class's last method:
```php

    /** The grow branch keeps the floor guard: a stored interval <= 0 would otherwise refetch the feed every run. */
    private function grownInterval(Feed $feed): int
    {
        return max(
            self::FLOOR_MINUTES,
            min(self::CEILING_MINUTES, (int) round($feed->getFetchIntervalMinutes() * 1.5)),
        );
    }
```
In `recordSuccess()`, the comment `// The grow branch keeps the floor guard: …` moves to `grownInterval()` above: delete that line from `recordSuccess()`, keep `// New entries reset to the floor at once, not by halving, or a burst blocks the top of All items (#643).`

`FeedOutcomePersister::storeNotModified()`: replace `$this->scheduler->recordSuccess($feed, 0);` with `$this->scheduler->recordNotModified($feed);`.

Run: `php bin/phpunit tests/Service/Feed tests/Service/Refresh && composer stan`. Expected: PASS.

- [ ] **Step 3: Deletion checks**

1. In `recordNotModified()`, replace `$this->grownInterval($feed)` with `self::FLOOR_MINUTES`. Expected: the new test fails, `Failed asserting that 5 is identical to 90.`
2. Replace `recordNotModified()`'s body with nothing (an empty method). Expected: the new test fails, `Failed asserting that 60 is identical to 90.`

- [ ] **Step 4: Mutate and commit**

Run: `composer check && composer md && composer infection:diff`. Expected: no escaped mutant in `FeedOutcomePersister::storeNotModified()`.
```bash
git add src/Service/Feed/FeedScheduler.php src/Service/Refresh/FeedOutcomePersister.php tests/Service/Feed/FeedSchedulerTest.php
git commit -m "refactor(#1169): a 304 is FeedScheduler::recordNotModified(), so no literal zero stands in for it"
```

---

### Task G8: Properties holding a factory end in `Factory` (D-19)

**Files:**
- Modify: the 19 files in the table; `CLAUDE.md`

- [ ] **Step 1: The survey**

```bash
git grep -n -E '(private|protected|public)( readonly)? [A-Za-z]+Factory(Interface)? \$[a-z][A-Za-z]*[,)]' -- src | grep -v -E 'Factory(Interface)? \$[a-zA-Z]*Factory[,)]|LockFactory \$lockFactory|RateLimiterFactoryInterface '
```
Expected at `5dbc55d3`: 19 lines for the 20 properties below (`RestoredFoundationFactory`'s two share a line). `RateLimiterFactoryInterface $…Limiter` is filtered out: Symfony binds named limiters by that parameter name, so they keep it. `PasswordHasherFactoryInterface $hasherFactory` already ends in `Factory`. `DateLineRecognizer::$formatters` is new since `137d9631`: #1202 I's `DateFormatterFactoryInterface`.

| File | Type | Old | New |
|---|---|---|---|
| `src/Controller/Api/AccountBackupController.php` | `BackupDownloadResponseFactory` | `downloads` | `downloadResponseFactory` |
| `src/Controller/Api/EntrySearchController.php` | `EntrySearchRequestFactory` | `requests` | `requestFactory` |
| `src/Controller/Api/OAuthController.php` | `OAuthRedirectFactory` | `oauthRedirect` | `oauthRedirectFactory` |
| `src/EventListener/ApiExceptionListener.php`, `src/EventListener/JwtFailureResponseListener.php`, `src/Security/LoginFailureHandler.php` | `ProblemResponseFactory` | `responses` | `responseFactory` |
| `src/Service/Ai/AiProviderConfigurator.php` | `AiConfigurationFactory` | `configurations` | `configurationFactory` |
| `src/Service/Auth/RegistrationService.php` | `SignupUserFactory` | `signupUsers` | `signupUserFactory` |
| `src/Service/Backup/Factory/RestoreEntryLoaderFactory.php`, `src/Service/Backup/Pass/RestoreEntryLoader.php` | `RestoredEntryStateFactory` | `states` | `stateFactory` |
| `src/Service/Backup/Factory/RestoredFoundationFactory.php` | `TagFactory`, `FeedFactory` | `tags`, `feeds` | `tagFactory`, `feedFactory` |
| `src/Service/Backup/Pass/RestoreLoadPass.php`, `src/Service/Backup/RestoreLoader.php` | `RestoredFoundationFactory` | `rows` | `foundationFactory` |
| `src/Service/Mail/Transport/Factory/ActiveMailTransportFactory.php` | `EsmtpTransportFactory` | `esmtpTransports` | `esmtpTransportFactory` |
| `src/Service/Passkey/AttestationVerifier.php` | `UserPasskeyFactory` | `passkeys` | `passkeyFactory` |
| `src/Service/Recommendation/Run/RecommendationBatchWave.php`, `RecommendationProviderCall.php`, `RecommendationRunAdvancer.php` | `ProviderConnectionFactory` | `connections` | `connectionFactory` |
| `src/Service/Reader/DateLineRecognizer.php` | `DateFormatterFactoryInterface` | `formatters` | `formatterFactory` |

- [ ] **Step 2: The renames (property and its uses only, never a local variable of the same name)**

For each row, in each file:
```bash
perl -pi -e 's/(\b<Type> )\$<old>\b/$1\$<new>/g; s/\$this-><old>\b/\$this-><new>/g' <file>
```
e.g. `perl -pi -e 's/(\bProblemResponseFactory )\$responses\b/$1\$responseFactory/g; s/\$this->responses\b/\$this->responseFactory/g' src/EventListener/ApiExceptionListener.php`. For `RestoredFoundationFactory.php`'s two constructor parameters run both pairs.

Re-run Step 1's survey. Expected: nothing. Positive control: the survey at `origin/develop` (`git grep … origin/develop -- src | …`) still lists them.

No test builds one of these with named arguments. `git grep -n -E '(downloads|requests|oauthRedirect|responses|configurations|signupUsers|states|tags|feeds|rows|esmtpTransports|passkeys|connections|formatters): ' -- tests` prints 14 lines at `5dbc55d3`, and none is such an argument: comments, `@return array{…}` shapes, `tags: []` to `SubscriptionLine` (`RestoredFoundationFactoryTest:64`, `RestoreLoadPassTest:107`) and `tags:`/`feeds:` to `BackupInventoryModel` (`RestorePreviewerTest:180`, `:182`). Those lines are the grep's positive control. Run it again after the renames: a new line that passes one of the table's properties to its class's constructor gets the new name.

- [ ] **Step 3: CLAUDE.md**

In the "Names reveal intent" bullet, replace the line
```markdown
  `…ExceptionInterface`). If a name needs a comment to be understood, rename it.
```
with
```markdown
  `…ExceptionInterface`). A property holding a `…Factory` or `…FactoryInterface`
  ends in `Factory` too (`$tagFactory`, never `$tags`, which elsewhere names a
  repository). If a name needs a comment to be understood, rename it.
```

- [ ] **Step 4: Run, gates, commit**

Run: `bin/console cache:clear && bin/console lint:container && php bin/phpunit && composer check && composer md`
Expected: PASS.
```bash
git add src ../CLAUDE.md
git commit -m "refactor(#1169): every property holding a factory is named for it"
```

---

### Task G9: `ApiExceptionListener` runs after the exception is logged, by priority (D-20)

`ErrorListener::logKernelException` (priority 0) logs, and `ApiExceptionListener` (priority 0 by default) answers; its `setResponse()` stops propagation. Only registration order makes the log come first today. The ruling's pin is functional: the real kernel handles an `/api` request that throws, and the test sees both the log record and the problem+json answer.

**Files:**
- Modify: `src/EventListener/ApiExceptionListener.php`
- Test: `tests/EventListener/ApiExceptionIsLoggedAndAnsweredTest.php` (new)

- [ ] **Step 1: The test**

An unrouted path under `/api` throws `NotFoundHttpException` from the router listener (kernel.request, priority 32, before the firewall at 8), so the request needs no token. `ErrorListener` logs through the `request` channel, and the test container hands out `monolog.logger.<channel>` (as `ClientErrorChannelSourceTest` fetches `monolog.logger.client_errors`), so a Monolog `TestHandler` pushed onto that logger records exactly what `logKernelException` writes.

`tests/EventListener/ApiExceptionIsLoggedAndAnsweredTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApiExceptionIsLoggedAndAnsweredTest extends KernelTestCase
{
    public function testAnApiExceptionIsLoggedAndAnsweredAsProblemJson(): void
    {
        $kernel = self::bootKernel();
        $requestLogger = self::getContainer()->get('monolog.logger.request');
        self::assertInstanceOf(Logger::class, $requestLogger);
        $recorder = new TestHandler();
        $requestLogger->pushHandler($recorder);

        $response = $kernel->handle(Request::create('/api/no-such-route-1169'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertCount(
            1,
            self::uncaughtNotFound($recorder),
            'ErrorListener::logKernelException logged the exception the API answered',
        );
    }

    /** @return list<LogRecord> */
    private static function uncaughtNotFound(TestHandler $recorder): array
    {
        return array_values(array_filter(
            $recorder->getRecords(),
            static fn (LogRecord $record): bool => str_starts_with(
                $record->message,
                'Uncaught PHP Exception ' . NotFoundHttpException::class,
            ),
        ));
    }
}
```
Run: `php bin/phpunit tests/EventListener/ApiExceptionIsLoggedAndAnsweredTest.php`
Expected: PASS: registration order holds today, and the test pins it before the priority makes it explicit. If `get('monolog.logger.request')` throws because the service was removed or inlined, stop and report: the pin needs the channel logger ErrorListener really uses, not a stand-in.

- [ ] **Step 2: The explicit priority**

`ApiExceptionListener.php`: replace `#[AsEventListener(event: ExceptionEvent::class)]` with `#[AsEventListener(event: ExceptionEvent::class, priority: -64)]`. `-64` sits after `logKernelException` (0) and before `ErrorListener::onKernelException` (-128), which renders Symfony's HTML error page.

Run: `bin/console cache:clear && php bin/phpunit tests/EventListener tests/Http/Problem && bin/console debug:event-dispatcher kernel.exception`
Expected: PASS; the dispatcher lists `ErrorListener::logKernelException()` above `ApiExceptionListener::onKernelException()`.

- [ ] **Step 3: Deletion check**

Change `priority: -64` to `priority: 64`, then run `bin/console cache:clear && php bin/phpunit tests/EventListener/ApiExceptionIsLoggedAndAnsweredTest.php`. Expected: FAIL with `ErrorListener::logKernelException logged the exception the API answered` and `Failed asserting that actual size 0 matches expected size 1.` The answer came first and stopped propagation, so nothing logged the exception; the status and content-type assertions above it still passed. Quote the FAIL, restore `-64` by hand and clear the cache.

- [ ] **Step 4: Commit**

Run: `composer check`.
```bash
git add src/EventListener/ApiExceptionListener.php tests/EventListener/ApiExceptionIsLoggedAndAnsweredTest.php
git commit -m "refactor(#1169): the API exception listener answers after the exception is logged, by priority"
```

---

### Finishing PR G

- [ ] **Step 1: The gates** (Global Constraints); the MySQL leg matters for G3's boolean parameter. Expected: all green.
- [ ] **Step 2: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 3: /simplify**; commit `refactor(#1169): simplify pass` if anything changed.
- [ ] **Step 4: Final review (opus)**, attack points: each G task keeps behaviour (the reorder's 404, mark-read's scope, the parser's empty paths, the resolver's paths, the reader's kinds, the 304's schedule); no Infection ignore was added and one is gone; the property renames touched no local variable; the listener order is the dispatcher's, not registration's, and G9's test drives the real kernel (not a hand-built dispatcher); re-run G4's, G7's and G9's deletion checks and quote each FAIL; no closing keyword in a commit (mind `OwnedSubscriptions`' method names).
- [ ] **Step 5: Open the PR** (Appendix K) with `var/refactor-1169/pr-g-body.md`:
```markdown
Refs #1169 (PR G of eight).

- `PositionReorderer::reorderFound()` looks the requested ids up once for both catalog editors.
- `OwnedSubscriptions` takes the owner first, like every other user-scoped lookup.
- Mark-all-read with scope "all" selects only the All-items subscriptions in SQL instead of loading every subscription with its tags.
- `HtmlDocumentParser::parseOrEmpty()` hands back an empty document for blank or unreadable HTML, and `parseOrNull()` is gone. One edge moves: an unreadable, non-blank page reaches `WordPressRestProbe`'s fingerprint check on its raw text; the parser throws only on input it cannot read at all.
- `UrlResolver` drops the last path segment instead of casting `strrpos()`, `BackupReader` maps body kinds to line classes (its Infection ignore is gone), and a 304 is `FeedScheduler::recordNotModified()`: three equivalent mutants gone without an ignore.
- Every property holding a factory is named for it.
- `ApiExceptionListener` runs at priority -64, after the exception is logged, instead of relying on registration order. A functional test drives an unrouted `/api` request through the kernel and sees both the `ErrorListener` log record and the problem+json answer.

No wire change.
```
Title: `refactor(#1169): one reorder lookup, owner-first ids, typed results`.
- [ ] **Step 6: Merge when green**, then check #1169 is `OPEN`.

---

# PR H — Homes of shared values; the Recommendation suppressions go

### Task H0: Preflight (PR G merged)

- [ ] **Step 1: Branch (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
git log --oneline origin/develop | grep -m1 "answers after the exception is logged"
git switch -c refactor/1169-value-homes origin/develop
mkdir -p backend/var/refactor-1169
```
Expected: one log line.

---

### Task H1: `ViewerTimeZoneModel` moves to `Service/Clock`; `Reading → Recommendation` is a boundary (D-15)

`Service/Reading` imports `Recommendation\Feed\Model\ViewerTimeZoneModel` and nothing else from `Recommendation`. `Service/Clock` is a leaf module (`NaiveUtcClock`) both may use.

**Files:**
- Move (by the script): `ViewerTimeZoneModel` and its test
- Modify: `tests/PhpStan/ServiceModuleBoundaryRule.php`, `ServiceModuleBoundaryRuleTest.php`, `data/service-module-boundary-fixtures.php`, `docs/architecture.md`

- [ ] **Step 1: The move**

`var/refactor-1169/viewer-time-zone.php`:
```php
<?php

declare(strict_types=1);

return [
    'App\Service\Recommendation\Feed\Model\ViewerTimeZoneModel' => 'App\Service\Clock\Model\ViewerTimeZoneModel',
    'App\Tests\Service\Recommendation\Feed\Model\ViewerTimeZoneModelTest'
        => 'App\Tests\Service\Clock\Model\ViewerTimeZoneModelTest',
];
```
```bash
php ../docs/superpowers/plans/2026-09-28-1202-scripts/move-classes.php var/refactor-1169/viewer-time-zone.php
find src tests -type d -empty -delete
bin/console cache:clear && bin/console cache:warmup
php ../docs/superpowers/plans/2026-09-28-1202-scripts/compare-moves.php var/refactor-1169/viewer-time-zone.php
git grep -n -E 'App\\Service\\Recommendation' -- src/Service/Reading
git grep -n -E 'App\\Service\\Clock' -- src/Service/Reading/ReadingActivityCounter.php
```
Expected: `Moved 2 classes (0 renamed); …`; `0 of 2 moved files differ in code.`; the first grep prints nothing (Reading no longer names Recommendation); the second prints the `NaiveUtcClock` import and the new `ViewerTimeZoneModel` one (positive control).

Run: `php bin/phpunit tests/Service/Reading tests/Service/Recommendation tests/Service/Clock tests/Controller/Api && composer stan`. Expected: PASS.

- [ ] **Step 2: The boundary (fixture and test first)**

```bash
wc -l < tests/PhpStan/data/service-module-boundary-fixtures.php
```
Expected: `60`. Append:
```php

namespace App\Service\Reading\Fixtures\TimeZone {
    final class ReadingKnowsRecommendations
    {
        public function zone(): string
        {
            return \App\Service\Recommendation\Feed\Model\ViewerTimeZoneModel::class;
        }
    }
}
```
(The `return` is line 67.) In `ServiceModuleBoundaryRuleTest`, add the constants
```php
    private const string READING = 'App\Service\Reading\Fixtures\TimeZone';
    private const string VIEWER_TIME_ZONE = 'App\Service\Recommendation\Feed\Model\ViewerTimeZoneModel';
    private const string RECOMMENDATION_IN_READING_REMEDY
        = 'Reading sits below recommendations; the viewer time zone lives in Service/Clock (#1169).';
```
and after the expectation ending `READER_IN_RECOMMENDATION_REMEDY), 24],` add:
```php
                [self::message(self::READING, self::VIEWER_TIME_ZONE, self::RECOMMENDATION_IN_READING_REMEDY), 67],
```
Run: `php bin/phpunit tests/PhpStan/ServiceModuleBoundaryRuleTest.php`. Expected: FAIL, the `67:` line missing.

`ServiceModuleBoundaryRule.php`, in `REMEDIES`, after the `'App\\Service\\Recommendation\\' => [ … ],` entry:
```php
        'App\\Service\\Reading\\' => [
            'App\\Service\\Recommendation\\' => 'Reading sits below recommendations; '
                . 'the viewer time zone lives in Service/Clock (#1169).',
        ],
```
Run the test and `composer stan`. Expected: PASS (the real `Reading` names no `Recommendation` class after Step 1).

Deletion checks, one at a time, restoring each by hand:
1. Delete the new `REMEDIES` entry. Expected: the rule test fails, the `67:` line missing.
2. Real tree: in `src/Service/Reading/ReadingActivityCounter.php`, add the constant `private const string PROBE = \App\Service\Recommendation\Feed\HistoryMonthSummariser::class;`. Expected: `composer stan` fails, `Service module boundary: App\Service\Reading references App\Service\Recommendation\Feed\HistoryMonthSummariser. Reading sits below recommendations; the viewer time zone lives in Service/Clock (#1169).` Delete the constant.

- [ ] **Step 3: §9, commit**

`docs/architecture.md` §9, replace
```markdown
- **Removed on purpose.** `Reader → Search` and `Recommendation → Reader` (#1163) closed no cycle, so the cycle rule
  would not stop them coming back; `ServiceModuleBoundaryRule` names them.
```
with
```markdown
- **Removed on purpose.** `Reader → Search` and `Recommendation → Reader` (#1163), and `Reading → Recommendation`
  (#1169: the viewer time zone moved to `Service/Clock`), closed no cycle, so the cycle rule would not stop them
  coming back; `ServiceModuleBoundaryRule` names them.
```
Run: `composer check && composer md`.
```bash
git add -A -- . ../docker ../.github ../CLAUDE.md ../docs/architecture.md
git status --short | grep -v '^[RMAD] ' && echo 'STOP: an unstaged or untracked change' || true
git commit -m "refactor(#1169): the viewer time zone lives in Service/Clock, and Reading may not name Recommendation"
```

---

### Task H2: The homes of shared enums and `SupportedLocale`, in §8 (D-12, D-13)

No class moves. `TickDriver`, `RecommendationDriverKind`, `ScrapeFailureReason` and `VisualMediaKind` stay in the module that owns their meaning; `SupportedLocale` stays in `App\Enum`. §8 turns "for now" into the rule and records why.

**Files:**
- Modify: `docs/architecture.md`, `src/Enum/SupportedLocale.php`

- [ ] **Step 1: §8**

Replace
```markdown
  never touch an entity. Module enums, including ones several `Service/*` modules share, stay in their owning module
  for now (`ScrapeFallback`, `SocksReplyCode`, `CatalogImportMode`, `CommentsStatus`, `TickDriver`,
  `RecommendationDriverKind`, `ScrapeFailureReason`, `VisualMediaKind`).
```
with
```markdown
  never touch an entity: it also holds the value sets a stored column is limited to when no entity type names them
  (`SupportedLocale`, the locales `User::$locale` may hold, which the request DTO, `translation.yaml` and the signup
  factory all read; `SourceFormat`). Module enums, including ones several `Service/*` modules share, stay in the
  module that owns their meaning (`ScrapeFallback`, `SocksReplyCode`, `CatalogImportMode`, `CommentsStatus`,
  `TickDriver`, `RecommendationDriverKind`, `ScrapeFailureReason`, `VisualMediaKind`): `ServiceModuleCycleRule`
  guarantees sharing one closes no cycle, and an enum that returns a Service value (`TickDriver::retryPlan()`)
  could not move below the services anyway (#1169).
```

- [ ] **Step 2: `SupportedLocale`'s docblock names what it limits**

In `src/Enum/SupportedLocale.php`, replace ` * The locales the UI ships translations for. A constants holder rather than a` with ` * The locales the UI ships translations for, and so the values User::$locale may hold. A constants holder rather than a` if the line stays within 120 columns; otherwise break it after `hold.` and re-wrap the rest of that paragraph to 120 columns, words unchanged.

- [ ] **Step 3: Commit**

```bash
git add ../docs/architecture.md src/Enum/SupportedLocale.php
git commit -m "refactor(#1169): §8 rules where shared module enums and stored value sets live"
```

---

### Task H3: Repositories name only Service values (D-14)

`PersistenceKnowsNoServiceRule` gains a scope for `App\Repository`: a repository may name a Service class in a `Model/`, `Support/` or `Exception/` folder, or an `…Interface` (the inversion `SavedSearchMembershipWriterInterface` and PR A's eleven use), nothing else. `EntryBatchInserter` is the allow-listed exception.

**Files:**
- Create: `tests/PhpStan/data/persistence-knows-no-service/repository-names.php`, `tests/PhpStan/data/persistence-knows-no-service/EntryBatchInserter.php`
- Modify: `tests/PhpStan/PersistenceKnowsNoServiceRule.php`, `tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php`, `docs/architecture.md`, `CLAUDE.md`

**Interfaces:**
- Produces: `PersistenceKnowsNoServiceRule::__construct(NodeFinder $finder, list<string> $repositoryAllowList = self::REPOSITORY_ALLOW_LIST)` (the list is overridable only for the rule's own test, as `QueriesLiveInRepositoriesRule`'s is); a second message, identifier unchanged: `Repositories name only Service values: <namespace> references <name>. Hand the repository a model, a helper's result or an interface it implements (docs/architecture.md §8).`

- [ ] **Step 1: The fixtures**

`tests/PhpStan/data/persistence-knows-no-service/repository-names.php` (the line numbers matter):
```php
<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Repository\Fixtures {
    use App\Service\Search\Model\SearchTermsModel;
    use App\Service\Search\Support\LikePattern;
    use App\Service\Search\Membership\SavedSearchMembershipWriter\SavedSearchMembershipWriterInterface;
    use App\Service\Reader\Exception\ArticleNotExtractedException;
    use App\Service\Url\UrlNormalizer;
    use App\Service\Backup\Dto\EntryLine;

    final class SpeaksServiceValues
    {
        public function __construct(public SearchTermsModel $terms, public UrlNormalizer $normalizer)
        {
        }

        public function line(): string
        {
            return EntryLine::class;
        }
    }
}
```

`tests/PhpStan/data/persistence-knows-no-service/EntryBatchInserter.php`:
```php
<?php

declare(strict_types=1);

// Fixtures for PersistenceKnowsNoServiceRuleTest, excluded from `composer stan` like everything in this directory.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Repository\Fixtures;

use App\Service\Backup\Dto\EntryLine;

final readonly class EntryBatchInserter
{
    public function line(): string
    {
        return EntryLine::class;
    }
}
```

- [ ] **Step 2: The failing test**

In `PersistenceKnowsNoServiceRuleTest`:
- Replace `getRule()`'s body with `        return new PersistenceKnowsNoServiceRule(new NodeFinder(), ['App\Repository\Fixtures\EntryBatchInserter']);`.
- Add after the existing test:
```php
    public function testARepositoryNamesOnlyServiceValuesExceptWhereAllowed(): void
    {
        $this->analyse(
            [
                __DIR__ . '/data/persistence-knows-no-service/repository-names.php',
                __DIR__ . '/data/persistence-knows-no-service/EntryBatchInserter.php',
            ],
            [
                [self::repositoryMessage('App\Service\Url\UrlNormalizer'), 13],
                [self::repositoryMessage('App\Service\Backup\Dto\EntryLine'), 14],
                [self::repositoryMessage('App\Service\Url\UrlNormalizer'), 18],
                [self::repositoryMessage('App\Service\Backup\Dto\EntryLine'), 24],
            ],
        );
    }

    private static function repositoryMessage(string $reference): string
    {
        return sprintf(
            'Repositories name only Service values: App\Repository\Fixtures references %s. '
            . 'Hand the repository a model, a helper\'s result or an interface it implements (docs/architecture.md §8).',
            $reference,
        );
    }
```
Run: `php bin/phpunit tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php`. Expected: FAIL: the constructor rejects the second argument (`… 2 passed …` or an unknown-parameter error), or, if PHP accepts it, the four lines are missing.

- [ ] **Step 3: The rule**

In `PersistenceKnowsNoServiceRule.php` (as PR F left it: `processNode()` maps `forbiddenInFile()` onto `error()`):
- Add after `REMEDIES`:
```php
    private const string REPOSITORY_NAMESPACE = 'App\\Repository\\';

    /** Service roles a query may speak: domain values, static helpers, typed exceptions (and any …Interface). */
    private const array REPOSITORY_MAY_NAME = ['\\Model\\', '\\Support\\', '\\Exception\\'];

    /** The restore's bulk insert reads the backup line format and hashes URLs itself: 14x the ORM (spec appendix). */
    private const array REPOSITORY_ALLOW_LIST = ['App\\Repository\\EntryBatchInserter'];

    private const string NAME_ONLY_VALUES = 'Hand the repository a model, a helper\'s result or an interface it '
        . 'implements (docs/architecture.md §8).';
```
- Replace the constructor with:
```php
    /** @param list<string> $repositoryAllowList class names; overridable only for the rule's own test */
    public function __construct(NodeFinder $finder, private array $repositoryAllowList = self::REPOSITORY_ALLOW_LIST)
    {
        $this->references = new ClassNameReferences($finder, array_keys(self::REMEDIES));
    }
```
(`private ClassNameReferences $references;` is declared above it; the class is `final readonly`, so the promoted list is readonly too.)
- Replace `processNode()`'s body with:
```php
        return [
            ...array_map(
                self::error(...),
                $this->references->forbiddenInFile($node, self::PERSISTENCE_NAMESPACES),
            ),
            ...array_map(
                self::repositoryError(...),
                $this->forbiddenInRepository($node, basename($scope->getFile(), '.php')),
            ),
        ];
```
- Add:
```php
    /** @return list<ForbiddenReference> */
    private function forbiddenInRepository(FileNode $file, string $fileClassName): array
    {
        $forbidden = [];
        foreach ($this->references->forbiddenInFile($file, [self::REPOSITORY_NAMESPACE]) as $reference) {
            $className = $reference->inNamespace . '\\' . $fileClassName;
            if (!\in_array($className, $this->repositoryAllowList, true) && !self::isServiceValue($reference->name)) {
                $forbidden[] = $reference;
            }
        }

        return $forbidden;
    }

    private static function isServiceValue(string $name): bool
    {
        return str_ends_with($name, 'Interface')
            || array_any(self::REPOSITORY_MAY_NAME, static fn (string $segment): bool => str_contains($name, $segment));
    }

    private static function repositoryError(ForbiddenReference $reference): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'Repositories name only Service values: %s references %s. %s',
            $reference->inNamespace,
            $reference->name,
            self::NAME_ONLY_VALUES,
        ))
            ->identifier('simpleFeedReader.persistenceKnowsNoService')
            ->line($reference->line)
            ->build();
    }
```
- In the class docblock, append the sentence `Repositories may name Service values and the interfaces they implement, nothing else.` to its first paragraph (re-wrap to 120 columns).

Run: `php bin/phpunit tests/PhpStan/PersistenceKnowsNoServiceRuleTest.php && composer stan`
Expected: PASS: every real repository import is a model, a helper, an interface or `EntryBatchInserter`'s (`CategoryRepository`, `DatabaseSavedSearchMatcher`, `EntrySearchQuery`, `ReaderAuditRepository`, `RecommendationCallRepository`, `RecommendationRunHistoryRepository`, `SavedSearchEntryMembershipRepository`, `SearchTermsPredicateBuilder` and PR A's eleven implementers). A real error names a repository this survey missed: report it before changing anything.

- [ ] **Step 4: Deletion checks**

One at a time, restoring each by hand:
1. Remove `'\\Model\\', ` from `REPOSITORY_MAY_NAME`. Expected: the new test fails with extra lines `9:` and `18:` naming `App\Service\Search\Model\SearchTermsModel`.
2. Replace `!\in_array($className, $this->repositoryAllowList, true) && ` with nothing. Expected: the new test fails with extra lines `10:` and `16:` for the `EntryBatchInserter` fixture.
3. Real tree: remove `'App\\Repository\\EntryBatchInserter'` from `REPOSITORY_ALLOW_LIST`. Expected: `composer stan` fails with `Repositories name only Service values: App\Repository references App\Service\Backup\Dto\EntryLine. …` and the same for `App\Service\Url\UrlNormalizer`.

- [ ] **Step 5: Docs and commit**

`docs/architecture.md` §8: replace
```markdown
Repository → Service value imports (`SearchTermsModel`, `LikePattern`, `NormalizedCategoryModel`,
`MonthWindowModel`, `CompletionUsageModel`…) are an open question; the rule below does not check `App\Repository`.
```
with
```markdown
A repository speaks the domain's values: it may name a Service `Model/`, `Support/` or `Exception/` class
(`SearchTermsModel`, `LikePattern`, `MonthWindowModel`) and the `…Interface` it implements, never a service, a DTO
or a per-call object. `EntryBatchInserter` is the one exception: the restore's bulk insert reads the backup's line
format and hashes each URL itself, 14 times faster than going through the ORM (#1169).
```
and in the "Enforced by" sentence replace `` (no `App\Service` in `App\Entity`, `App\Enum` or `App\Doctrine`)`` with `` (no `App\Service` in `App\Entity`, `App\Enum` or `App\Doctrine`; only Service values in `App\Repository`)``.

CLAUDE.md, "Shared values have one home" bullet: after `…so persistence never imports a service:`, the sentence stands; append at the bullet's end: `A repository names only Service values (a model, a helper, an exception) and the interfaces it implements.`
```bash
git add tests/PhpStan ../docs/architecture.md ../CLAUDE.md
git commit -m "refactor(#1169): repositories name only Service values, and the persistence rule checks it"
```

---

### Task H4: `RecommendationHistoryCaps` and `RecommendationPoolLimits`; two suppressions go (D-11)

`RecommendationSettingsValues` takes 13 constructor parameters and `EffectiveRecommendationSettingsModel` 12; PHPMD's `ExcessiveParameterList` fires at 10. Two values that belong together, the three history caps and the three pool limits, become one parameter each.

**Files:**
- Create: `src/Entity/RecommendationHistoryCaps.php`, `src/Entity/RecommendationPoolLimits.php`
- Modify: `src/Entity/RecommendationSettingsValues.php`, `src/Entity/RecommendationSettings.php`, `src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php`, `src/Service/Recommendation/Settings/RecommendationSettingsResolver.php`, `src/Service/Recommendation/Settings/RecommendationSettingsWriter.php`, `src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`, `src/Http/RecommendationSettingsJson.php`, `src/Service/Recommendation/Prompt/RecommendationHistoryLoader.php`, `src/Service/Recommendation/Prompt/RecommendationPromptBuilder.php`, `src/Service/Recommendation/Run/RecommendationRunFinalizer.php`, `src/Service/Recommendation/Run/SnapshotPhase.php`
- Test: every test that builds or reads the two values (Step 4)

**Interfaces:**
- Produces:
  - `App\Entity\RecommendationHistoryCaps(int $favorites, int $kept, int $viewed)`, `::defaults(): self`
  - `App\Entity\RecommendationPoolLimits(int $candidatePoolSize, int $lookbackDays, int $picksLimit)`, `::defaults(): self`
  - `RecommendationSettingsValues::__construct(?string $guidancePrompt, RecommendationHistoryCaps $historyCaps, RecommendationPoolLimits $poolLimits, ?int $contextWindow, RecommendationBatchSize $batchSize, bool $debugEnabled, ?int $autoGenerateIntervalHours = null, ?string $profileText = null, bool $showReasons = false)` (9)
  - `EffectiveRecommendationSettingsModel::__construct(?string $guidancePrompt, RecommendationHistoryCaps $historyCaps, RecommendationPoolLimits $poolLimits, RecommendationPackingSettingsModel $packing, bool $debugEnabled, ?int $autoGenerateIntervalHours = null, ?string $profileText = null, bool $showReasons = false)` (8)
- The JSON wire shape is unchanged: `RecommendationSettingsJson` still writes `favoritesCap`, `keptCap`, `viewedCap`, `candidatePoolSize`, `lookbackDays`, `picksLimit`.

- [ ] **Step 1: The two values**

`src/Entity/RecommendationHistoryCaps.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** How many of the reader's favorite, kept and viewed posts a recommendation prompt carries. */
final readonly class RecommendationHistoryCaps
{
    public function __construct(
        public int $favorites,
        public int $kept,
        public int $viewed,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            RecommendationSettings::DEFAULT_FAVORITES_CAP,
            RecommendationSettings::DEFAULT_KEPT_CAP,
            RecommendationSettings::DEFAULT_VIEWED_CAP,
        );
    }
}
```

`src/Entity/RecommendationPoolLimits.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

/** How large the candidate pool is, how far back it reaches, and how many picks a run keeps. */
final readonly class RecommendationPoolLimits
{
    public function __construct(
        public int $candidatePoolSize,
        /** How many days back the candidate pool reaches, counted as N x 24 h from the snapshot instant. */
        public int $lookbackDays,
        public int $picksLimit,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            RecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            RecommendationSettings::DEFAULT_LOOKBACK_DAYS,
            RecommendationSettings::DEFAULT_PICKS_LIMIT,
        );
    }
}
```

- [ ] **Step 2: The two carriers take them; the suppressions go**

`RecommendationSettingsValues.php`: replace the six parameters `public int $favoritesCap,` … `public int $picksLimit,` with
```php
        public RecommendationHistoryCaps $historyCaps,
        public RecommendationPoolLimits $poolLimits,
```
and delete the docblock lines ` *` and ` * @SuppressWarnings("PHPMD.ExcessiveParameterList") a data carrier that mirrors the row 1:1`.

`EffectiveRecommendationSettingsModel.php`: replace the parameters from `public int $favoritesCap,` through `public int $picksLimit,` (with the `lookbackDays` docblock, which moved to `RecommendationPoolLimits`) with
```php
        public RecommendationHistoryCaps $historyCaps,
        public RecommendationPoolLimits $poolLimits,
```
import both, and delete the docblock lines from ` *` before ` * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that` through ` * method.`.

- [ ] **Step 3: The readers and writers**

| File | Replace | With |
|---|---|---|
| `Entity/RecommendationSettings.php` `update()` | `$this->favoritesCap = $values->favoritesCap;` / `keptCap` / `viewedCap` | `$this->favoritesCap = $values->historyCaps->favorites;` / `->kept` / `->viewed` |
| same | `$this->candidatePoolSize = $values->candidatePoolSize;` / `lookbackDays` / `picksLimit` | `$this->candidatePoolSize = $values->poolLimits->candidatePoolSize;` / `->lookbackDays` / `->picksLimit` |
| `Entity/RecommendationSettings.php` `values()` | the six named arguments `favoritesCap: $this->favoritesCap,` … `picksLimit: $this->picksLimit,` | `historyCaps: new RecommendationHistoryCaps($this->favoritesCap, $this->keptCap, $this->viewedCap),` and `poolLimits: new RecommendationPoolLimits($this->candidatePoolSize, $this->lookbackDays, $this->picksLimit),` |
| `Dto/Recommendation/SaveRecommendationSettingsRequest.php` `values()` | `favoritesCap: $this->favoritesCap,` … `picksLimit: $this->picksLimit,` | the same two lines as `values()` above (the DTO keeps its flat, validated fields) |
| `RecommendationSettingsResolver.php` | the named arguments `favoritesCap:` … `picksLimit: $row?->values()->picksLimit ?? RecommendationSettings::DEFAULT_PICKS_LIMIT,` (lines 43–50 at `5dbc55d3`) | `historyCaps: $row?->values()->historyCaps ?? RecommendationHistoryCaps::defaults(),` and `poolLimits: $row?->values()->poolLimits ?? RecommendationPoolLimits::defaults(),` |
| `RecommendationSettingsWriter.php` (twice) | `favoritesCap: $values->favoritesCap,` … `picksLimit: $values->picksLimit,` | `historyCaps: $values->historyCaps,` and `poolLimits: $values->poolLimits,` |
| `Http/RecommendationSettingsJson.php` | `$effective->favoritesCap`, `->keptCap`, `->viewedCap` | `$effective->historyCaps->favorites`, `->historyCaps->kept`, `->historyCaps->viewed` |
| same | `$effective->candidatePoolSize`, `->lookbackDays`, `->picksLimit` | `$effective->poolLimits->candidatePoolSize`, `->poolLimits->lookbackDays`, `->poolLimits->picksLimit` |
| `Prompt/RecommendationHistoryLoader.php` | `$settings->favoritesCap`, `$settings->keptCap`, `$settings->viewedCap` | `$settings->historyCaps->favorites`, `->historyCaps->kept`, `->historyCaps->viewed` |
| `Prompt/RecommendationPromptBuilder.php` | `$context->settings->picksLimit` | `$context->settings->poolLimits->picksLimit` |
| `Run/RecommendationRunFinalizer.php` | `->forUser($run->getUser())->picksLimit)` | `->forUser($run->getUser())->poolLimits->picksLimit)` |
| `Run/SnapshotPhase.php` | `$tick->settings->lookbackDays` and `$tick->settings->candidatePoolSize` | `$tick->settings->poolLimits->lookbackDays` and `$tick->settings->poolLimits->candidatePoolSize` |

Import the two values where they are named. (`RecommendationSettingsResolver`'s `RecommendationSettings` import may now be unused: delete it if PhpStorm says so.)

```bash
git grep -n -E -e '->(favoritesCap|keptCap|viewedCap|candidatePoolSize|lookbackDays|picksLimit)' -- src | grep -v -E 'Dto/Recommendation/SaveRecommendationSettingsRequest|Entity/RecommendationSettings\.php|->poolLimits->|RecommendationPoolLimits'
```
Expected: nothing. (The DTO and the entity keep flat fields of those names.) Positive control: the same pipeline over `origin/develop` (`git grep -n -E -e '->(favoritesCap|keptCap|viewedCap|candidatePoolSize|lookbackDays|picksLimit)' origin/develop -- src | grep -v …` with the same filter) prints the old reads.

- [ ] **Step 4: The tests**

Constructions: `FILES=$(git grep -l -E 'new (RecommendationSettingsValues|EffectiveRecommendationSettingsModel)\(' -- tests)` (sixteen files at `5dbc55d3`, `tests/Support/RecommendationRunFixtures.php` among them). All use named arguments with the three caps and the three pool fields adjacent:
```bash
perl -0pi -e 's/favoritesCap: ([^,\n]+),\s*keptCap: ([^,\n]+),\s*viewedCap: ([^,\n]+),/historyCaps: new RecommendationHistoryCaps($1, $2, $3),/g; s/candidatePoolSize: ([^,\n]+),\s*lookbackDays: ([^,\n]+),\s*picksLimit: ([^,\n]+),/poolLimits: new RecommendationPoolLimits($1, $2, $3),/g' $FILES
git grep -n -E '(favoritesCap|keptCap|viewedCap|candidatePoolSize|lookbackDays|picksLimit): ' -- $FILES
```
Expected: the grep lists only arguments to a `SaveRecommendationSettingsRequest` (flat, unchanged: restore any the perl rewrote inside one) or a call whose fields were not adjacent (rewrite it by hand). Add `use App\Entity\RecommendationHistoryCaps;` and `use App\Entity\RecommendationPoolLimits;` to each file the perl changed.

Reads: in `RecommendationSettingsResolverTest`, `RecommendationSettingsRoundTripTest` and `RecommendationSettingsWriterTest`:
```bash
perl -pi -e 's/->favoritesCap\b/->historyCaps->favorites/g; s/->keptCap\b/->historyCaps->kept/g; s/->viewedCap\b/->historyCaps->viewed/g; s/->candidatePoolSize\b/->poolLimits->candidatePoolSize/g; s/->lookbackDays\b/->poolLimits->lookbackDays/g; s/->picksLimit\b/->poolLimits->picksLimit/g' tests/Service/Recommendation/Settings/RecommendationSettingsResolverTest.php tests/Service/Recommendation/Settings/RecommendationSettingsRoundTripTest.php tests/Service/Recommendation/Settings/RecommendationSettingsWriterTest.php
```

- [ ] **Step 5: Run and PHPMD**

Run: `bin/console cache:clear && php bin/phpunit tests/Entity tests/Http tests/Service/Recommendation tests/Service/Account tests/Service/Backup tests/Service/Worker tests/Command tests/Controller/Api && composer stan && composer md`
Expected: PASS; `composer md` reports nothing for the two files, and `git grep -n 'ExcessiveParameterList' -- src/Entity/RecommendationSettingsValues.php src/Service/Recommendation/Settings/Model/EffectiveRecommendationSettingsModel.php` prints nothing, while the same grep at `origin/develop` prints the two suppression lines (positive control).

Deletion check (the resolver now relies on `defaults()` when no row exists): in `RecommendationHistoryCaps::defaults()`, replace `RecommendationSettings::DEFAULT_FAVORITES_CAP` with `RecommendationSettings::DEFAULT_VIEWED_CAP`. Expected: `RecommendationSettingsResolverTest`'s no-row case (its `self::assertSame(40, $effective->historyCaps->favorites);`) fails, `Failed asserting that 80 is identical to 40.` Restore by hand.

- [ ] **Step 6: Commit**

Run: `composer check`, then the PhpStorm inspections.
```bash
git add src tests
git commit -m "refactor(#1169): the recommendation settings group their history caps and pool limits; two PHPMD suppressions go"
```

---

### Task H5: `RecommendationRun` below PHPMD's method ceiling; the suppression goes (D-10)

PHPMD counts 17 public methods outside its `get`/`set`/`is`/`has`/`with` pattern, the constructor among them, and reports a class above 10. Three are queries, which take honest `get…`/`is…` names. Four transitions on the throttle and the call attempts go through two accessors that guard "running" first, so the aggregate keeps its invariant, and the parts they hand out carry their own transitions (D-10's condition). `stampProvider()` is a command and keeps its name: renaming it `setProvider()` only to leave PHPMD's count would game the metric (planner ruling). That leaves 10: `__construct`, `snapshot`, `recordBatchWinners`, `markFirstBatchStarted`, `recordProfile`, `stampProvider`, `complete`, `fail`, `cancel`, `resume`.

**Files:**
- Modify: `src/Entity/RecommendationRun.php`, `src/Service/Recommendation/Run/InvalidReplyRetry.php`, `Model/RecommendationRunReportModel.php`, `ProviderPhase/BatchPhase.php`, `RecommendationRunDeferral.php`, `RecommendationTransportFailureRecorder.php`, `RecommendationWaveConcurrency.php`, `TickPhases.php`, `WaveContextLoader.php`
- Test: `tests/Entity/RecommendationRunTest.php` (new cases), and the call sites in `RecommendationRunAdvancerTest`, `AdvanceRecommendationRunsHandlerTest`, `RecommendationDebugLogControllerTest` (`RecommendationRunHistoryControllerTest` and `RecommendationRunStarterTest` call only `stampProvider()`, which keeps its name)

**Interfaces:**
- Produces: `getProgress(): RecommendationRunProgress` (was `progress()`), `isRetryDeferredAt(\DateTimeImmutable $now): bool` (was `mustWaitBeforeRetry()`), `getWaveConcurrencyCap(int $configuredCap): int` (was `waveConcurrencyCap()`), `getRunningThrottle(): RunThrottle`, `getRunningCallAttempts(): RunCallAttempts`. `stampProvider()` is unchanged. Gone: `recordInvalidReply()`, `recordTransportFailure()`, `deferRetryUntil()`, `reduceWaveConcurrency()` (callers use `RunCallAttempts::recordInvalidReply()`, `recordTransportFailure()`, `RunThrottle::deferUntil()`, `reduceConcurrency()` through the accessors).

- [ ] **Step 1: The failing tests**

In `tests/Entity/RecommendationRunTest.php`, add:
```php
    public function testAPendingRunHandsOutNoThrottle(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot throttle a recommendation run from status "pending".');

        $this->pendingRun()->getRunningThrottle();
    }

    public function testAPendingRunHandsOutNoCallAttempts(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot record a call attempt on a recommendation run from status "pending".');

        $this->pendingRun()->getRunningCallAttempts();
    }

    public function testARunningRunDefersThroughItsThrottle(): void
    {
        $run = $this->pendingRun();
        $run->snapshot([[1, 2]]);
        $until = new \DateTimeImmutable('2026-07-01 12:05:00');

        $run->getRunningThrottle()->deferUntil($until);

        self::assertTrue($run->isRetryDeferredAt(new \DateTimeImmutable('2026-07-01 12:00:00')));
        self::assertSame($until, $run->getRetryNotBefore());
    }

    private function pendingRun(): RecommendationRun
    {
        $createdAt = new \DateTimeImmutable('2026-07-01 10:00:00');

        return new RecommendationRun(new User('run-guard@example.com', $createdAt), $createdAt);
    }
```
(If the file already has a helper that builds a pending run, use it instead of `pendingRun()`. `RunThrottle` stores and returns the instant it is given, so `assertSame` holds.)

Run: `php bin/phpunit tests/Entity/RecommendationRunTest.php`. Expected: FAIL, `Call to undefined method App\Entity\RecommendationRun::getRunningThrottle()` and `…::getRunningCallAttempts()`.

- [ ] **Step 2: The entity**

In `src/Entity/RecommendationRun.php`:
- Delete the docblock paragraph from ` * The public surface sits over PHPMD's ten-method ceiling, accepted by the` through ` * finding keeps pointing at.`, the ` *` line before it, and the lines ` *` and ` * @SuppressWarnings("PHPMD.TooManyPublicMethods")`.
- Rename the three queries: `public function progress(): RecommendationRunProgress` → `public function getProgress(): RecommendationRunProgress`; in `complete()`, `$this->progress()->batchesTotal` → `$this->getProgress()->batchesTotal`; `public function mustWaitBeforeRetry(` → `public function isRetryDeferredAt(`; `public function waveConcurrencyCap(` → `public function getWaveConcurrencyCap(`. Each name says what the method answers; no command is renamed.
- Delete `recordInvalidReply()`, `recordTransportFailure()`, `deferRetryUntil()` and `reduceWaveConcurrency()`, each with the blank line before it.
- After `getLastInvalidReply()`, add:
```php

    /** The call attempts of a running run: an unusable reply or a transport failure is recorded through them. */
    public function getRunningCallAttempts(): RunCallAttempts
    {
        $this->guardStatus(RunStatus::Running, 'record a call attempt on');

        return $this->callAttempts;
    }
```
- After `getRetryNotBefore()`, add:
```php

    /** The throttle of a running run: a rate limit defers it and narrows the next wave through it. */
    public function getRunningThrottle(): RunThrottle
    {
        $this->guardStatus(RunStatus::Running, 'throttle');

        return $this->throttle;
    }
```

- [ ] **Step 3: The callers**

| File | Replace | With |
|---|---|---|
| `InvalidReplyRetry.php` | `$run->recordInvalidReply($invalidReply);` | `$run->getRunningCallAttempts()->recordInvalidReply($invalidReply);` |
| `InvalidReplyRetry.php` | `$run->progress()` | `$run->getProgress()` |
| `RecommendationTransportFailureRecorder.php` | `$run->recordTransportFailure();` | `$run->getRunningCallAttempts()->recordTransportFailure();` |
| `RecommendationRunDeferral.php` | `$run->deferRetryUntil(` | `$run->getRunningThrottle()->deferUntil(` |
| `RecommendationWaveConcurrency.php` | `$run->reduceWaveConcurrency(` | `$run->getRunningThrottle()->reduceConcurrency(` |
| `RecommendationWaveConcurrency.php` | `$run->waveConcurrencyCap(` | `$run->getWaveConcurrencyCap(` |
| `TickPhases.php` | `$run->mustWaitBeforeRetry(` | `$run->isRetryDeferredAt(` |
| `TickPhases.php`, `Model/RecommendationRunReportModel.php` | `$run->progress()` | `$run->getProgress()` |
| `ProviderPhase/BatchPhase.php`, `WaveContextLoader.php` | `$tick->run->progress()` | `$tick->run->getProgress()` |
```bash
git grep -n -E -e '->(progress\(\)|mustWaitBeforeRetry|waveConcurrencyCap|deferRetryUntil|reduceWaveConcurrency)' -- src
git grep -n -E -e '\$(run|latest|tick->run)->(recordInvalidReply|recordTransportFailure)\(' -- src
git grep -n -E -e '\$run->stampProvider\(' -- src
git grep -n -E 'public function set[A-Z]' -- src/Entity/RunThrottle.php src/Entity/RunCallAttempts.php
git grep -c -E 'public function set[A-Z]' -- src/Entity/Feed.php
```
Expected: the first two print nothing (the embeddables' own `recordInvalidReply()` calls inside `RecommendationRun` are gone with the four methods); the third prints the one `RecommendationRunStarter` line (the command keeps its name, and it is this set's positive control). The fourth prints nothing: neither part the accessors hand out has a setter. Each public method is a transition that keeps the part's own invariant (`deferUntil()`, `reduceConcurrency()` flooring at 1, `recordInvalidReply()` writing the count and the reply together, `recordTransportFailure()`, the checkpoint resets) or a query, never raw state to set field by field (D-10's condition). The fifth is that grep's positive control and prints `src/Entity/Feed.php:7` (seven setters at `5dbc55d3`).

- [ ] **Step 4: The tests' call sites**

```bash
FILES="tests/Entity/RecommendationRunTest.php tests/Service/Recommendation/Run/RecommendationRunAdvancerTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Controller/Api/RecommendationDebugLogControllerTest.php"
perl -pi -e 's/->progress\(\)/->getProgress()/g; s/->mustWaitBeforeRetry\(/->isRetryDeferredAt(/g; s/->waveConcurrencyCap\(/->getWaveConcurrencyCap(/g; s/->recordInvalidReply\(/->getRunningCallAttempts()->recordInvalidReply(/g; s/->recordTransportFailure\(\)/->getRunningCallAttempts()->recordTransportFailure()/g; s/->deferRetryUntil\(/->getRunningThrottle()->deferUntil(/g; s/->reduceWaveConcurrency\(/->getRunningThrottle()->reduceConcurrency(/g' $FILES
```
`tests/Entity/RunCallAttemptsTest.php` is not in the list: it calls the embeddable directly and keeps its calls. A test expecting a `LogicException` message from one of the four removed transitions now expects `Cannot record a call attempt on a recommendation run from status "…".` or `Cannot throttle a recommendation run from status "…".`; `git grep -n -E "Cannot (recordInvalidReply|recordTransportFailure|defer a recommendation run|reduce the wave concurrency of)" -- tests` lists them (nothing at `5dbc55d3`).

- [ ] **Step 5: Run, PHPMD, deletion checks**

Run: `bin/console cache:clear && php bin/phpunit tests/Entity tests/Service/Recommendation tests/Service/Worker tests/Controller/Api tests/Command && composer stan && composer md`
Expected: PASS; `composer md` reports nothing for `RecommendationRun.php`: 10 counted public methods, PHPMD's ceiling. If it still reports `TooManyPublicMethods`, stop and report to the planner. Do not rename a command to get under it.

Deletion checks, one at a time, restoring each by hand:
1. Delete `$this->guardStatus(RunStatus::Running, 'throttle');` from `getRunningThrottle()`. Expected: `testAPendingRunHandsOutNoThrottle` fails, `Failed asserting that exception of type "LogicException" is thrown.`
2. Delete `$this->guardStatus(RunStatus::Running, 'record a call attempt on');` from `getRunningCallAttempts()`. Expected: `testAPendingRunHandsOutNoCallAttempts` fails the same way.

- [ ] **Step 6: Commit**

Run: `composer check`, then the PhpStorm inspections.
```bash
git add src tests
git commit -m "refactor(#1169): RecommendationRun guards its throttle and call attempts behind two accessors; its PHPMD suppression goes"
```

---

### Task H6: CLAUDE.md and the closing sweep

**Files:**
- Modify: `CLAUDE.md` (only if Step 1 finds a gap)

- [ ] **Step 1: Every Scope row is done**

```bash
git grep -n -E 'SuppressWarnings\("PHPMD' -- src
git grep -n 'parseOrNull\|AbstractEntryProjectionRepository\|MatchArmRemoval' -- src tests infection.json5
git grep -n -E '^(class|abstract class) ' -- src/Entity src/Repository
git grep -n -E -e '->getRepository\(' -- src
composer stan -- --error-format=raw --no-progress 2>&1 | grep -c -E 'Service role "(supportHome|supportShape)"'
git grep -c 'private function __construct' -- src/Service/Html/Support/HtmlDocumentParser.php
```
Expected:
- The first grep lists only the three data carriers #1169 does not name (`Dto/Admin/MailSettingsRequest.php`, `Dto/Recommendation/SaveRecommendationSettingsRequest.php`, `Service/Backup/Dto/EntryLine.php`) and `Service/Parser/Model/ParsedEntryModel.php`: none of the three the issue names.
- The next three print nothing; the stan count prints `0` (D1's `11` and E1's `9` were its positive controls); the last prints `…HtmlDocumentParser.php:1` (a `Support/` class keeps its private constructor: the pathspec and pattern work).

Walk this plan's Scope table against develop plus this branch; a row that is neither done nor ruled is a gap: stop and report it.

- [ ] **Step 2: Commit (only if CLAUDE.md changed)**

```bash
git add ../CLAUDE.md
git commit -m "refactor(#1169): CLAUDE.md names what #1169 made mechanical"
```

---

### Finishing PR H

- [ ] **Step 1: The gates on the whole branch** (Global Constraints), including Appendix M with `viewer-time-zone` (a move: restart the worker, check it is healthy).
- [ ] **Step 2: The real recommendation run** (Appendix R): the settings values, the run entity and every tick phase changed. Paste steps 3, 5, 6 and 7.
- [ ] **Step 3: PhpStorm inspections** on every changed PHP file.
- [ ] **Step 4: /simplify**; commit `refactor(#1169): simplify pass` if anything changed.
- [ ] **Step 5: Final review (opus)**

Dispatch one fresh reviewer subagent with `model: "opus"`, this plan, the issue with its comment, the ledger's `#1169` lines and `git diff -M origin/develop...HEAD`. Attack points:
1. **The whole issue.** Walk the Scope table: every body bullet, every comment bullet, every ledger line is done in PRs A–H or ruled with a reason.
2. **`RecommendationRun`'s invariant holds:** no throttle or call-attempt transition can reach a run that is not running; the accessors hand out parts with transitions, not setters; no command was renamed into PHPMD's ignore pattern (D-10's condition), and the PR body lists every rename with what it does.
3. **The settings wire shape** is unchanged (`RecommendationSettingsJsonTest`, the round-trip test).
4. **The repository scope** has no false negative: a new repository that imports a root service fails `composer stan`.
5. **Deletion checks:** re-run H3's third and H5's first; quote both FAILs.
6. **The body says `Closes #1169`**, and no earlier commit on any #1169 branch closed it.

Fix each finding rated Important or above in its own commit, re-run the gates, and record the rest in the PR body.

- [ ] **Step 6: Open the PR** (Appendix K, closing variant) with `var/refactor-1169/pr-h-body.md`:
```markdown
Closes #1169 (PR H of eight; PRs A to G are merged).

- `ViewerTimeZoneModel` moves to `Service/Clock`, and `ServiceModuleBoundaryRule` keeps `Reading → Recommendation` out.
- `docs/architecture.md` §8 rules the homes #1169 asked about: shared module enums stay with the module that owns their meaning; `SupportedLocale` stays in `App\Enum` as the value set of `User::$locale`.
- `PersistenceKnowsNoServiceRule` now checks repositories: they name Service models, helpers, exceptions and the interfaces they implement, nothing else (`EntryBatchInserter` is the one recorded exception).
- The three Recommendation PHPMD suppressions are gone: the settings values group their history caps and pool limits, and `RecommendationRun` reaches its throttle and call attempts through two accessors that guard "running".
- `RecommendationRun`'s renamed methods, each a query:
  - `progress()` → `getProgress()`: the run's batch progress read off its checkpoints.
  - `mustWaitBeforeRetry($now)` → `isRetryDeferredAt($now)`: whether a rate-limit deferral still holds at `$now`.
  - `waveConcurrencyCap($configuredCap)` → `getWaveConcurrencyCap($configuredCap)`: the wave concurrency after any 429 halving.
  - `recordInvalidReply()`, `recordTransportFailure()`, `deferRetryUntil()` and `reduceWaveConcurrency()` are gone. Callers reach the same transitions of `RunCallAttempts` and `RunThrottle` through `getRunningCallAttempts()` and `getRunningThrottle()`, which throw unless the run is running.
  - `stampProvider()` keeps its name: it is a command, not a setter.

Over the eight PRs: persistence classes are final; every file is strict; stateless classes are `final readonly`; no service defaults or locates a collaborator; tuned heuristics are injected services (`Support/` computes, a service decides); and the carry-forward items on this issue are done or ruled.

No wire change. Real recommendation run: <paste the Appendix R summary line>.
```
Title: `refactor(#1169): shared values have ruled homes; the recommendation suppressions go`.
- [ ] **Step 7: Merge when green**, then `gh issue view 1169 --json state --jq .state`. Expected: `CLOSED` (the merge into `develop`, the default branch, closes it). If it is still open, report it; do not close it by hand.

---

# Appendix K — The closing-keyword gate on `gh pr create`

From `backend/`, with `<x>` the PR's letter, its body in `var/refactor-1169/pr-<x>-body.md` and its title:
```bash
KEYWORDS='(^|[^a-z])(close|closes|closed|fix|fixes|fixed|resolve|resolves|resolved)([^a-z]|$)'
printf 'this fixes it\n' | grep -iqE "$KEYWORDS" && echo 'guard control: a planted hit is seen'
printf 'fixture, MissingFaviconResolver, resolveFor\n' | grep -iqE "$KEYWORDS" || echo 'guard control: look-alikes pass'
git push -u origin "$(git branch --show-current)"
git log origin/develop..HEAD --format=%B > var/refactor-1169/commits.txt
! grep -inE "$KEYWORDS" var/refactor-1169/commits.txt \
  && ! grep -inE "$KEYWORDS" var/refactor-1169/pr-<x>-body.md \
  && gh pr create --base develop --title "<title>" --body-file var/refactor-1169/pr-<x>-body.md
```
Expected: both control lines print; then the PR URL. A keyword in a commit or the body prints its line and stops before `gh pr create`: reword it (an amended commit message on this unmerged branch, or the body file) and run the block again.

**Closing variant (PR H):** the body must say `Closes #1169` once and nothing else closing:
```bash
! grep -inE "$KEYWORDS" var/refactor-1169/commits.txt \
  && grep -c '^Closes #1169 ' var/refactor-1169/pr-h-body.md \
  && ! grep -v '^Closes #1169 ' var/refactor-1169/pr-h-body.md | grep -inE "$KEYWORDS" \
  && gh pr create --base develop --title "<title>" --body-file var/refactor-1169/pr-h-body.md
```
Expected: `1`, then the PR URL.

After creating any PR, `gh pr view --json body --jq .body | grep -inE "$KEYWORDS"` prints nothing (A–G) or only the `Closes #1169` line (H).

# Appendix M — Stale names and the worker (move PRs D, E, H)

From the repository root, with `<map>` each map the PR moved (`reader-policies`, `policies`, `viewer-time-zone`):
```bash
php docs/superpowers/plans/2026-09-28-1202-scripts/stale-names.php backend/var/refactor-1169/<map>.php > backend/var/refactor-1169/<map>.stale
git grep -l -E -f backend/var/refactor-1169/<map>.stale origin/develop -- backend/src backend/tests | wc -l
git grep -n -E \( -f backend/var/refactor-1169/<map>.stale \) --and --not -e '^namespace ' -- . ':!docs' ':!backend/tests/PhpStan'
git grep -n -E \( -f backend/var/refactor-1169/<map>.stale \) --and --not -e '^namespace ' -- docs/architecture.md
```
Expected: the first grep prints a number above 0 (the patterns match the old names on develop: the positive control); the other two print nothing. A hit is an old name the script left: rewrite it by hand and report the file. (`tests/PhpStan` keeps fixture names on purpose; the parentheses bind `--and` to every pattern.)

Then the worker:
```bash
docker compose exec php bin/console cache:clear
docker compose restart worker
```
Wait with the Monitor tool until `docker compose ps worker --format '{{.Health}}'` prints `healthy` (allow four minutes), then `docker compose exec -T worker php /usr/local/bin/worker-healthcheck.php; echo "exit $?"`. Expected: `healthy`, then `exit 0`.

# Appendix R — The real recommendation run (PRs A, E, H)

User 2, `qwen/qwen3.7-flash` on OpenRouter, from the repository root:

1. The stack serves this checkout, with current code:
```bash
bash backend/bin/e2e-preflight.sh "$(git rev-parse --show-toplevel)"
docker compose ps --format '{{.Service}} {{.State}}'
docker compose exec php bin/console cache:clear
docker compose restart worker
docker compose exec php bin/console doctrine:migrations:up-to-date
```
Expected: the preflight exits 0 silently; `php`, `worker`, `nginx`, `mysql` are `running`; the cache cleared; `[OK] Up-to-date! No migrations to execute.` A preflight error naming another checkout: stop and report.

2. User 2 and its model; no run active (read-only queries):
```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT u.id, u.email, s.model FROM app_user u JOIN user_ai_settings s ON s.id = u.active_ai_config_id WHERE u.id = 2"
EMAIL=<the email>
docker compose exec -T php bin/console dbal:run-sql "SELECT id, status FROM recommendation_run WHERE user_id = 2 AND status IN ('pending', 'running')"
```
Expected: one row with `qwen/qwen3.7-flash`; the second query prints no row. An active run: let it finish (step 4) first; never write SQL to end it.

3. Start a run (the token is the output line starting `eyJ`; a blank line follows it, so `tail` would take the wrong one):
```bash
TOKEN=$(docker compose exec -T php bin/console lexik:jwt:generate-token "$EMAIL" | grep -E '^eyJ' | tr -d '[:space:]')
curl -sk -X POST https://localhost:8443/api/recommendations/runs -H "Authorization: Bearer $TOKEN" | jq '{status, batchesTotal, batchesDone}'
```
Expected: `"status": "pending"`.

4. Poll until it leaves `pending`/`running` (foreground `sleep` is blocked: run the loop with the Monitor tool):
```bash
RUN_ID=$(docker compose exec -T php bin/console dbal:run-sql "SELECT MAX(id) AS id FROM recommendation_run WHERE user_id = 2" | grep -Eo '[0-9]+' | tail -n 1)
until docker compose exec -T php bin/console dbal:run-sql "SELECT status FROM recommendation_run WHERE id = $RUN_ID" | grep -qE 'completed|failed|cancelled'; do sleep 20; done
```

5. Completed, every batch banked, no transport failure:
```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT status, batches_done, JSON_LENGTH(candidate_batches) + 2 AS batches_total, transport_failures, attempts, error FROM recommendation_run WHERE id = $RUN_ID"
```
Expected: `completed`, `batches_done` = `batches_total`, `transport_failures` 0, `error` NULL.

6. The per-call story:
```bash
docker compose exec -T php bin/console dbal:run-sql "SELECT phase, batch_number, attempt, verdict, finish_reason, error_detail FROM recommendation_run_log WHERE run_id = $RUN_ID ORDER BY id"
```
Expected: one `distill` row, one `batch` row per batch, one `consolidate` row, every verdict `usable`. **Known provider-side filter:** a consolidate that aborts right after candidate 549575 (the stream ends, `finish_reason` `error`) is the provider's content filter, not this code: start one more run (steps 3–6) and report both. Any other retry, `unusable` or `transport-failed` row is a warning to report even when the run completed.

7. The dev log since the run started holds no new warning or error:
```bash
ls -t backend/var/log/dev-*.log | head -n 1 | xargs tail -n 400 | jq -c 'select(.level >= 300) | {datetime, channel, message}'
```
Expected: nothing dated after the run's `created_at`.

The summary line for the PR body: `run <id> as user 2 (qwen/qwen3.7-flash): completed, <n>/<n> steps, 0 transport failures, every call usable on attempt 1, dev log clean`.
