# Consolidate Duplicated Helpers and Queries (#1168) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1168 in two PRs. Every helper and query the issue found copied three or more times gets one home, the copies that already disagree are made to agree, and six carry-forward items are folded in: four from #1157 and #1170, and two found in files #1164 touched.
- **PR A** (Tasks A0–A5, `Refs #1168`): the text, URL, JSON-LD, base64url and HTTP-header helpers in `Service/Reader`, `Service/Scraper`, `Service/Fetch`, `Service/Text` and the two cursors.
- **PR B** (Tasks B0–B12, `Closes #1168`): the persistence, clock, config, command and auth duplicates, plus the carry-forward test fixtures, assertions and imports.

**Architecture:**
- `App\Service\Text\Whitespace::collapse(?string): string` is the one Unicode-aware whitespace collapse. `TextNormalizer::normalize()` composes it with its soft-hyphen strip. `LeadingEngagementRules::collapse()` goes: it was a general text utility living in a rule class. The three copies that dropped the `/u` flag now collapse a no-break space like every other copy.
- `App\Service\Url\AbsoluteHttpUrl` (already there) answers every "is this an absolute http(s) URL" question. The six copies the issue lists go, and so do four more the sweep found (`PageUrls`, `UrlResolver`, `SlideCaptionResolver`, `CatalogDocument`). The last two were case-sensitive and now accept `HTTPS://`, like every other copy.
- `App\Service\Html\JsonLd` is the one JSON-LD reader. It knows which scripts are JSON-LD, decodes a block and walks every node in it. `JsonLdLayer`, `JsonLdMediaSource`, `ScriptEmbedSource` and `SchemaOrgAccess` use it. `SchemaOrgAccess` stops scanning raw HTML with a regex and reads the raw document that `ArticleExtractor` already parses once for the media scan. `JsonLdArticles` keeps its own typed walk: it follows `@graph`/`ItemList` structure and is not a generic node walk.
- The four base64url encoders call `ParagonIE\ConstantTime\Base64UrlSafe::encodeUnpadded()`, which the passkey code already uses. It is constant-time, which matters for the generated password and the PKCE challenge. The package becomes a direct dependency. It is already in the lock at v3.1.3 through `web-auth/webauthn-lib`.
- `App\Service\Fetch\ResponseHeader::first(ResponseInterface, string): ?string` is the one header lookup. It reads the first value, and a response that can no longer be read counts as a missing header. `LandedResponse::header()` delegates to it. `RedirectFollower`, `ResponseClassifier`, `OpenAiCompatibleChatClient` and `CatalogFaviconFetcher` call it.
- Two injected collaborators in `src/Repository`, following `docs/architecture.md` §7 (composition, not a base class):
  - `NextPosition::in(QueryBuilder $list): int` replaces the five `MAX(position)` queries.
  - `RowIds` (`selectedBy(QueryBuilder): list<int>`, `delete(class-string, list<int>): void`) replaces the three "select ids, skip when empty, `DELETE … IN (:ids)`" copies and `RetentionRepository::deleteEntries()`'s fourth `DELETE … IN`.
- `DigestEnablement` injects `NaiveUtcClock` instead of re-implementing it. `DigestHtmlRenderer` injects the PSR clock for the header date instead of calling `new \DateTimeImmutable('now')`.
- `App\Service\Auth\PasswordPolicy` holds the 12-character minimum (and the 4096 maximum) that two commands and three DTOs repeat, and the two commands' messages read it from there.
- `App\Command\ConsoleOption` is the one console-option parser for the five commands: the four the issue lists, plus `ReaderAuditReportCommand::option()`, which the re-take at `fd5fa734` found. Values are trimmed, and an absent option reads as `null`. A malformed number throws `MalformedOptionException`, a console `InvalidOptionException` with code `INVALID`, so the application prints it and exits 2.
- `AltchaService::requireSolved(string)` throws the `altcha` `ValidationException` that `AuthController` built twice.
- `App\Http\TrialEndJson::of(User)` is the one serialisation of a trial's end, used by four fields.
- Test support: `SeedsUsers::user(string $email)` and `ReloadsEntities::reload(object $entity)` replace 41 hand-copied helpers (32 `user()`, 9 `reload()`). `ProvidesWorkerHeartbeats::heartbeats()` gives the heartbeat tests the typed repository, and every heartbeat read goes through `findTouchedAt()`, never `find()`.

**Tech Stack:** PHP 8.4 (`\Dom\HTMLDocument`, `Dom\Element::matches()`), Symfony 7.4 (Console, HttpClient contracts, Clock, Validator), Doctrine ORM 3 QueryBuilder/DQL, paragonie/constant_time_encoding 3, PHPUnit 12, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1168 (`gh issue view 1168`).
- Carry-forward rulings (planner): (1) heartbeat reads via `findTouchedAt()`; (2) one shared trait for the `user()`/`reload()` helpers; (3) one helper for `trialEndsAt?->format(ATOM)`; (4) OAuthFlowTest's vacuous replay assertion; (5) `AccountRestorerTest`'s bare `$feed->getUrl();` statement; (6) the unused imports in `PasskeyRegistrationTest` and `PasskeySignInAvailabilityTest`. Items 5 and 6 predate `1cdcf65d` and were found in files #1164 touched.
- CLAUDE.md "PHP code style — Clean Code is mandatory". `docs/architecture.md` §7 (where queries live, composition over inheritance).

## Status

| Task | State |
|---|---|
| A0: Preflight | ⬜ |
| A1: `Whitespace::collapse` | ⬜ |
| A2: `AbsoluteHttpUrl` for every http(s) check | ⬜ |
| A3: `JsonLd`, and `SchemaOrgAccess` reads the raw document | ⬜ |
| A4: `Base64UrlSafe::encodeUnpadded` for the four encoders | ⬜ |
| A5: `ResponseHeader::first` | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: `SeedsUsers` and `ReloadsEntities` | ⬜ |
| B2: `ProvidesWorkerHeartbeats`; heartbeat reads via `findTouchedAt()` | ⬜ |
| B3: The provider-mismatch replay assertion bites | ⬜ |
| B4: `NextPosition` | ⬜ |
| B5: `RowIds` | ⬜ |
| B6: Digest clocks | ⬜ |
| B7: `PasswordPolicy` | ⬜ |
| B8: `ConsoleOption` | ⬜ |
| B9: `AltchaService::requireSolved()` | ⬜ |
| B10: `TrialEndJson` | ⬜ |
| B11: `SeedsDigestReaders` | ⬜ |
| B12: Carry-forward test tidy-ups from #1164 | ⬜ |

## Scope

| Issue bullet / ruling | Task |
|---|---|
| Whitespace collapse 12×, three copies without `/u`, `LeadingEngagementRules::collapse` in the wrong home | A1 |
| `preg_match('#^https?://#i', …)` 6× beside `AbsoluteHttpUrl::matches` | A2 |
| JSON-LD decoded four ways, each with its own recursive walk | A3 |
| Base64url encoder 4× | A4 |
| Header lookup wrapped in `catch → null` 3×, plus two near-copies | A5 |
| Carry-forward 2: `user()`/`reload()` copied into ~35 test files | B1 |
| Carry-forward 1: heartbeat tests `find()` after touch/forget | B2 |
| Carry-forward 4: OAuthFlowTest's vacuous "replay after provider mismatch" assertion | B3 |
| `MAX(position)` next-position query 5× | B4 |
| "Select ids → empty guard → `DELETE … WHERE id IN (:ids)`" 3× | B5 |
| `DigestEnablement::nowAsNaiveUtc()` re-implements `NaiveUtcClock`; `DigestHtmlRenderer`'s stray `new \DateTimeImmutable('now')` | B6 |
| Password minimum 12 in two commands, the DTOs (three, not two) and the messages | B7 |
| Command option parsing 4×, plus a fifth copy (`ReaderAuditReportCommand::option()`) that the re-take found | B8 |
| DRY third occurrence found in the sweep (planner ruling): three digest-test verified-reader helpers | B11 |
| ALTCHA check duplicated in `AuthController` | B9 |
| Carry-forward 3: `trialEndsAt?->format(ATOM)` 4× in `src/Http` | B10 |
| Carry-forward 5: `AccountRestorerTest::fixtureRowsOf()` has a bare `$feed->getUrl();` whose result is unused | B12 |
| Carry-forward 6: unused imports in `PasskeyRegistrationTest` (`InstanceSettings`, `InstanceSettingsUpdate`) and `PasskeySignInAvailabilityTest` (`EffectivePasskeyRelyingPartyId`, `PublicBaseUrl`) | B12 |

**Scope decisions:**
- **`CategoryNormalizer::normalizeOne()` keeps its own collapse.** It trims with PHP's ASCII `trim()` first and then collapses inside. `Whitespace::collapse()` would also strip an edge no-break space, which changes the category's `canonicalKey`, the stored identity (memory: "category collation must match identity()"). It is not one of the issue's twelve.
- **`LeadingEngagementRules::withoutWhitespace()` stays.** It deletes whitespace instead of collapsing it, which is a different operation.
- **`JsonLdArticles` keeps its walk.** It is a typed descent (`@graph`, `ItemList`, `ListItem.item`) with a collection cap. It only reads blocks through `JsonLd` now.
- **The base64url decoders stay.** `EntryCursor`, `RecommendationCursor`, `ImageProxyUrl` and `IdTokenClaims` each do `base64_decode(strtr(…), true)`. That is PHP's strict decoder, which skips whitespace and accepts padding. paragonie's decoder refuses both. Swapping the decoders would change what three untrusted inputs accept, and one of them is inside the OIDC boundary. The issue lists the encoders only.
- **Passkey code keeps `Base64UrlSafe` as it is.** It already uses the one encoder this plan adopts.
- **The digest tests' verified-reader helpers get their own trait (B11), not `SeedsUsers`.** They build a pending account with a random address and verify it after the flush, which is a different fixture from `user($email)`.
- **In-memory `user()` helpers stay** (`new User('x@…', <fixed date>)` with a test-specific setter, never persisted): `TrialExpiryGuardTest`, `UserCheckerTest`, `SavedSearchTest`, `UserSecurityTest`, `UserAiConfigurationsTest`, `MeProfileJsonTest`, `AdminUserLimitsJsonTest`, `RecommendationRunStatusJsonTest`, `MeJsonTest`, `PasskeyRemovalPolicyTest`, `AccountMailerTest`, `DigestMailerTest`, `DigestMailBuilderTest`, `ScrapeFallbackPolicyTest`, `UserStatisticsTest`, `SubscriptionLimitResolverTest`, `FeedPreviewServiceTest`, `RestoreEntryLoaderTest`. Each is a constructor call plus the one property its test varies. A trait would not remove any knowledge.
- **`AiProviderConfiguratorTest::reload(string $email)` stays.** It looks a user up by address and does not clear the entity manager, so it is a different helper under the same name.
- **`ApiTestCase::factory()` stays.** It is already the shared home for the API tests' factory, and none of the swept `user()` copies extends `ApiTestCase`.

## Deliberate behaviour changes (both PR bodies list theirs)

PR A:
1. A no-break space (or any other Unicode space) in a lead-figure caption, a slide caption or an inline-teaser caption now collapses to one space. Every other text path already did this. Pinned by the three new tests in A1.
2. A slide's caption link and a shipped-catalog feed URL now accept an upper-case scheme (`HTTPS://…`), like every other http(s) check. Pinned in A2.
3. `SchemaOrgAccess` reads exactly the scripts `script[type="application/ld+json"]` selects in the raw page. The old regex matched `application/ld+json` anywhere in the tag. `ScriptEmbedSource` now skips exactly the scripts `JsonLdMediaSource` reads, where before it compared the `type` attribute byte for byte. Pinned in A3.
4. A `Location` or `Retry-After` header that can no longer be read now reads as absent in `CatalogFaviconFetcher` and `OpenAiCompatibleChatClient`, as it already did in the fetch path. Both read it after the status code, so the headers have arrived and this cannot happen in practice.

PR B:
1. The five commands trim option values, and a malformed number option (negative, fractional or partly digits) now stops the command. It prints `The --<option> option takes a whole number; "<value>" is not one.` and exits 2 (`INVALID`). Before, the two catalog commands ignored a bad `--limit` and ran unbounded, `app:feeds:refresh --feed=abc` refreshed every due feed, `app:reader:audit` read `--limit=12abc` as 12, and `app:reader:audit:report` read `--top=12abc` as 12. Pinned in B8.

No response body, status code or header changes in either PR.

## Depends on #1164 and #1167 (both landed at `fd5fa734`)

Both issues are CLOSED. #1164 merged as PRs #1185 and #1186, #1167 as PRs #1187 and #1188, and `fd5fa734` is the develop merge of #1188. Every before-block, path, class, method, signature, import and fixture in this plan was re-read at `fd5fa734`, and the line numbers quoted below are at `fd5fa734` for orientation only. **Locate every edit by its text, never by a line number.** `ReloadsEntities` and `SeedsUsers` depend on `UserFactory::create()` keeping its signature. #1164 kept it; only its status line changed (`NewUserStatus::apply()`).

What landed, per step this plan edits:

| Step | File | What #1164/#1167 changed | Effect on this plan |
|---|---|---|---|
| A4 Step 3 | `src/Service/Auth/PasswordResetter.php` | Nothing: the file is unchanged since `1cdcf65d` | None. |
| B1 Step 4 | `tests/Controller/Admin/AdminUserControllerTest.php` | #1164 rewrote status fixtures (12 lines) | None: `reload(int $id)`, its docblock line and the 8 `$this->reload($id)` sites are unchanged. |
| B1 Step 4 | `tests/Entity/UserPasskeyTest.php` | #1164 B1 builds every passkey through `PasskeyRegistrations::any()` | None: `user()` is unchanged, and all three imports (`App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface`) become unused. |
| B1 Step 4 | `BulkSubscriberTest`, `RefreshDueFeedsHandlerTest`, `SubscriptionEntryCountsTest` | #1164 replaced `Feed`/`EntryState` setter lines | None: the helpers are unchanged. `ForYouSweepTest`, `CatalogSubscriberTest` and `SubscriptionBulkTest` are unchanged. |
| B1 Step 4 | `FeedTagMoveTest`, `UnsubscribeAllTest`, `OwnedTagsCacheTest`, `SavedSearchMembershipLoaderTest` | #1167 A5/B3 rewrote call sites (`move(Subscription, MoveFeedToTagRequest)`, owner-first `findAllByIdsForUser(int $userId, array $tagIds)`) | None: the helpers are unchanged, and both `QueryRecorder` resets still sit after the fixture flush. `MoveFeedToTagTest` is unchanged. |
| B2 Step 3 | `ForYouSweepTest`, `RecommendationRunControllerTest`, `RecommendationSettingsControllerTest` | Nothing | None. |
| B3 Step 1 | `tests/Controller/Api/OAuthFlowTest.php` | #1167 A8 split `fakeProvider(OAuthIdentity, bool $failExchange)` into `fakeProvider(OAuthIdentity)` and `failingFakeProvider(OAuthIdentity)`, both over `installBeforeTheFirstRequest()` | None: the target test still opens with `$provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));`, and `flowCookieValue()`/`replaceFlowCookie()` are unchanged. |
| B4 Step 3 | `src/Repository/CatalogFeedRepository.php` | #1167 A6 moved the favicon criteria into `CatalogFaviconDueCriteria` | None: the constructor and `nextPositionInCategory()` are unchanged. |
| B4 Step 3 | `src/Repository/TagRepository.php`, `src/Repository/SubscriptionRepository.php` | #1167 B1/B3 renamed `findOneOwnedBy`/`getOneOwnedBy` to `findOneForUser`/`getOneForUser(int $userId, int $…Id)` and made `findAllByIdsForUser(int $userId, array $…Ids)` owner-first | None: the constructors and `nextPositionForUser()` are unchanged. |
| B5 Step 5 | `src/Repository/RecommendationRunLogRepository.php` | #1167 B1 renamed `getOwned(int, User)` to `getOneForUser(User $user, int $logId)` | None: the constructor, `deleteForUser()`, `deleteForUserOutsideRuns()`, `idsForUser()` and `deleteIds()` are unchanged. |
| B6 Step 4 | `tests/Service/Mail/Digest/DigestMailerTest.php` | Nothing | None. |
| B8 Step 5 | `src/Command/RefreshFeedsCommand.php` | #1167 A1 moved the `default =>` arm into `allDueRequest($input, $budget)` (with `withoutPruning()`/`ignoringSchedule()`) and added `tests/Command/RefreshFeedsCommandRequestTest.php` | B8 still replaces only the three `$this->intOption(…)` calls and deletes `intOption()`, which now sits between `execute()` and `allDueRequest()`. B8 adds its `--feed=abc` pin to the new unit test. |
| B11 Step 2 | `tests/Service/Mail/Digest/SendDueDigestsTest.php` | #1167 A8 replaced `user(bool $verified = true)` with `verifiedUser()`/`unverifiedUser()` and rewrote the nine callers | B11 deletes exactly the two helpers #1167 wrote (quoted in B11, byte for byte as landed) and keeps their call sites. |
| B12 | `tests/Service/Backup/AccountRestorerTest.php`, `tests/Controller/Api/PasskeyRegistrationTest.php`, `tests/Service/Passkey/PasskeySignInAvailabilityTest.php` | #1164 touched all three; the bare `$feed->getUrl();` statement and the four unused imports predate it | B12 cleans them up. |

No step of this plan depends on anything #1164 or #1167 left unfinished.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **No wire change.** Every response body, status code and header stays byte-identical. The deliberate behaviour changes are listed above and nowhere else.
- **Clean Code (CLAUDE.md) is mandatory:**
  - Names reveal intent.
  - No boolean flag parameters.
  - Three parameters at most.
  - Guard clauses over nesting.
  - `final readonly` by default. Static helpers are `final class`, like `AbsoluteHttpUrl` and `PlainText`.
  - A controller calls only `get*`/`is*`/`has*`/`requireId()` on an entity.
  - Queries live in `src/Repository`.
  - Domain code imports nothing from `App\Http` (`DomainKnowsNoHttpRule`). `App\Exception\ValidationException` is domain.
- **Comments:** default none, at most three lines, only where a future reader would otherwise get the code wrong. A docblock this plan touches is trimmed to that bar. Delete `@param`/`@return` lines that repeat the signature, unless PHPStan needs the array shape.
- **Tests read persisted ids with `requireId()`** (`EntityIdCoercionRule` covers `tests/`).
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`.
- **Test-first where behaviour changes; pin-first where it does not.** A task that changes behaviour writes a failing test first. A pure consolidation starts from a pin: a test that passes on develop and must keep passing. The pin is listed as "Expected: PASS" in the step that first runs it. That is intended, not a mistake.
- **Every new test gets a deletion check.** Break the production line the test covers, run the test and watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Gates for every task:** the task's own tests, `composer check` (cs + stan + tramp), `composer md`, and PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`). ERROR and WARNING block. A Symfony-plugin `AutowireWrongClass` false positive on a value object built with `new` gets `/** @noinspection AutowireWrongClass */` plus a one-line reason.
- **Gates per PR (Finishing):**
  - `php bin/phpunit` (SQLite)
  - `docker compose exec php composer test` (MySQL)
  - `composer check`
  - `composer md`
  - `composer infection:diff`
  - PhpStorm lint on all changed PHP
- **Commits:** `refactor(#1168): <lower-case summary>` for production changes, `test(#1168): <lower-case summary>` for test-only tasks, one commit per task, no attribution lines. The plan copy is `docs/superpowers/plans/2026-09-26-1168-consolidate-duplicated-helpers.md`, committed as `docs(#1168): plan — consolidate duplicated helpers and queries`, following `docs(#1164)` at `36734f32` and `docs(#1167)` at `3b497845`. Never commit to `develop`.
- **Branches, both cut from `origin/develop`:**
  - PR A: `refactor/1168-shared-text-and-http-helpers`.
  - PR B: `refactor/1168-shared-queries-config-and-fixtures`, cut after PR A merges.
- **PR A's body says `Refs #1168`.** It must never contain "closes", "fixes" or "resolves" followed by `#1168` anywhere: not in the body, the prose or any commit message. PR B's body says `Closes #1168`.
- **The checkout is shared.** Check `git status` and `git branch --show-current` before any `checkout`, `switch`, `reset` or `stash`. Another session may be mid-edit.
- **Work in place.** No worktrees (memory: "No git worktrees").

---

# PR A

### Task A0: Preflight

**Files:** `docs/superpowers/plans/2026-09-26-1168-consolidate-duplicated-helpers.md` (the plan copy, named like the #1164 and #1167 copies). No code changes.

- [ ] **Step 1: Confirm #1164 and #1167 are closed and the checkout is free**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1164 --json state --jq .state
gh issue view 1167 --json state --jq .state
gh issue view 1168 --json state --jq .state
git merge-base --is-ancestor fd5fa734 origin/develop && echo 'fd5fa734 is on develop'
```
Expected: a clean tree (or only another session's files, which you leave alone), `CLOSED`, `CLOSED`, `OPEN`, and `fd5fa734 is on develop`. If either earlier issue is still open, or the last line prints nothing, stop and report: this plan was reconciled against `fd5fa734` and does not apply to an older develop.

- [ ] **Step 2: Cut the branch and commit the plan copy**

```bash
git switch -c refactor/1168-shared-text-and-http-helpers origin/develop
git merge-base --is-ancestor fd5fa734 HEAD && echo 'fd5fa734 is an ancestor of HEAD'
mkdir -p ../docs/superpowers/plans
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1168-draft.md ../docs/superpowers/plans/2026-09-26-1168-consolidate-duplicated-helpers.md
git add ../docs/superpowers/plans/2026-09-26-1168-consolidate-duplicated-helpers.md
git commit -m "docs(#1168): plan — consolidate duplicated helpers and queries"
```
Expected: `fd5fa734 is an ancestor of HEAD` before the copy. If it does not print, stop.

- [ ] **Step 3: Re-take the PR A sites**

```bash
git grep -nE "preg_replace\('/\\\\s\+/u?', ' '" -- src
git grep -n "LeadingEngagementRules::collapse" -- src tests
git grep -nE "https\?://#|str_starts_with\(\\\$href, 'http" -- src
git grep -n "application/ld+json" -- src
git grep -n "rtrim(strtr(base64_encode" -- src tests
git grep -n "getHeaders(false)" -- src
```
Expected, against the lists in Tasks A1–A5 (re-taken at `fd5fa734`; neither #1164 nor #1167 touched a PR A file):
- 12 collapse lines: `CategoryNormalizer` (stays, see Scope decisions), `DuplicateBlockCollapser`, `ExtractionCoverageGate`, `LeadFigureCaptions`, `LeadingEngagementRules`, `LeadingTitleRemover`, `TeaserPlayerScanner`, `NavigationChromeTrimmer`, `ShareIntentLinkRemover`, `SlideCaptionResolver`, `TextNormalizer`, `PlainText`.
- 10 `LeadingEngagementRules::collapse` lines in `src` (`BlockText`, `HtmlPageFetcher`, `LeadingEngagementBlocks` ×3, `LeadingEngagementCleaner` ×2, `PlayerChromeCleaner`, `ExtractedBody` ×2) and 2 in `LeadingEngagementRulesTest`.
- 11 http(s) lines: `CatalogDocument`, `PageUrls`, `UrlResolver`, `HeroImageSelector`, `ImageProxyUrl`, `LeadFigureCaptions`, `ReaderLeadImage`, `ImageButtonUnwrapper`, `SubstackGatedVideoPlaceholder` (its constant), the `str_starts_with` pair in `SlideCaptionResolver`, and `AbsoluteHttpUrl` itself.
- 3 JSON-LD lines: `JsonLdLayer`, `JsonLdMediaSource` and `ScriptEmbedSource`. `SchemaOrgAccess` is the fourth reader, but its regex writes `ld\+json`, so this grep does not print it.
- 10 encoder lines: `EntryCursor`, `RecommendationCursor`, `OAuthStateStore` and `PasswordResetter` in `src`, and six in `tests` (`EntryCursorTest`, `OAuthStateStoreTest`, `AbstractOidcProviderTest`, `GoogleOAuthProviderTest`, `IdTokenVerifierTest`, `ImageIdentityTest`). The test copies stay: each is an independent oracle or builds its own fixture.
- 6 `getHeaders(false)` lines: `RedirectFollower`, `ResponseClassifier`, `LandedResponse`, `OpenAiCompatibleChatClient` and `CatalogFaviconFetcher` (twice; its `assertAllowedType($response->getHeaders(false))` reads the whole header map and stays).

A site that is not in these lists gets the same rewrite as its siblings. Record it in the task report.

---

### Task A1: `Whitespace::collapse`

**Files:**
- Create: `src/Service/Text/Whitespace.php`
- Create: `tests/Service/Text/WhitespaceTest.php`
- Modify: `src/Service/Scraper/TextNormalizer.php` (rewritten in full)
- Modify: `src/Service/Reader/LeadingEngagementRules.php` (delete `collapse()`, one docblock line)
- Modify (callers of the deleted method): `src/Service/Reader/BlockText.php`, `src/Service/Reader/HtmlPageFetcher.php`, `src/Service/Reader/LeadingEngagementBlocks.php`, `src/Service/Reader/LeadingEngagementCleaner.php`, `src/Service/Reader/PlayerChromeCleaner.php`, `src/Service/ReaderAudit/ExtractedBody.php`
- Modify (hand-written copies): `src/Service/Reader/NavigationChromeTrimmer.php`, `src/Service/Reader/DuplicateBlockCollapser.php`, `src/Service/Reader/LeadingTitleRemover.php`, `src/Service/Reader/ExtractionCoverageGate.php`, `src/Service/Reader/Repair/ShareIntentLinkRemover.php`, `src/Service/Text/PlainText.php`, `src/Service/Reader/LeadFigureCaptions.php`, `src/Service/Reader/Slideshow/SlideCaptionResolver.php`, `src/Service/Reader/Media/Teaser/TeaserPlayerScanner.php`
- Test: `tests/Service/Reader/LeadFigureCaptionsTest.php`, `tests/Service/Reader/Slideshow/SlideCaptionResolverTest.php`, `tests/Service/Reader/Media/Teaser/TeaserPlayerScannerTest.php` (one new test each), `tests/Service/Reader/LeadingEngagementRulesTest.php` (one test moves to `WhitespaceTest`)

**Interfaces:**
- Produces: `App\Service\Text\Whitespace::collapse(?string $text): string`. Every run of Unicode whitespace becomes one ASCII space, the ends are trimmed, and `null` becomes `''`.
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

`tests/Service/Text/WhitespaceTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Text;

use App\Service\Text\Whitespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhitespaceTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function texts(): iterable
    {
        yield 'ascii runs fold and the ends trim' => ["  a \n\t b  ", 'a b'];
        yield 'a no-break space run' => ["a\u{00A0}\u{00A0}b", 'a b'];
        yield 'thin and ideographic spaces' => ["a\u{2009}b\u{3000}c", 'a b c'];
        yield 'unicode space at both ends' => ["\u{00A0}a\u{00A0}", 'a'];
        yield 'a soft hyphen is not whitespace' => ["Gesundheits\u{00AD}ministerin", "Gesundheits\u{00AD}ministerin"];
        yield 'only whitespace' => [" \u{00A0}\n", ''];
        yield 'no text at all' => [null, ''];
    }

    #[DataProvider('texts')]
    public function testCollapsesEveryWhitespaceRunToOneSpace(?string $text, string $collapsed): void
    {
        self::assertSame($collapsed, Whitespace::collapse($text));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Text/WhitespaceTest.php`
Expected: FAIL, 7 errors: `Class "App\Service\Text\Whitespace" not found`.

- [ ] **Step 3: Write the helper**

`src/Service/Text/Whitespace.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Text;

final class Whitespace
{
    public static function collapse(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Text/WhitespaceTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Write the three failing caption tests**

These are the three copies that dropped the `/u` flag, so they left a no-break space in place.

`tests/Service/Reader/LeadFigureCaptionsTest.php`, after `testCollapsesInternalWhitespaceAndTrims()`:
```php
    public function testCollapsesANoBreakSpaceLikeAnyOtherSpace(): void
    {
        $html = '<body><figure><img src="https://cdn.test/hero-photo.jpg">'
            . '<figcaption>Bild:&nbsp;&nbsp;Berti Kolbow-Lehradt&nbsp;</figcaption></figure></body>';

        $captions = $this->captionsOf($html);

        self::assertSame('Bild: Berti Kolbow-Lehradt', $captions->captionFor('https://cdn.test/hero-photo.jpg'));
    }
```

`tests/Service/Reader/Slideshow/SlideCaptionResolverTest.php`, after `testIgnoresScriptTextInTheCaption()`:
```php
    public function testCollapsesANoBreakSpaceInTheCaption(): void
    {
        $slide = $this->slide('<li class="swiper-slide">Head&nbsp;&nbsp;line&nbsp;</li>');

        self::assertSame('Head line', (new SlideCaptionResolver())->resolve($slide)->text);
    }
```

`tests/Service/Reader/Media/Teaser/TeaserPlayerScannerTest.php`, after `testReadsThePlayerItsStillCaptionAndLink()`:
```php
    public function testCollapsesANoBreakSpaceInTheCaption(): void
    {
        $html = '<body><div class="block">'
            . '<picture><img src="https://x.test/still.jpg"></picture>'
            . '<div data-v="https://x.test/clip.webxxl.mp4"></div>'
            . '<a href="https://x.test/related.html">Kicker&nbsp;—&nbsp;The&nbsp;headline</a>'
            . '</div></body>';

        $found = $this->scanner->scan($this->document($html), 'https://x.test/article-100.html');

        self::assertCount(1, $found);
        self::assertSame('Kicker — The headline', $found[0]->caption);
    }
```

- [ ] **Step 6: Run them to verify they fail**

Run:
```bash
php bin/phpunit --filter 'testCollapsesANoBreakSpace' tests/Service/Reader
```
Expected: FAIL, 3 failures. Each actual string still holds `\u{00A0}` where the expected one has a plain space.

- [ ] **Step 7: Replace every hand-written copy**

Each file that gains a `Whitespace::` call gets `use App\Service\Text\Whitespace;` among its `use` lines, in alphabetical order. The exception is `PlainText`, which already sits in `App\Service\Text`.

`src/Service/Scraper/TextNormalizer.php`, rewritten in full:
```php
<?php

declare(strict_types=1);

namespace App\Service\Scraper;

use App\Service\Text\Whitespace;

/** Cleans text extracted from scraped HTML (soft hyphens, run-on whitespace). */
final class TextNormalizer
{
    public static function normalize(string $text): string
    {
        return Whitespace::collapse(str_replace("\u{00AD}", '', $text));
    }
}
```

`src/Service/Reader/DuplicateBlockCollapser.php` and `src/Service/Reader/LeadingTitleRemover.php`, each in its `normalize()`:
```diff
-        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
+        return mb_strtolower(Whitespace::collapse($text));
```

`src/Service/Reader/ExtractionCoverageGate.php`, the last line of `plainText()`:
```diff
-        return trim((string) preg_replace('/\s+/u', ' ', $text));
+        return Whitespace::collapse($text);
```

`src/Service/Text/PlainText.php`, in `from()`:
```diff
-        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $decoded));
+        $collapsed = Whitespace::collapse($decoded);
```

`src/Service/Reader/LeadFigureCaptions.php`, in `captionedFigure()`:
```diff
-        $caption = trim((string) preg_replace('/\s+/', ' ', $captionElement->textContent ?? ''));
+        $caption = Whitespace::collapse($captionElement->textContent);
```

`src/Service/Reader/Slideshow/SlideCaptionResolver.php`, in `visibleText()`:
```diff
-        return trim((string) preg_replace('/\s+/', ' ', $this->collectText($slide)));
+        return Whitespace::collapse($this->collectText($slide));
```

`src/Service/Reader/Media/Teaser/TeaserPlayerScanner.php`, in `readableText()`:
```diff
-        $text = trim((string) preg_replace('/\s+/', ' ', $element->textContent ?? ''));
+        $text = Whitespace::collapse($element->textContent);
```

`src/Service/Reader/Repair/ShareIntentLinkRemover.php`: in `textLengthOutsideLinks()`,
```diff
-        return mb_strlen($this->collapsedText($this->textOutsideLinks($element)));
+        return mb_strlen(Whitespace::collapse($this->textOutsideLinks($element)));
```
and delete the private method with the blank line above it:
```php

    private function collapsedText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
```

`src/Service/Reader/NavigationChromeTrimmer.php`: `BlockText::collapsed()` (same namespace) already is this measurement. Replace all three `$this->collapsedText(` with `BlockText::collapsed(` (Edit with `replace_all`) and delete the private method with the blank line above it:
```php

    private function collapsedText(Element $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $element->textContent));
    }
```
This file needs no new import.

- [ ] **Step 8: Move `LeadingEngagementRules::collapse()` to its home**

`src/Service/Reader/BlockText.php`, in `collapsed()`:
```diff
-        return LeadingEngagementRules::collapse((string) $element->textContent);
+        return Whitespace::collapse($element->textContent);
```

In `src/Service/Reader/HtmlPageFetcher.php`, `src/Service/Reader/LeadingEngagementBlocks.php`, `src/Service/Reader/LeadingEngagementCleaner.php`, `src/Service/Reader/PlayerChromeCleaner.php` and `src/Service/ReaderAudit/ExtractedBody.php`, replace every `LeadingEngagementRules::collapse(` with `Whitespace::collapse(` (Edit with `replace_all`). Add the `Whitespace` import to each. In `ExtractedBody.php`, replace the import line itself, since nothing else there reads `LeadingEngagementRules`:
```diff
-use App\Service\Reader\LeadingEngagementRules;
+use App\Service\Text\Whitespace;
```
(Keep that `use` block in alphabetical order.)

`src/Service/Reader/LeadingEngagementRules.php`: delete the method and the blank line after it,
```php
    /** The single whitespace-collapse every rule and both layers normalize with. */
    public static function collapse(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }

```
and repoint `isProse()`'s docblock:
```diff
-    /** Callers pass already-collapsed text (see {@see collapse()}). */
+    /** Callers pass text already collapsed by {@see \App\Service\Text\Whitespace::collapse()}. */
```

`tests/Service/Reader/LeadingEngagementRulesTest.php`: delete the test that `WhitespaceTest` now covers (the first and last data rows), with the blank line above it:
```php

    public function testCollapseTrimsAndFoldsRunsOfWhitespace(): void
    {
        self::assertSame('a b', LeadingEngagementRules::collapse("  a \n\t b  "));
        self::assertSame('', LeadingEngagementRules::collapse(null));
    }
```

- [ ] **Step 9: Check nothing hand-written is left**

```bash
git grep -nE "preg_replace\('/\\\\s\+/u?', ' '" -- src
git grep -n "LeadingEngagementRules::collapse\|collapsedText(" -- src tests
```
Expected: the first prints only `src/Service/Text/Whitespace.php` and `src/Service/Category/CategoryNormalizer.php`. The second prints nothing.

- [ ] **Step 10: Run the affected suites**

Run:
```bash
php bin/phpunit tests/Service/Text tests/Service/Reader tests/Service/Scraper tests/Service/ReaderAudit tests/Service/Discovery tests/Service/Category
```
Expected: PASS, the three caption tests included.

- [ ] **Step 11: Deletion check**

In `Whitespace::collapse()`, change `'/\s+/u'` to `'/\s+/'`. Run `php bin/phpunit tests/Service/Text/WhitespaceTest.php` and `php bin/phpunit --filter 'testCollapsesANoBreakSpace' tests/Service/Reader`. Expected: the no-break, thin/ideographic and edge rows of `WhitespaceTest` fail, and so do the three caption tests. Restore `'/\s+/u'` with the Edit tool. Paste both outputs into the report.

- [ ] **Step 12: Gates**

Run: `composer check`, then `composer md`, then PhpStorm `lint_files` on every PHP file this task changed.
Expected: all clean.

- [ ] **Step 13: Commit**

```bash
git add src/Service/Text/Whitespace.php tests/Service/Text/WhitespaceTest.php src/Service/Scraper/TextNormalizer.php src/Service/Reader/LeadingEngagementRules.php src/Service/Reader/BlockText.php src/Service/Reader/HtmlPageFetcher.php src/Service/Reader/LeadingEngagementBlocks.php src/Service/Reader/LeadingEngagementCleaner.php src/Service/Reader/PlayerChromeCleaner.php src/Service/ReaderAudit/ExtractedBody.php src/Service/Reader/NavigationChromeTrimmer.php src/Service/Reader/DuplicateBlockCollapser.php src/Service/Reader/LeadingTitleRemover.php src/Service/Reader/ExtractionCoverageGate.php src/Service/Reader/Repair/ShareIntentLinkRemover.php src/Service/Text/PlainText.php src/Service/Reader/LeadFigureCaptions.php src/Service/Reader/Slideshow/SlideCaptionResolver.php src/Service/Reader/Media/Teaser/TeaserPlayerScanner.php tests/Service/Reader/LeadFigureCaptionsTest.php tests/Service/Reader/Slideshow/SlideCaptionResolverTest.php tests/Service/Reader/Media/Teaser/TeaserPlayerScannerTest.php tests/Service/Reader/LeadingEngagementRulesTest.php
git commit -m "refactor(#1168): one unicode-aware whitespace collapse"
```

---

### Task A2: `AbsoluteHttpUrl` for every http(s) check

**Files:**
- Modify: `src/Service/Reader/ReaderLeadImage.php`, `src/Service/Reader/LeadFigureCaptions.php`, `src/Service/Reader/ImageProxyUrl.php`, `src/Service/Reader/HeroImageSelector.php`, `src/Service/Reader/Repair/ImageButtonUnwrapper.php`, `src/Service/Reader/Repair/SubstackGatedVideoPlaceholder.php`, `src/Service/Reader/Slideshow/SlideCaptionResolver.php`, `src/Service/Fetch/PageUrls.php`, `src/Service/Fetch/UrlResolver.php`, `src/Service/Catalog/CatalogDocument.php`
- Test: `tests/Service/Reader/Slideshow/SlideCaptionResolverTest.php`, `tests/Service/Catalog/CatalogDocumentTest.php` (one new test each)

**Interfaces:**
- Consumes: `App\Service\Url\AbsoluteHttpUrl::matches(string): bool` and `::orNull(?string): ?string`, both unchanged. `AbsoluteHttpUrlTest` already pins upper-case schemes, site-relative and protocol-relative paths, foreign schemes and a scheme that is not at the start.
- Produces: nothing new.

- [ ] **Step 1: Write the two failing tests**

These two checks were the only case-sensitive ones.

`tests/Service/Reader/Slideshow/SlideCaptionResolverTest.php`, after `testSkipsANonHttpAnchorForTheNextAbsoluteOne()`:
```php
    public function testTakesALinkWithAnUpperCaseScheme(): void
    {
        $slide = $this->slide('<li class="swiper-slide"><a href="HTTPS://example.com/upper">Upper</a></li>');

        self::assertSame('HTTPS://example.com/upper', (new SlideCaptionResolver())->resolve($slide)->link);
    }
```

`tests/Service/Catalog/CatalogDocumentTest.php`, after `testANonHttpFeedUrlIsRejected()`:
```php
    public function testAnUpperCaseSchemeIsAnHttpFeedUrl(): void
    {
        $document = $this->parser()->parse($this->opml([
            '<outline type="rss" text="Upper" xmlUrl="HTTPS://example.com/upper.xml"/>',
        ]));

        self::assertSame('HTTPS://example.com/upper.xml', $document->categories[0]->feeds[0]->url);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'testTakesALinkWithAnUpperCaseScheme|testAnUpperCaseSchemeIsAnHttpFeedUrl' tests/Service`
Expected: FAIL. The slide test gets `null`, and the catalog test errors with `InvalidCatalogDocumentException: Feed URL "HTTPS://example.com/upper.xml" is not http(s).`

- [ ] **Step 3: Replace every copy**

Each file gains `use App\Service\Url\AbsoluteHttpUrl;` among its `use` lines, in alphabetical order.

`src/Service/Reader/ReaderLeadImage.php`, in `restore()`:
```diff
-        if ($body === null || $leadUrl === null || preg_match('#^https?://#i', $leadUrl) !== 1) {
+        if ($body === null || $leadUrl === null || !AbsoluteHttpUrl::matches($leadUrl)) {
```

`src/Service/Reader/LeadFigureCaptions.php`, in `captionFor()`. The `''` guard goes, because `matches('')` is already false:
```diff
-        if ($leadUrl === null || $leadUrl === '' || preg_match('#^https?://#i', $leadUrl) !== 1) {
+        if ($leadUrl === null || !AbsoluteHttpUrl::matches($leadUrl)) {
```

`src/Service/Reader/HeroImageSelector.php`, in `select()`:
```diff
-        if ($candidate === null || preg_match('#^https?://#i', $candidate->url) !== 1) {
+        if ($candidate === null || !AbsoluteHttpUrl::matches($candidate->url)) {
```

`src/Service/Reader/Repair/ImageButtonUnwrapper.php`, in `isContentPhoto()`:
```diff
-        if (preg_match('#^https?://#i', $source) !== 1) {
+        if (!AbsoluteHttpUrl::matches($source)) {
```

`src/Service/Reader/ImageProxyUrl.php` has no `use` block yet: put `use App\Service\Url\AbsoluteHttpUrl;` after the `namespace` line, with one blank line on each side. Replace all three `self::isHttpUrl(` with `AbsoluteHttpUrl::matches(` (Edit with `replace_all`) and delete the private method with the blank line above it:
```php

    private static function isHttpUrl(string $value): bool
    {
        return preg_match('#^https?://#i', $value) === 1;
    }
```

`src/Service/Reader/Repair/SubstackGatedVideoPlaceholder.php`: delete the constant line
```php
    private const string HTTP_URL_PATTERN = '#^https?://#i';
```
and replace the body of `httpUrlFrom()`:
```diff
-        $value = $page->querySelector($selector)?->getAttribute($attribute);
-
-        return $value !== null && preg_match(self::HTTP_URL_PATTERN, $value) === 1 ? $value : null;
+        return AbsoluteHttpUrl::orNull($page->querySelector($selector)?->getAttribute($attribute));
```

`src/Service/Reader/Slideshow/SlideCaptionResolver.php`, in `firstLink()`:
```diff
-            if ($href !== null && (str_starts_with($href, 'http://') || str_starts_with($href, 'https://'))) {
+            if ($href !== null && AbsoluteHttpUrl::matches($href)) {
```

`src/Service/Fetch/PageUrls.php`, the last line of `httpUrl()`:
```diff
-        return 1 === preg_match('#^https?://#i', $resolved) ? $resolved : null;
+        return AbsoluteHttpUrl::orNull($resolved);
```

`src/Service/Fetch/UrlResolver.php`, in `resolve()`:
```diff
-        if (preg_match('#^https?://#i', $location) === 1) {
+        if (AbsoluteHttpUrl::matches($location)) {
```

`src/Service/Catalog/CatalogDocument.php`, in the outline check:
```diff
-        if (1 !== preg_match('#^https?://#', $url)) {
+        if (!AbsoluteHttpUrl::matches($url)) {
```

- [ ] **Step 4: Check nothing hand-written is left**

```bash
git grep -nE "'#\^https\?://#i?'|str_starts_with\(\\\$href, 'http" -- src
```
Expected: exactly one line, `src/Service/Url/AbsoluteHttpUrl.php`. The foreign-scheme patterns `#^(?!https?://)…` in `PageUrls` and `ImageSourceUrl` do not match this grep. They answer a different question and stay.

- [ ] **Step 5: Run the affected suites**

Run:
```bash
php bin/phpunit tests/Service/Url tests/Service/Reader tests/Service/Fetch tests/Service/Catalog tests/Service/Scraper tests/Service/Discovery
```
Expected: PASS, the two new tests included.

- [ ] **Step 6: Deletion check**

In `AbsoluteHttpUrl::matches()`, change `'#^https?://#i'` to `'#^https?://#'`. Run `php bin/phpunit --filter 'testTakesALinkWithAnUpperCaseScheme|testAnUpperCaseSchemeIsAnHttpFeedUrl' tests/Service`. Expected: both fail. Restore the `i` with the Edit tool. Paste both outputs into the report.

- [ ] **Step 7: Gates**

Run: `composer check`, `composer md`, PhpStorm `lint_files` on the changed PHP files.
Expected: all clean.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Reader/ReaderLeadImage.php src/Service/Reader/LeadFigureCaptions.php src/Service/Reader/ImageProxyUrl.php src/Service/Reader/HeroImageSelector.php src/Service/Reader/Repair/ImageButtonUnwrapper.php src/Service/Reader/Repair/SubstackGatedVideoPlaceholder.php src/Service/Reader/Slideshow/SlideCaptionResolver.php src/Service/Fetch/PageUrls.php src/Service/Fetch/UrlResolver.php src/Service/Catalog/CatalogDocument.php tests/Service/Reader/Slideshow/SlideCaptionResolverTest.php tests/Service/Catalog/CatalogDocumentTest.php
git commit -m "refactor(#1168): one absolute-http-url check"
```

---

### Task A3: `JsonLd`, and `SchemaOrgAccess` reads the raw document

**Files:**
- Create: `src/Service/Html/JsonLd.php`
- Create: `tests/Service/Html/JsonLdTest.php`
- Modify: `src/Service/Scraper/Layer/JsonLdLayer.php` (the `extract()` body and one import)
- Modify: `src/Service/Reader/Media/Source/JsonLdMediaSource.php` (`find()`, `declarationsIn()` and `collect()` become `find()`, `declarationsIn()` and `urlsIn()`; the class docblock's first paragraph)
- Modify: `src/Service/Reader/Media/Source/ScriptEmbedSource.php` (one condition, one import)
- Modify: `src/Service/Reader/Paywall/SchemaOrgAccess.php` (rewritten in full)
- Modify: `src/Service/Reader/Paywall/PaywallSignals.php` (the `isPreview()` signature)
- Modify: `src/Service/Reader/ArticleExtractor.php` (two lines become three; one docblock phrase)
- Test: `tests/Service/Reader/Paywall/SchemaOrgAccessTest.php` (every call takes a document; one new test), `tests/Service/Reader/Paywall/PaywallSignalsTest.php` (the two helpers)

**Interfaces:**
- Produces:
  - `App\Service\Html\JsonLd::scriptsIn(HTMLDocument $document): list<Element>`: the `script[type="application/ld+json"]` elements, in document order.
  - `JsonLd::isScript(Element $script): bool`: whether that same selector matches the script.
  - `JsonLd::decode(Element $script): array<mixed>`: the decoded block, or `[]` when it is not a JSON object or array. An empty block has no nodes, so every walker treats it as "nothing declared".
  - `JsonLd::nodesIn(array $block): iterable<array<mixed>>`: the block and every array inside it, parent before children, in document order.
  - `SchemaOrgAccess::declaredIn(HTMLDocument $rawPage): AccessDeclaration`
  - `PaywallSignals::isPreview(HTMLDocument $rawPage, ?HTMLDocument $normalized): bool`
- Consumes: `RawPage::parse(string $html, string $url)`, whose `document` falls back to `HTMLDocument::createEmpty()`.

- [ ] **Step 1: Write the failing test for the reader**

`tests/Service/Html/JsonLdTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Html;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Html\JsonLd;
use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

final class JsonLdTest extends TestCase
{
    public function testFindsOnlyTheScriptsThatCarryJsonLdInDocumentOrder(): void
    {
        $document = $this->document(
            '<html><head><script type="application/ld+json">{"a":1}</script><script>var b = 2;</script>'
            . '<script type="application/json">{"c":3}</script></head>'
            . '<body><script type="application/ld+json">[{"d":4}]</script></body></html>',
        );

        $scripts = JsonLd::scriptsIn($document);

        self::assertCount(2, $scripts);
        self::assertSame(['a' => 1], JsonLd::decode($scripts[0]));
        self::assertSame([['d' => 4]], JsonLd::decode($scripts[1]));
    }

    public function testRecognisesAJsonLdScriptOnItsOwn(): void
    {
        $scripts = $this->document('<script type="application/ld+json">{}</script><script>var a = 1;</script>')
            ->getElementsByTagName('script');
        $jsonLd = $scripts->item(0);
        $plain = $scripts->item(1);
        self::assertNotNull($jsonLd);
        self::assertNotNull($plain);

        self::assertTrue(JsonLd::isScript($jsonLd));
        self::assertFalse(JsonLd::isScript($plain));
    }

    public function testABlockThatIsNotAJsonObjectOrArrayDecodesToNothing(): void
    {
        $scripts = JsonLd::scriptsIn($this->document(
            '<script type="application/ld+json">{not json</script>'
            . '<script type="application/ld+json">"a string"</script>'
            . '<script type="application/ld+json">42</script>',
        ));

        self::assertSame([[], [], []], array_map(JsonLd::decode(...), $scripts));
    }

    public function testWalksEveryNodeParentFirstInDocumentOrder(): void
    {
        $article = ['@type' => 'Article', 'video' => ['contentUrl' => 'https://x.test/a.mp4']];
        $page = ['@type' => 'WebPage'];
        $block = ['@graph' => [$article, 'a bare string', $page]];

        $nodes = [];
        foreach (JsonLd::nodesIn($block) as $node) {
            $nodes[] = $node;
        }

        self::assertSame([$block, $block['@graph'], $article, $article['video'], $page], $nodes);
    }

    private function document(string $html): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return $document;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Html/JsonLdTest.php`
Expected: FAIL, 4 errors: `Class "App\Service\Html\JsonLd" not found`.

- [ ] **Step 3: Write the reader**

`src/Service/Html/JsonLd.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Html;

use Dom\Element;
use Dom\HTMLDocument;

/** A page's schema.org JSON-LD: which scripts carry it, what a block decodes to, and every node in it. */
final class JsonLd
{
    private const string SCRIPT_SELECTOR = 'script[type="application/ld+json"]';

    /** @return list<Element> */
    public static function scriptsIn(HTMLDocument $document): array
    {
        $scripts = [];
        foreach ($document->querySelectorAll(self::SCRIPT_SELECTOR) as $script) {
            $scripts[] = $script;
        }

        return $scripts;
    }

    public static function isScript(Element $script): bool
    {
        return $script->matches(self::SCRIPT_SELECTOR);
    }

    /** @return array<mixed> */
    public static function decode(Element $script): array
    {
        $decoded = json_decode((string) $script->textContent, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<mixed> $block
     *
     * @return iterable<array<mixed>>
     */
    public static function nodesIn(array $block): iterable
    {
        yield $block;
        foreach ($block as $child) {
            if (\is_array($child)) {
                yield from self::nodesIn($child);
            }
        }
    }
}
```
`querySelectorAll()` items are typed `Dom\Element`: at `fd5fa734`, PHPStan level max accepts `JsonLdMediaSource` passing them straight to `PageFurniture::holds(Element)` and `PageTextBlocks::before(Element)`, so the append needs no `instanceof` guard.

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Html/JsonLdTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Make the paywall tests hand over a document (red)**

`tests/Service/Reader/Paywall/SchemaOrgAccessTest.php`:
- Replace every `SchemaOrgAccess::declaredIn($html)` with `SchemaOrgAccess::declaredIn($this->document($html))` (Edit with `replace_all`).
- Add the imports `use App\Service\Html\HtmlDocumentParser;` and `use Dom\HTMLDocument;`.
- Add this test after `testAnOrdinaryScriptIsNotReadAsJsonLd()`:
```php
    public function testReadsOnlyTheScriptsTheJsonLdSelectorMatches(): void
    {
        $html = '<html><head><script type="application/ld+json; charset=utf-8">{"isAccessibleForFree":false}'
            . '</script></head><body></body></html>';

        self::assertSame(AccessDeclaration::Undeclared, SchemaOrgAccess::declaredIn($this->document($html)));
    }
```
- Add this helper after `page()`:
```php
    private function document(string $html): HTMLDocument
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);

        return $document;
    }
```

`tests/Service/Reader/Paywall/PaywallSignalsTest.php`:
- Add `use Dom\HTMLDocument;`.
- Replace the body of `testWithoutADocumentTheDeclarationStillDecides()`:
```php
        $premium = '<script type="application/ld+json">{"isAccessibleForFree":"False"}</script>';

        self::assertFalse(PaywallSignals::isPreview($this->rawPage(''), null));
        self::assertTrue(PaywallSignals::isPreview($this->rawPage($premium), null));
```
- Replace `isPreview()` and add `rawPage()` after it:
```php
    private function isPreview(string $html): bool
    {
        return PaywallSignals::isPreview($this->rawPage($html), HtmlDocumentParser::parseOrNull($html));
    }

    private function rawPage(string $html): HTMLDocument
    {
        return HtmlDocumentParser::parseOrNull($html) ?? HTMLDocument::createEmpty();
    }
```

Run: `php bin/phpunit tests/Service/Reader/Paywall`
Expected: FAIL. `SchemaOrgAccessTest` and `PaywallSignalsTest` error with `TypeError: … Argument #1 ($html) must be of type string, Dom\HTMLDocument given`.

- [ ] **Step 6: Route every JSON-LD read through `JsonLd`**

`src/Service/Reader/Paywall/SchemaOrgAccess.php`, rewritten in full:
```php
<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Html\JsonLd;
use Dom\HTMLDocument;

/**
 * The publisher's own paywall declaration: schema.org `isAccessibleForFree`,
 * the markup Google documents for paywalled content. Read from the raw page,
 * because FetchedPageNormalizer strips every <script> from the normalized one.
 */
final readonly class SchemaOrgAccess
{
    private const string KEY = 'isAccessibleForFree';

    public static function declaredIn(HTMLDocument $rawPage): AccessDeclaration
    {
        $declarations = [];
        foreach (JsonLd::scriptsIn($rawPage) as $script) {
            array_push($declarations, ...self::declarationsIn(JsonLd::decode($script)));
        }

        if (\in_array(false, $declarations, true)) {
            return AccessDeclaration::Paywalled;
        }

        return $declarations === [] ? AccessDeclaration::Undeclared : AccessDeclaration::Free;
    }

    /**
     * @param array<mixed> $block
     *
     * @return list<bool> every isAccessibleForFree in the block, as a boolean
     */
    private static function declarationsIn(array $block): array
    {
        $declarations = [];
        foreach (JsonLd::nodesIn($block) as $node) {
            $declared = self::asBoolean($node[self::KEY] ?? null);
            if ($declared !== null) {
                $declarations[] = $declared;
            }
        }

        return $declarations;
    }

    private static function asBoolean(mixed $value): ?bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (!\is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'true', 'http://schema.org/true', 'https://schema.org/true' => true,
            'false', 'http://schema.org/false', 'https://schema.org/false' => false,
            default => null,
        };
    }
}
```

`src/Service/Reader/Paywall/PaywallSignals.php`:
```diff
-    public static function isPreview(string $html, ?HTMLDocument $normalized): bool
+    public static function isPreview(HTMLDocument $rawPage, ?HTMLDocument $normalized): bool
     {
-        return match (SchemaOrgAccess::declaredIn($html)) {
+        return match (SchemaOrgAccess::declaredIn($rawPage)) {
```

`src/Service/Reader/ArticleExtractor.php`, in `extract()`. The raw page is already parsed once for the media scan, and now the paywall check reads the same parse:
```diff
-        $paywalled = PaywallSignals::isPreview($page->html, $normalized);
-        $media = $this->mediaScanner->scan(RawPage::parse($page->html, $page->finalUrl), $feedMedia);
+        $rawPage = RawPage::parse($page->html, $page->finalUrl);
+        $paywalled = PaywallSignals::isPreview($rawPage->document, $normalized);
+        $media = $this->mediaScanner->scan($rawPage, $feedMedia);
```
In the class docblock:
```diff
- * readability's own extraction is thin (#748). PaywallSignals reads the same
- * normalised document and raw source, trusting the publisher's declaration and
+ * readability's own extraction is thin (#748). PaywallSignals reads the same
+ * normalised document and raw page, trusting the publisher's declaration and
```

`src/Service/Scraper/Layer/JsonLdLayer.php`: add `use App\Service\Html\JsonLd;` and replace the loop in `extract()`:
```php
    public function extract(HTMLDocument $doc, string $baseUrl): array
    {
        $articles = new JsonLdArticles(new PageUrls($baseUrl));
        foreach (JsonLd::scriptsIn($doc) as $script) {
            $articles->collect(JsonLd::decode($script));
            if ($articles->isFull()) {
                break;
            }
        }

        return $articles->all();
    }
```
(An undecodable block is `[]`, and `JsonLdArticles::collect([])` adds nothing, so the old `continue` branches are covered.)

`src/Service/Reader/Media/Source/JsonLdMediaSource.php`: add `use App\Service\Html\JsonLd;`. Replace the first paragraph of the class docblock:
```diff
- * A publisher's own schema.org markup. `Service/Scraper/JsonLdArticles.php`
- * walks the same blocks but exposes only modelled Article cards, nothing this
- * layer could reuse for a bare media URL — so this is its own walker.
+ * A publisher's own schema.org markup. `JsonLdArticles` reads the same blocks
+ * but models only Article cards, so this source walks every node for a bare
+ * media URL instead.
```
Then replace `find()`, `declarationsIn(string $jsonLd)` and `collect(array $node)` with these three methods. `thumbnailIn()`, `firstPlayable()` and `toCandidate()` stay as they are:
```php
    public function find(RawPage $page): array
    {
        $found = [];
        foreach (JsonLd::scriptsIn($page->document) as $script) {
            if (PageFurniture::holds($script)) {
                continue;
            }
            foreach ($this->declarationsIn(JsonLd::decode($script)) as $declaration) {
                $candidate = $this->firstPlayable($declaration, $page->blocks->before($script));
                if ($candidate !== null) {
                    $found[$candidate->url] ??= $candidate;
                }
            }
        }

        return array_values($found);
    }

    /**
     * One node is one asset: its URL keys are gathered together, with the poster
     * schema.org places beside them (`thumbnailUrl` on the same node).
     *
     * @param array<mixed> $block
     *
     * @return list<array{urls: list<string>, poster: ?string}>
     */
    private function declarationsIn(array $block): array
    {
        $declarations = [];
        foreach (JsonLd::nodesIn($block) as $node) {
            $urls = $this->urlsIn($node);
            if ($urls !== []) {
                $declarations[] = ['urls' => $urls, 'poster' => $this->thumbnailIn($node)];
            }
        }

        return $declarations;
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    private function urlsIn(array $node): array
    {
        $urls = [];
        foreach (self::URL_KEYS as $key) {
            if (isset($node[$key]) && \is_string($node[$key])) {
                $urls[] = $node[$key];
            }
        }

        return $urls;
    }
```

`src/Service/Reader/Media/Source/ScriptEmbedSource.php`: add `use App\Service\Html\JsonLd;`. The skip now uses the very selector `JsonLdMediaSource` reads with, so the two can never disagree about which scripts are JSON-LD:
```diff
-            if ($script->getAttribute('type') === 'application/ld+json') {
+            if (JsonLd::isScript($script)) {
```

- [ ] **Step 7: Check nothing hand-written is left**

```bash
git grep -n "application/ld+json" -- src
git grep -n "json_decode" -- src/Service/Reader/Media/Source src/Service/Reader/Paywall src/Service/Scraper/Layer
```
Expected: the first prints only `src/Service/Html/JsonLd.php` and the `JsonLdMediaSource`/`ScriptEmbedSource` docblocks, if they still name the MIME type. The second prints nothing.

- [ ] **Step 8: Run the affected suites**

Run:
```bash
php bin/phpunit tests/Service/Html tests/Service/Reader/Paywall tests/Service/Reader/Media tests/Service/Scraper tests/Service/Reader/ArticleExtractorTest.php
```
Expected: PASS. That includes `ArticleExtractorTest`'s four paywall fixtures (`article-paywalled-jsonld-boolean.html`, `…-string.html`, `…-dom-block.html`, `…-memberful.html`), which prove the raw-page wiring end to end.

- [ ] **Step 9: Deletion checks**

1. In `JsonLd::nodesIn()`, delete the `foreach` (yield the block only). Expected: `JsonLdTest::testWalksEveryNodeParentFirstInDocumentOrder`, `SchemaOrgAccessTest::testTheStringFalseUnderHasPartDeclaresAPaywall` and `JsonLdMediaSourceTest::testFindsAVideoObjectNestedUnderAnArticle` fail. Restore.
2. In `JsonLd::scriptsIn()`, change the selector constant to `'script'`. Expected: `JsonLdTest::testFindsOnlyTheScriptsThatCarryJsonLdInDocumentOrder` and `SchemaOrgAccessTest::testAnOrdinaryScriptIsNotReadAsJsonLd` fail. Restore.
3. In `ArticleExtractor::extract()`, pass `HTMLDocument::createEmpty()` to `isPreview()` instead of `$rawPage->document`. Expected: `ArticleExtractorTest::testFlagsAPaywalledArticleDeclaredInJsonLd` fails. Restore.

Restore each by hand with the Edit tool. Paste all outputs into the report.

- [ ] **Step 10: Gates**

Run: `composer check`, `composer md` (`ArticleExtractor` gained one line, so check that its method stays under the length limit), and PhpStorm `lint_files` on the changed PHP files.
Expected: all clean.

- [ ] **Step 11: Commit**

```bash
git add src/Service/Html/JsonLd.php tests/Service/Html/JsonLdTest.php src/Service/Scraper/Layer/JsonLdLayer.php src/Service/Reader/Media/Source/JsonLdMediaSource.php src/Service/Reader/Media/Source/ScriptEmbedSource.php src/Service/Reader/Paywall/SchemaOrgAccess.php src/Service/Reader/Paywall/PaywallSignals.php src/Service/Reader/ArticleExtractor.php tests/Service/Reader/Paywall/SchemaOrgAccessTest.php tests/Service/Reader/Paywall/PaywallSignalsTest.php
git commit -m "refactor(#1168): one json-ld reader; the paywall check reads the raw document"
```

---

### Task A4: `Base64UrlSafe::encodeUnpadded` for the four encoders

**Files:**
- Modify: `composer.json`, `composer.lock` (the direct `paragonie/constant_time_encoding` requirement)
- Modify: `src/Pagination/EntryCursor.php`, `src/Pagination/RecommendationCursor.php`, `src/Service/OAuth/OAuthStateStore.php`, `src/Service/Auth/PasswordResetter.php`
- Test: `tests/Pagination/EntryCursorTest.php`, `tests/Pagination/RecommendationCursorTest.php`, `tests/Service/Auth/PasswordResetterTest.php` (one pin each). `OAuthStateStoreTest::testTheCodeChallengeIsTheS256OfTheVerifier` already pins the PKCE challenge against a hand-written oracle.

**Interfaces:**
- Consumes: `ParagonIE\ConstantTime\Base64UrlSafe::encodeUnpadded(string): string` (v3.1.3, already locked).
- Produces: nothing new. All four outputs are byte-identical.

- [ ] **Step 1: Write the pins**

`tests/Pagination/RecommendationCursorTest.php`, after `testEncodeStripsBase64Padding()`:
```php
    public function testEncodesThePairAsUnpaddedBase64Url(): void
    {
        self::assertSame('MXwxMg', RecommendationCursor::encode(1, 12));
    }
```
(`"1|12"` is the bytes `31 7C 31 32`. Standard base64 gives `MXwxMg==`, and unpadded that is `MXwxMg`.)

`tests/Pagination/EntryCursorTest.php`, after `testEncodeIsUrlSafeAndOpaque()`:
```php
    public function testEncodesTheInstantAndIdAsUnpaddedBase64Url(): void
    {
        $expected = rtrim(strtr(base64_encode('2026-01-01T00:00:00+00:00|1'), '+/', '-_'), '=');

        self::assertSame($expected, EntryCursor::encode(new \DateTimeImmutable('2026-01-01T00:00:00Z'), 1));
    }
```
(The test keeps the hand-written expression on purpose: it is the independent oracle, like the one in `OAuthStateStoreTest`.)

`tests/Service/Auth/PasswordResetterTest.php`, after `testGenerateAndSetReturnsAUsablePlaintext()`:
```php
    public function testTheGeneratedPasswordIsThirtyTwoUrlSafeCharacters(): void
    {
        $user = (new UserFactory($this->em, $this->hasher()))->create('reset-alphabet@example.com');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32}$/', $this->resetter()->generateAndSet($user));
    }
```
(24 random bytes encode to exactly 32 characters, with no padding.)

- [ ] **Step 2: Run the pins**

Run: `php bin/phpunit tests/Pagination tests/Service/Auth/PasswordResetterTest.php tests/Service/OAuth/OAuthStateStoreTest.php`
Expected: PASS. These are pins on develop's behaviour.

- [ ] **Step 3: Declare the dependency and switch the encoders**

```bash
composer require "paragonie/constant_time_encoding:^3.1"
git diff --stat composer.json composer.lock
```
Expected: `composer.json` gains the line in `require`. `composer.lock` changes only its `content-hash`, because the package stays at v3.1.3. If Composer wants to change any other package, stop and report.

Each of the four files gains `use ParagonIE\ConstantTime\Base64UrlSafe;` among its `use` lines, in alphabetical order.

`src/Pagination/EntryCursor.php` and `src/Pagination/RecommendationCursor.php`, each in `encode()`:
```diff
-        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
+        return Base64UrlSafe::encodeUnpadded($raw);
```

`src/Service/OAuth/OAuthStateStore.php`, in `challengeFor()`:
```diff
-        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
+        return Base64UrlSafe::encodeUnpadded(hash('sha256', $codeVerifier, true));
```

`src/Service/Auth/PasswordResetter.php`, in `generateAndSet()` (unchanged by #1164 and #1167):
```diff
-        $plain = rtrim(strtr(base64_encode(random_bytes(self::GENERATED_LENGTH)), '+/', '-_'), '=');
+        $plain = Base64UrlSafe::encodeUnpadded(random_bytes(self::GENERATED_LENGTH));
```

- [ ] **Step 4: Run the pins again**

Run: `php bin/phpunit tests/Pagination tests/Service/Auth/PasswordResetterTest.php tests/Service/OAuth tests/Controller/Api/OAuthFlowTest.php tests/Controller/Api/EntryControllerTest.php`
Expected: PASS.

Then `git grep -n "rtrim(strtr(base64_encode" -- src`. Expected: nothing.

- [ ] **Step 5: Deletion check**

In `RecommendationCursor::encode()`, change `encodeUnpadded` to `encode`. Expected: `testEncodesThePairAsUnpaddedBase64Url` and `testEncodeStripsBase64Padding` fail. Restore with the Edit tool. Paste both outputs into the report.

- [ ] **Step 6: Gates**

Run: `composer check`, `composer md`, PhpStorm `lint_files` on the four `src` files and three tests.
Expected: all clean.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock src/Pagination/EntryCursor.php src/Pagination/RecommendationCursor.php src/Service/OAuth/OAuthStateStore.php src/Service/Auth/PasswordResetter.php tests/Pagination/EntryCursorTest.php tests/Pagination/RecommendationCursorTest.php tests/Service/Auth/PasswordResetterTest.php
git commit -m "refactor(#1168): one constant-time base64url encoder"
```

---

### Task A5: `ResponseHeader::first`

**Files:**
- Create: `src/Service/Fetch/ResponseHeader.php`
- Create: `tests/Service/Fetch/ResponseHeaderTest.php`
- Modify: `src/Service/Fetch/LandedResponse.php`, `src/Service/Fetch/RedirectFollower.php`, `src/Service/Fetch/ResponseClassifier.php`, `src/Service/Recommendation/OpenAiCompatibleChatClient.php`, `src/Service/Catalog/CatalogFaviconFetcher.php`

**Interfaces:**
- Produces: `App\Service\Fetch\ResponseHeader::first(ResponseInterface $response, string $name): ?string`. It returns the first value of the lower-cased header name, or `null` when the header is absent or the response throws a transport error. It never throws for an error status, because it reads with `getHeaders(false)`.
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

`tests/Service/Fetch/ResponseHeaderTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\ResponseHeader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseHeaderTest extends TestCase
{
    public function testReadsTheFirstValueOfARepeatedHeader(): void
    {
        $response = $this->response(new MockResponse('', ['response_headers' => ['link' => ['<a>', '<b>']]]));

        self::assertSame('<a>', ResponseHeader::first($response, 'link'));
    }

    public function testAnAbsentHeaderHasNoValue(): void
    {
        self::assertNull(ResponseHeader::first($this->response(new MockResponse('')), 'location'));
    }

    public function testAnErrorStatusStillShowsItsHeaders(): void
    {
        $response = $this->response(new MockResponse('', [
            'http_code' => 503,
            'response_headers' => ['retry-after' => ['120']],
        ]));

        self::assertSame('120', ResponseHeader::first($response, 'retry-after'));
    }

    public function testAResponseThatCannotBeReadHasNoHeaders(): void
    {
        $response = $this->response(new MockResponse('', ['error' => 'connection reset']));

        self::assertNull(ResponseHeader::first($response, 'location'));
    }

    private function response(MockResponse $mock): ResponseInterface
    {
        return (new MockHttpClient($mock))->request('GET', 'https://example.test/');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Fetch/ResponseHeaderTest.php`
Expected: FAIL, 4 errors: `Class "App\Service\Fetch\ResponseHeader" not found`.

- [ ] **Step 3: Write the helper**

`src/Service/Fetch/ResponseHeader.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResponseHeader
{
    public static function first(ResponseInterface $response, string $name): ?string
    {
        try {
            return $response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Service/Fetch/ResponseHeaderTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Replace every copy**

`src/Service/Fetch/LandedResponse.php`: `header()` delegates. Delete the now-unused `use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;`.
```php
    public function header(string $name): ?string
    {
        return ResponseHeader::first($this->response, $name);
    }
```

`src/Service/Fetch/RedirectFollower.php`: in `redirectTarget()`,
```diff
-        $location = $this->header($response, 'location');
+        $location = ResponseHeader::first($response, 'location');
```
and delete the private method with the blank line above it. `ExceptionInterface` stays imported, because two other `catch` blocks use it.
```php

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            return $response->getHeaders(false)[$name][0] ?? null;
        } catch (ExceptionInterface) {
            return null;
        }
    }
```

`src/Service/Fetch/ResponseClassifier.php`: replace all four `$this->header($response, ` with `ResponseHeader::first($response, ` (Edit with `replace_all`: `etag`, `last-modified`, `location`, `retry-after`). Delete the private method with the blank line above it. `ExceptionInterface` stays imported for the other two `catch` blocks.
```php

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return null;
        }

        return $headers[$name][0] ?? null;
    }
```

`src/Service/Recommendation/OpenAiCompatibleChatClient.php`: add `use App\Service\Fetch\ResponseHeader;`, and in `retryAfterSeconds()`:
```diff
-        $header = $response->getHeaders(false)['retry-after'][0] ?? null;
+        $header = ResponseHeader::first($response, 'retry-after');
```

`src/Service/Catalog/CatalogFaviconFetcher.php`: add `use App\Service\Fetch\ResponseHeader;`, and replace `redirectLocation()` with its docblock. The helper no longer throws, so the four `@throws` lines go.
```php
    private function redirectLocation(ResponseInterface $response): string
    {
        return ResponseHeader::first($response, 'location')
            ?? throw new FaviconUnavailableException('Redirect response carried no Location header.');
    }
```
The four `Symfony\Contracts\HttpClient\Exception\*` imports stay: `download()` catches them and `fetchFollowingRedirects()`'s docblock names them.

- [ ] **Step 6: Check nothing hand-written is left**

`git grep -n "getHeaders(false)\[" -- src`
Expected: exactly one line, in `src/Service/Fetch/ResponseHeader.php`.

- [ ] **Step 7: Run the affected suites**

Run:
```bash
php bin/phpunit tests/Service/Fetch tests/Service/Catalog tests/Service/Recommendation tests/Service/Reader/HtmlPageFetcherTest.php tests/Service/Refresh
```
Expected: PASS.

- [ ] **Step 8: Deletion check**

In `ResponseHeader::first()`, replace `[0]` with `[1]`. Expected: `testReadsTheFirstValueOfARepeatedHeader`, `LandedResponseTest` and the redirect tests in `RedirectFollowerTest` fail. Restore with the Edit tool. Paste both outputs into the report.

- [ ] **Step 9: Gates**

Run: `composer check`, `composer md`, PhpStorm `lint_files` on the changed PHP files.
Expected: all clean.

- [ ] **Step 10: Commit**

```bash
git add src/Service/Fetch/ResponseHeader.php tests/Service/Fetch/ResponseHeaderTest.php src/Service/Fetch/LandedResponse.php src/Service/Fetch/RedirectFollower.php src/Service/Fetch/ResponseClassifier.php src/Service/Recommendation/OpenAiCompatibleChatClient.php src/Service/Catalog/CatalogFaviconFetcher.php
git commit -m "refactor(#1168): one response-header lookup"
```

---

### Finishing PR A

- [ ] **Step 1: Every gate on the whole branch**

```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
```
Also run PhpStorm `lint_files` on every PHP file in `git diff --name-only --relative origin/develop -- '*.php'`.
Expected: all green. Before running the MySQL leg, check that the containers are current (memory: "Check the container is current"). If `infection:diff` reports an escaped mutant on a line this PR touched, add the test that kills it in the task that owns the line, and never lower `minMsi`. Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no deprecation or error from a file this PR touched.

- [ ] **Step 2: /simplify**

Invoke the `simplify` skill on the branch diff. Apply only fixes that keep every gate green and do not undo a ruling in this plan (Scope decisions, Deliberate behaviour changes). Re-run Step 1's gates if anything changed, and commit as `refactor(#1168): simplify pass`.

- [ ] **Step 3: SDD final review**

Dispatch the final reviewer (superpowers:subagent-driven-development, final review) over `git diff origin/develop...HEAD`, with the spec, this plan and these attack points:
1. **Whitespace equivalence.** For ASCII input, every former caller of a hand-written collapse or of `LeadingEngagementRules::collapse()` must produce byte-identical output. The only intended difference is Unicode whitespace in the three former non-`/u` copies. `CategoryNormalizer` must be untouched.
2. **JSON-LD walk order and semantics.** `JsonLd::nodesIn()` must visit nodes in the order the old `JsonLdMediaSource::collect()` did (the node, then its array children depth-first), or the "first playable URL" choice changes. `SchemaOrgAccess` must still say "any `false` wins, else any `true`, else undeclared". `ArticleExtractor` must parse the raw page exactly once.
3. **The paywall selector tightening.** Is there any fixture under `tests/Fixtures` whose JSON-LD `type` attribute is not exactly `application/ld+json`? Run `grep -rho 'type="[^"]*ld+json[^"]*"' tests/Fixtures | sort | uniq -c`. Anything other than the exact value is a regression to report, not to paper over.
4. **Byte-identical encodings.** The four base64url outputs must be unchanged. `composer.lock` may differ only in `content-hash`. No decoder changed, and `src/Service/OAuth/Oidc/` and `OidcBoundaryTest` are untouched.
5. **Header lookup.** `ResponseClassifier`'s conditional-GET validators (`etag`, `last-modified`), `Retry-After` and `Location` must read exactly as before. `CatalogFaviconFetcher`'s error mapping (unavailable vs rejected) must be unchanged.
6. **No wire change:** `git diff origin/develop --stat -- src/Http src/Controller` must be empty.
7. **The PR body and every commit message are free of closing keywords for #1168.**

Fix every finding the reviewer rates Important or above in the task that owns the line, re-run the gates, and record the rest in the PR body.

- [ ] **Step 4: Open the PR**

```bash
git push -u origin refactor/1168-shared-text-and-http-helpers
git log origin/develop..HEAD --format=%B | grep -inE '(close|fix|resolve)[sd]?:? +#1168' && echo 'STOP: closing keyword in a commit' || true
gh pr create --base develop --title "refactor(#1168): shared text, URL, JSON-LD, base64url and header helpers" --body "$(cat <<'BODY'
Refs #1168 (PR A of two).

One home for each helper the issue found copied: `Whitespace::collapse`, `AbsoluteHttpUrl` at every http(s) check, `JsonLd` for every JSON-LD read, paragonie's constant-time `Base64UrlSafe` for the four encoders, and `ResponseHeader::first` for every header lookup.

Deliberate behaviour changes (nothing on the wire):
1. A no-break (or other Unicode) space in a lead-figure, slide or teaser caption now collapses like everywhere else.
2. A slide caption link and a shipped-catalog feed URL accept an upper-case scheme, like every other http(s) check.
3. The paywall declaration is read from the scripts `script[type="application/ld+json"]` selects in the raw page, the same set the media source reads; `ScriptEmbedSource` skips exactly that set.
4. An unreadable `Location`/`Retry-After` reads as absent in `CatalogFaviconFetcher` and `OpenAiCompatibleChatClient`, as it already did in the fetch path.

`paragonie/constant_time_encoding` becomes a direct requirement at the version already locked.
BODY
)"
```
Then check the body: `gh pr view --json body --jq .body | grep -inE '(close|fix|resolve)[sd]?:? +#1168'`. Expected: nothing.

- [ ] **Step 5: Merge when green**

Start a Monitor on `gh pr checks <PR> --watch --fail-fast` (one command, no loop). When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`. If it exits non-zero, read the failing job, fix it on the branch and restart the Monitor. CI runs the tip of phptramp's `develop`: if only the tramp step fails, run `composer show larspohlmann/phptramp` before hunting in application code.

- [ ] **Step 6: Verify the issue stayed open**

`gh issue view 1168 --json state --jq .state`. Expected: `OPEN`. If it closed, reopen it and edit the merged PR's body.

---

# PR B

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and cut the branch**

```bash
git status --short && git branch --show-current
git fetch origin
git log origin/develop --oneline | grep -c '(#1168)'
gh issue view 1164 --json state --jq .state
gh issue view 1167 --json state --jq .state
gh issue view 1168 --json state --jq .state
git switch -c refactor/1168-shared-queries-config-and-fixtures origin/develop
git merge-base --is-ancestor fd5fa734 HEAD && echo 'fd5fa734 is an ancestor of HEAD'
```
Expected: a non-zero count, `CLOSED`, `CLOSED`, `OPEN`, and `fd5fa734 is an ancestor of HEAD`. Anything else: stop and report.

- [ ] **Step 2: Re-take the PR B sites**

```bash
git grep -n "MAX(\w*\.position)" -- src
git grep -nE "DELETE FROM .*IN \(:ids\)" -- src/Repository
git grep -n "f.id IN (:ids)" -- src/Repository/MailSendFailureRepository.php
git grep -n "nowAsNaiveUtc\|new \\\\DateTimeImmutable('now'" -- src
git grep -nE "min: 12|MINIMUM_PASSWORD_LENGTH|min 12 characters|at least 12 characters" -- src
git grep -nE "private function (intOption|limit|limitOption|option|number)\(" -- src/Command
git grep -n "anti-spam challenge was not solved" -- src
git grep -n "getTrialEndsAt()?->format" -- src
git grep -nE "private function (user\(string \\\$email\): User|reload\()" -- tests
git grep -n "getRepository(WorkerHeartbeat::class)" -- tests
```
Expected, against the task lists below (counts re-taken at `fd5fa734`):
- 5 `MAX(…position)` lines: `CatalogCategoryRepository`, `CatalogFeedRepository`, `SubscriptionRepository`, `SubscriptionTagRepository`, `TagRepository`.
- 3 `DELETE … IN (:ids)` lines: `RecommendationItemRepository`, `RecommendationRunLogRepository` and `RetentionRepository` (a `sprintf`). The next grep prints the fourth, `MailSendFailureRepository`'s QueryBuilder delete. (A bare `IN (:ids)` grep also prints 15 SELECT filters; those are not deletes and stay.)
- 4 clock lines: `DigestEnablement` (twice), `DigestHtmlRenderer`, and the `nowAsNaiveUtc()` mention in `NaiveUtcClock`'s docblock.
- 11 password lines: the two commands (four lines each) and three DTOs.
- 6 parser lines: `CheckCatalogUrlsCommand::limit`, `WarmCatalogFaviconsCommand::limitOption`, `RefreshFeedsCommand::intOption`, `ReaderAuditCommand::option` and `::number`, and `ReaderAuditReportCommand::option`. The last one is not in the issue; B8 folds it in.
- `AuthController` (twice).
- `AdminUserJson` (twice), `MeJson`, `AdminUserLimitsJson`.
- 32 `user(string $email)` helpers and 10 `reload(` helpers: 9 are swept, and `AiProviderConfiguratorTest::reload(string $email)` stays.
- 11 `getRepository(WorkerHeartbeat::class)` lines: `ForYouSweepTest` ×3, `WorkerRunSweepTest` ×2, and one each in `CompletionStreamHeartbeatWiringTest`, `SweepStreamHeartbeatTest`, `WorkerPresenceTest`, `AdvanceRecommendationRunsHandlerTest`, `RecommendationRunControllerTest` and `RecommendationSettingsControllerTest`.

A site that is not in these lists gets the same rewrite as its siblings. Record it in the task report.

---

### Task B1: `SeedsUsers` and `ReloadsEntities`

**Files:**
- Create: `tests/Support/SeedsUsers.php`, `tests/Support/ReloadsEntities.php`
- Modify (a `user()` copy goes): the 32 test files in the tables in Step 4
- Modify (a `reload()` copy goes): `tests/Controller/Admin/AdminUserControllerTest.php`, `tests/Controller/Api/LastLoginStampTest.php`, `tests/Service/Catalog/CatalogFeedEditorTest.php`, `tests/Service/Catalog/CatalogCategoryEditorTest.php`, `tests/Service/Subscription/SubscriptionEditorTest.php`, `tests/Service/Search/SavedSearchEditorTest.php`, `tests/Service/Ai/AiConfigurationEditorTest.php`, `tests/Service/Account/AccountPreferencesWriterTest.php`, `tests/Service/Tag/TagEditorTest.php`

**Interfaces:**
- Produces:
  - `trait App\Tests\Support\SeedsUsers { private function user(string $email): User }`. An active, persisted and flushed account through `UserFactory`, bound to the current kernel's entity manager and hasher.
  - `trait App\Tests\Support\ReloadsEntities { private function reload(object $entity): object }` (templated: returns the argument's type). It clears the current kernel's entity manager and finds the entity again by its identifier.
- Consumes: `UserFactory::create(string $email, …)`, unchanged by #1164.

No new test: this task changes fixtures only. The suites below are the net. Every swept test must pass unchanged.

- [ ] **Step 1: Write the two traits**

`tests/Support/SeedsUsers.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

trait SeedsUsers
{
    private function user(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($entityManager, $hasher))->create($email);
    }
}
```

`tests/Support/ReloadsEntities.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;

trait ReloadsEntities
{
    /**
     * clear() first: without it the identity map hands back the object the test already holds.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function reload(object $entity): object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $identifier = $entityManager->getClassMetadata($entity::class)->getIdentifierValues($entity);
        $entityManager->clear();

        $reloaded = $entityManager->find($entity::class, $identifier);
        self::assertInstanceOf($entity::class, $reloaded);

        return $reloaded;
    }
}
```
Both traits read the entity manager from the current kernel's container. That is `$this->em` in a `DbTestCase`, and the rebooted kernel's manager in a `WebTestCase`, which is what `AdminUserControllerTest::reload()` did on purpose.

- [ ] **Step 2: Pilot on one file**

`tests/Service/Tag/TagEditorTest.php`:
- Add `use App\Tests\Support\ReloadsEntities;` and `use App\Tests\Support\SeedsUsers;` to the imports.
- Make `    use ReloadsEntities;`, `    use SeedsUsers;` and a blank line the first lines inside the class body.
- Delete the class's own `user()` (variant F1 below) and `reload()` (variant R below), each with the blank line above it.
- Delete the now-unused `use App\Tests\Support\UserFactory;` and `use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;`.

Run: `php bin/phpunit tests/Service/Tag/TagEditorTest.php`
Expected: PASS, the same test count as before.

- [ ] **Step 3: Know the copies you delete**

Delete a helper only if its body is one of these variants, apart from the dates. A body that differs is not a copy: stop and report it.

Variant F1 (17 files use F1 or F2 at `fd5fa734`: 12 F1, and 5 F2 with `$this->em()` in place of `$this->em`):
```php
    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }
```
Variant F3 (7 files):
```php
    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }
```
Variant P (8 files; bare persisted account; the date differs by file, and some add `$this->em->flush();` before the return):
```php
    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }
```
Variant R (the `CatalogFeed` type is replaced by each file's entity):
```php
    private function reload(CatalogFeed $feed): CatalogFeed
    {
        $id = $feed->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(CatalogFeed::class, $id);
        self::assertInstanceOf(CatalogFeed::class, $reloaded);

        return $reloaded;
    }
```

Variant P accounts become active, password-hashed and flushed at creation, where before they were pending-verification and flushed later with the rest of the fixture. None of the eight P files reads a user's status, password or creation date, and the two that count queries (`UnsubscribeAllTest`, `OwnedTagsCacheTest`) reset their `QueryRecorder` after the fixture is flushed. `OrphanedFeedReclaimerTest`'s `self::NOW` (`2026-07-01 10:00:00`) is `UserFactory`'s creation date too.

- [ ] **Step 4: Sweep**

For every file below:
- Add the trait imports (`use App\Tests\Support\SeedsUsers;` and/or `use App\Tests\Support\ReloadsEntities;`) in alphabetical order.
- Put `    use <Trait>;` lines first in the class body, alphabetical, followed by one blank line. If the class already has trait `use` lines (for example `use EnablesMailInTests;` in `AdminUserControllerTest`), merge them alphabetically.
- Delete the helper with the blank line above it.
- Delete the imports listed as unused.

`user()` copies:

| File | Variant | Imports that become unused |
|---|---|---|
| `tests/Repository/SubscriptionPositionAndCountsTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Repository/SubscriptionCountsByUserIdTest.php` | F3 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Entity/UserPasskeyTest.php` | F1 | `App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Controller/Api/MoveFeedToTagTest.php` | F2 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Controller/Api/SubscriptionBulkTest.php` | F2 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Controller/Api/ReorderTest.php` | F2 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Command/RecommendationDrainCommandTest.php` | F3 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Recommendation/DueRecommendationRunFinderTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Recommendation/ForYouSweepTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Catalog/CatalogSubscriberTest.php` | F2 | `App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Subscription/SubscriptionEditorTest.php` | F1 (+ R) | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Subscription/SubscriptionTagSyncTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Subscription/FeedTagMoveTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Subscription/BulkSubscriberTest.php` | F2 | `App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Search/SavedSearchEditorTest.php` | F1 (+ R) | `App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Subscription/SubscriptionTagPositionsTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Ai/AiProviderConfiguratorTest.php` | F3 | `UserFactory`, `UserPasswordHasherInterface` (its `reload(string $email)` stays) |
| `tests/Service/Ai/AiConfigurationForUserTest.php` | F3 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Account/AccountPreferencesWriterTest.php` | F1 (+ R) | `App\Entity\User` (only the two helpers named it), `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Worker/WorkerRunSweepTest.php` | F3 | `App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Worker/RefreshDueFeedsHandlerTest.php` | F3 | `App\Entity\User`, `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php` | F3 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Service/Tag/TagEditorTest.php` | F1 (+ R) | done in Step 2 |
| `tests/Service/Tag/TagOrderingTest.php` | F1 | `UserFactory`, `UserPasswordHasherInterface` |
| `tests/Repository/SubscriptionEntryCountsTest.php` | P (2026-07-01, no flush) | `App\Entity\User` |
| `tests/Repository/SavedSearchMembershipLoaderTest.php` | P (2026-07-01, flush) | none |
| `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php` | P (2026-01-01) | none |
| `tests/Service/Subscription/UnsubscribeAllTest.php` | P (2026-01-01) | none |
| `tests/Service/Subscription/OwnedSubscriptionsTest.php` | P (2026-01-01) | none |
| `tests/Service/Opml/OpmlImporterTest.php` | P (2026-07-01, flush) | none |
| `tests/Service/Subscription/OwnedTagsCacheTest.php` | P (2026-01-01) | none |
| `tests/Service/OrphanedFeedReclaimerTest.php` | P (`self::NOW`, flush) | none; `NOW` is still used by the entry fixture |

`reload()` copies:

| File | Call sites | Imports that become unused |
|---|---|---|
| `tests/Controller/Admin/AdminUserControllerTest.php` | Delete `reload(int $id)` together with its docblock line `/** Re-reads through the CURRENT kernel: the seeding EM belongs to a rebooted one. */`. Then `perl -pi -e 's/\$this->reload\(\$id\)/\$this->reload(\$target)/g' tests/Controller/Admin/AdminUserControllerTest.php`. Every one of the 8 sites takes `$id` from `$target->requireId()` earlier in the same test. Check each, and keep `$id`, which the URL still uses. | none |
| `tests/Controller/Api/LastLoginStampTest.php` | Delete `reload(int $id)`. Then `perl -pi -e 's/\$this->reload\(\$id\)/\$this->reload(\$user)/g' tests/Controller/Api/LastLoginStampTest.php`, and delete both `$id = $user->requireId();` lines, which are now unused. `git grep -n '\$id\b' -- tests/Controller/Api/LastLoginStampTest.php` must print nothing. | `App\Entity\User`, `App\Repository\UserRepository` |
| `tests/Service/Catalog/CatalogFeedEditorTest.php` | unchanged (`$this->reload($feed)`) | none |
| `tests/Service/Catalog/CatalogCategoryEditorTest.php` | unchanged | none |
| `tests/Service/Subscription/SubscriptionEditorTest.php` | unchanged | none |
| `tests/Service/Search/SavedSearchEditorTest.php` | unchanged (`$this->reload($outcome->savedSearch)`) | none |
| `tests/Service/Ai/AiConfigurationEditorTest.php` | unchanged. Its helper reads the id with `getId()` plus `assertNotNull()` instead of `requireId()`, which is variant R in substance. Delete it with the docblock above it, whose point `ReloadsEntities` now carries. | none |
| `tests/Service/Account/AccountPreferencesWriterTest.php` | unchanged | none |
| `tests/Service/Tag/TagEditorTest.php` | done in Step 2 | done |

The "unused" lists were re-taken at `fd5fa734`. Re-check every file in both tables before committing:
```bash
for f in $(git diff --name-only --relative -- tests); do
  for c in User UserFactory UserPasswordHasherInterface UserRepository EntityManagerInterface; do
    if grep -qE "^use [A-Za-z\\\\]*\\\\$c;" "$f" && [ "$(grep -cE "\b$c\b" "$f")" -eq 1 ]; then echo "unused $c in $f"; fi
  done
done
```
Expected: nothing. Delete any import it names.

- [ ] **Step 5: Check no copy is left**

```bash
git grep -nE "private function (user\(string \\\$email\): User|reload\()" -- tests
```
Expected: exactly three lines: `tests/Support/SeedsUsers.php`, `tests/Support/ReloadsEntities.php` and `tests/Service/Ai/AiProviderConfiguratorTest.php` (`reload(string $email)`).

- [ ] **Step 6: Run the swept suites, then everything**

Run each file in both tables with `php bin/phpunit <file>`, then `php bin/phpunit`.
Expected: PASS, with the same test count as on `origin/develop`.

- [ ] **Step 7: Gates**

Run: `composer check` (PHPStan covers `tests/`), and PhpStorm `lint_files` on the two traits and every swept file.
Expected: clean. OPEN QUESTION: if PHPStan cannot narrow `$entity::class` to `class-string<T>` in `ReloadsEntities`, replace the `assertInstanceOf` pair with `/** @var T $reloaded */` above an `assertNotNull($reloaded)`, and say so in the report.

- [ ] **Step 8: Commit**

```bash
git add tests/Support/SeedsUsers.php tests/Support/ReloadsEntities.php $(git diff --name-only --relative -- tests)
git commit -m "test(#1168): one user-seeding and one entity-reload test helper"
```

---

### Task B2: `ProvidesWorkerHeartbeats`; heartbeat reads via `findTouchedAt()`

**Files:**
- Create: `tests/Support/ProvidesWorkerHeartbeats.php`
- Modify: `tests/Service/Recommendation/ForYouSweepTest.php`, `tests/Service/Recommendation/CompletionStreamHeartbeatWiringTest.php`, `tests/Service/Worker/SweepStreamHeartbeatTest.php`, `tests/Service/Worker/WorkerRunSweepTest.php`, `tests/Service/Worker/WorkerPresenceTest.php`, `tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`, `tests/Controller/Api/RecommendationRunControllerTest.php`, `tests/Controller/Api/RecommendationSettingsControllerTest.php`

**Interfaces:**
- Produces: `trait App\Tests\Support\ProvidesWorkerHeartbeats { private function heartbeats(): WorkerHeartbeatRepository }`, the container's repository, typed.
- Consumes: `WorkerHeartbeatRepository::findTouchedAt(string $name): ?\DateTimeImmutable` and `touch(string, \DateTimeImmutable)`, unchanged.

Why: the repository writes with single statements and reads arrays, "so no managed copy goes stale behind a write" (its docblock). A test that `find()`s the entity reads a managed copy. It only stays correct because of an `em->clear()` in front of it, and nothing stops someone deleting that clear. `findTouchedAt()` reads the column, needs no clear and is what production reads. No new test: the rewritten reads are the assertions.

- [ ] **Step 1: Write the trait**

`tests/Support/ProvidesWorkerHeartbeats.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\WorkerHeartbeatRepository;

trait ProvidesWorkerHeartbeats
{
    private function heartbeats(): WorkerHeartbeatRepository
    {
        $heartbeats = self::getContainer()->get(WorkerHeartbeatRepository::class);
        self::assertInstanceOf(WorkerHeartbeatRepository::class, $heartbeats);

        return $heartbeats;
    }
}
```

- [ ] **Step 2: Wire the trait into every file**

Each of the eight files gets `use App\Tests\Support\ProvidesWorkerHeartbeats;` among its imports and `    use ProvidesWorkerHeartbeats;` first in the class body, merged alphabetically with any trait `use` lines (for example `SeedsUsers` from B1).

- [ ] **Step 3: Rewrite the reads and touches**

`tests/Service/Recommendation/ForYouSweepTest.php`: the same five lines appear twice (after the throwing-clock sweep and after the double surrender). Edit with `replace_all`:
```diff
-        $this->em->clear();
-        self::assertNull(
-            $this->em->getRepository(WorkerHeartbeat::class)
-                ->find(RecommendationDriverKind::CronSweep->heartbeatName()),
-        );
+        self::assertNull(
+            $this->heartbeats()->findTouchedAt(RecommendationDriverKind::CronSweep->heartbeatName()),
+        );
```
and in `sweepMarkingWith()`:
```diff
-        $presence = new WorkerPresence($this->em->getRepository(WorkerHeartbeat::class), $clock);
+        $presence = new WorkerPresence($this->heartbeats(), $clock);
```
Delete `use App\Entity\WorkerHeartbeat;`.

`tests/Service/Recommendation/CompletionStreamHeartbeatWiringTest.php`: replace `persistentWorkerTouchedAt()`'s body and delete `use App\Entity\WorkerHeartbeat;`:
```php
    private function persistentWorkerTouchedAt(): ?\DateTimeImmutable
    {
        return $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName());
    }
```

`tests/Service/Worker/SweepStreamHeartbeatTest.php`: replace `presence()` and `touchedAt()`, and delete `use App\Entity\WorkerHeartbeat;` and `use App\Repository\WorkerHeartbeatRepository;`:
```php
    private function presence(MockClock $clock): WorkerPresence
    {
        return new WorkerPresence($this->heartbeats(), $clock);
    }

    private function touchedAt(RecommendationDriverKind $kind): ?\DateTimeImmutable
    {
        return $this->heartbeats()->findTouchedAt($kind->heartbeatName());
    }
```

`tests/Service/Worker/WorkerRunSweepTest.php`: replace `touchedAt()`,
```php
    private function touchedAt(): ?\DateTimeImmutable
    {
        return $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName());
    }
```
delete its own `heartbeats()` with its docblock and the blank line above it,
```php

    /**
     * Through the EntityManager rather than the container: the repository has
     * a single referrer (WorkerPresence), and the compiler inlines
     * single-reference private services away.
     */
    private function heartbeats(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $this->em->getRepository(WorkerHeartbeat::class);

        return $repository;
    }
```
and delete `use App\Entity\WorkerHeartbeat;` and `use App\Repository\WorkerHeartbeatRepository;`. The docblock's claim no longer holds at `fd5fa734`: `SweepStreamHeartbeatTest::presence()` and `WorkerHeartbeatRepositoryTest::heartbeats()` already read `WorkerHeartbeatRepository` from the test container, which is what the trait does. If the trait's `get()` nevertheless throws `ServiceNotFoundException` in this suite, stop and report; do not bring back the entity-manager route.

`tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`: delete its own `heartbeats()` with the blank line above it (it has no docblock):
```php

    private function heartbeats(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $this->em->getRepository(WorkerHeartbeat::class);

        return $repository;
    }
``` Delete `use App\Entity\WorkerHeartbeat;` and `use App\Repository\WorkerHeartbeatRepository;`. Its calls (`$this->heartbeats()->findTouchedAt(…)`, `new WorkerPresence($this->heartbeats(), …)`) now resolve to the trait.

`tests/Service/Worker/WorkerPresenceTest.php`: run `perl -pi -e 's/\$this->repository\(\)/\$this->heartbeats()/g' tests/Service/Worker/WorkerPresenceTest.php` (18 sites). Delete `repository()` with the blank line above it,
```php

    private function repository(): WorkerHeartbeatRepository
    {
        /** @var WorkerHeartbeatRepository $repository */
        $repository = $this->em->getRepository(WorkerHeartbeat::class);

        return $repository;
    }
```
and delete `use App\Entity\WorkerHeartbeat;` and `use App\Repository\WorkerHeartbeatRepository;`.

`tests/Controller/Api/RecommendationRunControllerTest.php` and `tests/Controller/Api/RecommendationSettingsControllerTest.php`: neither `find()`s. They touched through an untyped `getRepository()` with an `@var` cast. In each, replace `touchHeartbeatNow()`'s body:
```php
    private function touchHeartbeatNow(string $name): void
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $this->heartbeats()->touch($name, $clock->now());
    }
```
and delete `use App\Entity\WorkerHeartbeat;` and `use App\Repository\WorkerHeartbeatRepository;`. `EntityManagerInterface` stays; both files use it elsewhere.

- [ ] **Step 4: Check nothing reads a managed heartbeat**

```bash
git grep -n "getRepository(WorkerHeartbeat::class)\|WorkerHeartbeat::class)->find\|->find(RecommendationDriverKind" -- tests
```
Expected: nothing. `WorkerHeartbeatRepositoryTest` persists `new WorkerHeartbeat(…)` on purpose and is untouched.

- [ ] **Step 5: Run the suites**

Run:
```bash
php bin/phpunit tests/Service/Recommendation/ForYouSweepTest.php tests/Service/Recommendation/CompletionStreamHeartbeatWiringTest.php tests/Service/Worker tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RecommendationSettingsControllerTest.php tests/Repository/WorkerHeartbeatRepositoryTest.php
```
Expected: PASS.

- [ ] **Step 6: Deletion check**

In `WorkerHeartbeatRepository::forget()`, replace the last two chain lines `->getQuery()` / `->execute();` with `->getQuery();`, so the delete is built but never run. Expected: `ForYouSweepTest::testTheCleanupTheShutdownHookRepeatsIsSafeToRunTwice` and the throwing-clock test fail on the new `findTouchedAt()` assertions. Restore with the Edit tool. Paste both outputs into the report.

- [ ] **Step 7: Gates**

Run: `composer check`, then PhpStorm `lint_files` on the nine files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add tests/Support/ProvidesWorkerHeartbeats.php tests/Service/Recommendation/ForYouSweepTest.php tests/Service/Recommendation/CompletionStreamHeartbeatWiringTest.php tests/Service/Worker/SweepStreamHeartbeatTest.php tests/Service/Worker/WorkerRunSweepTest.php tests/Service/Worker/WorkerPresenceTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php tests/Controller/Api/RecommendationRunControllerTest.php tests/Controller/Api/RecommendationSettingsControllerTest.php
git commit -m "test(#1168): heartbeat tests read the column, not a managed entity"
```

---

### Task B3: The provider-mismatch replay assertion bites

**Files:**
- Modify: `tests/Controller/Api/OAuthFlowTest.php` (`testAStateIssuedForOneProviderIsRefusedAtAnothersCallback`)

**Ruling: fix it, don't delete it.** Restoring the genuine binding cookie before the retry makes the burned state the only thing that can refuse it. That pins, over real HTTP, what `OAuthCallbackTest::testAStateReplayedAtAnotherProvidersCallbackIsRefusedAndBurned` pins one layer down. `OidcBoundaryTest` is untouched.

**Interfaces:** consumes the file's own `flowCookieValue()` and `replaceFlowCookie(string)` helpers, which `testAFailedBindingCheckBurnsTheStateSoItCannotBeRetried` already uses the same way.

- [ ] **Step 1: Make the retry carry the genuine cookie**

In `testAStateIssuedForOneProviderIsRefusedAtAnothersCallback()`, leave the `$provider = $this->fakeProvider(new OAuthIdentity('google', 'sub-1', 'bob@example.com', true));` line as it is (#1167 A8 kept `fakeProvider(OAuthIdentity)` and did not touch this test). After `$state = (string) $provider->lastState;`, add:
```php
        $genuine = $this->flowCookieValue();
```
Then replace the tail of the test:
```diff
         // ...and the state was burned on the way, so the mismatch cannot be
         // used to probe a state and then spend it at the right callback.
+        // The refusal also cleared the binding, so restore it: only the burn can refuse this retry.
+        $this->replaceFlowCookie($genuine);
         $this->requestCallback(['state' => $state, 'code' => 'c']);
         self::assertStringContainsString('error=invalid_state', $this->location());
+        self::assertSame([], $provider->exchanges);
```

- [ ] **Step 2: Run it**

Run: `php bin/phpunit --filter testAStateIssuedForOneProviderIsRefusedAtAnothersCallback tests/Controller/Api/OAuthFlowTest.php`
Expected: PASS.

- [ ] **Step 3: Deletion check (the point of this task)**

In `src/Service/OAuth/OAuthStateStore.php` `consume()`, comment out `$this->oauthStateCache->deleteItem($key);`. Run the same filter. Expected: FAIL. The retry at Google's callback now succeeds (`code=` in the location, one exchange recorded). Before this task the same break left the test green, because the cleared cookie refused the retry on its own. Restore the line with the Edit tool. Paste both outputs into the report.

- [ ] **Step 4: Gates**

Run: `php bin/phpunit tests/Controller/Api/OAuthFlowTest.php tests/Service/OAuth`, `composer check`, then PhpStorm `lint_files` on the test file.
Expected: PASS and clean.

- [ ] **Step 5: Commit**

```bash
git add tests/Controller/Api/OAuthFlowTest.php
git commit -m "test(#1168): the provider-mismatch replay is refused by the burn, not the cleared cookie"
```

---

### Task B4: `NextPosition`

**Files:**
- Create: `src/Repository/NextPosition.php`
- Create: `tests/Repository/NextPositionTest.php`
- Modify: `src/Repository/CatalogCategoryRepository.php`, `src/Repository/CatalogFeedRepository.php`, `src/Repository/TagRepository.php`, `src/Repository/SubscriptionTagRepository.php`, `src/Repository/SubscriptionRepository.php` (each: the constructor and its next-position method)

**Interfaces:**
- Produces: `App\Repository\NextPosition::in(QueryBuilder $list): int`, an injected collaborator (§7: composition, not a base repository). `$list` selects the rows of one ordered list from a root entity that has a `position` field. The result is one past the highest position, or 0 for an empty list.
- Consumes: nothing. The five public method names and signatures stay: `nextPosition()`, `nextPositionInCategory(int)`, `nextPositionForUser(int)` (twice) and `nextPositionForTag(Tag)`.

- [ ] **Step 1: Write the failing test**

`tests/Repository/NextPositionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Repository\NextPosition;
use App\Tests\DbTestCase;
use Doctrine\ORM\QueryBuilder;

final class NextPositionTest extends DbTestCase
{
    public function testAnEmptyListStartsAtZero(): void
    {
        $news = $this->category('next_position_empty');

        self::assertSame(0, $this->nextPosition()->in($this->feedsOf($news)));
    }

    public function testTheNextPositionIsOnePastTheListsHighest(): void
    {
        $news = $this->category('next_position_highest');
        $this->feed($news, 'https://a.next-position.example/feed.xml', 3);
        $this->feed($news, 'https://b.next-position.example/feed.xml', 7);
        $this->em->flush();

        self::assertSame(8, $this->nextPosition()->in($this->feedsOf($news)));
    }

    public function testAnotherListsPositionsDoNotCount(): void
    {
        $news = $this->category('next_position_mine');
        $tech = $this->category('next_position_other');
        $this->feed($tech, 'https://t.next-position.example/feed.xml', 40);
        $this->feed($news, 'https://n.next-position.example/feed.xml', 2);
        $this->em->flush();

        self::assertSame(3, $this->nextPosition()->in($this->feedsOf($news)));
    }

    private function nextPosition(): NextPosition
    {
        $nextPosition = self::getContainer()->get(NextPosition::class);
        self::assertInstanceOf(NextPosition::class, $nextPosition);

        return $nextPosition;
    }

    private function feedsOf(CatalogCategory $category): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from(CatalogFeed::class, 'f')
            ->andWhere('f.category = :category')
            ->setParameter('category', $category);
    }

    private function category(string $key): CatalogCategory
    {
        $category = new CatalogCategory($key, $key, 'star', '#000000');
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function feed(CatalogCategory $category, string $url, int $position): void
    {
        $feed = new CatalogFeed($category, 'Title', $url);
        $feed->setPosition($position);
        $this->em->persist($feed);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Repository/NextPositionTest.php`
Expected: FAIL, 3 errors: the container has no `App\Repository\NextPosition` service, or the class is not found.

- [ ] **Step 3: Write the collaborator and use it in the five repositories**

`src/Repository/NextPosition.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\QueryBuilder;

final readonly class NextPosition
{
    /** `$list` selects one ordered list's rows; its root entity has a `position` field. */
    public function in(QueryBuilder $list): int
    {
        $alias = $list->getRootAliases()[0];
        $max = $list->select(sprintf('MAX(%s.position)', $alias))->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
```

In each repository, the constructor gains the collaborator (same namespace, no import). Each next-position method becomes one call. Locate each method by its name: #1167 edited neighbouring methods.

`src/Repository/CatalogCategoryRepository.php`:
```php
    public function __construct(ManagerRegistry $registry, private readonly NextPosition $nextPosition)
    {
        parent::__construct($registry, CatalogCategory::class);
    }
```
```php
    public function nextPosition(): int
    {
        return $this->nextPosition->in($this->createQueryBuilder('c'));
    }
```

`src/Repository/CatalogFeedRepository.php`:
```php
    public function __construct(ManagerRegistry $registry, private readonly NextPosition $nextPosition)
    {
        parent::__construct($registry, CatalogFeed::class);
    }
```
```php
    public function nextPositionInCategory(int $categoryId): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('f')->andWhere('f.category = :category')->setParameter('category', $categoryId),
        );
    }
```

`src/Repository/TagRepository.php`: the constructor becomes the same shape with `Tag::class`. Keep the method's docblock:
```php
    public function nextPositionForUser(int $userId): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('t')->andWhere('t.user = :userId')->setParameter('userId', $userId),
        );
    }
```

`src/Repository/SubscriptionTagRepository.php`: the constructor becomes the same shape with `SubscriptionTag::class`. Keep the docblock:
```php
    public function nextPositionForTag(Tag $tag): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('st')->andWhere('st.tag = :tag')->setParameter('tag', $tag),
        );
    }
```

`src/Repository/SubscriptionRepository.php`: the constructor becomes the same shape with `Subscription::class`. Keep the docblock:
```php
    public function nextPositionForUser(int $userId): int
    {
        return $this->nextPosition->in(
            $this->createQueryBuilder('s')->andWhere('s.user = :userId')->setParameter('userId', $userId),
        );
    }
```

- [ ] **Step 4: Run the tests**

Run:
```bash
php bin/phpunit tests/Repository/NextPositionTest.php tests/Repository/SubscriptionPositionAndCountsTest.php tests/Service/Catalog tests/Service/Tag tests/Service/Subscription tests/Controller/Api/SubscriptionBulkTest.php tests/Controller/Api/ReorderTest.php tests/Command/E2eSeedAdminSubscriptionCommandTest.php
git grep -n "MAX(\w*\.position)" -- src
```
Expected: PASS. The grep prints only `src/Repository/NextPosition.php` (as `MAX(%s.position)`), or nothing if the grep's `\w*` does not match `%s`. Either way, no repository query remains.

- [ ] **Step 5: Deletion check**

In `NextPosition::in()`, change `(int) $max + 1` to `(int) $max`. Expected: `testTheNextPositionIsOnePastTheListsHighest`, `testAnotherListsPositionsDoNotCount` and `SubscriptionPositionAndCountsTest::testNextPositionForUserIsOnePastTheCurrentMaximum` fail. Then change `null === $max ? 0` to `null === $max ? 1`. Expected: `testAnEmptyListStartsAtZero` fails. Restore both with the Edit tool. Paste the outputs into the report.

- [ ] **Step 6: Gates**

Run: `composer check`, `composer md`, then PhpStorm `lint_files` on the six `src` files and the test.
Expected: clean. OPEN QUESTION: if PHPStan reports the `getRootAliases()[0]` offset as possibly undefined, read the alias with `$list->getRootAliases()[0] ?? throw new \LogicException('A list query needs a root alias.')`, and note it in the report.

- [ ] **Step 7: Commit**

```bash
git add src/Repository/NextPosition.php tests/Repository/NextPositionTest.php src/Repository/CatalogCategoryRepository.php src/Repository/CatalogFeedRepository.php src/Repository/TagRepository.php src/Repository/SubscriptionTagRepository.php src/Repository/SubscriptionRepository.php
git commit -m "refactor(#1168): one next-position query"
```

---

### Task B5: `RowIds`

**Files:**
- Create: `src/Repository/RowIds.php`
- Create: `tests/Repository/RowIdsTest.php`
- Modify: `src/Repository/RecommendationItemRepository.php` (constructor, `deleteForUser()`), `src/Repository/RecommendationRunLogRepository.php` (constructor, `deleteForUser()`, `deleteForUserOutsideRuns()`, `idsForUser()`; `deleteIds()` goes), `src/Repository/MailSendFailureRepository.php` (constructor, `pruneToRetention()`), `src/Repository/RetentionRepository.php` (constructor, `deleteEntries()`)

**Interfaces:**
- Produces: `App\Repository\RowIds`, injected:
  - `selectedBy(QueryBuilder $query): list<int>` reads the `id` column that the query selects `AS id`.
  - `delete(string $entityClass, list<int> $ids): void` runs one `DELETE … WHERE id IN (:ids)`, and no statement at all for an empty list.
- Consumes: nothing. Every public repository signature stays.

- [ ] **Step 1: Write the failing test**

`tests/Repository/RowIdsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Repository\MailSendFailureRepository;
use App\Repository\RowIds;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use Doctrine\ORM\QueryBuilder;

final class RowIdsTest extends DbTestCase
{
    public function testSelectedByReadsTheIdColumnAsIntegers(): void
    {
        $this->store('a@example.test', 'b@example.test');

        $ids = $this->rowIds()->selectedBy($this->idsOf('a@example.test', 'b@example.test'));

        self::assertCount(2, $ids);
        self::assertContainsOnlyInt($ids);
    }

    public function testDeleteRemovesExactlyTheNamedRows(): void
    {
        $this->store('a@example.test', 'b@example.test', 'c@example.test');
        $doomed = $this->rowIds()->selectedBy($this->idsOf('a@example.test', 'c@example.test'));

        $this->rowIds()->delete(MailSendFailure::class, $doomed);

        $left = array_map(
            static fn (MailSendFailure $failure): string => $failure->getRecipient(),
            $this->failures()->recent(10),
        );
        self::assertSame(['b@example.test'], $left);
    }

    public function testDeletingNoIdsRunsNoStatement(): void
    {
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->rowIds()->delete(MailSendFailure::class, []);

        self::assertSame([], $recorder->queriesMatching('delete from mail_send_failure'));
    }

    private function store(string ...$recipients): void
    {
        foreach ($recipients as $recipient) {
            $this->failures()->add(new MailSendFailure(
                MailKind::Digest,
                $recipient,
                'SMTP transport failed',
                new \DateTimeImmutable('2026-09-06T10:00:00Z'),
            ));
        }
        $this->em->flush();
    }

    private function idsOf(string ...$recipients): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->select('f.id AS id')
            ->from(MailSendFailure::class, 'f')
            ->andWhere('f.recipient IN (:recipients)')
            ->setParameter('recipients', $recipients);
    }

    private function rowIds(): RowIds
    {
        $rowIds = self::getContainer()->get(RowIds::class);
        self::assertInstanceOf(RowIds::class, $rowIds);

        return $rowIds;
    }

    private function failures(): MailSendFailureRepository
    {
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        self::assertInstanceOf(MailSendFailureRepository::class, $failures);

        return $failures;
    }
}
```
(`MailSendFailure` maps its address as `recipient`, a `VARCHAR(255)` column, at `fd5fa734`.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Repository/RowIdsTest.php`
Expected: FAIL, 3 errors: no `App\Repository\RowIds` service or class.

- [ ] **Step 3: Write the collaborator**

`src/Repository/RowIds.php`:
```php
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/** Select ids, then delete by id: portable across both suite dialects, unlike a DELETE with a subquery. */
final readonly class RowIds
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /** @return list<int> */
    public function selectedBy(QueryBuilder $query): array
    {
        /** @var list<int> $ids */
        $ids = array_column($query->getQuery()->getArrayResult(), 'id');

        return $ids;
    }

    /**
     * @param class-string $entityClass
     * @param list<int>    $ids
     */
    public function delete(string $entityClass, array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->entityManager
            ->createQuery(sprintf('DELETE FROM %s doomed WHERE doomed.id IN (:ids)', $entityClass))
            ->setParameter('ids', $ids)
            ->execute();
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Repository/RowIdsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Use it in the four repositories**

`src/Repository/RecommendationItemRepository.php`: the constructor gains `private readonly RowIds $rowIds`:
```php
    public function __construct(ManagerRegistry $registry, private readonly RowIds $rowIds)
    {
        parent::__construct($registry, RecommendationItem::class);
    }
```
and `deleteForUser()` with its docblock becomes (the "why" now lives on `RowIds`):
```php
    public function deleteForUser(User $user): void
    {
        $ids = $this->rowIds->selectedBy(
            $this->createQueryBuilder('i')
                ->select('i.id AS id')
                ->join('i.run', 'r')
                ->where('r.user = :user')
                ->setParameter('user', $user),
        );

        $this->rowIds->delete(RecommendationItem::class, $ids);
    }
```

`src/Repository/RecommendationRunLogRepository.php`: the constructor gains the collaborator in the same shape (`RecommendationRunLog::class`). Replace `deleteForUser()` and its docblock:
```php
    public function deleteForUser(User $user): void
    {
        $this->rowIds->delete(RecommendationRunLog::class, $this->idsForUser($user, null));
    }
```
In `deleteForUserOutsideRuns()`:
```diff
-        $this->deleteIds($this->idsForUser($user, $keptRunIds));
+        $this->rowIds->delete(RecommendationRunLog::class, $this->idsForUser($user, $keptRunIds));
```
At the end of `idsForUser()`:
```diff
-        /** @var list<int> $ids */
-        $ids = array_column($query->getQuery()->getArrayResult(), 'id');
-
-        return $ids;
+        return $this->rowIds->selectedBy($query);
```
and delete `deleteIds()` with its docblock and the blank line above it:
```php

    /** @param list<int> $ids */
    private function deleteIds(array $ids): void
    {
        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\RecommendationRunLog l WHERE l.id IN (:ids)',
        )->setParameter('ids', $ids)->execute();
    }
```

`src/Repository/MailSendFailureRepository.php`: the constructor gains the collaborator (`MailSendFailure::class`). `pruneToRetention()` keeps its docblock and becomes:
```php
    public function pruneToRetention(): void
    {
        $ids = $this->rowIds->selectedBy(
            $this->createQueryBuilder('f')
                ->select('f.id AS id')
                ->orderBy('f.createdAt', 'DESC')
                ->addOrderBy('f.id', 'DESC'),
        );

        $this->rowIds->delete(MailSendFailure::class, array_slice($ids, self::RETENTION));
    }
```

`src/Repository/RetentionRepository.php`, the fourth `DELETE … IN (:ids)`:
```diff
-    public function __construct(private EntityManagerInterface $em)
+    public function __construct(private EntityManagerInterface $em, private RowIds $rowIds)
```
```php
    /** @param list<int> $ids */
    public function deleteEntries(array $ids): void
    {
        $this->rowIds->delete(Entry::class, $ids);
    }
```

- [ ] **Step 6: Run the affected suites**

Run:
```bash
php bin/phpunit tests/Repository tests/Service/Retention tests/Service/Recommendation tests/Service/Account tests/Service/Mail tests/Controller/Api/RecommendationDebugLogControllerTest.php
git grep -nE "DELETE FROM .*IN \(:ids\)|->delete\(\)" -- src/Repository
```
Expected: PASS. The grep prints four lines: `src/Repository/RowIds.php`, and the three QueryBuilder deletes that do not delete by an id list and stay (`MailSendFailureRepository::deleteAll()`, `UserPasskeyRepository::deleteAll()`, `WorkerHeartbeatRepository::forget()`). The 15 SELECT filters on `IN (:ids)` are untouched.

- [ ] **Step 7: Deletion check**

In `RowIds::delete()`, delete the `if ([] === $ids) { return; }` guard. Expected: `testDeletingNoIdsRunsNoStatement` fails, or the DQL errors on an empty `IN`. Either way it goes red. Restore with the Edit tool. Paste both outputs into the report.

- [ ] **Step 8: Gates**

Run: `composer check`, `composer md`, then PhpStorm `lint_files` on the five `src` files and the test.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add src/Repository/RowIds.php tests/Repository/RowIdsTest.php src/Repository/RecommendationItemRepository.php src/Repository/RecommendationRunLogRepository.php src/Repository/MailSendFailureRepository.php src/Repository/RetentionRepository.php
git commit -m "refactor(#1168): one select-ids-then-delete collaborator"
```

---

### Task B6: Digest clocks

**Files:**
- Modify: `src/Service/Mail/Digest/DigestEnablement.php` (constructor; `nowAsNaiveUtc()` goes)
- Modify: `src/Service/Mail/Digest/DigestHtmlRenderer.php` (constructor, `today()`)
- Test: `tests/Service/Mail/Digest/DigestEnablementTest.php` (every construction), `tests/Service/Mail/Digest/DigestHtmlRendererTest.php` (one new test and the construction), `tests/Service/Mail/Digest/SendTestDigestTest.php`, `tests/Service/Mail/Digest/DigestMailerTest.php`, `tests/Service/Mail/Digest/DigestMailBuilderTest.php` (one construction each)

**Interfaces:**
- Consumes: `App\Service\Clock\NaiveUtcClock::__construct(Psr\Clock\ClockInterface)` and `::now()`, and `Psr\Clock\ClockInterface`.
- Produces:
  - `DigestEnablement::__construct(NaiveUtcClock $clock)`
  - `DigestHtmlRenderer::__construct(Environment $twig, DigestLinkBuilder $links, ClockInterface $clock)`

- [ ] **Step 1: Write the failing tests**

`tests/Service/Mail/Digest/DigestEnablementTest.php`: every construction wraps its clock. Run
```bash
perl -pi -e 's/new DigestEnablement\((new MockClock\([^)]*\))\)/new DigestEnablement(new NaiveUtcClock($1))/g; s/new DigestEnablement\(\$clock\)/new DigestEnablement(new NaiveUtcClock(\$clock))/g' tests/Service/Mail/Digest/DigestEnablementTest.php
```
and add `use App\Service\Clock\NaiveUtcClock;`. Then `git grep -c "new DigestEnablement(new NaiveUtcClock(" -- tests/Service/Mail/Digest/DigestEnablementTest.php` must equal `git grep -c "new DigestEnablement(" -- tests/Service/Mail/Digest/DigestEnablementTest.php` (8 at `fd5fa734`).

`tests/Service/Mail/Digest/DigestHtmlRendererTest.php`: add `use Symfony\Component\Clock\MockClock;`, pass a fixed clock in `renderer()`,
```diff
-        return new DigestHtmlRenderer(DigestTwigEnvironment::withTranslator($translator), $links);
+        return new DigestHtmlRenderer(
+            DigestTwigEnvironment::withTranslator($translator),
+            $links,
+            new MockClock('2026-08-30T12:00:00Z'),
+        );
```
and add after `testHeaderShowsTheTotalEntryCount()`:
```php
    public function testTheHeaderIsDatedByTheInjectedClock(): void
    {
        $html = $this->renderer()->render(new DigestPage([], 1), new DigestImageSet([], []), 'en');

        $clockDay = (new \IntlDateFormatter('en', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC'))
            ->format(new \DateTimeImmutable('2026-08-30T12:00:00Z'));
        self::assertIsString($clockDay);
        self::assertStringContainsString($clockDay, $html);
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Service/Mail/Digest/DigestEnablementTest.php tests/Service/Mail/Digest/DigestHtmlRendererTest.php`
Expected: FAIL.
- `DigestEnablementTest` errors with a `TypeError`: `DigestEnablement::__construct()` wants a Symfony `ClockInterface`, and `NaiveUtcClock` is not one.
- `testTheHeaderIsDatedByTheInjectedClock` fails because the header carries today's real date. PHP ignores the extra constructor argument.

- [ ] **Step 3: Inject the clocks**

`src/Service/Mail/Digest/DigestEnablement.php`:
- Replace `use Symfony\Component\Clock\ClockInterface;` with `use App\Service\Clock\NaiveUtcClock;`.
- Constructor:
```php
    public function __construct(
        private NaiveUtcClock $clock,
    ) {
    }
```
- In `applyTo()`:
```diff
-            $preferences->setDigestLastSentAt($this->nowAsNaiveUtc());
+            $preferences->setDigestLastSentAt($this->clock->now());
```
- Delete `nowAsNaiveUtc()` with its docblock and the blank line above it:
```php

    /** Doctrine persists naive wall-clock values, so a non-UTC clock must be normalised first. */
    private function nowAsNaiveUtc(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
```

`src/Service/Mail/Digest/DigestHtmlRenderer.php`:
- Add `use Psr\Clock\ClockInterface;`.
- Constructor:
```php
    public function __construct(
        private Environment $twig,
        private DigestLinkBuilder $links,
        private ClockInterface $clock,
    ) {
    }
```
- In `today()`. The formatter pins UTC, so the clock's own zone does not matter:
```diff
-        return (string) $formatter->format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
+        return (string) $formatter->format($this->clock->now());
```

- [ ] **Step 4: Give the other three constructions a clock**

In each of `tests/Service/Mail/Digest/SendTestDigestTest.php`, `tests/Service/Mail/Digest/DigestMailerTest.php` and `tests/Service/Mail/Digest/DigestMailBuilderTest.php`:
```diff
-            new DigestHtmlRenderer(DigestTwigEnvironment::withTranslator($translator), $links),
+            new DigestHtmlRenderer(
+                DigestTwigEnvironment::withTranslator($translator),
+                $links,
+                new MockClock('2026-08-30T12:00:00Z'),
+            ),
```
`SendTestDigestTest` already imports `Symfony\Component\Clock\MockClock`. Add the import to the other two.

- [ ] **Step 5: Run the digest suites**

Run:
```bash
php bin/phpunit tests/Service/Mail tests/Service/Account tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeDigestTestControllerTest.php
git grep -n "nowAsNaiveUtc\|new \\\\DateTimeImmutable('now'" -- src
```
Expected: PASS, and the grep prints only `src/Service/Clock/NaiveUtcClock.php`'s docblock mention of `nowAsNaiveUtc()`.

- [ ] **Step 6: Deletion check**

In `DigestHtmlRenderer::today()`, put `new \DateTimeImmutable('now', new \DateTimeZone('UTC'))` back in place of `$this->clock->now()`. Expected: `testTheHeaderIsDatedByTheInjectedClock` fails. Restore with the Edit tool. `DigestEnablementTest::testANonUtcClockIsNormalisedToNaiveUtcBeforeSeeding` already guards the enablement side. Paste both outputs into the report.

- [ ] **Step 7: Gates**

Run: `composer check`, `composer md`, then PhpStorm `lint_files` on the two `src` files and five tests.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Mail/Digest/DigestEnablement.php src/Service/Mail/Digest/DigestHtmlRenderer.php tests/Service/Mail/Digest/DigestEnablementTest.php tests/Service/Mail/Digest/DigestHtmlRendererTest.php tests/Service/Mail/Digest/SendTestDigestTest.php tests/Service/Mail/Digest/DigestMailerTest.php tests/Service/Mail/Digest/DigestMailBuilderTest.php
git commit -m "refactor(#1168): the digest reads its clocks instead of re-implementing them"
```

---

### Task B7: `PasswordPolicy`

**Files:**
- Create: `src/Service/Auth/PasswordPolicy.php`
- Create: `tests/Service/Auth/PasswordPolicyTest.php`
- Modify: `src/Command/CreateAdminCommand.php`, `src/Command/ResetUserPasswordCommand.php`
- Modify: `src/Dto/Auth/RegisterRequest.php`, `src/Dto/Auth/PasswordResetConfirmRequest.php`, `src/Dto/Setup/SetupAdminRequest.php`
- Test: `tests/Command/ResetUserPasswordCommandTest.php` (two new tests for the prompted path, which had none)

**Interfaces:**
- Produces:
  - `App\Service\Auth\PasswordPolicy::MINIMUM_LENGTH = 12`
  - `PasswordPolicy::MAXIMUM_LENGTH = 4096`
  - `PasswordPolicy::isLongEnough(string $password): bool` (counts characters, not bytes)
- Consumes: nothing.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Auth/PasswordPolicyTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Service\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testTwelveCharactersAreLongEnough(): void
    {
        self::assertTrue(PasswordPolicy::isLongEnough('twelve-chars'));
    }

    public function testElevenCharactersAreNot(): void
    {
        self::assertFalse(PasswordPolicy::isLongEnough('eleven-char'));
    }

    public function testTheLengthCountsCharactersNotBytes(): void
    {
        self::assertTrue(PasswordPolicy::isLongEnough(str_repeat('ä', 12)));
        self::assertFalse(PasswordPolicy::isLongEnough(str_repeat('ä', 11)));
    }
}
```

`tests/Command/ResetUserPasswordCommandTest.php`, after `testUnknownEmailFails()`:
```php
    public function testAPromptedPasswordOfTwelveCharactersIsSet(): void
    {
        $hasher = $this->hasher();
        (new UserFactory($this->em, $hasher))->create('prompted@example.com', password: 'the-old-password');

        $tester = $this->tester();
        $tester->setInputs(['twelve-chars']);
        $tester->execute(['email' => 'prompted@example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $user = $this->repository()->findOneByEmail('prompted@example.com');
        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, 'twelve-chars'));
    }

    public function testAPromptedPasswordOfElevenCharactersIsRefused(): void
    {
        $hasher = $this->hasher();
        (new UserFactory($this->em, $hasher))->create('too-short@example.com', password: 'the-old-password');

        $tester = $this->tester();
        $tester->setInputs(['eleven-char']);
        $tester->execute(['email' => 'too-short@example.com']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('at least 12 characters', $tester->getDisplay());
        $user = $this->repository()->findOneByEmail('too-short@example.com');
        self::assertNotNull($user);
        self::assertTrue($hasher->isPasswordValid($user, 'the-old-password'));
    }
```

- [ ] **Step 2: Run them**

Run: `php bin/phpunit tests/Service/Auth/PasswordPolicyTest.php tests/Command/ResetUserPasswordCommandTest.php`
Expected: `PasswordPolicyTest` FAILs with 3 errors (`Class "App\Service\Auth\PasswordPolicy" not found`). The two command tests PASS: they pin the prompted path, which had no test.

- [ ] **Step 3: Write the policy and read it everywhere**

`src/Service/Auth/PasswordPolicy.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** 12 characters and no composition rules: length beats character classes, and a memorable passphrase gets kept. */
final class PasswordPolicy
{
    public const int MINIMUM_LENGTH = 12;
    public const int MAXIMUM_LENGTH = 4096;

    public static function isLongEnough(string $password): bool
    {
        return mb_strlen($password) >= self::MINIMUM_LENGTH;
    }
}
```

`src/Dto/Auth/RegisterRequest.php`: add `use App\Service\Auth\PasswordPolicy;`. The two-line comment moves to the policy:
```diff
-        // 12 chars with no composition rules: length beats character classes,
-        // and the passphrase people actually remember is the one they keep.
         #[Assert\NotBlank]
-        #[Assert\Length(min: 12, max: 4096)]
+        #[Assert\Length(min: PasswordPolicy::MINIMUM_LENGTH, max: PasswordPolicy::MAXIMUM_LENGTH)]
         public string $password = '',
```

`src/Dto/Auth/PasswordResetConfirmRequest.php` and `src/Dto/Setup/SetupAdminRequest.php`: add the import and
```diff
-        #[Assert\Length(min: 12, max: 4096)]
+        #[Assert\Length(min: PasswordPolicy::MINIMUM_LENGTH, max: PasswordPolicy::MAXIMUM_LENGTH)]
```

`src/Command/CreateAdminCommand.php`: add `use App\Service\Auth\PasswordPolicy;`. Delete the constant and the blank line after it:
```php
    private const int MINIMUM_PASSWORD_LENGTH = 12;

```
Then:
```diff
-        $answer = $io->askHidden('Administrator password (min 12 characters)');
+        $answer = $io->askHidden(\sprintf('Administrator password (min %d characters)', PasswordPolicy::MINIMUM_LENGTH));
         $password = \is_string($answer) ? $answer : '';
-        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
-            $io->error('The password must be at least 12 characters.');
+        if (!PasswordPolicy::isLongEnough($password)) {
+            $io->error(\sprintf('The password must be at least %d characters.', PasswordPolicy::MINIMUM_LENGTH));
```

`src/Command/ResetUserPasswordCommand.php`: the same import, the same constant deletion, and:
```diff
-        $answer = $io->askHidden('New password (min 12 characters)');
+        $answer = $io->askHidden(\sprintf('New password (min %d characters)', PasswordPolicy::MINIMUM_LENGTH));
         $password = \is_string($answer) ? $answer : '';
-        if (mb_strlen($password) < self::MINIMUM_PASSWORD_LENGTH) {
-            $io->error('The password must be at least 12 characters.');
+        if (!PasswordPolicy::isLongEnough($password)) {
+            $io->error(\sprintf('The password must be at least %d characters.', PasswordPolicy::MINIMUM_LENGTH));
```

- [ ] **Step 4: Run the tests**

Run:
```bash
php bin/phpunit tests/Service/Auth/PasswordPolicyTest.php tests/Command/ResetUserPasswordCommandTest.php tests/Command/CreateAdminCommandTest.php tests/Controller/Api/RegistrationTest.php tests/Controller/Api/PasswordResetTest.php tests/Controller/Api/SetupControllerTest.php tests/Dto
git grep -nE "min: 12|MINIMUM_PASSWORD_LENGTH|min 12 characters|at least 12 characters" -- src
```
Expected: PASS, and the grep prints nothing.

- [ ] **Step 5: Deletion check**

In `PasswordPolicy::isLongEnough()`, change `>=` to `>`. Expected: `testTwelveCharactersAreLongEnough`, the `ä` case and `testAPromptedPasswordOfTwelveCharactersIsSet` fail. Then change `mb_strlen` to `strlen`. Expected: `testTheLengthCountsCharactersNotBytes` fails. Restore both with the Edit tool. Paste the outputs into the report.

- [ ] **Step 6: Gates**

Run: `composer check`, `composer md`, then PhpStorm `lint_files` on the six `src` files and two tests.
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Auth/PasswordPolicy.php tests/Service/Auth/PasswordPolicyTest.php src/Command/CreateAdminCommand.php src/Command/ResetUserPasswordCommand.php src/Dto/Auth/RegisterRequest.php src/Dto/Auth/PasswordResetConfirmRequest.php src/Dto/Setup/SetupAdminRequest.php tests/Command/ResetUserPasswordCommandTest.php
git commit -m "refactor(#1168): one password length policy"
```

---

### Task B8: `ConsoleOption`

**Files:**
- Create: `src/Command/ConsoleOption.php`, `src/Command/Exception/MalformedOptionException.php`
- Create: `tests/Command/ConsoleOptionTest.php`
- Modify: `src/Command/RefreshFeedsCommand.php`, `src/Command/CheckCatalogUrlsCommand.php`, `src/Command/WarmCatalogFaviconsCommand.php`, `src/Command/ReaderAuditCommand.php`, `src/Command/ReaderAuditReportCommand.php`
- Test: `tests/Command/RefreshFeedsCommandTest.php` (`testInvalidBudgetIsRejected` becomes an application-level test), `tests/Command/RefreshFeedsCommandRequestTest.php` (#1167 A1's unit test; one new test for `--feed=abc`)

**Interfaces:**
- Produces:
  - `App\Command\ConsoleOption::text(InputInterface $input, string $name): ?string`: the value trimmed, or `null` when the option is absent or blank.
  - `ConsoleOption::wholeNumber(InputInterface $input, string $name): ?int`: `null` when the option is absent. A value that is not all digits after trimming throws `MalformedOptionException`.
  - `ConsoleOption::limit(InputInterface $input): ?int`: the `--limit` option, at least 1. `null` when absent; throws when malformed.
  - `App\Command\Exception\MalformedOptionException extends Symfony\Component\Console\Exception\InvalidOptionException`, with code `Command::INVALID`. The console application prints its message and exits with that code, so a command needs no `catch`.
- Consumes: nothing.

Before this task the five copies disagreed:
- Only one of them trimmed the value.
- `ReaderAuditCommand` cast `12abc` to 12, and so did `ReaderAuditReportCommand` for `--top`. The report command's `option()` is the fifth copy: the issue lists four, and the re-take at `fd5fa734` found this one, which predates the plan base.
- The other three read a malformed number as absent. For the two catalog commands that meant an unlimited run, and `app:feeds:refresh --feed=abc` refreshed every due feed.

Planner ruling: absent stays absent, and a malformed number is refused loudly, never read as null.

- [ ] **Step 1: Write the failing tests**

`tests/Command/ConsoleOptionTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConsoleOption;
use App\Command\Exception\MalformedOptionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class ConsoleOptionTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string}> */
    public static function texts(): iterable
    {
        yield 'absent' => [null, null];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'padded' => ['  var/out.jsonl ', 'var/out.jsonl'];
    }

    #[DataProvider('texts')]
    public function testTextIsTrimmedAndBlankReadsAsAbsent(?string $given, ?string $read): void
    {
        self::assertSame($read, ConsoleOption::text($this->input('value', $given), 'value'));
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function numbers(): iterable
    {
        yield 'absent' => [null, null];
        yield 'zero' => ['0', 0];
        yield 'digits' => ['42', 42];
        yield 'padded digits' => [' 7 ', 7];
    }

    #[DataProvider('numbers')]
    public function testAWholeNumberIsReadTrimmed(?string $given, ?int $read): void
    {
        self::assertSame($read, ConsoleOption::wholeNumber($this->input('value', $given), 'value'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedNumbers(): iterable
    {
        yield 'negative' => ['-3'];
        yield 'partly digits' => ['10abc'];
        yield 'not a number' => ['abc'];
        yield 'a fraction' => ['1.5'];
    }

    #[DataProvider('malformedNumbers')]
    public function testAMalformedNumberIsRefusedNamingTheOptionAndTheValue(string $given): void
    {
        try {
            ConsoleOption::wholeNumber($this->input('value', $given), 'value');
            self::fail('A malformed number must be refused, not read as absent.');
        } catch (MalformedOptionException $refusal) {
            self::assertStringContainsString('--value', $refusal->getMessage());
            self::assertStringContainsString('"' . $given . '"', $refusal->getMessage());
            self::assertSame(Command::INVALID, $refusal->getCode());
        }
    }

    /** @return iterable<string, array{?string, ?int}> */
    public static function limits(): iterable
    {
        yield 'absent' => [null, null];
        yield 'zero still does one item' => ['0', 1];
        yield 'a limit' => ['5', 5];
    }

    #[DataProvider('limits')]
    public function testALimitIsAtLeastOne(?string $given, ?int $read): void
    {
        self::assertSame($read, ConsoleOption::limit($this->input('limit', $given)));
    }

    public function testAMalformedLimitIsRefused(): void
    {
        $this->expectException(MalformedOptionException::class);

        ConsoleOption::limit($this->input('limit', '10abc'));
    }

    private function input(string $name, ?string $value): InputInterface
    {
        $definition = new InputDefinition([new InputOption($name, null, InputOption::VALUE_REQUIRED)]);

        return new ArrayInput($value === null ? [] : ['--' . $name => $value], $definition);
    }
}
```

`tests/Command/RefreshFeedsCommandTest.php`: replace `testInvalidBudgetIsRejected()` with an application-level test. A `CommandTester` would let the exception escape, and the point is what an operator sees: a message and exit code 2. Add `use Symfony\Component\Console\Tester\ApplicationTester;`.
```php
    public function testAMalformedBudgetIsReportedAndRefused(): void
    {
        $application = new Application(self::$kernel ?? self::bootKernel());
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $exitCode = $tester->run(['command' => 'app:feeds:refresh', '--budget' => 'not-a-number']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('"not-a-number" is not', $tester->getDisplay());
    }
```

`tests/Command/RefreshFeedsCommandRequestTest.php` (added by #1167 A1): add `use App\Command\Exception\MalformedOptionException;` among the imports, and after `testAUserOptionRefreshesThatUsersFeeds()`:
```php
    public function testAMalformedFeedIdIsRefusedInsteadOfRefreshingEveryDueFeed(): void
    {
        $runner = new FakeRefreshRunner(RefreshReport::busy());

        try {
            (new CommandTester(new RefreshFeedsCommand($runner)))->execute(['--budget' => '45', '--feed' => 'abc']);
            self::fail('A malformed --feed must be refused, not read as "every due feed".');
        } catch (MalformedOptionException) {
            self::assertSame([], $runner->requests);
        }
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit tests/Command/ConsoleOptionTest.php tests/Command/RefreshFeedsCommandTest.php tests/Command/RefreshFeedsCommandRequestTest.php`
Expected: FAIL.
- `ConsoleOptionTest`: 16 errors, `Class "App\Command\ConsoleOption" not found` (or `MalformedOptionException`).
- `testAMalformedBudgetIsReportedAndRefused`: exits `INVALID`, but its display carries the old "must be a positive integer" message, not `"not-a-number" is not`.
- `testAMalformedFeedIdIsRefusedInsteadOfRefreshingEveryDueFeed`: fails on `self::fail()`, because today the command reads `abc` as absent and asks the runner for an all-due refresh.

- [ ] **Step 3: Write the parser and its exception**

`src/Command/Exception/MalformedOptionException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Command\Exception;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;

/** The console's own option error, so the application prints it and exits INVALID without a catch per command. */
final class MalformedOptionException extends InvalidOptionException
{
    public function __construct(string $option, string $value)
    {
        parent::__construct(
            \sprintf('The --%s option takes a whole number; "%s" is not one.', $option, $value),
            Command::INVALID,
        );
    }
}
```

`src/Command/ConsoleOption.php`:
```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Exception\MalformedOptionException;
use Symfony\Component\Console\Input\InputInterface;

final class ConsoleOption
{
    public static function text(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @throws MalformedOptionException */
    public static function wholeNumber(InputInterface $input, string $name): ?int
    {
        $value = self::text($input, $name);
        if ($value === null) {
            return null;
        }
        if (!ctype_digit($value)) {
            throw new MalformedOptionException($name, $value);
        }

        return (int) $value;
    }

    /** @throws MalformedOptionException */
    public static function limit(InputInterface $input): ?int
    {
        $limit = self::wholeNumber($input, 'limit');

        return $limit === null ? null : max(1, $limit);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php bin/phpunit tests/Command/ConsoleOptionTest.php`
Expected: PASS (16 tests).

- [ ] **Step 5: Replace the five parsers**

All five commands share the `App\Command` namespace, so no import is needed.

`src/Command/RefreshFeedsCommand.php`. Locate by text: since #1167 A1 the `default =>` arm calls `$this->allDueRequest($input, $budget)`, and `intOption()` sits between `execute()` and `allDueRequest()`.
```diff
-        $budget = $this->intOption($input, 'budget');
+        $budget = ConsoleOption::wholeNumber($input, 'budget');
```
```diff
-        $feedId = $this->intOption($input, 'feed');
-        $userId = $this->intOption($input, 'user');
+        $feedId = ConsoleOption::wholeNumber($input, 'feed');
+        $userId = ConsoleOption::wholeNumber($input, 'user');
```
The `if ($budget === null || $budget < 1)` guard stays, with its message: `--budget=0` is well-formed but not a usable budget. Delete `intOption()` with the blank line above it:
```php

    private function intOption(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
```

`src/Command/CheckCatalogUrlsCommand.php`:
```diff
-        $report = $this->checker->check($this->limit($input));
+        $report = $this->checker->check(ConsoleOption::limit($input));
```
and delete `limit()` with the blank line above it:
```php

    private function limit(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if (!\is_string($value) || !ctype_digit($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
```

`src/Command/WarmCatalogFaviconsCommand.php`:
```diff
-        $limit = $this->limitOption($input);
+        $limit = ConsoleOption::limit($input);
```
and delete `limitOption()` with the blank line above it:
```php

    private function limitOption(InputInterface $input): ?int
    {
        $value = $input->getOption('limit');
        if (!\is_string($value) || '' === trim($value) || !ctype_digit(trim($value))) {
            return null;
        }

        return max(1, (int) $value);
    }
```

`src/Command/ReaderAuditCommand.php`:
- Replace every `$this->option($input, ` with `ConsoleOption::text($input, ` (Edit with `replace_all`: `user`, `out`, `base-url`, `entries`, `before`).
- Delete `option()` with the blank line above it:
```php

    private function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
```
- `number()` keeps its name. All five options it reads carry defaults, so "absent" can only mean an explicit blank value. It becomes one line:
```php
    private function number(InputInterface $input, string $name): int
    {
        return ConsoleOption::wholeNumber($input, $name) ?? 0;
    }
```

`src/Command/ReaderAuditReportCommand.php` (the fifth copy). All three options carry defaults, so a blank value is the only way to reach the fallbacks, which stay what they were (`''` and `0`). In `execute()`:
```diff
-        $pattern = $this->option($input, 'in');
+        $pattern = ConsoleOption::text($input, 'in') ?? '';
```
```diff
-        $reportPath = $this->option($input, 'out');
-        $report = new AuditReportHtml((int) $this->option($input, 'top'));
+        $reportPath = ConsoleOption::text($input, 'out') ?? '';
+        $report = new AuditReportHtml(ConsoleOption::wholeNumber($input, 'top') ?? 0);
```
and delete `option()` with the blank line above it:
```php

    private function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : '';
    }
```
Neither audit command has a test in `tests/`. Their option reading is covered through `ConsoleOptionTest`.
PLANNER RULING: keep the fifth parser. It falls under the DRY rule and the general-solution preference, so B8 covers all five.

- [ ] **Step 6: Run the command suites**

Run:
```bash
php bin/phpunit tests/Command
git grep -nE "private function (intOption|limit|limitOption|option)\(" -- src/Command
git grep -n 'getOption(' -- src/Command/RefreshFeedsCommand.php src/Command/CheckCatalogUrlsCommand.php src/Command/WarmCatalogFaviconsCommand.php src/Command/ReaderAuditCommand.php src/Command/ReaderAuditReportCommand.php
```
Expected: PASS, and the first grep prints nothing. The second prints only three flag reads, which stay: `--no-prune` and `--force` in `RefreshFeedsCommand::allDueRequest()`, and `--force` in `WarmCatalogFaviconsCommand::execute()`. No other test in `tests/Command` passes a malformed number. If one does, it now meets `MalformedOptionException`: report it and do not loosen the parser.

- [ ] **Step 7: Deletion checks**

1. In `wholeNumber()`, replace the `throw` with `return null;`. Expected: every `malformedNumbers` row, `testAMalformedLimitIsRefused`, `testAMalformedBudgetIsReportedAndRefused` and `testAMalformedFeedIdIsRefusedInsteadOfRefreshingEveryDueFeed` fail.
2. In `text()`, return `$value` instead of `trim($value)`. Expected: the `padded` rows fail.
3. In `limit()`, return `$limit` instead of `max(1, $limit)`. Expected: `zero still does one item` fails.

Restore each with the Edit tool. Paste the outputs into the report.

- [ ] **Step 8: Gates**

Run: `composer check`, `composer md`, then PhpStorm `lint_files` on the seven `src` files and three tests.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add src/Command/ConsoleOption.php src/Command/Exception/MalformedOptionException.php tests/Command/ConsoleOptionTest.php tests/Command/RefreshFeedsCommandTest.php tests/Command/RefreshFeedsCommandRequestTest.php src/Command/RefreshFeedsCommand.php src/Command/CheckCatalogUrlsCommand.php src/Command/WarmCatalogFaviconsCommand.php src/Command/ReaderAuditCommand.php src/Command/ReaderAuditReportCommand.php
git commit -m "refactor(#1168): one console-option reading; a malformed number is refused"
```

---

### Task B9: `AltchaService::requireSolved()`

**Files:**
- Modify: `src/Service/Auth/AltchaService.php` (one new public method, one import)
- Modify: `src/Controller/Api/AuthController.php` (two identical blocks, one import)
- Test: `tests/Service/Auth/AltchaServiceTest.php` (two new tests). `RegistrationTest::testUnsolvedAltchaIsRejectedAndCreatesNoUser` and `PasswordResetTest`'s `'garbage'` case pin the 422 body on the wire.

**Interfaces:**
- Produces: `AltchaService::requireSolved(string $payload): void`. It throws `App\Exception\ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']])` when `verify()` refuses, and claims the solution otherwise, as `verify()` does.
- Consumes: `AltchaService::verify(string): bool`, unchanged.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Auth/AltchaServiceTest.php`: add `use App\Exception\ValidationException;` and, after `testAcceptsACorrectSolution()`:
```php
    public function testRequireSolvedRefusesAnUnsolvedChallengeOnTheAltchaField(): void
    {
        try {
            $this->service->requireSolved('garbage');
            self::fail('An unsolved challenge must be refused.');
        } catch (ValidationException $refusal) {
            self::assertSame(['altcha' => ['The anti-spam challenge was not solved correctly.']], $refusal->errors);
        }
    }

    public function testRequireSolvedSpendsACorrectSolution(): void
    {
        $challenge = $this->service->createChallenge();
        $number = $this->solve($challenge->salt, $challenge->maxNumber, $challenge->challenge);
        $payload = $this->payloadFor($number, $challenge->salt, $challenge->challenge, $challenge->signature);

        $this->service->requireSolved($payload);

        self::assertFalse($this->service->verify($payload), 'a solution that passed must be claimed against replay');
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter RequireSolved tests/Service/Auth/AltchaServiceTest.php`
Expected: FAIL, 2 errors: `Call to undefined method App\Service\Auth\AltchaService::requireSolved()`.

- [ ] **Step 3: Move the check into the service**

`src/Service/Auth/AltchaService.php`: add `use App\Exception\ValidationException;`, and add after `verify()`:
```php
    /**
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function requireSolved(string $payload): void
    {
        if (!$this->verify($payload)) {
            throw new ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']]);
        }
    }
```

`src/Controller/Api/AuthController.php`: the block appears twice, in `register()` and `passwordResetRequest()`. Edit with `replace_all`:
```diff
-        if (!$this->altcha->verify($request->altcha)) {
-            throw new ValidationException(['altcha' => ['The anti-spam challenge was not solved correctly.']]);
-        }
+        $this->altcha->requireSolved($request->altcha);
```
and delete `use App\Exception\ValidationException;`, which nothing else in the controller uses.

- [ ] **Step 4: Run the tests**

Run:
```bash
php bin/phpunit tests/Service/Auth/AltchaServiceTest.php tests/Controller/Api/RegistrationTest.php tests/Controller/Api/PasswordResetTest.php
git grep -n "anti-spam challenge was not solved" -- src
```
Expected: PASS, and the grep prints only `src/Service/Auth/AltchaService.php`.

- [ ] **Step 5: Deletion check**

In `requireSolved()`, drop the `!` from the condition. Expected: both new tests fail, and so do `RegistrationTest`'s successful registrations. Restore with the Edit tool. Paste both outputs into the report.

- [ ] **Step 6: Gates**

Run: `composer check` (`ThinControllerRule` included), `composer md`, then PhpStorm `lint_files` on the two `src` files and the test.
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Auth/AltchaService.php src/Controller/Api/AuthController.php tests/Service/Auth/AltchaServiceTest.php
git commit -m "refactor(#1168): the altcha service refuses an unsolved challenge itself"
```

---

### Task B10: `TrialEndJson`

**Files:**
- Create: `src/Http/TrialEndJson.php`
- Create: `tests/Http/TrialEndJsonTest.php`
- Modify: `src/Http/AdminUserJson.php` (two fields), `src/Http/MeJson.php` (one field), `src/Http/AdminUserLimitsJson.php` (one field)

**Interfaces:**
- Produces: `App\Http\TrialEndJson::of(User $user): ?string`, the trial's end as an ATOM instant (offset kept), or `null` when the account has no trial.
- Consumes: `User::getTrialEndsAt(): ?\DateTimeImmutable`, kept by #1164.

- [ ] **Step 1: Write the failing test**

`tests/Http/TrialEndJsonTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\TrialEndJson;
use PHPUnit\Framework\TestCase;

final class TrialEndJsonTest extends TestCase
{
    public function testAnAccountWithoutATrialHasNoEnd(): void
    {
        self::assertNull(TrialEndJson::of($this->user()));
    }

    public function testATrialEndsAtAnAtomInstantThatKeepsItsOffset(): void
    {
        $user = $this->user();
        $user->setTrialEndsAt(new \DateTimeImmutable('2026-10-01T09:30:00+02:00'));

        self::assertSame('2026-10-01T09:30:00+02:00', TrialEndJson::of($user));
    }

    private function user(): User
    {
        return new User('trial-end@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Http/TrialEndJsonTest.php`
Expected: FAIL, 2 errors: `Class "App\Http\TrialEndJson" not found`.

- [ ] **Step 3: Write the helper and use it in the four fields**

`src/Http/TrialEndJson.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\User;

final class TrialEndJson
{
    public static function of(User $user): ?string
    {
        return $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM);
    }
}
```

All four callers share the `App\Http` namespace, so no import is needed.

`src/Http/AdminUserJson.php`, in `listRows()`:
```diff
-                'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
+                'trialEndsAt' => TrialEndJson::of($user),
```
and in `limits()`:
```diff
-            trialEndsAt: $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
+            trialEndsAt: TrialEndJson::of($user),
```

`src/Http/MeJson.php` and `src/Http/AdminUserLimitsJson.php`, each:
```diff
-            'trialEndsAt' => $user->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
+            'trialEndsAt' => TrialEndJson::of($user),
```

- [ ] **Step 4: Run the tests**

Run:
```bash
php bin/phpunit tests/Http tests/Controller/Api/MeControllerTest.php tests/Controller/Admin/AdminUserControllerTest.php
git grep -n "getTrialEndsAt()?->format" -- src
```
Expected: PASS, and the grep prints only `src/Http/TrialEndJson.php`.

- [ ] **Step 5: Deletion check**

In `TrialEndJson::of()`, change `\DateTimeInterface::ATOM` to `'Y-m-d\TH:i:sP'`. That is the same text for these values, so the tests must stay green, which shows the pin is on the output and not on the constant. Then change it to `\DateTimeInterface::RFC2822`. Expected: `testATrialEndsAtAnAtomInstantThatKeepsItsOffset`, `AdminUserLimitsJsonTest::testATrialReportsTheStatusAndItsEnd` and `MeControllerTest::testTrialEndsAtIsExposedWhenSet` fail. Restore with the Edit tool. Paste the outputs into the report.

- [ ] **Step 6: Gates**

Run: `composer check` (`DomainKnowsNoHttpRule`: `TrialEndJson` lives in `App\Http` and imports only the entity), `composer md`, then PhpStorm `lint_files` on the four `src` files and the test.
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Http/TrialEndJson.php tests/Http/TrialEndJsonTest.php src/Http/AdminUserJson.php src/Http/MeJson.php src/Http/AdminUserLimitsJson.php
git commit -m "refactor(#1168): one serialisation of a trial's end"
```

---

### Task B11: `SeedsDigestReaders`

**Files:**
- Create: `tests/Support/SeedsDigestReaders.php`
- Modify: `tests/Service/Mail/Digest/SendDueDigestsTest.php` (`verifiedUser()`/`unverifiedUser()`, as #1167 A8 left them, go), `tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`, `tests/Service/Worker/SendDueDigestsHandlerTest.php` (`user()` goes)

**Interfaces:**
- Produces: `trait App\Tests\Support\SeedsDigestReaders`. It has `private function unverifiedUser(): User`, a persisted, flushed account with a unique `digest-…@example.com` address, created `2026-07-01T00:00:00Z`. It also has `private function verifiedUser(): User`, the same account with `markEmailVerified(2026-07-02T00:00:00Z)` applied after the flush. That is exactly what all three copies do: the next flush in the test writes it.
- Consumes: nothing. The method names are the ones #1167 A8 gave `SendDueDigestsTest`.

This is the third copy of the digest reader fixture (DRY rule, planner ruling). The copies differ only in the address prefix and domain, and no test reads the address except `SendDueDigestsHealthTest`, which compares it with the recorded failure's recipient. No new test: the three suites are the net.

- [ ] **Step 1: Write the trait**

`tests/Support/SeedsDigestReaders.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

trait SeedsDigestReaders
{
    private function verifiedUser(): User
    {
        $user = $this->unverifiedUser();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }

    private function unverifiedUser(): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $email = \sprintf('digest-%s@example.com', uniqid('', true));
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
```

- [ ] **Step 2: Sweep the three copies**

Each file gains `use App\Tests\Support\SeedsDigestReaders;` among its imports, and `    use SeedsDigestReaders;` first in the class body, followed by a blank line. Merge it alphabetically with any trait `use` lines already there.

`tests/Service/Mail/Digest/SendDueDigestsTest.php`: delete `verifiedUser()` and `unverifiedUser()`, each with the blank line above it. This is the text #1167 A8 landed, verified at `fd5fa734`:
```php

    private function verifiedUser(): User
    {
        $user = $this->unverifiedUser();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }

    private function unverifiedUser(): User
    {
        $email = \sprintf('digest-%s@example.com', uniqid('', true));
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
```
Call sites stay as they are: eight `$this->verifiedUser()` and one `$this->unverifiedUser()`.

`tests/Service/Mail/Digest/SendDueDigestsHealthTest.php`: delete `user()` with the blank line above it,
```php

    private function user(): User
    {
        $email = 'reader-' . uniqid('', true) . '@example.test';
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }
```
then `perl -pi -e 's/\$this->user\(\)/\$this->verifiedUser()/g' tests/Service/Mail/Digest/SendDueDigestsHealthTest.php` (2 sites).

`tests/Service/Worker/SendDueDigestsHandlerTest.php`: delete `user()` with the blank line above it,
```php

    private function user(): User
    {
        $email = 'digest-' . uniqid('', true) . '@example.com';
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->flush();
        $user->markEmailVerified(new \DateTimeImmutable('2026-07-02T00:00:00Z'));

        return $user;
    }
```
then `perl -pi -e 's/\$this->user\(\)/\$this->verifiedUser()/g' tests/Service/Worker/SendDueDigestsHandlerTest.php` (1 site).

`use App\Entity\User;` stays in all three files: each still names `User` in `duePreferences(User $user, …)`, and `SendDueDigestsTest` also in a `static function (User $user)` callback. Confirm with `grep -cE '\bUser\b' <file>`: every count must be above 1.

- [ ] **Step 3: Check no copy is left**

```bash
git grep -n "markEmailVerified(new \\\\DateTimeImmutable('2026-07-02" -- tests
git grep -n '\$this->user()' -- tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php
```
Expected: the first prints only `tests/Support/SeedsDigestReaders.php`, unless another test verifies a user at that instant for its own reason (read it before touching it). The second prints nothing.

- [ ] **Step 4: Run the suites**

Run: `php bin/phpunit tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php`
Expected: PASS, with the same test count as before.

- [ ] **Step 5: Deletion check**

In `SeedsDigestReaders::verifiedUser()`, delete the `markEmailVerified()` line. Expected: at least one digest-sending test in each of the three files fails, because unverified readers get no digest. Restore it with the Edit tool. Paste both outputs into the report.

- [ ] **Step 6: Gates**

Run: `composer check`, then PhpStorm `lint_files` on the four files.
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add tests/Support/SeedsDigestReaders.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php
git commit -m "test(#1168): one digest-reader fixture"
```

---

### Task B12: Carry-forward test tidy-ups from #1164

**Files:**
- Modify: `tests/Service/Backup/AccountRestorerTest.php` (`fixtureRowsOf()`: one statement and its comment)
- Modify: `tests/Controller/Api/PasskeyRegistrationTest.php`, `tests/Service/Passkey/PasskeySignInAvailabilityTest.php` (two unused imports each)

**Interfaces:** none. Test-only; no new test.

The bare `$feed->getUrl();` in `fixtureRowsOf()` (line 560 at `fd5fa734`) is not a lost assertion. Its six-line comment says what it is for: it forces the lazy `Feed` to load while its row still exists, because `testEveryBackedUpFieldSurvivesTheRestoreRoundTrip()` calls `deleteEveryFeed()` before it reads `$sourceRows['feed']`. Deleting the statement would bring that failure back, and an assertion on the URL would hide the reason behind a check nobody needs. `EntityManagerInterface::initializeObject()` (Doctrine ORM 3.6, declared on `Doctrine\Persistence\ObjectManager`) says exactly this, so the statement becomes that call and the comment shrinks to the one line that keeps it from being deleted.

- [ ] **Step 1: Make the proxy initialisation explicit**

`tests/Service/Backup/AccountRestorerTest.php`, in `fixtureRowsOf()`:
```diff
-        // A ManyToOne association loads lazily: without touching it here, the
-        // caller's later getUrl() call would try to initialize this proxy for
-        // the first time AFTER the source account's own feed row was deleted
-        // to force the target's rows to build fresh from the file — and find
-        // nothing to load. Touching it now, while the row still exists, bakes
-        // the value into the object so it survives that deletion detached.
         $feed = $subscription->getFeed();
-        $feed->getUrl();
+        // Load it now: the round-trip test deletes every feed row before it reads this one.
+        $this->em->initializeObject($feed);
```

- [ ] **Step 2: Remove the unused imports**

`tests/Controller/Api/PasskeyRegistrationTest.php` (lines 11-12 at `fd5fa734`):
```diff
-use App\Service\Settings\InstanceSettings;
-use App\Service\Settings\InstanceSettingsUpdate;
```

`tests/Service/Passkey/PasskeySignInAvailabilityTest.php` (lines 10 and 13 at `fd5fa734`):
```diff
-use App\Service\Settings\EffectivePasskeyRelyingPartyId;
```
```diff
-use App\Service\Settings\PublicBaseUrl;
```

Then check that nothing else in either file names them:
```bash
grep -cE '\b(InstanceSettings|InstanceSettingsUpdate)\b' tests/Controller/Api/PasskeyRegistrationTest.php
grep -cE '\b(EffectivePasskeyRelyingPartyId|PublicBaseUrl)\b' tests/Service/Passkey/PasskeySignInAvailabilityTest.php
```
Expected: `0` and `0`. (`PasskeySignInAvailabilityTest` keeps `use App\Service\Settings\InstanceSettings;`, which it uses.)

- [ ] **Step 3: Run the three suites**

Run: `php bin/phpunit tests/Service/Backup/AccountRestorerTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Service/Passkey/PasskeySignInAvailabilityTest.php`
Expected: PASS, with the same test count as on `origin/develop`.

- [ ] **Step 4: Deletion check**

Delete the `$this->em->initializeObject($feed);` line and run `php bin/phpunit --filter testEveryBackedUpFieldSurvivesTheRestoreRoundTrip tests/Service/Backup/AccountRestorerTest.php`. Expected: FAIL, with an `EntityNotFoundException` (or a field mismatch on `Feed`), because the proxy has no row left to load. Restore the line with the Edit tool. Paste both outputs into the report. If the test stays green, the feed was never a proxy at that point and the statement was never load-bearing: delete the call and its comment for good, re-run Step 3, and record that in the report.

- [ ] **Step 5: Gates**

Run: `composer check`, then PhpStorm `lint_files` on the three files.
Expected: clean. The "expression result unused" inspection on `AccountRestorerTest` and the unused-import inspections on the two passkey tests are gone.

- [ ] **Step 6: Commit**

```bash
git add tests/Service/Backup/AccountRestorerTest.php tests/Controller/Api/PasskeyRegistrationTest.php tests/Service/Passkey/PasskeySignInAvailabilityTest.php
git commit -m "test(#1168): the restorer test loads its feed explicitly; unused passkey test imports go"
```

---

### Finishing PR B

- [ ] **Step 1: Every gate on the whole branch**

```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
```
Also run PhpStorm `lint_files` on every PHP file in `git diff --name-only --relative origin/develop -- '*.php'`.
Expected: all green, with the containers current first. An escaped mutant on a touched line gets a killing test in the owning task. Never lower `minMsi`. Scan today's dev log as in PR A.

- [ ] **Step 2: /simplify**

As in PR A. Commit as `refactor(#1168): simplify pass` if anything changed.

- [ ] **Step 3: SDD final review**

Dispatch the final reviewer over `git diff origin/develop...HEAD` with the spec, this plan and these attack points:
1. **Fixture drift in B1.** The eight variant-P files now seed active, flushed, password-hashed accounts. Hunt for any assertion that depended on a pending status, an unflushed user or a query count that now includes the early flush. Confirm that the `QueryRecorder` resets in `UnsubscribeAllTest` and `OwnedTagsCacheTest` still sit after the fixture flush.
2. **`ReloadsEntities` on a rebooted kernel.** `AdminUserControllerTest` reloads an entity that a previous kernel created. Check that the identifier comes from the entity's own id property and that `find()` runs on the current kernel's manager, which is what the deleted helper did on purpose.
3. **Heartbeat reads.** No test may still read a managed `WorkerHeartbeat`, and removing the `em->clear()` calls must not hide a stale read somewhere else in those methods.
4. **The OAuth replay test.** Break `OAuthStateStore::consume()`'s `deleteItem()` yourself: the test must fail. `OidcBoundaryTest` must be untouched.
5. **Collaborator wiring.** `NextPosition` and `RowIds` are injected, not `new`-ed, and sit in `src/Repository` (`QueriesLiveInRepositoriesRule`). No `DELETE … IN` or `MAX(position)` is left in a repository. The `RetentionRepository` change keeps `EntryPruner`'s chunking.
6. **Clocks.** `DigestEnablement` still seeds naive UTC from a non-UTC clock. `DigestHtmlRenderer` has no `new \DateTimeImmutable` left, and the DI container wires `Psr\Clock\ClockInterface` (`bin/console lint:container`).
7. **Command behaviour.** Compare every option each of the five commands reads before and after, `ReaderAuditReportCommand` included. The only differences are the ones listed under "Deliberate behaviour changes". A malformed number must reach the operator as a message with exit code 2, never as `null`. Check that no command catches `MalformedOptionException` and turns it back into a default.
8. **No wire change.** The 422 bodies for an unsolved ALTCHA and a short password, and every `trialEndsAt` field, are byte-identical. `git diff origin/develop --stat -- src/Controller` shows only `AuthController`.
9. **B11 fixture equivalence.** The digest reader is persisted and flushed, and only then verified, in all three suites, as before.
10. **B12.** `AccountRestorerTest` still loads the source feed before `deleteEveryFeed()` runs (the Step 4 deletion check proves it), and no import removed from the two passkey tests is still named in them.

Fix every finding rated Important or above in the owning task, re-run the gates, and record the rest in the PR body.

- [ ] **Step 4: Open the PR**

```bash
git push -u origin refactor/1168-shared-queries-config-and-fixtures
gh pr create --base develop --title "refactor(#1168): shared queries, clocks, config, commands and test fixtures" --body "$(cat <<'BODY'
Closes #1168 (PR B of two; PR A was the text, URL, JSON-LD, base64url and header helpers).

- `NextPosition` and `RowIds`: injected repository collaborators for the five `MAX(position)` queries and the four `DELETE WHERE id IN (:ids)` deletes.
- `DigestEnablement` injects `NaiveUtcClock`; `DigestHtmlRenderer` dates its header from the injected clock.
- `PasswordPolicy` holds the 12-character minimum for both commands and all three DTOs.
- `ConsoleOption`: one option reading for the four commands the issue lists and a fifth copy the sweep found (`app:reader:audit:report`); a malformed number is refused with exit code 2.
- `SeedsDigestReaders` replaces the three digest-test verified-reader helpers.
- `AltchaService::requireSolved()` replaces the check `AuthController` built twice.
- `TrialEndJson`: one serialisation of a trial's end for four fields.
- Carry-forwards: `SeedsUsers`/`ReloadsEntities` replace 41 hand-copied test helpers; heartbeat tests read through `findTouchedAt()`; the OAuth provider-mismatch replay assertion now fails if the state is not burned; `AccountRestorerTest` loads its feed with `initializeObject()` instead of a bare getter call; four unused imports leave the passkey tests.

Deliberate behaviour change (nothing on the wire): console option values are trimmed, and a malformed number option (negative, fractional, partly digits) now stops the command with a message naming it and exit code 2. It used to be read as absent (the catalog commands ran unbounded, `app:feeds:refresh --feed=abc` refreshed everything) or, in `app:reader:audit` and `app:reader:audit:report`, cast (`--limit=12abc` and `--top=12abc` meant 12).
BODY
)"
```

- [ ] **Step 5: Merge when green**

Start a Monitor on `gh pr checks <PR> --watch --fail-fast`. When it exits 0, run `gh pr merge <PR> --merge`. Never pass `--auto`. On a red check, fix it on the branch and restart the Monitor. For a tramp-only failure, check `composer show larspohlmann/phptramp` first.

- [ ] **Step 6: Verify the issue closed**

`gh issue view 1168 --json state --jq .state`. Expected: `CLOSED`, closed by the merge. Do not close it by hand. If it is still open, check the merged PR's body for the `Closes #1168` line and report.
