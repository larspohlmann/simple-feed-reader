# Split the Admin Settings Services from the Runtime Configuration (#1159) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close #1159 in two PRs. Each of the three instance settings (proxy, Grafana, mail) gets an admin service that the controller maps to JSON, and a separate runtime reader that the rest of the app consumes through an interface its consumer owns.
- **PR A** (Tasks A0–A3, `Refs #1159`): the proxy. This PR breaks the Fetch ↔ Proxy cycle.
- **PR B** (Tasks B0–B7, `Closes #1159`): Grafana and mail. This PR breaks the Grafana ↔ Profiling cycle, and B7 fixes the raw `SecretUnreadableException` that escaped a proxied mail send.

**Architecture:**
- Each setting follows the same three steps, in this order: read side, then update value, then runtime split. Every test file that the split rewrites is therefore written once, in its final form, in the split task.
- **Read side.** The admin read returns a plain snapshot instead of the entity:
  - `ProxySettingsSnapshot(ProxyConnection $connection, bool $hasPassword)`.
  - `MailSettingsSnapshot(MailConnection $connection, bool $hasPassword)`.
  - `GrafanaSettingsSnapshot`, which already exists as the cache copy. Its fields become public, `hasToken()` is added, and `toEntity()` goes.
  - `ProxyServerSettings::connection()` and `GrafanaSettings::connection()` join the existing `MailServerSettings::connection()`.
  - The `*Overview` values hold no entity, and their `@noinspection AutowireWrongClass` lines go.
  - `ProxySettings::stored()` becomes `current(): ProxySettingsSnapshot`.
- **Update value.** Each request DTO gets `toUpdate()`, following the `InstanceSettingsRequest::toUpdate()` precedent. It returns `ProxySettingsUpdate`, `GrafanaSettingsUpdate` or `MailSettingsUpdate`: a connection value plus an `App\Service\Crypto\SecretChange` (keep, replace or remove). No service imports `App\Dto` any more.
- **Runtime split.**
  - `App\Service\Fetch\EgressProxySource` is implemented by `App\Service\Proxy\StoredProxy`, which replaces `ProxyEgressResolver`. `StoredProxy` also implements `App\Service\Proxy\ConfiguredProxySource`, a narrow interface that proxied mail and the proxy tester read.
  - `App\Service\Profiling\ProfilingConfigSource` is implemented by `App\Service\Grafana\EffectiveGrafanaSettings`.
  - `App\Service\Mail\MailSendingSettings` is implemented by `App\Service\Mail\Settings\EffectiveMailSettings`.
  - The admin services keep their names (`ProxySettings`, `GrafanaSettings`, `MailSettings`) and become `final readonly`. Tests double the interfaces.

**Tech Stack:** PHP 8.4, Symfony 7.4 (DI aliases in `config/services.yaml`, `#[MapRequestPayload]`), Doctrine ORM, PSR-6 cache, PHPUnit 12, PHPStan level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection.

**Spec:**
- GitHub issue #1159 (`gh issue view 1159`). #1182 (`gh issue view 1182 --comments`) takes every *other* request DTO a service still accepts, the `toArray()` leaks and the shared value-type home.
- The planner's carry-forward rulings from #1158:
  - #1159 owns the settings split and its request DTOs.
  - `ProxySettings::stored()` becomes a read-side value.
  - `GrafanaSettingsOverview::$settings` is no longer nullable.
  - The Grafana entity gets `connection()`, and no `*Overview` holds a settings entity.
  - The settings entities are snapshotted into plain values for the read side.
- CLAUDE.md "PHP code style — Clean Code is mandatory", `docs/architecture.md` §6 (native-client checklist) and §7 (queries live in repositories).

## Status

| Task | State |
|---|---|
| A0: Preflight | ⬜ |
| A1: Proxy read side: `ProxySettingsSnapshot`, `current()` | ⬜ |
| A2: `SecretChange` and `ProxySettingsUpdate` | ⬜ |
| A3: `EgressProxySource` / `StoredProxy`; `ProxyEgressResolver` goes | ⬜ |
| B0: Preflight (PR A merged) | ⬜ |
| B1: Grafana read side: `connection()`, public snapshot, entity-free overview | ⬜ |
| B2: `GrafanaSettingsUpdate` | ⬜ |
| B3: `ProfilingConfigSource` / `EffectiveGrafanaSettings`; profiling failure decided | ⬜ |
| B4: Mail read side: `MailSettingsSnapshot`, entity-free overview | ⬜ |
| B5: `MailSettingsUpdate` | ⬜ |
| B6: `MailSendingSettings` / `EffectiveMailSettings` | ⬜ |
| B7: An unreadable proxy password fails a proxied mail send like every other transport failure | ⬜ |

## Scope

| Issue bullet or ruling | Task |
|---|---|
| Proxy serves the admin screen and the runtime | A1, A2, A3 |
| Grafana serves the admin screen and the runtime | B1, B2, B3 |
| Mail serves the admin screen and the runtime | B4, B5, B6 |
| Fetch ↔ Proxy cycle (`ProxyEgressResolver → ProxySettings → Fetch\ProxyConfig`) | A3 |
| Grafana ↔ Profiling cycle (`ProfilingPolicy → GrafanaSettings → ProfileSampler`) | B3 |
| Core fetch code depends on admin JSON mappers and request DTOs | A2, A3 |
| The three classes and `ProxyEgressResolver` are non-`final` only for mocking | A3, B3, B6 |
| `ProfilingPolicy` catches `\Throwable`: decide what a failing config read means | B3 |
| Ruling: #1159 owns the admin settings request DTOs (#1182 bullet 1) | A2, B2, B5 |
| Ruling: `ProxySettings::stored()` hands out the entity | A1 |
| Ruling: `GrafanaSettingsOverview::$settings` nullable | B1 |
| Ruling: Grafana `connection()`; no `*Overview` holds a settings entity | B1 (Grafana), B4 (Mail) |
| Ruling: snapshot the settings entities for the read side | A1, B1, B4 |
| Ruling: a raw `SecretUnreadableException` escaping a proxied mail send is fixed in PR B | B7 |

**Design decisions (D1 and D3–D7 confirmed by the planner; D2 as ruled):**
- **D1: the admin services keep their names.** `ProxySettings`, `GrafanaSettings` and `MailSettings` remain as the admin services, so the three controllers and the kernel tests that save settings keep working. The runtime readers get new names:
  - `StoredProxy`. It has no env fallback, so "stored" is exact.
  - `EffectiveGrafanaSettings` and `EffectiveMailSettings`. Both resolve row-or-env, which is what "effective" already means on the Grafana wire (`lokiPushUrlEffective`).
- **D2: `ActiveMailTransportFactory` reads the proxy through `App\Service\Proxy\ConfiguredProxySource`**, a one-method interface on the Proxy side (`configuredProxy(): ?ProxyConfig`) that `StoredProxy` implements.
  - `Fetch\EgressProxySource` does not fit: mail must use the saved proxy whether or not feed egress is on.
  - A mail-owned interface implemented in `Service/Proxy` would add Proxy → Mail, a new cycle. Mail → Proxy already exists (`MailSettings` reads `ProxySettings`) and is fine.
  - `ProxyConnectionTester` takes the same interface. `ActiveMailTransportFactoryTest` stubs it, and `ProxyConnectionTesterTest` keeps driving a real `StoredProxy` (it needs a real cipher to prove the rotated-key report).
- **D3: one mail runtime interface, `App\Service\Mail\MailSendingSettings`, with the five read methods that exist today, unrenamed.** Every consumer is in `Service/Mail`. Splitting it further would give `MailConnectionTester` a sixth constructor parameter.
- **D4: `ProfilingConfigSource` carries `refresh()` as well as `profilingEnabled()`.** The worker listener has to drop its per-process memo before each toggle re-check (#1012), and after the split the listener depends on this interface alone.
- **D5: a failed profiling config read means profiling is off.** `ProfilingPolicy` keeps catching `\Throwable`. `RequestProfilingListener` calls `isEnabled()` on every request at priority 4096, outside its own `try`, so an exception there would fail every request. B3 names the decision in one comment line and pins both failure paths (the toggle and the push URL) with tests.
- **D6: `SecretChange` lives in `App\Service\Crypto`**, next to `SealedSecret`. Each DTO maps its own intent fields in a private method. Proxy and mail are identical, and Grafana also treats `''` as "keep". A shared factory would need a `bool $remove` flag parameter.
- **D7: each admin service keeps its own three-branch "apply the secret change".** The three entities have different mutators (`apply`/`applyWithoutPassword`/`clearStoredPassword` against `…Token`, plus the Grafana hint). A shared helper would need an entity interface that #1164 did not introduce.

**Not in scope:**
- `InstanceSettingsRequest` / `RelyingPartyChange` and the other request DTOs services still take (#1182, bullet 1).
- `MailTestResult::toArray()` and `ProxyTestResult::toArray()` (#1182, bullet 2).
- Where `ProxyConnection`, `MailConnection`, `GrafanaConnection` and `SecretChange` finally live (#1182, bullet 3).
- `RecommendationDebugLog` holds a `RecommendationRun`. It is neither an `*Overview` nor a settings entity.
- The unsuppressed `AutowireWrongClass` warnings in `SubscribeOutcome`, `SavedSearchOutcome` and `AddedConfiguration` belong to #1182.
- The entity mutator names (`apply`, `applyWithoutPassword`, `clearStoredPassword`, `…Token`) stay as they are.

- A PHPStan rule that stops the two cycles from coming back. The planner records it separately.

## Wire changes

Two. The first is a side effect of the read-side snapshot (ruling 2):
- `GET /api/admin/mail`, `PUT /api/admin/mail` and `POST /api/admin/mail/reset` decrypted the stored **proxy** password just to print the proxy's label. When that password could not be opened (a rotated `INSTANCE_SECRET_KEY`, or a dump restored onto another instance), they answered **500**. They now answer as usual, because the label is read from `ProxySettingsSnapshot`. A1 pins this with a controller test. The PR A body lists it.

The second is B7's fix (planner ruling):
- `POST /api/admin/mail/test` answered **500** for a saved row that routes through a proxy whose stored password cannot be opened, because `ActiveMailTransportFactory` opened it outside the tester's `SecretUnreadableException` guard. It now answers `200 {"ok": false, "reason": "<cipher message>"}`, exactly as it already does for an unreadable *mail* password.
- A mail send in that state (digest, account mail) now fails with a Symfony `TransportException` ("The stored proxy password is unreadable: …"), like a rotated mail password or a dead relay, instead of a raw `SecretUnreadableException`. That is not an HTTP response, but it changes what the send paths catch and record.
- The PR B body lists both.

Nothing else changes. Every other response body, status code and header stays byte-identical. The frontend is untouched, so `npm run check` is not a gate for either PR.

## Planner rulings at reconcile (89c7e17f)

- B1: trim `GrafanaSettingsJson`'s class docblock to at most three lines (CLAUDE.md cap); drop any line that restates the class.
- B6 and every other step that touches a docblock already longer than three lines (`EnablesMailInTests`, `InstanceSettingsJson`, `MailCapabilityWiringTest`, and any the executor meets): trim it to three lines or fewer while touching it ("delete on sight in code you touch"). Record each trim in the PR's execution rulings.

## Depends on #1164, #1167 and #1168 (landed)

All three have merged into `develop`: #1164 at `0f80267b`, #1167 at `fd5fa734`, #1168 (PRs #1189 and #1190) at `89c7e17f`. This plan was reconciled against `89c7e17f`: every quoted "before", every text anchor, every perl pattern and every full-file rewrite below was checked against that tree. Locate each edit by its **text**; any line number or grep count in this plan is at `89c7e17f`, for orientation only.

What landed in the files this plan touches:

| Step | File | Landed change | How this plan reconciles |
|---|---|---|---|
| A1 Step 1, A2 Step 4, A3 Step 1 | `tests/Service/Proxy/ProxySettingsTest.php` | #1167 A7b (`09aad711`): the ten `new ProxySettingsRequest(` became `SettingsRequests::proxy(`, and the DTO import became `use App\Tests\Support\SettingsRequests;`. Nothing else. | A1 edits `viewOf()`'s `->stored()` and adds a test after `viewOf()`, using the file's own `service($stored)` helper. A2's perl appends `->toUpdate()`. A3 rewrites the file; its runtime cases move to `StoredProxyTest`. |
| A1 Step 1 | `tests/Controller/Admin/AdminMailControllerTest.php` | #1167 A7b: `mailBody()`, `SAVED_SMTP_ROW`, full PUT bodies, the 422 tests; #1167 `2f1c3c83`: users come from `ApiTestCase::factory()`. | A1 adds one test that calls `mailBody()`, `admin()`, `em()` and `payload()`, all present, plus four imports around `use App\Entity\User;`. |
| A1 Step 3, A2 Step 3 | `src/Controller/Admin/AdminProxyController.php` | #1167 A7b: `update()` maps its payload with `FullReplacePayload::CONTEXT`. | A1 replaces both `->stored()` calls; A2 replaces `$this->settings->update($request);`. Neither touches the signature. |
| A2 Step 1, B2 Step 1, B5 Step 1 | `tests/Dto/Admin/{Proxy,Grafana,Mail}SettingsRequestTest.php` | #1167 A7b: `SettingsRequests` import, one explicit-constructor test per file. | Imports go after the DTO import; the new tests go before the class's closing brace. |
| A2, B2, B5 Step 3 | `src/Dto/Admin/{Proxy,Grafana,Mail}SettingsRequest.php` | #1167 A7b and R7: no defaults on the settings, a docblock that points at `{@see FullReplacePayload::CONTEXT}`, and the `use App\Http\FullReplacePayload;` that the `{@see}` needs. | Each rewrite is the landed file verbatim (docblock and `FullReplacePayload` import included) plus the new imports and `toUpdate()`. |
| B2 Step 3, B5 Step 3 | `src/Controller/Admin/AdminGrafanaController.php`, `src/Controller/Admin/AdminMailController.php` | #1167 A7b, as for the proxy controller. | Only `$this->settings->update($request);` changes. |
| A2 Step 4, B5 Step 4, B7 Step 1 | `tests/Service/Proxy/ProxyConnectionTesterTest.php`, `tests/Service/Mail/Settings/MailSettingsTest.php`, `tests/Service/Mail/Settings/MailConnectionTesterTest.php`, `tests/Service/Mail/Transport/DynamicMailTransportTest.php` | #1167 A7b: `SettingsRequests::proxy(`/`::mail(` substitution and import swap. Nothing else. | The perl appends `->toUpdate()` to each balanced call and matches text, not lines. B7 anchors on imports and test names that are all present. |
| B6 Step 1 | `tests/Service/Mail/Settings/MailConnectionTesterTest.php` | As above. `authenticateAsAdmin()` still builds its own `UserFactory`; #1168's `SeedsUsers` cannot pass `roles`, so it stays. | B6 replaces only `testerWithHealth()`'s `$this->settings(),`, which occurs once. |
| B2 Step 4, B3 Step 1 | `tests/Service/Grafana/GrafanaSettingsTest.php`, `tests/Functional/RequestProfilingTest.php` | #1167 A7b: `SettingsRequests::grafana(` substitution. Nothing else. | B2's perl appends `->toUpdate()`; B3 rewrites `GrafanaSettingsTest`; its runtime cases move to `EffectiveGrafanaSettingsTest`. |
| B6 Step 1 | `tests/Service/Mail/Settings/MailSettingsTest.php` | #1167 A7b only. | Rewritten; the runtime reads move to `EffectiveMailSettingsTest`. |
| A3 Step 5 | `tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php` | #1164 (`scheduleNextFetchAt()`), #1168 (`new RetentionRepository($this->em, new RowIds($this->em))`). | The perl matches only `ProxyEgressResolver`, `->method('resolve')` and `$proxyEgressResolver`, none of which those changes touched. |
| B6 Step 5 | `tests/Service/OAuth/OAuthAccountLinkerTest.php` | #1164: `persistUser()` goes through `NewUserStatus::apply()`. | The perl matches only the `MailSettings` import and `MailSettings::class`. |
| B6 Step 5 | `tests/Service/Mail/Digest/DigestMailBuilderTest.php`, `DigestMailerTest.php`, `SendTestDigestTest.php` (#1168 `4201ce6a`, the digest clocks), `SendDueDigestsTest.php`, `SendDueDigestsHealthTest.php`, `tests/Service/Worker/SendDueDigestsHandlerTest.php` (#1168 `c99c8d94`, `SeedsDigestReaders`; `SendDueDigestsTest` also #1167 A8) | Fixture and clock changes only. | Same perl, same three patterns; every `MailSettings` line they match is still there. |

Checked by `git diff --stat 1cdcf65d 89c7e17f`: apart from the three request DTOs and the three admin controllers, no `src/` or `config/` file this plan edits changed. That includes the settings services and their snapshots, the entities, the JSON mappers, the repositories, the listeners, `ProxyConnectionTester`, `ActiveMailTransportFactory`, `DynamicMailTransport`, `MailConnectionTester`, `config/services.yaml` and `config/services_test.yaml`. The test files this plan rewrites in full and that the table does not list (`ActiveMailTransportFactoryTest`, `CheckCatalogUrlsCommandProxyTest`, `GrafanaSettingsSnapshotTest`, `GrafanaSettingsJsonTest`, `SettingsLokiEndpointTest`, `SettingsPyroscopeEndpointTest`, `ProfilingPolicyTest`, `MailSettingsJsonTest`) did not change either. The set of files that name `ProxySettings`, `GrafanaSettings` (the service) or `MailSettings` is the same at both SHAs, so no new consumer needs a rewrite.

#1168's shared helpers (`ReloadsEntities`, `SeedsUsers`/`UserFactory`, `SeedsDigestReaders`, `Whitespace::collapse()`, `AbsoluteHttpUrl`, `NaiveUtcClock`, `PersistedId`, `ConsoleOption`) have no hand-rolled counterpart in this plan's new code: no new test reloads an entity, seeds a user, reads an id or reads the clock, and no new `src` code trims whitespace or checks a URL.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **Wire:** only the change listed under "Wire changes". Everything else stays byte-identical.
- **Clean Code (CLAUDE.md) is mandatory.**
  - Names reveal intent.
  - No boolean flag parameters.
  - Three parameters at most, constructors aside.
  - Guard clauses over nesting.
  - `final readonly` by default. `final class` only where a memo needs a mutable field (`EffectiveGrafanaSettings`).
  - Controllers call only `get*`/`is*`/`has*`/`requireId()` on an entity. `$request->toUpdate()` is a DTO call, as in `AdminSettingsController`.
  - Queries live in `src/Repository`.
  - Domain code imports nothing from `App\Http` (`DomainKnowsNoHttpRule`).
  - Errors are typed exceptions.
- **Comments:** default none. At most three lines, and only where a future reader would otherwise get the code wrong. Every docblock this plan rewrites is trimmed to that bar. No `@param`/`@return` that repeats the signature, unless PHPStan needs the array shape.
- **Tests read persisted ids with `requireId()`** (`EntityIdCoercionRule` covers `tests/`). No test here reads an id.
- **Every touched `src` file is PHPMD-clean** under `composer md`. Fix the design, never the threshold.
- **PHPStan at level max:** no new baseline entry and no `@phpstan-ignore`.
- **PSR-12 line length:** 120 columns. If a perl substitution below pushes a line past 120, wrap that call's arguments one per line (`composer cs` reports it).
- **Gates for every task:**
  - the task's own tests,
  - `composer check` (cs + stan + tramp),
  - `composer md`,
  - PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`). ERROR and WARNING block.
- **Gates per PR (Finishing):**
  - `php bin/phpunit` (SQLite),
  - `docker compose exec php composer test` (MySQL),
  - `composer check`,
  - `composer md`,
  - `composer infection:diff`,
  - PhpStorm lint on all changed PHP.
  - No frontend file changes. If one does, add `docker compose exec -T frontend npm run check`.
- **Every new test gets a deletion check.** Break the production line it covers, run it and watch it fail, then restore the line by hand with the Edit tool (never `git checkout --`). Paste both outputs into the task report.
- **Commits:** `refactor(#1159): <lower-case summary>`, one per task, with no attribution lines. The plan copy is committed as `docs(#1159): …` (the #1164 precedent). No commit message or PR text in PR A may contain "close", "closes", "fix", "fixes", "resolve" or "resolves" next to `#1159`.
- **Branches, both cut from `origin/develop`:**
  - PR A: `refactor/1159-proxy-settings-split`.
  - PR B: `refactor/1159-grafana-and-mail-settings-split`, cut after PR A merges.
- **The checkout is shared.** Run `git status --short` and `git branch --show-current` before any `switch`, `reset` or `stash`. Another session may be mid-edit.

---

# PR A

### Task A0: Preflight

**Files:** none changed, except the plan copy.

- [ ] **Step 1: Confirm #1164, #1167 and #1168 have landed**

Run:
```bash
for n in 1164 1167 1168; do gh issue view $n --json state --jq .state; done
git fetch origin
git grep -n "final class FullReplacePayload" origin/develop -- backend/src/Http/FullReplacePayload.php
git grep -nE "public static function (proxy|grafana|mail)\(" origin/develop -- backend/tests/Support/SettingsRequests.php
git grep -n "serializationContext: FullReplacePayload::CONTEXT" origin/develop -- backend/src/Controller/Admin
git grep -n "private function mailBody" origin/develop -- backend/tests/Controller/Admin/AdminMailControllerTest.php
```
Expected:
- `CLOSED` three times.
- One `FullReplacePayload` hit.
- Three `SettingsRequests` factories.
- Four controllers: settings, proxy, Grafana and mail.
- One `mailBody`.

If any issue is still open, or any line is missing, stop and report. A1, A2, B2 and B5 build on #1167 A7b.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1159-proxy-settings-split origin/develop
```

- [ ] **Step 3: Commit the plan (from the repository root)**

```bash
cp /private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/d391b4d3-0e6b-4c11-9d20-34b69ad80840/scratchpad/plans/1159-draft.md docs/superpowers/plans/2026-09-26-1159-settings-admin-runtime-split.md
git add docs/superpowers/plans/2026-09-26-1159-settings-admin-runtime-split.md
git commit -m "docs(#1159): plan — split the admin settings services from the runtime configuration"
```

- [ ] **Step 4: Re-take the call sites this PR rewrites**

Run each command and compare it with the task that owns the result. A site the plan does not list gets the same rewrite as its siblings. Note it in the task report.
```bash
git grep -n "ProxyEgressResolver\|proxyEgressResolver" -- src tests config
git grep -n "->stored()\|configuredProxy()\|egressProxy()" -- src tests
git grep -n "ProxySettings::class\|new ProxySettings(" -- tests
git grep -nE "SettingsRequests::proxy\(" -- tests
```
Expected at `89c7e17f`:
- `ProxyEgressResolver`: 19 files. The three `src` consumers (`CatalogUrlChecker`, `ConcurrentFeedFetcher`, `FailoverRequestSender`), the class itself, and 15 test files. The test files are A3's perl list (13), `CheckCatalogUrlsCommandProxyTest` and `ProxyEgressResolverTest`.
- `->stored()`, `configuredProxy()`, `egressProxy()`: 18 lines.
  - `src`: `AdminProxyController` (2, A1), `ProxyEgressResolver` (1, deleted in A3), `MailSettings` (2: `overview()` and the proxy guard, A1), `ActiveMailTransportFactory` (1, A3), `ProxyConnectionTester` (2: its docblock and its call, A3), `ProxySettings` (the two declarations, A3).
  - `tests`: `ProxySettingsTest` (8, rewritten in A3).
- `ProxySettings::class` / `new ProxySettings(`: 14 lines in `ProxyEgressResolverTest` (2, deleted), `MailConnectionTesterTest` (1), `MailSettingsTest` (1), `ActiveMailTransportFactoryTest` (4, rewritten), `DynamicMailTransportTest` (1), `ProxyConnectionTesterTest` (3, rewritten) and `ProxySettingsTest` (2, rewritten). The three container `get()`s keep working: `ProxySettings` stays a service.
- `SettingsRequests::proxy(`, 22 calls in six files:
  - `ProxySettingsTest` (10),
  - `ProxyConnectionTesterTest` (2),
  - `MailSettingsTest` (1, `configureAProxy`),
  - `MailConnectionTesterTest` (1),
  - `DynamicMailTransportTest` (1),
  - `ProxySettingsRequestTest` (7).

---

### Task A1: Proxy read side: `ProxySettingsSnapshot`, `current()`

**Files:**
- Modify: `src/Entity/ProxyServerSettings.php` (add `connection()`)
- Create: `src/Service/Proxy/ProxySettingsSnapshot.php`
- Modify: `src/Service/Proxy/ProxySettings.php` (`stored()` becomes `current()`)
- Modify: `src/Http/Admin/ProxySettingsJson.php` (rewritten)
- Modify: `src/Controller/Admin/AdminProxyController.php` (two calls)
- Modify: `src/Service/Mail/Settings/MailSettings.php` (`overview()` and the proxy guard)
- Modify: `src/Service/Mail/Settings/MailSettingsOverview.php` (rewritten)
- Test: `tests/Entity/ProxyServerSettingsTest.php` (one test)
- Test: `tests/Service/Proxy/ProxySettingsSnapshotTest.php` (new)
- Test: `tests/Service/Proxy/ProxySettingsTest.php` (`viewOf()` and one test)
- Test: `tests/Http/Admin/MailSettingsJsonTest.php` (the proxy test)
- Test: `tests/Controller/Admin/AdminMailControllerTest.php` (one test)

**Interfaces:**
- Produces:
  - `ProxyServerSettings::connection(): ProxyConnection`.
  - `final readonly class App\Service\Proxy\ProxySettingsSnapshot { public ProxyConnection $connection; public bool $hasPassword; public static function fromEntity(ProxyServerSettings): self; public function isConfigured(): bool }`.
  - `ProxySettings::current(): ProxySettingsSnapshot`. With no row, this describes a fresh entity, exactly what `ProxySettingsJson` used to build for `null`. `stored()` is gone.
  - `ProxySettingsJson::from(ProxySettingsSnapshot $settings)`.
  - `MailSettingsOverview::$proxy` is `?ProxyConnection`, not `?ProxyConfig`. It is null when no proxy host is saved.
- The mail admin no longer decrypts the proxy password: `overview()` and the `useProxy` guard read `ProxySettings::current()`.

- [ ] **Step 1: Write the failing tests**

`tests/Entity/ProxyServerSettingsTest.php`, add after `testClearStoredPasswordDropsTheSecretButKeepsTheConnection()`:
```php
    public function testConnectionReadsBackEveryAppliedField(): void
    {
        $settings = new ProxyServerSettings();
        $connections = [
            new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', false),
            new ProxyConnection(false, true, ProxyType::Socks5, 'other.example', 1081, null, false),
            new ProxyConnection(false, false, ProxyType::Socks5, 'third.example', 1082, 'u3', true),
        ];

        foreach ($connections as $connection) {
            $settings->applyWithoutPassword($connection);

            self::assertEquals($connection, $settings->connection());
        }
    }
```
The three connections each set a different one of the three switches, so `connection()` cannot swap two of them unnoticed.

`tests/Service/Proxy/ProxySettingsSnapshotTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Service\Crypto\SealedSecret;
use App\Service\Proxy\ProxyConnection;
use App\Service\Proxy\ProxySettingsSnapshot;
use PHPUnit\Framework\TestCase;

final class ProxySettingsSnapshotTest extends TestCase
{
    public function testItCarriesTheRowsConnectionAndWhetherAPasswordIsStored(): void
    {
        $connection = new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', true);
        $row = new ProxyServerSettings();
        $row->apply($connection, new SealedSecret('cipher', 'nonce', 'salt', 1));

        $snapshot = ProxySettingsSnapshot::fromEntity($row);

        self::assertEquals($connection, $snapshot->connection);
        self::assertTrue($snapshot->hasPassword);
    }

    public function testAFreshRowIsNotConfiguredAndHoldsNoPassword(): void
    {
        $snapshot = ProxySettingsSnapshot::fromEntity(new ProxyServerSettings());

        self::assertFalse($snapshot->isConfigured());
        self::assertFalse($snapshot->hasPassword);
    }

    public function testARowWithAHostIsConfiguredWhetherOrNotItIsEnabled(): void
    {
        $snapshot = new ProxySettingsSnapshot(
            new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, null),
            false,
        );

        self::assertTrue($snapshot->isConfigured());
    }
}
```

`tests/Service/Proxy/ProxySettingsTest.php` (post-#1167):
- In `viewOf()`, replace `return ProxySettingsJson::from($settings->stored());` with `return ProxySettingsJson::from($settings->current());`.
- Add after `viewOf()`:
```php
    public function testWithNoRowTheViewDescribesAnUnconfiguredProxy(): void
    {
        self::assertSame([
            'enabled' => false,
            'directFallback' => true,
            'type' => 'SOCKS5',
            'host' => '',
            'port' => 1080,
            'username' => null,
            'remoteDns' => false,
            'hasPassword' => false,
        ], $this->viewOf($this->service($stored)));
    }
```

`tests/Http/Admin/MailSettingsJsonTest.php`:
- Replace `use App\Service\Fetch\ProxyConfig;` with `use App\Service\Proxy\ProxyConnection;`.
- In `testProxyAvailabilityIsExposedWhenAProxyIsConfigured()`, replace the `$proxy = new ProxyConfig(…);` line with:
```php
        $proxy = new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null, true);
```

`tests/Controller/Admin/AdminMailControllerTest.php` (post-#1167):
- Add `use App\Entity\ProxyServerSettings;` before `use App\Entity\User;`.
- Add these after `use App\Entity\User;`:
```php
use App\Enum\ProxyType;
use App\Service\Crypto\SealedSecret;
use App\Service\Proxy\ProxyConnection;
```
- Add before `testResetAsNonAdminIsForbidden()`:
```php
    public function testAnUnreadableProxyPasswordDoesNotBreakTheMailPage(): void
    {
        $proxy = new ProxyServerSettings();
        $proxy->apply(
            new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );
        $this->em()->persist($proxy);
        $this->em()->flush();
        $admin = $this->admin();

        $this->requestAs($admin, 'GET');

        self::assertResponseIsSuccessful();
        $body = $this->payload($this->client);
        self::assertTrue($body['proxyConfigured']);
        self::assertSame('SOCKS5 · proxy.example:1080', $body['proxyLabel']);

        $this->requestWithJsonBody(
            'PUT',
            $admin,
            $this->mailBody(['host' => 'smtp.example', 'useProxy' => true, 'password' => 'sw0rdfish']),
        );

        self::assertResponseIsSuccessful();
        self::assertTrue($this->payload($this->client)['useProxy']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Entity/ProxyServerSettingsTest.php tests/Service/Proxy tests/Http/Admin/MailSettingsJsonTest.php tests/Controller/Admin/AdminMailControllerTest.php`
Expected: FAIL.
- `connection()` and `current()` are undefined.
- `ProxySettingsSnapshot` does not exist.
- `MailSettingsJsonTest` hits a `TypeError`, because `MailSettingsOverview` still wants a `?ProxyConfig`.
- `testAnUnreadableProxyPasswordDoesNotBreakTheMailPage` gets a 500: the mail overview opens the proxy password.

- [ ] **Step 3: Implement**

`src/Entity/ProxyServerSettings.php`, add after `isRemoteDns()`:
```php
    public function connection(): ProxyConnection
    {
        return new ProxyConnection(
            $this->enabled,
            $this->directFallback,
            $this->type,
            $this->host,
            $this->port,
            $this->username,
            $this->remoteDns,
        );
    }
```
`ProxyConnection` is already imported there.

`src/Service/Proxy/ProxySettingsSnapshot.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;

final readonly class ProxySettingsSnapshot
{
    public function __construct(
        public ProxyConnection $connection,
        public bool $hasPassword,
    ) {
    }

    public static function fromEntity(ProxyServerSettings $settings): self
    {
        return new self($settings->connection(), $settings->hasPassword());
    }

    public function isConfigured(): bool
    {
        return '' !== $this->connection->host;
    }
}
```

`src/Service/Proxy/ProxySettings.php`, replace
```php
    public function stored(): ?ProxyServerSettings
    {
        return $this->repository->findSingleton();
    }
```
with
```php
    public function current(): ProxySettingsSnapshot
    {
        return ProxySettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new ProxyServerSettings());
    }
```

`src/Http/Admin/ProxySettingsJson.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Proxy\ProxySettingsSnapshot;

/**
 * The admin proxy payload. The password is absent by construction: only a
 * hasPassword flag crosses the wire, never the secret.
 */
final readonly class ProxySettingsJson
{
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
    public static function from(ProxySettingsSnapshot $settings): array
    {
        $connection = $settings->connection;

        return [
            'enabled' => $connection->enabled,
            'directFallback' => $connection->directFallback,
            'type' => $connection->type->value,
            'host' => $connection->host,
            'port' => $connection->port,
            'username' => $connection->username,
            'remoteDns' => $connection->remoteDns,
            'hasPassword' => $settings->hasPassword,
        ];
    }
}
```

`src/Controller/Admin/AdminProxyController.php`: replace both `ProxySettingsJson::from($this->settings->stored())` with `ProxySettingsJson::from($this->settings->current())` (Edit with `replace_all`).

`src/Service/Mail/Settings/MailSettingsOverview.php` (rewritten in full; B4 removes the entity):
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Service\Proxy\ProxyConnection;

final readonly class MailSettingsOverview
{
    public function __construct(
        /** @noinspection AutowireWrongClass Built with new, never autowired */
        public ?MailServerSettings $saved,
        public MailConnection $fallback,
        public ?ProxyConnection $proxy,
    ) {
    }
}
```

`src/Service/Mail/Settings/MailSettings.php`:
- Replace `overview()`:
```php
    public function overview(): MailSettingsOverview
    {
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverview(
            $this->repository->findSingleton(),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }
```
- In `guardAgainstProxyRoutingWithoutAProxy()`, replace
```php
        if ($request->useProxy && null === $this->proxySettings->configuredProxy()) {
```
with
```php
        if ($request->useProxy && !$this->proxySettings->current()->isConfigured()) {
```

`src/Http/Admin/MailSettingsJson.php` needs no edit. `ProxyConnection` has the same `type`, `host` and `port` properties the label reads.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command, then `php bin/phpunit tests/Controller/Admin tests/Service/Mail`.
Expected: PASS.

- [ ] **Step 5: Deletion checks**

Restore each by hand before the next.
1. In `ProxySettingsSnapshot::isConfigured()`, return `true`. Expected: `testAFreshRowIsNotConfiguredAndHoldsNoPassword` fails.
2. In `MailSettings::overview()`, replace `$proxy->isConfigured() ? $proxy->connection : null` with `null !== $this->proxySettings->configuredProxy() ? $proxy->connection : null`. That opens the password again. Expected: `testAnUnreadableProxyPasswordDoesNotBreakTheMailPage` answers 500.
3. In `ProxyServerSettings::connection()`, pass `$this->remoteDns` in place of `$this->directFallback`. Expected: `testConnectionReadsBackEveryAppliedField` fails.

- [ ] **Step 6: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on every changed file.
Expected: clean. `MailSettingsOverview` keeps its `AutowireWrongClass` suppression until B4, because it still holds the entity.

- [ ] **Step 7: Commit**

```bash
git add src/Entity/ProxyServerSettings.php src/Service/Proxy/ProxySettingsSnapshot.php src/Service/Proxy/ProxySettings.php src/Http/Admin/ProxySettingsJson.php src/Controller/Admin/AdminProxyController.php src/Service/Mail/Settings/MailSettings.php src/Service/Mail/Settings/MailSettingsOverview.php tests/Entity/ProxyServerSettingsTest.php tests/Service/Proxy/ProxySettingsSnapshotTest.php tests/Service/Proxy/ProxySettingsTest.php tests/Http/Admin/MailSettingsJsonTest.php tests/Controller/Admin/AdminMailControllerTest.php
git commit -m "refactor(#1159): the proxy admin reads a snapshot, and the mail page stops opening the proxy password"
```

---

### Task A2: `SecretChange` and `ProxySettingsUpdate`

**Files:**
- Create: `src/Service/Crypto/SecretChange.php`
- Create: `src/Service/Proxy/ProxySettingsUpdate.php`
- Modify: `src/Dto/Admin/ProxySettingsRequest.php` (rewritten: #1167 A7b's version plus `toUpdate()`)
- Modify: `src/Service/Proxy/ProxySettings.php` (rewritten)
- Modify: `src/Controller/Admin/AdminProxyController.php` (one line)
- Test: `tests/Service/Crypto/SecretChangeTest.php` (new)
- Test: `tests/Dto/Admin/ProxySettingsRequestTest.php` (five tests)
- Test (perl, `->toUpdate()` appended):
  - `tests/Service/Proxy/ProxySettingsTest.php`,
  - `tests/Service/Proxy/ProxyConnectionTesterTest.php`,
  - `tests/Service/Mail/Settings/MailSettingsTest.php`,
  - `tests/Service/Mail/Settings/MailConnectionTesterTest.php`,
  - `tests/Service/Mail/Transport/DynamicMailTransportTest.php`.

**Interfaces:**
- Produces:
  - `final readonly class App\Service\Crypto\SecretChange { static keep(): self; static replaceWith(string $secret): self; static remove(): self; replacement(): ?string; isRemoval(): bool }`. B2 and B5 consume it.
  - `final readonly class App\Service\Proxy\ProxySettingsUpdate { public ProxyConnection $connection; public SecretChange $password }`.
  - `ProxySettingsRequest::toUpdate(): ProxySettingsUpdate`.
  - `ProxySettings::update(ProxySettingsUpdate $update): void`. `connectionFrom()` and the DTO import leave the service.
- The mapping is unchanged:
  - `removePassword` wins over a sent password.
  - `null` keeps the stored secret.
  - Any string, `''` included, replaces it.
  - A blank username becomes `null`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Crypto/SecretChangeTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\SecretChange;
use PHPUnit\Framework\TestCase;

final class SecretChangeTest extends TestCase
{
    public function testKeepNeitherReplacesNorRemoves(): void
    {
        $change = SecretChange::keep();

        self::assertNull($change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testReplaceWithCarriesTheNewSecret(): void
    {
        $change = SecretChange::replaceWith('sw0rdfish');

        self::assertSame('sw0rdfish', $change->replacement());
        self::assertFalse($change->isRemoval());
    }

    public function testRemoveCarriesNoSecret(): void
    {
        $change = SecretChange::remove();

        self::assertNull($change->replacement());
        self::assertTrue($change->isRemoval());
    }
}
```

`tests/Dto/Admin/ProxySettingsRequestTest.php` (post-#1167):
- Add after `use App\Dto\Admin\ProxySettingsRequest;`:
```php
use App\Enum\ProxyType;
use App\Service\Crypto\SecretChange;
use App\Service\Proxy\ProxyConnection;
```
- Add before the class's closing `}`:
```php
    public function testToUpdateCarriesTheConnection(): void
    {
        $update = SettingsRequests::proxy(
            enabled: true,
            directFallback: false,
            type: 'HTTP',
            host: 'proxy.example',
            port: 3128,
            username: 'user',
            remoteDns: true,
        )->toUpdate();

        self::assertEquals(
            new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', true),
            $update->connection,
        );
    }

    public function testToUpdateTurnsABlankUsernameIntoNone(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', username: '')->toUpdate();

        self::assertNull($update->connection->username);
    }

    public function testNoPasswordKeepsTheStoredOne(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example')->toUpdate();

        self::assertEquals(SecretChange::keep(), $update->password);
    }

    public function testAPasswordReplacesTheStoredOne(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', password: 'sw0rdfish')->toUpdate();

        self::assertEquals(SecretChange::replaceWith('sw0rdfish'), $update->password);
    }

    public function testRemovePasswordWinsOverASentPassword(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', password: 'sw0rdfish', removePassword: true)
            ->toUpdate();

        self::assertEquals(SecretChange::remove(), $update->password);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Crypto/SecretChangeTest.php tests/Dto/Admin/ProxySettingsRequestTest.php`
Expected: FAIL. `SecretChange` does not exist, and `toUpdate()` is undefined.

- [ ] **Step 3: Implement**

`src/Service/Crypto/SecretChange.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Crypto;

final readonly class SecretChange
{
    private function __construct(
        private ?string $replacement,
        private bool $removal,
    ) {
    }

    public static function keep(): self
    {
        return new self(null, false);
    }

    public static function replaceWith(string $secret): self
    {
        return new self($secret, false);
    }

    public static function remove(): self
    {
        return new self(null, true);
    }

    public function replacement(): ?string
    {
        return $this->replacement;
    }

    public function isRemoval(): bool
    {
        return $this->removal;
    }
}
```

`src/Service/Proxy/ProxySettingsUpdate.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Service\Crypto\SecretChange;

final readonly class ProxySettingsUpdate
{
    public function __construct(
        public ProxyConnection $connection,
        public SecretChange $password,
    ) {
    }
}
```

`src/Dto/Admin/ProxySettingsRequest.php` (rewritten in full; the constructor is #1167 A7b's, unchanged):
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\ProxyType;
use App\Http\FullReplacePayload;
use App\Service\Crypto\SecretChange;
use App\Service\Proxy\ProxyConnection;
use App\Service\Proxy\ProxySettingsUpdate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every connection setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without
 * which a missing nullable setting reads as null. The password is an optional three-state intent: null keeps the
 * stored secret, a string replaces it, `removePassword` clears it.
 */
final readonly class ProxySettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled,
        #[Assert\Type('bool')]
        public bool $directFallback,
        #[Assert\Choice(choices: [ProxyType::Socks5->value, ProxyType::Http->value])]
        public string $type,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $host,
        #[Assert\Range(min: 1, max: 65535)]
        public int $port,
        #[Assert\Length(max: 255)]
        public ?string $username,
        #[Assert\Type('bool')]
        public bool $remoteDns,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }

    public function toUpdate(): ProxySettingsUpdate
    {
        return new ProxySettingsUpdate(
            new ProxyConnection(
                $this->enabled,
                $this->directFallback,
                ProxyType::from($this->type),
                $this->host,
                $this->port,
                '' === $this->username ? null : $this->username,
                $this->remoteDns,
            ),
            $this->passwordChange(),
        );
    }

    private function passwordChange(): SecretChange
    {
        if ($this->removePassword) {
            return SecretChange::remove();
        }

        return null === $this->password ? SecretChange::keep() : SecretChange::replaceWith($this->password);
    }
}
```
The constructor, the class docblock and the `App\Http\FullReplacePayload` import (the docblock's `{@see}` target) are #1167 A7b's as landed at `89c7e17f`; only the three new imports and the two methods are this task's.

`src/Service/Proxy/ProxySettings.php` (rewritten in full; A3 removes the runtime half):
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;

readonly class ProxySettings
{
    public function __construct(
        private ProxyServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function current(): ProxySettingsSnapshot
    {
        return ProxySettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new ProxyServerSettings());
    }

    public function update(ProxySettingsUpdate $update): void
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings) {
            $settings = new ProxyServerSettings();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
    }

    /** The stored connection regardless of the enable switch — the tester probes this. */
    public function configuredProxy(): ?ProxyConfig
    {
        return $this->proxyFrom($this->repository->findSingleton());
    }

    /** The connection only when it is turned on — the fetch paths resolve this. */
    public function egressProxy(): ?ProxyConfig
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->isEnabled() ? $this->proxyFrom($settings) : null;
    }

    private function apply(ProxySettingsUpdate $update, ProxyServerSettings $settings): void
    {
        $replacement = $update->password->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement));

            return;
        }

        $settings->applyWithoutPassword($update->connection);
        if ($update->password->isRemoval()) {
            $settings->clearStoredPassword();
        }
    }

    private function proxyFrom(?ProxyServerSettings $settings): ?ProxyConfig
    {
        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ProxyConfig(
            $settings->getType(),
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->isDirectFallback(),
            $settings->isRemoteDns(),
        );
    }
}
```
The class stays non-final `readonly` until A3, because `ProxyEgressResolverTest` and `ActiveMailTransportFactoryTest` still stub it. The class docblock goes: after A3 its claim ("the rest of the app depends on this, never on the repository") is false.

`src/Controller/Admin/AdminProxyController.php`: replace `$this->settings->update($request);` with `$this->settings->update($request->toUpdate());`.

- [ ] **Step 4: Move the service tests to the update value**

Every `ProxySettings::update()` call in a test now receives the update value. Append `->toUpdate()` to each balanced `SettingsRequests::proxy(…)` call, in these five files only. The DTO test keeps building DTOs.
```bash
perl -0pi -e 's/(SettingsRequests::proxy(\((?:[^()]++|(?2))*\)))/$1->toUpdate()/g' tests/Service/Proxy/ProxySettingsTest.php tests/Service/Proxy/ProxyConnectionTesterTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php
perl -0ne 'while (/(SettingsRequests::proxy(\((?:[^()]++|(?2))*\)))(?!->toUpdate\(\))/g) { print "$ARGV: $1\n" }' tests/Service/Proxy/ProxySettingsTest.php tests/Service/Proxy/ProxyConnectionTesterTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php
```
Expected: the second command prints nothing. `SettingsRequests::mail(…)` calls are untouched: `MailSettings::update()` takes the DTO until B5.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Crypto tests/Dto/Admin tests/Service/Proxy tests/Service/Mail tests/Controller/Admin/AdminProxyControllerTest.php tests/Controller/Admin/AdminMailControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Deletion checks**

Restore each by hand before the next.
1. In `ProxySettingsRequest::passwordChange()`, delete the `if ($this->removePassword) { … }` guard. Expected: `testRemovePasswordWinsOverASentPassword` fails, and so does `ProxySettingsTest::testRemovePasswordClearsTheStoredSecret`.
2. In `ProxySettings::apply()`, delete the `if ($update->password->isRemoval()) { … }` block. Expected: `ProxySettingsTest::testRemovePasswordClearsTheStoredSecret` and `AdminProxyControllerTest::testUpdateRemovesTheStoredPasswordWhenRemovePasswordIsSet` fail.
3. In `toUpdate()`, pass `$this->username` unchanged. Expected: `testToUpdateTurnsABlankUsernameIntoNone` fails.

- [ ] **Step 7: Gates**

Run `composer check`, `composer md` and PhpStorm `lint_files` on the changed files.
Expected: clean. `composer stan` confirms that every `ProxySettings::update()` call now passes a `ProxySettingsUpdate`.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Crypto/SecretChange.php src/Service/Proxy/ProxySettingsUpdate.php src/Dto/Admin/ProxySettingsRequest.php src/Service/Proxy/ProxySettings.php src/Controller/Admin/AdminProxyController.php tests/Service/Crypto/SecretChangeTest.php tests/Dto/Admin/ProxySettingsRequestTest.php tests/Service/Proxy/ProxySettingsTest.php tests/Service/Proxy/ProxyConnectionTesterTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php
git commit -m "refactor(#1159): the proxy admin takes an update value the request builds"
```

---

### Task A3: `EgressProxySource` / `StoredProxy`; `ProxyEgressResolver` goes

**Files:**
- Create: `src/Service/Fetch/EgressProxySource.php`
- Create: `src/Service/Proxy/StoredProxy.php`
- Create: `src/Service/Proxy/ConfiguredProxySource.php`
- Delete: `src/Service/Fetch/ProxyEgressResolver.php`, `tests/Service/Fetch/ProxyEgressResolverTest.php`
- Modify: `src/Service/Proxy/ProxySettings.php` (rewritten: admin only, `final readonly`)
- Modify: `src/Service/Proxy/ProxyConnectionTester.php` (constructor, one call)
- Modify: `src/Service/Mail/Transport/ActiveMailTransportFactory.php` (import, constructor, one call)
- Modify (perl): `src/Service/Catalog/CatalogUrlChecker.php`, `src/Service/Fetch/ConcurrentFeedFetcher.php`, `src/Service/Fetch/FailoverRequestSender.php`
- Modify: `src/Repository/ProxyServerSettingsRepository.php` (docblock)
- Modify: `config/services.yaml` (two aliases)
- Test: `tests/Service/Proxy/StoredProxyTest.php` (new)
- Test (each rewritten in full): `tests/Service/Proxy/ProxySettingsTest.php`, `tests/Service/Proxy/ProxyConnectionTesterTest.php`, `tests/Service/Mail/Transport/ActiveMailTransportFactoryTest.php`, `tests/Command/CheckCatalogUrlsCommandProxyTest.php`
- Test (perl): the 13 files in Step 5

**Interfaces:**
- Consumes: `ProxySettingsSnapshot`, `ProxySettingsUpdate`, `SecretChange` (A1, A2).
- Produces:
  - `interface App\Service\Fetch\EgressProxySource { public function egressProxy(): ?ProxyConfig; }`, owned by the fetch code. `config/services.yaml` aliases it to `StoredProxy`.
  - `interface App\Service\Proxy\ConfiguredProxySource { public function configuredProxy(): ?ProxyConfig; }`: the saved proxy whether or not feed egress is on. `config/services.yaml` aliases it to `StoredProxy`. It lives on the Proxy side so that mail can read it without a Proxy → Mail edge (D2).
  - `final readonly class App\Service\Proxy\StoredProxy implements EgressProxySource, ConfiguredProxySource`: `__construct(ProxyServerSettingsRepository $repository, ProxyPasswordCipher $cipher)`, `configuredProxy(): ?ProxyConfig`, `egressProxy(): ?ProxyConfig`. The bodies move unchanged from `ProxySettings`.
  - `final readonly class ProxySettings`: `current()` and `update()` only.
  - `ProxyConnectionTester::__construct(ConfiguredProxySource $proxySource, HttpClientInterface $httpClient)`.
  - `ActiveMailTransportFactory::__construct(ConfiguredProxySource $proxySource, HttpClientInterface $httpClient)`.
  - `CatalogUrlChecker`, `ConcurrentFeedFetcher` and `FailoverRequestSender` take `EgressProxySource $egressProxySource`, in the same constructor position, and call `egressProxy()`.
- After this task nothing under `src/Service/Fetch` imports `App\Service\Proxy`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Proxy/StoredProxyTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Crypto\SealedSecret;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\ProxyConnection;
use App\Service\Proxy\StoredProxy;
use PHPUnit\Framework\TestCase;

final class StoredProxyTest extends TestCase
{
    private const string SECRET = 'test-master-secret-at-least-32-chars-long!!';

    public function testNothingIsConfiguredWithoutARow(): void
    {
        $storedProxy = $this->storedProxy(null);

        self::assertNull($storedProxy->configuredProxy());
        self::assertNull($storedProxy->egressProxy());
    }

    public function testARowWithoutAHostIsNotAProxy(): void
    {
        $row = new ProxyServerSettings();
        $row->applyWithoutPassword(new ProxyConnection(true, true, ProxyType::Socks5, '', 1080, null));

        self::assertNull($this->storedProxy($row)->egressProxy());
    }

    public function testTheConfiguredProxyCarriesEveryFieldAndTheOpenedPassword(): void
    {
        $row = $this->row(
            new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', true),
            'sw0rdfish',
        );

        $proxy = $this->storedProxy($row)->configuredProxy();

        self::assertNotNull($proxy);
        self::assertSame(ProxyType::Http, $proxy->type);
        self::assertSame('proxy.example', $proxy->host);
        self::assertSame(3128, $proxy->port);
        self::assertSame('user', $proxy->username);
        self::assertSame('sw0rdfish', $proxy->password);
        self::assertFalse($proxy->directFallback);
        self::assertTrue($proxy->remoteDns);
    }

    public function testARowWithoutAPasswordGivesAProxyWithoutOne(): void
    {
        $row = new ProxyServerSettings();
        $row->applyWithoutPassword(new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null));

        $proxy = $this->storedProxy($row)->configuredProxy();

        self::assertNotNull($proxy);
        self::assertNull($proxy->password);
    }

    public function testADisabledProxyIsConfiguredButNotUsedForEgress(): void
    {
        $row = $this->row(new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, null), 'pw');
        $storedProxy = $this->storedProxy($row);

        self::assertNull($storedProxy->egressProxy());
        self::assertNotNull($storedProxy->configuredProxy());
    }

    public function testAnEnabledProxyIsUsedForEgressWithLocalDnsByDefault(): void
    {
        $row = $this->row(new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null), 'pw');

        self::assertSame('socks5://proxy.example:1080', $this->storedProxy($row)->egressProxy()?->dsn());
    }

    public function testRemoteDnsReachesTheEgressScheme(): void
    {
        $row = $this->row(
            new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null, true),
            'pw',
        );

        self::assertSame('socks5h://proxy.example:1080', $this->storedProxy($row)->egressProxy()?->dsn());
    }

    public function testAPasswordThatCannotBeOpenedIsReportedAsUnreadable(): void
    {
        $row = new ProxyServerSettings();
        $row->apply(
            new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );

        $this->expectException(SecretUnreadableException::class);

        $this->storedProxy($row)->egressProxy();
    }

    private function row(ProxyConnection $connection, string $password): ProxyServerSettings
    {
        $row = new ProxyServerSettings();
        $row->apply($connection, $this->cipher()->seal($password));

        return $row;
    }

    private function storedProxy(?ProxyServerSettings $row): StoredProxy
    {
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        return new StoredProxy($repository, $this->cipher());
    }

    private function cipher(): ProxyPasswordCipher
    {
        return new ProxyPasswordCipher(new InstanceSecretCipher(self::SECRET));
    }
}
```

`tests/Service/Proxy/ProxySettingsTest.php` (rewritten in full; the runtime cases moved to `StoredProxyTest`):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Http\Admin\ProxySettingsJson;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\ProxySettings;
use App\Tests\Support\SettingsRequests;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProxySettingsTest extends TestCase
{
    private const string SECRET = 'test-master-secret-at-least-32-chars-long!!';

    private ?ProxyServerSettings $stored = null;

    /**
     * @return array{
     *     enabled: bool, directFallback: bool, type: string, host: string, port: int,
     *     username: string|null, remoteDns: bool, hasPassword: bool,
     * }
     */
    private function viewOf(ProxySettings $settings): array
    {
        return ProxySettingsJson::from($settings->current());
    }

    public function testWithNoRowTheViewDescribesAnUnconfiguredProxy(): void
    {
        self::assertSame([
            'enabled' => false,
            'directFallback' => true,
            'type' => 'SOCKS5',
            'host' => '',
            'port' => 1080,
            'username' => null,
            'remoteDns' => false,
            'hasPassword' => false,
        ], $this->viewOf($this->settings()));
    }

    public function testUpdateThenViewHidesTheSecretButFlagsThatOneIsStored(): void
    {
        $settings = $this->settings();

        $settings->update(SettingsRequests::proxy(
            enabled: true,
            directFallback: true,
            type: 'SOCKS5',
            host: 'proxy.example',
            port: 1080,
            username: 'user',
            password: 'sw0rdfish',
        )->toUpdate());

        $view = $this->viewOf($settings);
        self::assertTrue($view['enabled']);
        self::assertTrue($view['directFallback']);
        self::assertSame('SOCKS5', $view['type']);
        self::assertSame('proxy.example', $view['host']);
        self::assertSame(1080, $view['port']);
        self::assertSame('user', $view['username']);
        self::assertTrue($view['hasPassword']);
        self::assertArrayNotHasKey('passwordHint', $view);
        self::assertArrayNotHasKey('password', $view);
    }

    public function testThePasswordIsStoredSealed(): void
    {
        $settings = $this->settings();

        $settings->update(SettingsRequests::proxy(host: 'proxy.example', password: 'sw0rdfish')->toUpdate());

        $stored = $this->stored;
        self::assertNotNull($stored);
        self::assertNotSame('sw0rdfish', $stored->getSealedPassword()->ciphertext);
        self::assertSame('sw0rdfish', $this->cipher()->open($stored->getSealedPassword()));
    }

    public function testANullPasswordKeepsTheStoredSecretWhileTheConnectionChanges(): void
    {
        $settings = $this->settings();
        $settings->update(
            SettingsRequests::proxy(enabled: true, host: 'a', port: 1, password: 'sw0rdfish')->toUpdate(),
        );

        $settings->update(
            SettingsRequests::proxy(directFallback: false, type: 'HTTP', host: 'b', port: 2)->toUpdate(),
        );

        $stored = $this->stored;
        self::assertNotNull($stored);
        self::assertSame('sw0rdfish', $this->cipher()->open($stored->getSealedPassword()));
        $view = $this->viewOf($settings);
        self::assertFalse($view['enabled']);
        self::assertFalse($view['directFallback']);
        self::assertSame('HTTP', $view['type']);
        self::assertSame('b', $view['host']);
        self::assertSame(2, $view['port']);
    }

    public function testRemovePasswordClearsTheStoredSecretAndStillAppliesTheConnection(): void
    {
        $settings = $this->settings();
        $settings->update(
            SettingsRequests::proxy(host: 'proxy.example', username: 'user', password: 'sw0rdfish')->toUpdate(),
        );
        self::assertTrue($this->viewOf($settings)['hasPassword']);

        $settings->update(
            SettingsRequests::proxy(host: 'other.example', username: 'user', removePassword: true)->toUpdate(),
        );

        $view = $this->viewOf($settings);
        self::assertFalse($view['hasPassword']);
        self::assertSame('other.example', $view['host']);
    }

    public function testRemoteDnsIsStoredAndShown(): void
    {
        $settings = $this->settings();

        $settings->update(SettingsRequests::proxy(host: 'proxy.example', remoteDns: true)->toUpdate());

        self::assertTrue($this->viewOf($settings)['remoteDns']);
    }

    public function testUpdateFlushesTheEntityManager(): void
    {
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(new ProxyServerSettings());

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        (new ProxySettings($repository, $em, $this->cipher()))
            ->update(SettingsRequests::proxy(host: 'proxy.example', password: 'pw123456')->toUpdate());
    }

    private function settings(): ProxySettings
    {
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturnCallback(fn (): ?ProxyServerSettings => $this->stored);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof ProxyServerSettings) {
                $this->stored = $entity;
            }
        });

        return new ProxySettings($repository, $em, $this->cipher());
    }

    private function cipher(): ProxyPasswordCipher
    {
        return new ProxyPasswordCipher(new InstanceSecretCipher(self::SECRET));
    }
}
```

`tests/Service/Proxy/ProxyConnectionTesterTest.php` (rewritten in full). The test bodies are unchanged. The tester now takes a `StoredProxy` over a stubbed repository. The stored row is **disabled**, which pins that the tester probes the saved proxy whether or not it is switched on.
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use App\Service\Proxy\ProxyConnection;
use App\Service\Proxy\ProxyConnectionTester;
use App\Service\Proxy\ProxyTestFailure;
use App\Service\Proxy\StoredProxy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProxyConnectionTesterTest extends TestCase
{
    private const string SECRET = 'test-master-secret-at-least-32-chars-long!!';
    private const string ROTATED_SECRET = 'a-DIFFERENT-master-secret-at-least-32-chars!';

    public function testReturnsEgressIpOnSuccessAndRoutesThroughTheProxy(): void
    {
        $seenProxy = null;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$seenProxy): MockResponse {
                $seenProxy = $options['proxy'] ?? null;

                return new MockResponse('203.0.113.7');
            }
        );
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
        self::assertSame('socks5://user:pw@proxy.example:1080', $seenProxy);
    }

    public function testReturnsNotConfiguredWhenNoProxyStored(): void
    {
        $tester = new ProxyConnectionTester($this->storedProxy(null, self::SECRET), new MockHttpClient());

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::NotConfigured, $result->failure);
    }

    public function testMapsTransportFailureToAReason(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'Failed to connect via proxy']);
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::Unreachable, $result->failure);
        self::assertNotNull($result->detail);
    }

    public function testRequestDisablesRedirectsAndAsksForPlainText(): void
    {
        $seenOptions = null;
        $client = new MockHttpClient(
            function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
                $seenOptions = $options;

                return new MockResponse('203.0.113.7');
            }
        );
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $tester->test();

        self::assertIsArray($seenOptions);
        self::assertSame(0, $seenOptions['max_redirects'] ?? null);
        $headers = $seenOptions['headers'] ?? [];
        self::assertIsArray($headers);
        self::assertContains('Accept: text/plain', $headers);
    }

    public function testHttpStatusOutsideTheSuccessRangeIsReportedByCode(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('nope', ['http_code' => 404]);
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);
        self::assertSame('HTTP 404', $result->detail);
    }

    public function testAStatusOfExactlyThreeHundredIsAlreadyAFailure(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('nope', ['http_code' => 300]);
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::UnexpectedStatus, $result->failure);
        self::assertSame('HTTP 300', $result->detail);
    }

    public function testEgressIpIsTruncatedToTheByteCapBeforeTrimming(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(str_repeat('9', 2000) . "\n");
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame(str_repeat('9', 1024), $result->egressIp);
    }

    public function testEgressIpHasSurroundingWhitespaceTrimmed(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(" 203.0.113.7 \n");
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertTrue($result->ok);
        self::assertSame('203.0.113.7', $result->egressIp);
    }

    /**
     * The row was sealed under one master secret and is read under another (a rotated INSTANCE_SECRET_KEY, or a
     * dump restored onto a fresh instance). Diagnosing that is what the Test button is for, so it must not throw.
     */
    public function testAnUnreadableStoredPasswordIsReportedRatherThanThrown(): void
    {
        $afterRotation = $this->storedProxy($this->configuredRow(), self::ROTATED_SECRET);

        $result = (new ProxyConnectionTester($afterRotation, new MockHttpClient()))->test();

        self::assertFalse($result->ok);
        self::assertNull($result->egressIp);
        self::assertSame(ProxyTestFailure::SecretUnreadable, $result->failure);
        self::assertNotNull($result->detail);
    }

    /**
     * The reported defect: the page showed curl's raw RFC 1928 reply byte, so
     * the admin saw "(4)" where a reason belonged.
     */
    public function testASocks5HandshakeRefusalIsExplainedNotJustNumbered(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', [
                'error' => 'cannot complete SOCKS5 connection to api.ipify.org. (4)',
            ]);
        });
        $tester = new ProxyConnectionTester($this->configuredProxy(), $client);

        $result = $tester->test();

        self::assertFalse($result->ok);
        self::assertSame(ProxyTestFailure::Unreachable, $result->failure);
        self::assertStringContainsString('does not resolve host names', (string) $result->detail);
    }

    private function configuredProxy(): StoredProxy
    {
        return $this->storedProxy($this->configuredRow(), self::SECRET);
    }

    private function configuredRow(): ProxyServerSettings
    {
        $row = new ProxyServerSettings();
        $row->apply(
            new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            (new ProxyPasswordCipher(new InstanceSecretCipher(self::SECRET)))->seal('pw'),
        );

        return $row;
    }

    private function storedProxy(?ProxyServerSettings $row, string $secret): StoredProxy
    {
        $repository = $this->createStub(ProxyServerSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        return new StoredProxy($repository, new ProxyPasswordCipher(new InstanceSecretCipher($secret)));
    }
}
```

`tests/Service/Mail/Transport/ActiveMailTransportFactoryTest.php` (rewritten in full). It stubs `ConfiguredProxySource`. That the source hands out the saved proxy whether or not feed egress is on is pinned by `StoredProxyTest::testADisabledProxyIsConfiguredButNotUsedForEgress`.
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Transport;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Service\Fetch\ProxyConfig;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\ResolvedMailTransport;
use App\Service\Mail\Transport\ActiveMailTransportFactory;
use App\Service\Mail\Transport\CurlSmtpTransport;
use App\Service\Proxy\ConfiguredProxySource;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ActiveMailTransportFactoryTest extends TestCase
{
    public function testDirectResolvedGivesAnEsmtpTransport(): void
    {
        $factory = $this->factory(null);
        $resolved = new ResolvedMailTransport('h', 587, 'u', 'p', MailEncryption::Starttls, false);

        self::assertInstanceOf(EsmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedGivesACurlTransport(): void
    {
        $factory = $this->factory(new ProxyConfig(ProxyType::Socks5, 'proxy.example', 1080, null, null, true, true));
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        self::assertInstanceOf(CurlSmtpTransport::class, $factory->forResolved($resolved, null, new NullLogger()));
    }

    public function testProxiedResolvedWithNoProxyThrows(): void
    {
        $factory = $this->factory(null);
        $resolved = new ResolvedMailTransport('smtp.gmail.com', 587, 'u', 'p', MailEncryption::Starttls, true);

        $this->expectException(IncompleteMailConfigurationException::class);
        $factory->forResolved($resolved, null, new NullLogger());
    }

    public function testFallbackDsnBuildsTheTransportForThatDsn(): void
    {
        self::assertInstanceOf(
            NullTransport::class,
            $this->factory(null)->forFallbackDsn('null://null', null, new NullLogger()),
        );
    }

    private function factory(?ProxyConfig $configuredProxy): ActiveMailTransportFactory
    {
        $proxySource = $this->createStub(ConfiguredProxySource::class);
        $proxySource->method('configuredProxy')->willReturn($configuredProxy);

        return new ActiveMailTransportFactory($proxySource, $this->createStub(HttpClientInterface::class));
    }
}
```

`tests/Command/CheckCatalogUrlsCommandProxyTest.php` (rewritten in full). It now saves a real, enabled proxy row instead of swapping a stub into the container. The command reaches `StoredProxy` through the `EgressProxySource` alias, so the wiring is under test too. DAMA rolls the row back after each test.
```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ProxyServerSettings;
use App\Enum\ProxyType;
use App\Service\Proxy\ProxyConnection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CheckCatalogUrlsCommandProxyTest extends KernelTestCase
{
    private const string FEED_BODY
        = '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title></channel></rss>';

    /**
     * @param array<int, array<string, mixed>> $recordedOptions
     */
    private function tester(array &$recordedOptions): CommandTester
    {
        self::bootKernel();

        $client = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$recordedOptions): MockResponse {
                $recordedOptions[] = $options;

                return new MockResponse(self::FEED_BODY, [
                    'response_headers' => ['content-type' => ['application/rss+xml']],
                ]);
            },
        );

        self::getContainer()->set('catalog.rot_check.http_client', $client);

        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:catalog:check-urls'));
    }

    private function enableAnEgressProxy(): void
    {
        $proxy = new ProxyServerSettings();
        $proxy->applyWithoutPassword(new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($proxy);
        $entityManager->flush();
    }

    public function testRequestCarriesTheProxyOptionWhenAnEgressProxyIsEnabled(): void
    {
        $recordedOptions = [];
        $tester = $this->tester($recordedOptions);
        $this->enableAnEgressProxy();

        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotEmpty($recordedOptions);
        self::assertArrayHasKey('proxy', $recordedOptions[0]);
        self::assertSame('socks5://proxy.example:1080', $recordedOptions[0]['proxy']);
    }

    public function testRequestCarriesNoProxyOptionWithoutAnEgressProxy(): void
    {
        $recordedOptions = [];
        $tester = $this->tester($recordedOptions);

        $tester->execute(['--limit' => '1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNotEmpty($recordedOptions);
        self::assertArrayNotHasKey('proxy', $recordedOptions[0]);
        self::assertSame(20.0, $recordedOptions[0]['timeout'] ?? null);
    }
}
```
`self::getContainer()->get(EntityManagerInterface::class)` needs no `assertInstanceOf()` here: at `89c7e17f`, `DynamicMailTransportTest` (a `KernelTestCase` too) persists through the same call with no assertion and passes `composer stan`: `phpstan.dist.neon` points the Symfony extension at the dev container XML, which types the lookup.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Proxy tests/Service/Mail/Transport/ActiveMailTransportFactoryTest.php tests/Command/CheckCatalogUrlsCommandProxyTest.php`
Expected: FAIL.
- `StoredProxy` does not exist.
- `ConfiguredProxySource` does not exist, and `ProxyConnectionTester` still wants a `ProxySettings`, so a `TypeError` is thrown.
- `CheckCatalogUrlsCommandProxyTest` passes already. It is a wiring net, and Step 7 checks it bites.

- [ ] **Step 3: The runtime reader and its two interfaces**

`src/Service/Fetch/EgressProxySource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;

interface EgressProxySource
{
    /** @throws SecretUnreadableException when the enabled proxy's stored password cannot be opened */
    public function egressProxy(): ?ProxyConfig;
}
```

`src/Service/Proxy/ConfiguredProxySource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\ProxyConfig;

interface ConfiguredProxySource
{
    /**
     * The saved proxy whether or not feed egress is switched on: the tester and proxied mail read this.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredProxy(): ?ProxyConfig;
}
```

`src/Service/Proxy/StoredProxy.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Fetch\EgressProxySource;
use App\Service\Fetch\ProxyConfig;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;

final readonly class StoredProxy implements EgressProxySource, ConfiguredProxySource
{
    public function __construct(
        private ProxyServerSettingsRepository $repository,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function configuredProxy(): ?ProxyConfig
    {
        return $this->proxyFrom($this->repository->findSingleton());
    }

    public function egressProxy(): ?ProxyConfig
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings && $settings->isEnabled() ? $this->proxyFrom($settings) : null;
    }

    private function proxyFrom(?ProxyServerSettings $settings): ?ProxyConfig
    {
        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ProxyConfig(
            $settings->getType(),
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->isDirectFallback(),
            $settings->isRemoteDns(),
        );
    }
}
```

`config/services.yaml`, add after `App\Service\Fetch\BatchFeedFetcherInterface: '@App\Service\Fetch\ConcurrentFeedFetcher'`:
```yaml
    App\Service\Fetch\EgressProxySource: '@App\Service\Proxy\StoredProxy'
    App\Service\Proxy\ConfiguredProxySource: '@App\Service\Proxy\StoredProxy'
```

- [ ] **Step 4: The admin service loses its runtime half; the consumers switch**

`src/Service/Proxy/ProxySettings.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Proxy;

use App\Entity\ProxyServerSettings;
use App\Repository\ProxyServerSettingsRepository;
use App\Service\Proxy\Crypto\ProxyPasswordCipher;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ProxySettings
{
    public function __construct(
        private ProxyServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private ProxyPasswordCipher $cipher,
    ) {
    }

    public function current(): ProxySettingsSnapshot
    {
        return ProxySettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new ProxyServerSettings());
    }

    public function update(ProxySettingsUpdate $update): void
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings) {
            $settings = new ProxyServerSettings();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
    }

    private function apply(ProxySettingsUpdate $update, ProxyServerSettings $settings): void
    {
        $replacement = $update->password->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement));

            return;
        }

        $settings->applyWithoutPassword($update->connection);
        if ($update->password->isRemoval()) {
            $settings->clearStoredPassword();
        }
    }
}
```

`src/Service/Proxy/ProxyConnectionTester.php`:
- Replace `private ProxySettings $settings,` with `private ConfiguredProxySource $proxySource,`.
- Replace `$proxy = $this->settings->configuredProxy();` with `$proxy = $this->proxySource->configuredProxy();`.

`src/Service/Mail/Transport/ActiveMailTransportFactory.php`:
- Replace `use App\Service\Proxy\ProxySettings;` with `use App\Service\Proxy\ConfiguredProxySource;`.
- Replace `private ProxySettings $proxySettings,` with `private ConfiguredProxySource $proxySource,`.
- Replace `$proxy = $this->proxySettings->configuredProxy();` with `$proxy = $this->proxySource->configuredProxy();`.

The three fetch-side consumers:
```bash
perl -pi -e 's/\bProxyEgressResolver\b/EgressProxySource/g; s/\$proxyEgressResolver\b/\$egressProxySource/g; s/->proxyEgressResolver->resolve\(\)/->egressProxySource->egressProxy()/g' src/Service/Catalog/CatalogUrlChecker.php src/Service/Fetch/ConcurrentFeedFetcher.php src/Service/Fetch/FailoverRequestSender.php
git rm src/Service/Fetch/ProxyEgressResolver.php tests/Service/Fetch/ProxyEgressResolverTest.php
```

`src/Repository/ProxyServerSettingsRepository.php`, replace the docblock's first paragraph:
```php
 * Not final: ProxySettings unit-tests against a mock of this repository rather
 * than a real database, so it needs to stay doubleable.
```
with
```php
 * Not final: the proxy settings tests stub it instead of using a database.
```

- [ ] **Step 5: Move the fetch-side tests to the interface**

```bash
perl -pi -e "s/\bProxyEgressResolver\b/EgressProxySource/g; s/->method\('resolve'\)/->method('egressProxy')/g; s/\\\$proxyEgressResolver\b/\\\$egressProxySource/g; s/\bnoProxyResolver\(/noEgressProxy(/g" tests/Service/Catalog/CatalogFaviconFetcherTest.php tests/Service/Fetch/ConcurrentFeedFetcherProxyTest.php tests/Service/Fetch/ConcurrentFeedFetcherTest.php tests/Service/Fetch/FailoverRequestSenderProxyTest.php tests/Service/Fetch/FailoverRequestSenderTest.php tests/Service/Fetch/HttpFeedFetcherTest.php tests/Service/Fetch/RedirectFollowerTest.php tests/Service/Reader/ArticleExtractorTest.php tests/Service/Reader/HtmlPageFetcherTest.php tests/Service/Reader/Media/MediaLandingTest.php tests/Service/Reader/Media/Sibling/SiblingMediaExtenderTest.php tests/Service/Reader/Media/StreamLocationResolverTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php
git grep -n "ProxyEgressResolver\|proxyEgressResolver\|noProxyResolver\|method('resolve')" -- src tests config
git grep -n 'App\\Service\\Proxy' -- src/Service/Fetch
git grep -nE '^(readonly )?class ' -- src/Service/Proxy src/Service/Fetch
```
Expected: all three greps print nothing.
- The `'resolve'` in `$options['resolve']` and `assertArrayHasKey('resolve', …)` is the curl option and is untouched.
- So is `StreamLocationResolver::resolve()`.

- [ ] **Step 6: Run the tests to verify they pass**

Run:
```bash
php bin/phpunit tests/Service/Proxy tests/Service/Fetch tests/Service/Catalog tests/Service/Reader tests/Service/Refresh tests/Service/Mail tests/Command/CheckCatalogUrlsCommandProxyTest.php tests/Controller/Admin
php bin/console lint:container
```
Expected: PASS.

- [ ] **Step 7: Deletion checks**

Restore each by hand before the next.
1. In `StoredProxy::egressProxy()`, drop `$settings->isEnabled() && `. Expected: `testADisabledProxyIsConfiguredButNotUsedForEgress` fails.
2. In `StoredProxy::egressProxy()`, `return null;` as the first line. Expected: `CheckCatalogUrlsCommandProxyTest::testRequestCarriesTheProxyOptionWhenAnEgressProxyIsEnabled` fails. That proves the command reaches `StoredProxy` through the alias.
3. In `StoredProxy::configuredProxy()`, return `$this->egressProxy()`. Expected, with the proxy row disabled in each:
   - `StoredProxyTest::testADisabledProxyIsConfiguredButNotUsedForEgress` fails.
   - `ProxyConnectionTesterTest::testReturnsEgressIpOnSuccessAndRoutesThroughTheProxy` fails.
   - `DynamicMailTransportTest::testActiveTransportUsesTheCurlTransportForAProxiedRow` fails.
4. In `config/services.yaml`, delete the `ConfiguredProxySource` alias. Expected: `lint:container` still passes, because Symfony auto-aliases a single implementation. The explicit alias is house style. Record the result, then restore the alias.

- [ ] **Step 8: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on every changed `src` and test file.
Expected: clean.

- [ ] **Step 9: Commit**

Run `git status --short` first. Every modified or untracked path it lists must belong to this task. Another session may share the checkout, and the directory-level `git add` below would sweep its files in. If anything else shows up, stage this task's files by explicit path instead.
```bash
git add -A src/Service/Fetch src/Service/Proxy src/Service/Catalog/CatalogUrlChecker.php src/Service/Mail/Transport/ActiveMailTransportFactory.php src/Repository/ProxyServerSettingsRepository.php config/services.yaml tests/Service/Proxy tests/Service/Fetch tests/Service/Catalog/CatalogFaviconFetcherTest.php tests/Service/Reader/ArticleExtractorTest.php tests/Service/Reader/HtmlPageFetcherTest.php tests/Service/Reader/Media/MediaLandingTest.php tests/Service/Reader/Media/Sibling/SiblingMediaExtenderTest.php tests/Service/Reader/Media/StreamLocationResolverTest.php tests/Service/Refresh/RefreshRunnerConcurrentFetchTest.php tests/Service/Mail/Transport/ActiveMailTransportFactoryTest.php tests/Command/CheckCatalogUrlsCommandProxyTest.php
git commit -m "refactor(#1159): fetch reads the egress proxy through its own interface; the proxy admin is admin only"
```

---

### Finishing PR A

- [ ] **Step 1: /simplify**

Invoke the `simplify` skill over the branch diff (`git diff origin/develop...HEAD`). Apply what it proposes, unless it would reintroduce any of these:
- a dependency from `Service/Fetch` on `Service/Proxy`,
- an entity in a read value,
- a DTO in a service.

Rerun the affected tests and `composer check` after each fix, and commit the fixes as `refactor(#1159): simplify review`.

- [ ] **Step 2: SDD final review**

Dispatch one fresh reviewer subagent with the plan, the branch diff and these attack points. It reports findings. It does not fix.
1. **Cycle closed:** `git grep -n 'App\\Service\\Proxy' -- src/Service/Fetch` is empty, and no `src/Service/Proxy` file imports `App\Service\Mail`.
2. **Wire, byte for byte:** on develop and on the branch, capture `GET /api/admin/proxy` with no row and with a saved row, and `PUT /api/admin/proxy`, with the same admin token. The bodies must be identical.
3. **Secret intent:**
   - `removePassword` wins over a sent password,
   - `null` keeps,
   - `''` still *replaces* (proxy), as before,
   - a blank username still becomes `null`.
4. **Enable switch:**
   - the tester and proxied mail depend on `ConfiguredProxySource` (not `StoredProxy`, not `EgressProxySource`), so they work while egress is off;
   - fetch, catalog and favicon use `egressProxy()`, so they are off while egress is off.
5. **The only wire change:** the mail page no longer opens the proxy password (`AdminMailControllerTest::testAnUnreadableProxyPasswordDoesNotBreakTheMailPage`), and nothing else under `/api/admin/mail` changed.
6. **Finality:** no `readonly class` or plain `class` is left in `src/Service/Proxy` or `src/Service/Fetch`, and `ProxySettings` has no public method besides `current()` and `update()`.
7. **Comment bar:** every new or touched comment is one to three lines and would stop a future reader from getting the code wrong.

Fix what it confirms, one commit per finding (`refactor(#1159): review — <finding>`).

- [ ] **Step 3: Per-PR gates**

Run in order and paste each summary line into the report:
```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
```
Lint every changed PHP file with `mcp__phpstorm__lint_files`. Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.

- [ ] **Step 4: Push and open the PR**

The body references the issue without a closing keyword. Do not add "close", "fix" or "resolve" in any form next to `#1159`, prose included.
```bash
git push -u origin refactor/1159-proxy-settings-split
gh pr create --base develop --title "refactor(#1159): split the proxy admin from the egress it feeds" --body "$(cat <<'EOF'
Refs #1159

First of two PRs. The second one splits the Grafana and mail settings the same way.

- **Runtime:** fetch reads the egress proxy through `Fetch\EgressProxySource`, an interface the fetch code owns, and `Proxy\StoredProxy` implements it. `ProxyEgressResolver` is gone, and with it the Fetch ↔ Proxy cycle: nothing under `Service/Fetch` imports `Service/Proxy`. Proxied mail and the proxy tester read `Proxy\ConfiguredProxySource`, which `StoredProxy` also implements; that keeps Mail → Proxy one-way.
- **Admin:** `ProxySettings` is only the admin service now, and it is `final readonly`.
  - `current()` returns a `ProxySettingsSnapshot` and replaces `stored()`, which handed out the entity.
  - `update()` takes a `ProxySettingsUpdate`, built by `ProxySettingsRequest::toUpdate()`. The service no longer imports the request DTO.
- `Crypto\SecretChange` (keep, replace or remove) carries the password intent. The next PR uses it for the Grafana token and the mail password.
- The admin mail page reads the proxy's connection from the snapshot. It no longer decrypts the proxy password to print a label.

**Wire change** (a side effect of that last point): `GET /api/admin/mail`, `PUT /api/admin/mail` and `POST /api/admin/mail/reset` answered 500 when the stored proxy password could not be opened (a rotated `INSTANCE_SECRET_KEY`). They now answer as usual. Every other body, status code and header is unchanged.
EOF
)"
```

- [ ] **Step 5: Merge when green**

Watch the checks with the Monitor tool. Do not use `--auto`: it merges at once.
```bash
PR=$(gh pr view --json number --jq .number)
until [ "$(gh pr checks "$PR" --json bucket --jq '[.[].bucket] | any(. == "pending")')" = "false" ]; do sleep 30; done
gh pr checks "$PR" --json name,bucket --jq '.[] | "\(.bucket)\t\(.name)"'
```
Merge only if every bucket is `pass` or `skipping`:
```bash
gh pr merge "$PR" --merge
```
On a failure:
- Read the failing job's log (`gh run view --log-failed`).
- If the failure is in `composer tramp`, first check `composer show larspohlmann/phptramp`: CI runs phptramp's `develop` tip (CLAUDE.md).
- Fix on the branch, push, and watch again.

- [ ] **Step 6: Verify the issue state**

Run: `gh issue view 1159 --json state --jq .state`
Expected: `OPEN`. If it closed, reopen it and report: a closing keyword slipped in somewhere.

---

# PR B

### Task B0: Preflight (PR A merged)

**Files:** none changed.

- [ ] **Step 1: Confirm PR A merged and the issue is still open**

```bash
git fetch origin
git log origin/develop --oneline | grep -c '(#1159)'
git grep -n "interface EgressProxySource" origin/develop -- backend/src/Service/Fetch/EgressProxySource.php
gh issue view 1159 --json state --jq .state
```
Expected:
- At least four `(#1159)` commits.
- One hit for `EgressProxySource`.
- `OPEN`.

- [ ] **Step 2: Cut the branch**

```bash
git status --short && git branch --show-current
git switch -c refactor/1159-grafana-and-mail-settings-split origin/develop
```

- [ ] **Step 3: Re-take the call sites this PR rewrites**

```bash
git grep -n "GrafanaSettings::class\|new GrafanaSettings(\|toEntity()" -- src tests
git grep -n "MailSettings::class\|use App\\\\Service\\\\Mail\\\\Settings\\\\MailSettings;" -- src tests
git grep -nE "SettingsRequests::(grafana|mail)\(" -- tests
```
Expected at `89c7e17f` (PR A changes none of these lines):
- `GrafanaSettings::class|new GrafanaSettings(|toEntity()`: 28 lines in 18 files.
  - The service and its read side, rewritten in B1–B3: `src/Service/Grafana/GrafanaSettings.php`, `GrafanaSettingsSnapshot.php`, `src/Http/Admin/GrafanaSettingsJson.php`, and in `tests/` `GrafanaSettingsTest` (4), `GrafanaSettingsCacheTest` (2), `GrafanaSettingsSnapshotTest` (1), `GrafanaSettingsJsonTest` (2), `SettingsLokiEndpointTest` (1), `SettingsPyroscopeEndpointTest` (2), `ProfilingPolicyTest` (3), `RequestProfilingListenerTest` (1), `WorkerProfilingListenerTest` (1).
  - `RequestProfilingTest` (1): a container `get()` of the admin service, which B3 keeps.
  - The entity, untouched: `GrafanaSettingsRepository`, `tests/Entity/GrafanaSettingsTest` (3), `AdminGrafanaControllerTest` (1), `GrafanaSettingsRepositoryTest` (1), `BackupSchemaCoverageTest` (1).
- `MailSettings::class` or its import: 41 lines in 21 files. `src`: `AdminMailController` (kept) and the four senders B6 switches (`AccountMailer`, `DigestMailBuilder`, `MailCapability`, `DynamicMailTransport`); `MailConnectionTester` is in the same namespace and has no import. `tests`: B6 Step 5's 13 perl files, plus `MailConnectionTesterTest`, `MailSettingsTest` and `DynamicMailTransportTest`, which keep the admin service.
- `SettingsRequests::grafana(`: `GrafanaSettingsRequestTest` (14), `GrafanaSettingsTest` (14), `RequestProfilingTest` (1). `SettingsRequests::mail(`: `MailSettingsRequestTest` (12), `MailSettingsTest` (19), `MailConnectionTesterTest` (5), `DynamicMailTransportTest` (5).

Compare each hit with B1–B6. Any extra site gets the same rewrite as its siblings. Note it in the report.

---

### Task B1: Grafana read side: `connection()`, public snapshot, entity-free overview

**Files:**
- Modify: `src/Entity/GrafanaSettings.php` (add `connection()`)
- Modify: `src/Service/Grafana/GrafanaSettingsSnapshot.php` (rewritten)
- Modify: `src/Service/Grafana/GrafanaSettingsOverview.php` (rewritten)
- Modify: `src/Service/Grafana/GrafanaSettings.php` (rewritten: the memo holds the snapshot)
- Modify: `src/Http/Admin/GrafanaSettingsJson.php` (rewritten)
- Test: `tests/Entity/GrafanaSettingsTest.php` (one test)
- Test: `tests/Service/Grafana/GrafanaSettingsSnapshotTest.php` (rewritten)
- Test: `tests/Service/Grafana/GrafanaSettingsCacheTest.php` (two assertions)
- Test: `tests/Http/Admin/GrafanaSettingsJsonTest.php` (rewritten)

**Interfaces:**
- Produces:
  - `GrafanaSettings` (entity) gets `connection(): GrafanaConnection`.
  - `GrafanaSettingsSnapshot`:
    - `public GrafanaConnection $connection`,
    - `public SealedSecret $sealedToken`,
    - `public string $tokenHint`,
    - `hasToken(): bool`,
    - `fromEntity()`, `toArray()` and `fromArrayOrNull()` unchanged in behaviour.
    - `toEntity()` is gone.
  - `GrafanaSettingsOverview(GrafanaSettingsSnapshot $stored, GrafanaEnvDefaults $defaults, bool $profilerAvailable)`. It is non-null, and the `AutowireWrongClass` suppression is gone.
  - `GrafanaSettingsJson::from()` reads the snapshot. `App\Http` no longer imports `App\Entity\GrafanaSettings`.
- The service's public API is unchanged in this task. Only its memo type changes: it held an entity rebuilt from the cache and now holds the snapshot.

- [ ] **Step 1: Write the failing tests**

`tests/Entity/GrafanaSettingsTest.php`, add after `testClearStoredTokenLeavesOverridesButDropsSecret()`:
```php
    public function testConnectionReadsBackWhatWasApplied(): void
    {
        $connection = new GrafanaConnection(
            'https://loki.example/push',
            'tenant42',
            'https://grafana.example',
            'https://pyroscope.example',
            true,
        );
        $settings = new GrafanaSettings();

        $settings->applyWithoutToken($connection);

        self::assertEquals($connection, $settings->connection());
    }
```

`tests/Service/Grafana/GrafanaSettingsSnapshotTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaSettingsSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsSnapshotTest extends TestCase
{
    public function testItCarriesTheRowsConnectionTokenAndHint(): void
    {
        $connection = new GrafanaConnection(
            'https://loki.example/push',
            'tenant42',
            'https://grafana.example',
            'http://pyro:4040',
            true,
        );
        $entity = new GrafanaSettingsEntity();
        $entity->apply($connection, new SealedSecret('cipher', 'nonce', 'salt', 3), 'oken');

        $snapshot = GrafanaSettingsSnapshot::fromEntity($entity);

        self::assertEquals($connection, $snapshot->connection);
        self::assertEquals(new SealedSecret('cipher', 'nonce', 'salt', 3), $snapshot->sealedToken);
        self::assertSame('oken', $snapshot->tokenHint);
        self::assertTrue($snapshot->hasToken());
    }

    public function testAFreshRowHasNoToken(): void
    {
        $snapshot = GrafanaSettingsSnapshot::fromEntity(new GrafanaSettingsEntity());

        self::assertFalse($snapshot->hasToken());
        self::assertSame('', $snapshot->tokenHint);
        self::assertEquals(new GrafanaConnection(null, null, null, null, false), $snapshot->connection);
    }

    public function testARowWithATokenSurvivesTheArrayRoundTrip(): void
    {
        $snapshot = new GrafanaSettingsSnapshot(
            new GrafanaConnection('https://loki.example/push', 'tenant42', 'https://grafana.example', null, true),
            new SealedSecret('cipher', 'nonce', 'salt', 3),
            'oken',
        );

        self::assertEquals($snapshot, GrafanaSettingsSnapshot::fromArrayOrNull($snapshot->toArray()));
    }

    public function testATokenlessRowSurvivesTheArrayRoundTripWithoutGainingAToken(): void
    {
        $snapshot = GrafanaSettingsSnapshot::fromEntity(new GrafanaSettingsEntity());

        $rebuilt = GrafanaSettingsSnapshot::fromArrayOrNull($snapshot->toArray());

        self::assertEquals($snapshot, $rebuilt);
        self::assertFalse($rebuilt?->hasToken());
    }

    #[DataProvider('malformedEntries')]
    public function testAMalformedEntryIsRejectedAsAMiss(mixed $stored): void
    {
        self::assertNull(GrafanaSettingsSnapshot::fromArrayOrNull($stored));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedEntries(): iterable
    {
        $valid = [
            'lokiPushUrl' => null,
            'lokiUsername' => null,
            'grafanaUrl' => null,
            'pyroscopePushUrl' => null,
            'profilingEnabled' => true,
            'tokenCiphertext' => 'cipher',
            'tokenNonce' => 'nonce',
            'tokenSalt' => 'salt',
            'tokenKeyVersion' => 1,
            'tokenHint' => 'oken',
        ];

        yield 'not an array' => ['a string'];
        yield 'missing a key' => [array_diff_key($valid, ['tokenHint' => null])];
        yield 'profiling flag is not a bool' => [['profilingEnabled' => 1] + $valid];
        yield 'key version is not an int' => [['tokenKeyVersion' => '1'] + $valid];
        yield 'url override is not a string' => [['lokiPushUrl' => 42] + $valid];
        yield 'hint is not a string' => [['tokenHint' => null] + $valid];
    }
}
```

`tests/Service/Grafana/GrafanaSettingsCacheTest.php`: replace both
```php
        self::assertTrue($cache->remember($loader)->toEntity()->isProfilingEnabled());
```
with
```php
        self::assertTrue($cache->remember($loader)->connection->profilingEnabled);
```
(Edit with `replace_all`).

`tests/Http/Admin/GrafanaSettingsJsonTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Entity\GrafanaSettings;
use App\Http\Admin\GrafanaSettingsJson;
use App\Service\Crypto\SealedSecret;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsOverview;
use App\Service\Grafana\GrafanaSettingsSnapshot;
use PHPUnit\Framework\TestCase;

final class GrafanaSettingsJsonTest extends TestCase
{
    public function testNoRowFallsBackToDefaultsAndReportsContainerPresent(): void
    {
        $payload = GrafanaSettingsJson::from(
            new GrafanaSettingsOverview($this->unconfigured(), $this->defaults(), false),
        );

        self::assertNull($payload['lokiPushUrl']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $payload['lokiPushUrlDefault']);
        self::assertSame('http://loki:3100/loki/api/v1/push', $payload['lokiPushUrlEffective']);
        self::assertNull($payload['grafanaUrl']);
        self::assertSame('http://localhost:3000', $payload['grafanaUrlEffective']);
        self::assertFalse($payload['hasToken']);
        self::assertSame('', $payload['tokenHint']);
        self::assertNull($payload['lokiUsername']);
        self::assertTrue($payload['containerPresent']);
    }

    public function testOverrideWinsOverDefaultAndSecretNeverLeaks(): void
    {
        $stored = new GrafanaSettingsSnapshot(
            new GrafanaConnection('https://cloud/loki/push', 'tenant42', 'https://cloud/grafana', null, false),
            new SealedSecret('c', 'n', 's', 1),
            'wxyz',
        );

        $payload = GrafanaSettingsJson::from(new GrafanaSettingsOverview($stored, $this->defaults(), false));

        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrl']);
        self::assertSame('https://cloud/loki/push', $payload['lokiPushUrlEffective']);
        self::assertSame('tenant42', $payload['lokiUsername']);
        self::assertSame('https://cloud/grafana', $payload['grafanaUrl']);
        self::assertSame('https://cloud/grafana', $payload['grafanaUrlEffective']);
        self::assertTrue($payload['hasToken']);
        self::assertSame('wxyz', $payload['tokenHint']);
        self::assertArrayNotHasKey('token', $payload);
        self::assertArrayNotHasKey('sealedToken', $payload);
    }

    public function testNoContainerWhenDefaultEmpty(): void
    {
        $payload = GrafanaSettingsJson::from(
            new GrafanaSettingsOverview($this->unconfigured(), new GrafanaEnvDefaults('', '', ''), false),
        );

        self::assertFalse($payload['containerPresent']);
        self::assertNull($payload['lokiPushUrlEffective']);
    }

    public function testProfilingOverrideToggleAndAvailabilityAreReported(): void
    {
        $stored = new GrafanaSettingsSnapshot(
            new GrafanaConnection(null, null, null, 'http://custom:4040', true),
            new SealedSecret('c', 'n', 's', 1),
            'wxyz',
        );

        $payload = GrafanaSettingsJson::from(new GrafanaSettingsOverview($stored, $this->defaults(), true));

        self::assertSame('http://custom:4040', $payload['pyroscopePushUrl']);
        self::assertSame('http://pyroscope:4040', $payload['pyroscopePushUrlDefault']);
        self::assertSame('http://custom:4040', $payload['pyroscopePushUrlEffective']);
        self::assertTrue($payload['profilingContainerPresent']);
        self::assertTrue($payload['profilingEnabled']);
        self::assertTrue($payload['profilerAvailable']);
    }

    public function testProfilingReportsAbsentContainerAndOffToggleWithoutARow(): void
    {
        $payload = GrafanaSettingsJson::from(
            new GrafanaSettingsOverview($this->unconfigured(), new GrafanaEnvDefaults('', '', ''), false),
        );

        self::assertNull($payload['pyroscopePushUrlEffective']);
        self::assertFalse($payload['profilingContainerPresent']);
        self::assertFalse($payload['profilingEnabled']);
        self::assertFalse($payload['profilerAvailable']);
    }

    private function unconfigured(): GrafanaSettingsSnapshot
    {
        return GrafanaSettingsSnapshot::fromEntity(new GrafanaSettings());
    }

    private function defaults(): GrafanaEnvDefaults
    {
        return new GrafanaEnvDefaults(
            'http://loki:3100/loki/api/v1/push',
            'http://localhost:3000',
            'http://pyroscope:4040',
        );
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Entity/GrafanaSettingsTest.php tests/Service/Grafana tests/Http/Admin/GrafanaSettingsJsonTest.php`
Expected: FAIL.
- `connection()` is undefined.
- The snapshot's properties are private, and `hasToken()` is undefined.
- The overview still wants an entity.

- [ ] **Step 3: Implement**

`src/Entity/GrafanaSettings.php`, add after `getPyroscopePushUrlOverride()`:
```php
    public function connection(): GrafanaConnection
    {
        return new GrafanaConnection(
            $this->lokiPushUrl,
            $this->lokiUsername,
            $this->grafanaUrl,
            $this->pyroscopePushUrl,
            $this->profilingEnabled,
        );
    }
```
`GrafanaConnection` is already imported there.

`src/Service/Grafana/GrafanaSettingsSnapshot.php` (rewritten in full; `toArray()`, `fromArrayOrNull()` and their helpers are unchanged):
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Service\Crypto\SealedSecret;

/**
 * The Grafana row as plain values, for the admin page, the runtime reads and GrafanaSettingsCache. The array form is
 * flat scalars, so an entry from an earlier release either reads back or is rejected as a miss.
 */
final readonly class GrafanaSettingsSnapshot
{
    public function __construct(
        public GrafanaConnection $connection,
        public SealedSecret $sealedToken,
        public string $tokenHint,
    ) {
    }

    public static function fromEntity(GrafanaSettingsEntity $entity): self
    {
        return new self($entity->connection(), $entity->getSealedToken(), $entity->getTokenHint());
    }

    public function hasToken(): bool
    {
        return '' !== $this->sealedToken->ciphertext;
    }

    /** @return array<string, string|bool|int|null> */
    public function toArray(): array
    {
        return [
            'lokiPushUrl' => $this->connection->lokiPushUrl,
            'lokiUsername' => $this->connection->lokiUsername,
            'grafanaUrl' => $this->connection->grafanaUrl,
            'pyroscopePushUrl' => $this->connection->pyroscopePushUrl,
            'profilingEnabled' => $this->connection->profilingEnabled,
            'tokenCiphertext' => $this->sealedToken->ciphertext,
            'tokenNonce' => $this->sealedToken->nonce,
            'tokenSalt' => $this->sealedToken->salt,
            'tokenKeyVersion' => $this->sealedToken->version,
            'tokenHint' => $this->tokenHint,
        ];
    }

    public static function fromArrayOrNull(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }

        $connection = self::connectionFromArrayOrNull($stored);
        $sealedToken = self::sealedTokenFromArrayOrNull($stored);
        $tokenHint = $stored['tokenHint'] ?? null;
        if (null === $connection || null === $sealedToken || !\is_string($tokenHint)) {
            return null;
        }

        return new self($connection, $sealedToken, $tokenHint);
    }

    /** @param array<array-key, mixed> $stored */
    private static function connectionFromArrayOrNull(array $stored): ?GrafanaConnection
    {
        $lokiPushUrl = $stored['lokiPushUrl'] ?? null;
        $lokiUsername = $stored['lokiUsername'] ?? null;
        $grafanaUrl = $stored['grafanaUrl'] ?? null;
        $pyroscopePushUrl = $stored['pyroscopePushUrl'] ?? null;
        $profilingEnabled = $stored['profilingEnabled'] ?? null;
        if (
            !self::isNullableString($lokiPushUrl)
            || !self::isNullableString($lokiUsername)
            || !self::isNullableString($grafanaUrl)
            || !self::isNullableString($pyroscopePushUrl)
            || !\is_bool($profilingEnabled)
        ) {
            return null;
        }

        return new GrafanaConnection($lokiPushUrl, $lokiUsername, $grafanaUrl, $pyroscopePushUrl, $profilingEnabled);
    }

    /** @param array<array-key, mixed> $stored */
    private static function sealedTokenFromArrayOrNull(array $stored): ?SealedSecret
    {
        $ciphertext = $stored['tokenCiphertext'] ?? null;
        $nonce = $stored['tokenNonce'] ?? null;
        $salt = $stored['tokenSalt'] ?? null;
        $keyVersion = $stored['tokenKeyVersion'] ?? null;
        if (!\is_string($ciphertext) || !\is_string($nonce) || !\is_string($salt) || !\is_int($keyVersion)) {
            return null;
        }

        return new SealedSecret($ciphertext, $nonce, $salt, $keyVersion);
    }

    /** @phpstan-assert-if-true string|null $value */
    private static function isNullableString(mixed $value): bool
    {
        return null === $value || \is_string($value);
    }
}
```

`src/Service/Grafana/GrafanaSettingsOverview.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

final readonly class GrafanaSettingsOverview
{
    public function __construct(
        public GrafanaSettingsSnapshot $stored,
        public GrafanaEnvDefaults $defaults,
        public bool $profilerAvailable,
    ) {
    }
}
```

`src/Http/Admin/GrafanaSettingsJson.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Http\Admin;

use App\Service\Grafana\GrafanaSettingsOverview;

/**
 * The admin Grafana payload. The token is absent by construction: only hasToken
 * and the last-four tokenHint cross the wire, never the secret. Each URL carries
 * its stored override, the env default, and the effective value the server uses
 * now, so an admin who leaves a field empty still sees the local-container value.
 */
final readonly class GrafanaSettingsJson
{
    /**
     * @return array{
     *     lokiPushUrl: string|null,
     *     lokiPushUrlDefault: string,
     *     lokiPushUrlEffective: string|null,
     *     lokiUsername: string|null,
     *     grafanaUrl: string|null,
     *     grafanaUrlDefault: string,
     *     grafanaUrlEffective: string|null,
     *     hasToken: bool,
     *     tokenHint: string,
     *     containerPresent: bool,
     *     pyroscopePushUrl: string|null,
     *     pyroscopePushUrlDefault: string,
     *     pyroscopePushUrlEffective: string|null,
     *     profilingEnabled: bool,
     *     profilingContainerPresent: bool,
     *     profilerAvailable: bool,
     * }
     */
    public static function from(GrafanaSettingsOverview $overview): array
    {
        $stored = $overview->stored;
        $connection = $stored->connection;
        $defaults = $overview->defaults;

        return [
            'lokiPushUrl' => $connection->lokiPushUrl,
            'lokiPushUrlDefault' => $defaults->lokiPushUrl,
            'lokiPushUrlEffective' => self::effective($connection->lokiPushUrl, $defaults->lokiPushUrl),
            'lokiUsername' => $connection->lokiUsername,
            'grafanaUrl' => $connection->grafanaUrl,
            'grafanaUrlDefault' => $defaults->grafanaUrl,
            'grafanaUrlEffective' => self::effective($connection->grafanaUrl, $defaults->grafanaUrl),
            'hasToken' => $stored->hasToken(),
            'tokenHint' => $stored->tokenHint,
            'containerPresent' => '' !== $defaults->lokiPushUrl,
            'pyroscopePushUrl' => $connection->pyroscopePushUrl,
            'pyroscopePushUrlDefault' => $defaults->pyroscopePushUrl,
            'pyroscopePushUrlEffective' => self::effective($connection->pyroscopePushUrl, $defaults->pyroscopePushUrl),
            'profilingEnabled' => $connection->profilingEnabled,
            'profilingContainerPresent' => '' !== $defaults->pyroscopePushUrl,
            'profilerAvailable' => $overview->profilerAvailable,
        ];
    }

    private static function effective(?string $override, string $default): ?string
    {
        if (null !== $override) {
            return $override;
        }

        return '' === $default ? null : $default;
    }
}
```

`src/Service/Grafana/GrafanaSettings.php` (rewritten in full; B2 changes `update()`, and B3 splits the class):
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;

class GrafanaSettings
{
    private ?GrafanaSettingsSnapshot $memoisedSettings = null;

    public function __construct(
        private readonly GrafanaSettingsRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
        private readonly ProfileSampler $sampler,
        private readonly GrafanaSettingsCache $cache,
    ) {
    }

    public function overview(): GrafanaSettingsOverview
    {
        return new GrafanaSettingsOverview($this->settings(), $this->defaults, $this->sampler->isAvailable());
    }

    public function update(GrafanaSettingsRequest $request): void
    {
        $settings = $this->repository->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->em->persist($settings);
        }

        $connection = $this->connectionFrom($request);

        if ($request->removeToken) {
            $settings->applyWithoutToken($connection);
            $settings->clearStoredToken();
        } elseif (null === $request->token || '' === $request->token) {
            $settings->applyWithoutToken($connection);
        } else {
            $settings->apply($connection, $this->cipher->seal($request->token), $this->hint($request->token));
        }

        $this->em->flush();
        $this->cache->forget();
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->memoisedSettings = null;
    }

    public function effectiveLokiPushUrl(): ?string
    {
        return $this->settings()->connection->lokiPushUrl ?? $this->defaultOrNull($this->defaults->lokiPushUrl);
    }

    public function effectivePyroscopePushUrl(): ?string
    {
        return $this->settings()->connection->pyroscopePushUrl
            ?? $this->defaultOrNull($this->defaults->pyroscopePushUrl);
    }

    public function profilingEnabled(): bool
    {
        return $this->settings()->connection->profilingEnabled;
    }

    public function lokiUsername(): ?string
    {
        return $this->settings()->connection->lokiUsername;
    }

    public function lokiToken(): ?string
    {
        $settings = $this->settings();

        return $settings->hasToken() ? $this->cipher->open($settings->sealedToken) : null;
    }

    private function settings(): GrafanaSettingsSnapshot
    {
        return $this->memoisedSettings ??= $this->cache->remember($this->loadSingleton(...));
    }

    private function loadSingleton(): GrafanaSettingsSnapshot
    {
        return GrafanaSettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new GrafanaSettingsEntity());
    }

    private function defaultOrNull(string $default): ?string
    {
        return '' === $default ? null : $default;
    }

    private function connectionFrom(GrafanaSettingsRequest $request): GrafanaConnection
    {
        return new GrafanaConnection(
            $this->blankToNull($request->lokiPushUrl),
            $this->blankToNull($request->lokiUsername),
            $this->blankToNull($request->grafanaUrl),
            $this->blankToNull($request->pyroscopePushUrl),
            $request->profilingEnabled,
        );
    }

    private function blankToNull(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
```
The 15-line class docblock goes. Its content has homes elsewhere:
- The memo and cache rationale is in `GrafanaSettingsCache`'s docblock and the memo tests.
- "Not final so SettingsLokiEndpointTest can stub it" stops being true in B3.

- [ ] **Step 4: Run the tests to verify they pass**

Run the Step 2 command, then `php bin/phpunit tests/Service/Grafana tests/Controller/Admin/AdminGrafanaControllerTest.php tests/Functional/RequestProfilingTest.php tests/Service/Profiling tests/EventListener`.
Expected: PASS.

- [ ] **Step 5: Deletion checks**

Restore each by hand before the next.
1. In `GrafanaSettingsSnapshot::hasToken()`, return `true`. Expected: `testAFreshRowHasNoToken` and `GrafanaSettingsJsonTest::testNoRowFallsBackToDefaultsAndReportsContainerPresent` fail.
2. In `GrafanaSettings` (entity) `connection()`, pass `$this->lokiUsername` twice, the second time for `grafanaUrl`. Expected: `testConnectionReadsBackWhatWasApplied` fails.
3. In `GrafanaSettings::lokiToken()` (service), open `$settings->sealedToken` unconditionally. Expected: `GrafanaSettingsTest::testRemoveTokenClearsTheStoredSecretButKeepsTheConnection` fails on the empty ciphertext.

- [ ] **Step 6: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on every changed file.
Expected: clean. In particular, `GrafanaSettingsOverview` gets **no** `AutowireWrongClass` warning. If PhpStorm raises one on `GrafanaSettingsSnapshot $stored`, stop and report instead of suppressing it. Ruling 4 is that the suppression goes.

- [ ] **Step 7: Commit**

```bash
git add src/Entity/GrafanaSettings.php src/Service/Grafana/GrafanaSettingsSnapshot.php src/Service/Grafana/GrafanaSettingsOverview.php src/Service/Grafana/GrafanaSettings.php src/Http/Admin/GrafanaSettingsJson.php tests/Entity/GrafanaSettingsTest.php tests/Service/Grafana/GrafanaSettingsSnapshotTest.php tests/Service/Grafana/GrafanaSettingsCacheTest.php tests/Http/Admin/GrafanaSettingsJsonTest.php
git commit -m "refactor(#1159): the grafana read side is a snapshot, not an entity"
```

---

### Task B2: `GrafanaSettingsUpdate`

**Files:**
- Create: `src/Service/Grafana/GrafanaSettingsUpdate.php`
- Modify: `src/Dto/Admin/GrafanaSettingsRequest.php` (rewritten: #1167 A7b's version plus `toUpdate()`)
- Modify: `src/Service/Grafana/GrafanaSettings.php` (rewritten)
- Modify: `src/Controller/Admin/AdminGrafanaController.php` (one line)
- Test: `tests/Dto/Admin/GrafanaSettingsRequestTest.php` (five tests)
- Test (perl, `->toUpdate()` appended): `tests/Service/Grafana/GrafanaSettingsTest.php`, `tests/Functional/RequestProfilingTest.php`

**Interfaces:**
- Consumes: `App\Service\Crypto\SecretChange` (A2).
- Produces:
  - `final readonly class App\Service\Grafana\GrafanaSettingsUpdate { public GrafanaConnection $connection; public SecretChange $token }`.
  - `GrafanaSettingsRequest::toUpdate(): GrafanaSettingsUpdate`.
  - `GrafanaSettings::update(GrafanaSettingsUpdate $update): void`.
- The mapping is unchanged:
  - `removeToken` wins.
  - `null` or `''` keeps the stored token.
  - Any other string replaces it.
  - Every blank URL or username becomes `null`, which falls back to the env default.
- The hint stays the token's last four characters.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Admin/GrafanaSettingsRequestTest.php` (post-#1167):
- Add after `use App\Dto\Admin\GrafanaSettingsRequest;`:
```php
use App\Service\Crypto\SecretChange;
use App\Service\Grafana\GrafanaConnection;
```
- Add before the class's closing `}`:
```php
    public function testToUpdateCarriesTheOverridesAndTheProfilingSwitch(): void
    {
        $update = SettingsRequests::grafana(
            lokiPushUrl: 'http://loki:3100/push',
            lokiUsername: 'tenant42',
            grafanaUrl: 'http://grafana:3000',
            pyroscopePushUrl: 'http://pyroscope:4040',
            profilingEnabled: true,
        )->toUpdate();

        self::assertEquals(
            new GrafanaConnection(
                'http://loki:3100/push',
                'tenant42',
                'http://grafana:3000',
                'http://pyroscope:4040',
                true,
            ),
            $update->connection,
        );
    }

    public function testToUpdateTurnsBlankOverridesIntoNone(): void
    {
        $update = SettingsRequests::grafana(lokiPushUrl: '', lokiUsername: '', grafanaUrl: '', pyroscopePushUrl: '')
            ->toUpdate();

        self::assertEquals(new GrafanaConnection(null, null, null, null, false), $update->connection);
    }

    public function testABlankOrMissingTokenKeepsTheStoredOne(): void
    {
        self::assertEquals(SecretChange::keep(), SettingsRequests::grafana(token: '')->toUpdate()->token);
        self::assertEquals(SecretChange::keep(), SettingsRequests::grafana()->toUpdate()->token);
    }

    public function testATokenReplacesTheStoredOne(): void
    {
        $update = SettingsRequests::grafana(token: 'glc_new')->toUpdate();

        self::assertEquals(SecretChange::replaceWith('glc_new'), $update->token);
    }

    public function testRemoveTokenWinsOverASentToken(): void
    {
        $update = SettingsRequests::grafana(token: 'glc_new', removeToken: true)->toUpdate();

        self::assertEquals(SecretChange::remove(), $update->token);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Dto/Admin/GrafanaSettingsRequestTest.php`
Expected: FAIL. `toUpdate()` is undefined.

- [ ] **Step 3: Implement**

`src/Service/Grafana/GrafanaSettingsUpdate.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Service\Crypto\SecretChange;

final readonly class GrafanaSettingsUpdate
{
    public function __construct(
        public GrafanaConnection $connection,
        public SecretChange $token,
    ) {
    }
}
```

`src/Dto/Admin/GrafanaSettingsRequest.php` (rewritten in full; the constructor is #1167 A7b's, unchanged):
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Http\FullReplacePayload;
use App\Service\Crypto\SecretChange;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaSettingsUpdate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without which a
 * missing nullable setting reads as null. A null URL falls back to the env default. The token is an optional
 * three-state intent: null keeps it, a string replaces it, `removeToken` clears it.
 */
final readonly class GrafanaSettingsRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $lokiPushUrl,
        #[Assert\Length(max: 255)]
        public ?string $lokiUsername,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $grafanaUrl,
        #[Assert\Length(max: 255)]
        #[Assert\Url(requireTld: false)]
        public ?string $pyroscopePushUrl,
        #[Assert\Type('bool')]
        public bool $profilingEnabled,
        #[Assert\Length(max: 512)]
        public ?string $token = null,
        #[Assert\Type('bool')]
        public bool $removeToken = false,
    ) {
    }

    public function toUpdate(): GrafanaSettingsUpdate
    {
        return new GrafanaSettingsUpdate(
            new GrafanaConnection(
                self::blankToNull($this->lokiPushUrl),
                self::blankToNull($this->lokiUsername),
                self::blankToNull($this->grafanaUrl),
                self::blankToNull($this->pyroscopePushUrl),
                $this->profilingEnabled,
            ),
            $this->tokenChange(),
        );
    }

    private function tokenChange(): SecretChange
    {
        if ($this->removeToken) {
            return SecretChange::remove();
        }

        $token = self::blankToNull($this->token);

        return null === $token ? SecretChange::keep() : SecretChange::replaceWith($token);
    }

    private static function blankToNull(?string $value): ?string
    {
        return '' === $value ? null : $value;
    }
}
```
The constructor, the class docblock and the `App\Http\FullReplacePayload` import are #1167 A7b's as landed at `89c7e17f`; only the three new imports and the three methods are this task's.

`src/Service/Grafana/GrafanaSettings.php` (rewritten in full; B3 splits it):
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;

class GrafanaSettings
{
    private ?GrafanaSettingsSnapshot $memoisedSettings = null;

    public function __construct(
        private readonly GrafanaSettingsRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
        private readonly ProfileSampler $sampler,
        private readonly GrafanaSettingsCache $cache,
    ) {
    }

    public function overview(): GrafanaSettingsOverview
    {
        return new GrafanaSettingsOverview($this->settings(), $this->defaults, $this->sampler->isAvailable());
    }

    public function update(GrafanaSettingsUpdate $update): void
    {
        $settings = $this->repository->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
        $this->cache->forget();
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->memoisedSettings = null;
    }

    public function effectiveLokiPushUrl(): ?string
    {
        return $this->settings()->connection->lokiPushUrl ?? $this->defaultOrNull($this->defaults->lokiPushUrl);
    }

    public function effectivePyroscopePushUrl(): ?string
    {
        return $this->settings()->connection->pyroscopePushUrl
            ?? $this->defaultOrNull($this->defaults->pyroscopePushUrl);
    }

    public function profilingEnabled(): bool
    {
        return $this->settings()->connection->profilingEnabled;
    }

    public function lokiUsername(): ?string
    {
        return $this->settings()->connection->lokiUsername;
    }

    public function lokiToken(): ?string
    {
        $settings = $this->settings();

        return $settings->hasToken() ? $this->cipher->open($settings->sealedToken) : null;
    }

    private function apply(GrafanaSettingsUpdate $update, GrafanaSettingsEntity $settings): void
    {
        $replacement = $update->token->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement), $this->hint($replacement));

            return;
        }

        $settings->applyWithoutToken($update->connection);
        if ($update->token->isRemoval()) {
            $settings->clearStoredToken();
        }
    }

    private function settings(): GrafanaSettingsSnapshot
    {
        return $this->memoisedSettings ??= $this->cache->remember($this->loadSingleton(...));
    }

    private function loadSingleton(): GrafanaSettingsSnapshot
    {
        return GrafanaSettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new GrafanaSettingsEntity());
    }

    private function defaultOrNull(string $default): ?string
    {
        return '' === $default ? null : $default;
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
```

`src/Controller/Admin/AdminGrafanaController.php`: replace `$this->settings->update($request);` with `$this->settings->update($request->toUpdate());`.

- [ ] **Step 4: Move the service tests to the update value**

```bash
perl -0pi -e 's/(SettingsRequests::grafana(\((?:[^()]++|(?2))*\)))/$1->toUpdate()/g' tests/Service/Grafana/GrafanaSettingsTest.php tests/Functional/RequestProfilingTest.php
perl -0ne 'while (/(SettingsRequests::grafana(\((?:[^()]++|(?2))*\)))(?!->toUpdate\(\))/g) { print "$ARGV: $1\n" }' tests/Service/Grafana/GrafanaSettingsTest.php tests/Functional/RequestProfilingTest.php
```
Expected: the second command prints nothing.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Dto/Admin/GrafanaSettingsRequestTest.php tests/Service/Grafana tests/Functional/RequestProfilingTest.php tests/Controller/Admin/AdminGrafanaControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Deletion checks**

Restore each by hand before the next.
1. In `tokenChange()`, use `$this->token` in place of `self::blankToNull($this->token)`, and make the check `null === $this->token`. Expected: `testABlankOrMissingTokenKeepsTheStoredOne` fails.
2. In `GrafanaSettings::apply()`, delete the `isRemoval()` block. Expected: `GrafanaSettingsTest::testRemoveTokenClearsTheStoredSecretButKeepsTheConnection` and `AdminGrafanaControllerTest::testRemoveTokenClearsTheStoredSecret` fail.
3. In `toUpdate()`, pass `$this->lokiUsername` unwrapped. Expected: `testToUpdateTurnsBlankOverridesIntoNone` and `GrafanaSettingsTest::testBlankUsernameClearsTheStoredOverrideAndItStartsNull` fail.

- [ ] **Step 7: Gates**

Run `composer check`, `composer md` and PhpStorm `lint_files` on the changed files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Grafana/GrafanaSettingsUpdate.php src/Dto/Admin/GrafanaSettingsRequest.php src/Service/Grafana/GrafanaSettings.php src/Controller/Admin/AdminGrafanaController.php tests/Dto/Admin/GrafanaSettingsRequestTest.php tests/Service/Grafana/GrafanaSettingsTest.php tests/Functional/RequestProfilingTest.php
git commit -m "refactor(#1159): the grafana admin takes an update value the request builds"
```

---

### Task B3: `ProfilingConfigSource` / `EffectiveGrafanaSettings`; profiling failure decided

**Files:**
- Create: `src/Service/Profiling/ProfilingConfigSource.php`
- Create: `src/Service/Grafana/EffectiveGrafanaSettings.php`
- Modify: `src/Service/Grafana/GrafanaSettings.php` (rewritten: admin only, `final readonly`)
- Modify: `src/Service/Grafana/SettingsLokiEndpoint.php`, `src/Service/Grafana/SettingsPyroscopeEndpoint.php` (constructor type)
- Modify: `src/Service/Profiling/ProfilingPolicy.php` (rewritten)
- Modify: `src/EventListener/WorkerProfilingListener.php` (import, constructor, one call)
- Modify: `src/Service/Grafana/GrafanaEnvDefaults.php`, `src/Service/Grafana/GrafanaSettingsCache.php`, `src/Repository/GrafanaSettingsRepository.php` (docblocks)
- Modify: `config/services.yaml` (one alias)
- Test: `tests/Service/Grafana/EffectiveGrafanaSettingsTest.php` (new)
- Test (each rewritten in full): `tests/Service/Grafana/GrafanaSettingsTest.php`, `tests/Service/Grafana/SettingsLokiEndpointTest.php`, `tests/Service/Grafana/SettingsPyroscopeEndpointTest.php`, `tests/Service/Profiling/ProfilingPolicyTest.php`
- Test (perl): `tests/EventListener/WorkerProfilingListenerTest.php`, `tests/EventListener/RequestProfilingListenerTest.php`

**Interfaces:**
- Consumes: `GrafanaSettingsSnapshot` (B1), `GrafanaSettingsUpdate` (B2).
- Produces:
  - `interface App\Service\Profiling\ProfilingConfigSource { public function profilingEnabled(): bool; public function refresh(): void; }`. It is owned by profiling, and `config/services.yaml` aliases it to `EffectiveGrafanaSettings`.
  - `final class App\Service\Grafana\EffectiveGrafanaSettings implements ProfilingConfigSource`:
    - `__construct(GrafanaSettingsRepository $repository, GrafanaApiKeyCipher $cipher, GrafanaEnvDefaults $defaults, GrafanaSettingsCache $cache)`.
    - `stored(): GrafanaSettingsSnapshot`, memoised per process and cached across processes.
    - `refresh(): void` drops the memo only.
    - `forgetStored(): void` drops the shared cache entry and the memo.
    - `effectiveLokiPushUrl()`, `effectivePyroscopePushUrl()`, `profilingEnabled()`, `lokiUsername()` and `lokiToken()`, unchanged in behaviour.
    - It is `final class`, not `readonly`, because the memo is a mutable field.
  - `final readonly class GrafanaSettings`:
    - `__construct(GrafanaSettingsRepository $repository, EntityManagerInterface $em, GrafanaApiKeyCipher $cipher, EffectiveGrafanaSettings $effective, GrafanaEnvDefaults $defaults, ProfileSampler $sampler)`.
    - `overview(): GrafanaSettingsOverview`.
    - `update(GrafanaSettingsUpdate $update): void`, which ends with `$effective->forgetStored()`.
  - `SettingsLokiEndpoint` and `SettingsPyroscopeEndpoint` take `EffectiveGrafanaSettings`.
  - `ProfilingPolicy::__construct(ProfilingConfigSource $config, ProfileSampler $sampler, PyroscopeEndpoint $endpoint)`.
  - `WorkerProfilingListener`'s fifth constructor parameter is `ProfilingConfigSource $profilingConfig`.
- After this task nothing under `src/Service/Profiling` or `src/EventListener` imports `App\Service\Grafana`.
- **D5, the decision:** a config read that throws means profiling is off. The catch stays `\Throwable`, because `RequestProfilingListener::onKernelRequest()` calls `isEnabled()` outside its own `try` on every request. The comment says so in one line, and two tests pin both reads.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Grafana/EffectiveGrafanaSettingsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\EffectiveGrafanaSettings;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class EffectiveGrafanaSettingsTest extends TestCase
{
    private const string SECRET = 'test-master-secret-at-least-32-chars-long!!';
    private const string LOKI_DEFAULT = 'http://loki:3100/loki/api/v1/push';
    private const string PYROSCOPE_DEFAULT = 'http://pyroscope:4040';

    public function testWithNoRowEachUrlFallsBackToItsEnvDefault(): void
    {
        $settings = $this->effective(null, new GrafanaEnvDefaults(self::LOKI_DEFAULT, '', self::PYROSCOPE_DEFAULT));

        self::assertSame(self::LOKI_DEFAULT, $settings->effectiveLokiPushUrl());
        self::assertSame(self::PYROSCOPE_DEFAULT, $settings->effectivePyroscopePushUrl());
    }

    public function testAStoredOverrideWinsOverTheEnvDefault(): void
    {
        $row = $this->row(
            new GrafanaConnection('https://cloud.example/loki/push', null, null, 'http://custom:4040', false),
        );

        $settings = $this->effective($row, new GrafanaEnvDefaults(self::LOKI_DEFAULT, '', self::PYROSCOPE_DEFAULT));

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
        self::assertSame('http://custom:4040', $settings->effectivePyroscopePushUrl());
    }

    public function testAUrlIsNullWhenNeitherOverrideNorDefaultIsConfigured(): void
    {
        $settings = $this->effective(null);

        self::assertNull($settings->effectiveLokiPushUrl());
        self::assertNull($settings->effectivePyroscopePushUrl());
    }

    public function testProfilingAndTheLokiUsernameComeFromTheRow(): void
    {
        $settings = $this->effective($this->row(new GrafanaConnection(null, 'tenant42', null, null, true)));

        self::assertTrue($settings->profilingEnabled());
        self::assertSame('tenant42', $settings->lokiUsername());
    }

    public function testWithNoRowProfilingIsOffAndThereIsNoUsernameOrToken(): void
    {
        $settings = $this->effective(null);

        self::assertFalse($settings->profilingEnabled());
        self::assertNull($settings->lokiUsername());
        self::assertNull($settings->lokiToken());
    }

    public function testTheStoredTokenIsOpened(): void
    {
        self::assertSame('glc_secrettoken', $this->effective($this->rowWithToken('glc_secrettoken'))->lokiToken());
    }

    /** A Loki flush reads push URL, username and token in a row (#983): that must cost one lookup, not three. */
    public function testResolvingPushUrlUsernameAndTokenTogetherQueriesTheRepositoryOnce(): void
    {
        $repository = $this->createMock(GrafanaSettingsRepository::class);
        $repository->expects(self::once())->method('findSingleton')->willReturn($this->rowWithToken('glc_secret'));
        $settings = $this->effectiveOver($repository);

        self::assertSame('https://cloud.example/loki/push', $settings->effectiveLokiPushUrl());
        self::assertSame('tenant42', $settings->lokiUsername());
        self::assertSame('glc_secret', $settings->lokiToken());
    }

    /** The worker calls refresh() every 30 s (#1012); a warm shared pool must keep that off the database. */
    public function testRefreshAloneKeepsServingTheCachedRowWithoutQueryingAgain(): void
    {
        $repository = $this->createMock(GrafanaSettingsRepository::class);
        $repository->expects(self::once())->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $settings = $this->effectiveOver($repository);

        $settings->profilingEnabled();
        $settings->refresh();
        $settings->profilingEnabled();
    }

    public function testForgetStoredSendsTheNextReadBackToTheDatabase(): void
    {
        $repository = $this->createMock(GrafanaSettingsRepository::class);
        $repository->expects(self::exactly(2))->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $settings = $this->effectiveOver($repository);

        $settings->profilingEnabled();
        $settings->forgetStored();
        $settings->profilingEnabled();
    }

    private function row(GrafanaConnection $connection): GrafanaSettingsEntity
    {
        $row = new GrafanaSettingsEntity();
        $row->applyWithoutToken($connection);

        return $row;
    }

    private function rowWithToken(string $token): GrafanaSettingsEntity
    {
        $row = new GrafanaSettingsEntity();
        $row->apply(
            new GrafanaConnection('https://cloud.example/loki/push', 'tenant42', null, null, false),
            $this->cipher()->seal($token),
            substr($token, -4),
        );

        return $row;
    }

    private function effective(
        ?GrafanaSettingsEntity $row,
        GrafanaEnvDefaults $defaults = new GrafanaEnvDefaults('', '', ''),
    ): EffectiveGrafanaSettings {
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        return $this->effectiveOver($repository, $defaults);
    }

    private function effectiveOver(
        GrafanaSettingsRepository $repository,
        GrafanaEnvDefaults $defaults = new GrafanaEnvDefaults('', '', ''),
    ): EffectiveGrafanaSettings {
        return new EffectiveGrafanaSettings(
            $repository,
            $this->cipher(),
            $defaults,
            new GrafanaSettingsCache(new ArrayAdapter()),
        );
    }

    private function cipher(): GrafanaApiKeyCipher
    {
        return new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET));
    }
}
```

`tests/Service/Grafana/GrafanaSettingsTest.php` (rewritten in full; the runtime cases moved to `EffectiveGrafanaSettingsTest`):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Http\Admin\GrafanaSettingsJson;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\EffectiveGrafanaSettings;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettings;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Profiling\NullProfileSampler;
use App\Service\Profiling\ProfileSampler;
use App\Tests\Support\SettingsRequests;
use App\Tests\Support\TrackingProfileSampler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GrafanaSettingsTest extends TestCase
{
    private const string SECRET = 'test-master-secret-at-least-32-chars-long!!';

    private ?GrafanaSettingsEntity $stored = null;

    /**
     * @return array{
     *     lokiPushUrl: string|null, lokiPushUrlDefault: string, lokiPushUrlEffective: string|null,
     *     lokiUsername: string|null, grafanaUrl: string|null, grafanaUrlDefault: string,
     *     grafanaUrlEffective: string|null, hasToken: bool, tokenHint: string, containerPresent: bool,
     *     pyroscopePushUrl: string|null, pyroscopePushUrlDefault: string, pyroscopePushUrlEffective: string|null,
     *     profilingEnabled: bool, profilingContainerPresent: bool, profilerAvailable: bool,
     * }
     */
    private function viewOf(GrafanaSettings $settings): array
    {
        return GrafanaSettingsJson::from($settings->overview());
    }

    public function testASaveIsVisibleToTheNextViewAndTheTokenStaysHidden(): void
    {
        $settings = $this->settings($this->effective());
        self::assertFalse($this->viewOf($settings)['hasToken']);

        $settings->update(SettingsRequests::grafana(
            lokiPushUrl: 'https://cloud.example/loki/push',
            lokiUsername: 'tenant42',
            grafanaUrl: 'https://cloud.example/grafana',
            token: 'glc_secrettoken',
        )->toUpdate());

        $view = $this->viewOf($settings);
        self::assertSame('https://cloud.example/loki/push', $view['lokiPushUrl']);
        self::assertSame('tenant42', $view['lokiUsername']);
        self::assertSame('https://cloud.example/grafana', $view['grafanaUrl']);
        self::assertTrue($view['hasToken']);
        self::assertSame('oken', $view['tokenHint']);
        self::assertArrayNotHasKey('token', $view);
    }

    public function testANullTokenKeepsTheStoredSecret(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://a.example', token: 'glc_first')->toUpdate());

        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://b.example', token: null)->toUpdate());

        $view = $this->viewOf($settings);
        self::assertTrue($view['hasToken']);
        self::assertSame('https://b.example', $view['grafanaUrl']);
        self::assertSame('glc_first', $effective->lokiToken());
    }

    public function testRemoveTokenClearsTheStoredSecretButKeepsTheConnection(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://a.example', token: 'glc_first')->toUpdate());

        $settings->update(
            SettingsRequests::grafana(grafanaUrl: 'https://other.example', removeToken: true)->toUpdate(),
        );

        $view = $this->viewOf($settings);
        self::assertFalse($view['hasToken']);
        self::assertSame('https://other.example', $view['grafanaUrl']);
        self::assertNull($effective->lokiToken());
    }

    public function testRemoveTokenWinsEvenWhenATokenIsAlsoSent(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        $settings->update(SettingsRequests::grafana(token: 'glc_first')->toUpdate());

        $settings->update(SettingsRequests::grafana(token: 'glc_second', removeToken: true)->toUpdate());

        self::assertFalse($this->viewOf($settings)['hasToken']);
        self::assertNull($effective->lokiToken());
    }

    public function testBlankUsernameClearsTheStoredOverrideAndItStartsNull(): void
    {
        $effective = $this->effective();
        $settings = $this->settings($effective);
        self::assertNull($effective->lokiUsername());

        $settings->update(SettingsRequests::grafana(lokiUsername: 'tenant42')->toUpdate());
        $settings->update(SettingsRequests::grafana(lokiUsername: '')->toUpdate());

        self::assertNull($effective->lokiUsername());
    }

    public function testTheViewReportsWhetherTheProfilerIsAvailable(): void
    {
        self::assertFalse($this->viewOf($this->settings($this->effective()))['profilerAvailable']);
        self::assertTrue(
            $this->viewOf($this->settings($this->effective(), new TrackingProfileSampler()))['profilerAvailable'],
        );
    }

    /**
     * The admin form saves in php-fpm; the worker re-checks the toggle in its own process. A save forgets the shared
     * pool, so the worker reads the new row once it refreshes its memo, not the stale cache (#1012).
     */
    public function testAnAdminSaveInvalidatesTheSharedCacheSoTheWorkerSeesTheChange(): void
    {
        $cache = new GrafanaSettingsCache(new ArrayAdapter());
        $webProcess = $this->settings($this->effective($cache));
        $workerProcess = $this->effective($cache);
        self::assertFalse($workerProcess->profilingEnabled());

        $webProcess->update(SettingsRequests::grafana(profilingEnabled: true)->toUpdate());

        $workerProcess->refresh();
        self::assertTrue($workerProcess->profilingEnabled());
    }

    public function testUpdateFlushesTheEntityManager(): void
    {
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(new GrafanaSettingsEntity());
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $settings = new GrafanaSettings(
            $repository,
            $em,
            $this->cipher(),
            $this->effective(),
            new GrafanaEnvDefaults('', '', ''),
            new NullProfileSampler(),
        );

        $settings->update(SettingsRequests::grafana(grafanaUrl: 'https://a.example')->toUpdate());
    }

    private function effective(?GrafanaSettingsCache $cache = null): EffectiveGrafanaSettings
    {
        return new EffectiveGrafanaSettings(
            $this->repository(),
            $this->cipher(),
            new GrafanaEnvDefaults('', '', ''),
            $cache ?? new GrafanaSettingsCache(new ArrayAdapter()),
        );
    }

    private function settings(
        EffectiveGrafanaSettings $effective,
        ProfileSampler $sampler = new NullProfileSampler(),
    ): GrafanaSettings {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof GrafanaSettingsEntity) {
                $this->stored = $entity;
            }
        });

        return new GrafanaSettings(
            $this->repository(),
            $em,
            $this->cipher(),
            $effective,
            new GrafanaEnvDefaults('', '', ''),
            $sampler,
        );
    }

    private function repository(): GrafanaSettingsRepository
    {
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturnCallback(fn (): ?GrafanaSettingsEntity => $this->stored);

        return $repository;
    }

    private function cipher(): GrafanaApiKeyCipher
    {
        return new GrafanaApiKeyCipher(new InstanceSecretCipher(self::SECRET));
    }
}
```

`tests/Service/Grafana/SettingsLokiEndpointTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\EffectiveGrafanaSettings;
use App\Service\Grafana\GrafanaConnection;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Grafana\SettingsLokiEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SettingsLokiEndpointTest extends TestCase
{
    public function testReadsTheEffectiveLokiConnection(): void
    {
        $cipher = new GrafanaApiKeyCipher(new InstanceSecretCipher('test-master-secret-at-least-32-chars-long!!'));
        $row = new GrafanaSettingsEntity();
        $row->apply(new GrafanaConnection(null, 'tenant42', null, null, false), $cipher->seal('secret'), 'cret');
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturn($row);

        $endpoint = new SettingsLokiEndpoint(new EffectiveGrafanaSettings(
            $repository,
            $cipher,
            new GrafanaEnvDefaults('http://loki:3100/loki/api/v1/push', '', ''),
            new GrafanaSettingsCache(new ArrayAdapter()),
        ));

        self::assertSame('http://loki:3100/loki/api/v1/push', $endpoint->pushUrl());
        self::assertSame('tenant42', $endpoint->username());
        self::assertSame('secret', $endpoint->token());
    }
}
```

`tests/Service/Grafana/SettingsPyroscopeEndpointTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Grafana;

use App\Repository\GrafanaSettingsRepository;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Grafana\EffectiveGrafanaSettings;
use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Grafana\GrafanaSettingsCache;
use App\Service\Grafana\SettingsPyroscopeEndpoint;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SettingsPyroscopeEndpointTest extends TestCase
{
    public function testReadsTheEffectivePushUrl(): void
    {
        self::assertSame('http://pyroscope:4040', $this->endpoint('http://pyroscope:4040')->pushUrl());
    }

    public function testReturnsNullWhenNoPushUrlIsConfigured(): void
    {
        self::assertNull($this->endpoint('')->pushUrl());
    }

    private function endpoint(string $pyroscopeDefault): SettingsPyroscopeEndpoint
    {
        $repository = $this->createStub(GrafanaSettingsRepository::class);
        $repository->method('findSingleton')->willReturn(null);

        return new SettingsPyroscopeEndpoint(new EffectiveGrafanaSettings(
            $repository,
            new GrafanaApiKeyCipher(new InstanceSecretCipher('test-master-secret-at-least-32-chars-long!!')),
            new GrafanaEnvDefaults('', '', $pyroscopeDefault),
            new GrafanaSettingsCache(new ArrayAdapter()),
        ));
    }
}
```

`tests/Service/Profiling/ProfilingPolicyTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\CollapsedProfile;
use App\Service\Profiling\ProfileSampler;
use App\Service\Profiling\ProfilingConfigSource;
use App\Service\Profiling\ProfilingPolicy;
use App\Service\Profiling\PyroscopeEndpoint;
use PHPUnit\Framework\TestCase;

final class ProfilingPolicyTest extends TestCase
{
    public function testEnabledWhenAvailableAndOnWithAUrl(): void
    {
        $policy = $this->policy(available: true, enabled: true, url: 'http://pyroscope:4040');

        self::assertTrue($policy->isEnabled());
    }

    public function testDisabledWhenTheSamplerIsUnavailableAndTheConfigIsNeverRead(): void
    {
        $config = $this->createMock(ProfilingConfigSource::class);
        $config->expects(self::never())->method('profilingEnabled');
        $policy = new ProfilingPolicy($config, $this->sampler(false), $this->endpoint('http://pyroscope:4040'));

        self::assertFalse($policy->isEnabled());
    }

    public function testDisabledWhenOnButThePushUrlIsNull(): void
    {
        self::assertFalse($this->policy(available: true, enabled: true, url: null)->isEnabled());
    }

    public function testDisabledWhenOffEvenWithAPushUrl(): void
    {
        self::assertFalse($this->policy(available: true, enabled: false, url: 'http://pyroscope:4040')->isEnabled());
    }

    public function testAConfigThatCannotBeReadMeansProfilingIsOff(): void
    {
        $config = $this->createStub(ProfilingConfigSource::class);
        $config->method('profilingEnabled')->willThrowException(new \RuntimeException('database gone'));
        $policy = new ProfilingPolicy($config, $this->sampler(true), $this->endpoint('http://pyroscope:4040'));

        self::assertFalse($policy->isEnabled());
    }

    public function testAPushUrlThatCannotBeReadMeansProfilingIsOff(): void
    {
        $endpoint = new class implements PyroscopeEndpoint {
            public function pushUrl(): ?string
            {
                throw new \RuntimeException('cache gone');
            }
        };
        $policy = new ProfilingPolicy($this->config(true), $this->sampler(true), $endpoint);

        self::assertFalse($policy->isEnabled());
    }

    private function policy(bool $available, bool $enabled, ?string $url): ProfilingPolicy
    {
        return new ProfilingPolicy($this->config($enabled), $this->sampler($available), $this->endpoint($url));
    }

    private function config(bool $enabled): ProfilingConfigSource
    {
        $config = $this->createStub(ProfilingConfigSource::class);
        $config->method('profilingEnabled')->willReturn($enabled);

        return $config;
    }

    private function sampler(bool $available): ProfileSampler
    {
        return new class ($available) implements ProfileSampler {
            public function __construct(private readonly bool $available)
            {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function start(float $periodSeconds): void
            {
            }

            public function stop(): ?CollapsedProfile
            {
                return null;
            }

            public function isRunning(): bool
            {
                return false;
            }
        };
    }

    private function endpoint(?string $url): PyroscopeEndpoint
    {
        return new class ($url) implements PyroscopeEndpoint {
            public function __construct(private readonly ?string $url)
            {
            }

            public function pushUrl(): ?string
            {
                return $this->url;
            }
        };
    }
}
```
`policy()` and `config()` take a `bool` that is test data, not a behaviour switch. The same holds at `89c7e17f` and in #1167 A8's scope rule ("A8 takes only the test helpers that branch on their `bool`").

The two listener tests double the new interface:
```bash
perl -0pi -e 's/use App\\Service\\Grafana\\GrafanaSettings;\n//; s/(use App\\Service\\Profiling\\ProfileSampler;\n)/$1use App\\Service\\Profiling\\ProfilingConfigSource;\n/; s/\bGrafanaSettings::class\b/ProfilingConfigSource::class/g; s/\): GrafanaSettings\n/): ProfilingConfigSource\n/g' tests/EventListener/WorkerProfilingListenerTest.php tests/EventListener/RequestProfilingListenerTest.php
git grep -n "GrafanaSettings" -- tests/EventListener
git grep -c "use App\\\\Service\\\\Profiling\\\\ProfilingConfigSource;" -- tests/EventListener
```
Expected:
- The first grep prints nothing.
- The second prints `1` for each file.

`WorkerProfilingListenerTest`'s stub stubs `refresh` and `profilingEnabled`, and both are interface methods. Its `new WorkerProfilingListener($policy, $sampler, $client, $clock, $settings)` stays positional.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Grafana tests/Service/Profiling tests/EventListener`
Expected: FAIL. `EffectiveGrafanaSettings` and `ProfilingConfigSource` do not exist.

- [ ] **Step 3: The profiling-owned interface and the runtime reader**

`src/Service/Profiling/ProfilingConfigSource.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Profiling;

interface ProfilingConfigSource
{
    public function profilingEnabled(): bool;

    /** Drops what this process has read, so the next read sees a save made by another process. */
    public function refresh(): void;
}
```

`src/Service/Grafana/EffectiveGrafanaSettings.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfilingConfigSource;

final class EffectiveGrafanaSettings implements ProfilingConfigSource
{
    private ?GrafanaSettingsSnapshot $memoised = null;

    public function __construct(
        private readonly GrafanaSettingsRepository $repository,
        private readonly GrafanaApiKeyCipher $cipher,
        private readonly GrafanaEnvDefaults $defaults,
        private readonly GrafanaSettingsCache $cache,
    ) {
    }

    public function stored(): GrafanaSettingsSnapshot
    {
        return $this->memoised ??= $this->cache->remember($this->loadSingleton(...));
    }

    public function refresh(): void
    {
        $this->memoised = null;
    }

    public function forgetStored(): void
    {
        $this->cache->forget();
        $this->refresh();
    }

    public function effectiveLokiPushUrl(): ?string
    {
        return $this->stored()->connection->lokiPushUrl ?? $this->defaultOrNull($this->defaults->lokiPushUrl);
    }

    public function effectivePyroscopePushUrl(): ?string
    {
        return $this->stored()->connection->pyroscopePushUrl
            ?? $this->defaultOrNull($this->defaults->pyroscopePushUrl);
    }

    public function profilingEnabled(): bool
    {
        return $this->stored()->connection->profilingEnabled;
    }

    public function lokiUsername(): ?string
    {
        return $this->stored()->connection->lokiUsername;
    }

    public function lokiToken(): ?string
    {
        $stored = $this->stored();

        return $stored->hasToken() ? $this->cipher->open($stored->sealedToken) : null;
    }

    private function loadSingleton(): GrafanaSettingsSnapshot
    {
        return GrafanaSettingsSnapshot::fromEntity($this->repository->findSingleton() ?? new GrafanaSettingsEntity());
    }

    private function defaultOrNull(string $default): ?string
    {
        return '' === $default ? null : $default;
    }
}
```

`config/services.yaml`, add after `App\Service\Profiling\PyroscopeEndpoint: '@App\Service\Grafana\SettingsPyroscopeEndpoint'`:
```yaml

    App\Service\Profiling\ProfilingConfigSource: '@App\Service\Grafana\EffectiveGrafanaSettings'
```

- [ ] **Step 4: The admin service, the adapters and the profiling consumers**

`src/Service/Grafana/GrafanaSettings.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Grafana;

use App\Entity\GrafanaSettings as GrafanaSettingsEntity;
use App\Repository\GrafanaSettingsRepository;
use App\Service\Grafana\Crypto\GrafanaApiKeyCipher;
use App\Service\Profiling\ProfileSampler;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GrafanaSettings
{
    public function __construct(
        private GrafanaSettingsRepository $repository,
        private EntityManagerInterface $em,
        private GrafanaApiKeyCipher $cipher,
        private EffectiveGrafanaSettings $effective,
        private GrafanaEnvDefaults $defaults,
        private ProfileSampler $sampler,
    ) {
    }

    public function overview(): GrafanaSettingsOverview
    {
        return new GrafanaSettingsOverview($this->effective->stored(), $this->defaults, $this->sampler->isAvailable());
    }

    public function update(GrafanaSettingsUpdate $update): void
    {
        $settings = $this->repository->findSingleton();
        if (null === $settings) {
            $settings = new GrafanaSettingsEntity();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
        $this->effective->forgetStored();
    }

    private function apply(GrafanaSettingsUpdate $update, GrafanaSettingsEntity $settings): void
    {
        $replacement = $update->token->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement), $this->hint($replacement));

            return;
        }

        $settings->applyWithoutToken($update->connection);
        if ($update->token->isRemoval()) {
            $settings->clearStoredToken();
        }
    }

    private function hint(string $token): string
    {
        return substr($token, -4);
    }
}
```

`src/Service/Grafana/SettingsLokiEndpoint.php` and `src/Service/Grafana/SettingsPyroscopeEndpoint.php`: in each, replace `private GrafanaSettings $settings` with `private EffectiveGrafanaSettings $settings`. Both files are in the same namespace, so no import is needed.

`src/Service/Profiling/ProfilingPolicy.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Profiling;

final readonly class ProfilingPolicy
{
    public function __construct(
        private ProfilingConfigSource $config,
        private ProfileSampler $sampler,
        private PyroscopeEndpoint $endpoint,
    ) {
    }

    public function isEnabled(): bool
    {
        if (!$this->sampler->isAvailable()) {
            return false;
        }
        try {
            return $this->config->profilingEnabled() && null !== $this->endpoint->pushUrl();
        } catch (\Throwable) {
            // Unreadable config means profiling is off: this runs on every request and must never fail one.
            return false;
        }
    }
}
```

`src/EventListener/WorkerProfilingListener.php`:
- Replace `use App\Service\Grafana\GrafanaSettings;` with nothing (delete the line). Add `use App\Service\Profiling\ProfilingConfigSource;` after `use App\Service\Profiling\ProfileSampler;`.
- Replace `private readonly GrafanaSettings $settings,` with `private readonly ProfilingConfigSource $profilingConfig,`.
- Replace `$this->settings->refresh();` with `$this->profilingConfig->refresh();`.

Docblocks:
- `src/Service/Grafana/GrafanaEnvDefaults.php`: replace `GrafanaSettings falls back to these.` with `EffectiveGrafanaSettings falls back to these.`
- `src/Service/Grafana/GrafanaSettingsCache.php`: replace the whole class docblock, from `/**` down to ` */` above `final readonly class GrafanaSettingsCache`, with:
```php
/**
 * Shares the Grafana row between processes: php-fpm saves the admin form while the long-running worker re-checks the
 * profiling toggle, so the invalidation must cross the process boundary (#1012). The lifetime is only a backstop.
 */
```
- `src/Repository/GrafanaSettingsRepository.php`: replace
```php
 * Not final: GrafanaSettings unit-tests against a mock of this repository
 * rather than a real database, so it needs to stay doubleable.
```
with
```php
 * Not final: the Grafana settings tests stub it instead of using a database.
```

- [ ] **Step 5: Verify the cycle is gone**

```bash
git grep -n 'App\\Service\\Grafana' -- src/Service/Profiling src/EventListener
git grep -nE '^(readonly )?class ' -- src/Service/Grafana src/Service/Profiling
```
Expected: both print nothing.

- [ ] **Step 6: Run the tests to verify they pass**

Run:
```bash
php bin/phpunit tests/Service/Grafana tests/Service/Profiling tests/EventListener tests/Functional/RequestProfilingTest.php tests/Controller/Admin/AdminGrafanaControllerTest.php tests/Http/Admin/GrafanaSettingsJsonTest.php tests/Service/Logging
php bin/console lint:container
```
Expected: PASS.

- [ ] **Step 7: Deletion checks**

Restore each by hand before the next.
1. In `EffectiveGrafanaSettings::forgetStored()`, delete `$this->cache->forget();`. Expected: `GrafanaSettingsTest::testAnAdminSaveInvalidatesTheSharedCacheSoTheWorkerSeesTheChange` and `EffectiveGrafanaSettingsTest::testForgetStoredSendsTheNextReadBackToTheDatabase` fail.
2. In `forgetStored()`, delete `$this->refresh();`. Expected: `GrafanaSettingsTest::testASaveIsVisibleToTheNextViewAndTheTokenStaysHidden` fails. The view was read before the save, so the memo would hold the old row.
3. In `ProfilingPolicy::isEnabled()`, remove the `try`/`catch` and keep the `return`. Expected: `testAConfigThatCannotBeReadMeansProfilingIsOff` and `testAPushUrlThatCannotBeReadMeansProfilingIsOff` error.
4. In `WorkerProfilingListener::refreshToggle()`, delete `$this->profilingConfig->refresh();`. Expected: the `WorkerProfilingListenerTest` case that asserts the `refresh` → `profilingEnabled` order fails.

- [ ] **Step 8: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on every changed file.
Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add src/Service/Profiling/ProfilingConfigSource.php src/Service/Grafana/EffectiveGrafanaSettings.php src/Service/Grafana/GrafanaSettings.php src/Service/Grafana/SettingsLokiEndpoint.php src/Service/Grafana/SettingsPyroscopeEndpoint.php src/Service/Profiling/ProfilingPolicy.php src/EventListener/WorkerProfilingListener.php src/Service/Grafana/GrafanaEnvDefaults.php src/Service/Grafana/GrafanaSettingsCache.php src/Repository/GrafanaSettingsRepository.php config/services.yaml tests/Service/Grafana/EffectiveGrafanaSettingsTest.php tests/Service/Grafana/GrafanaSettingsTest.php tests/Service/Grafana/SettingsLokiEndpointTest.php tests/Service/Grafana/SettingsPyroscopeEndpointTest.php tests/Service/Profiling/ProfilingPolicyTest.php tests/EventListener/WorkerProfilingListenerTest.php tests/EventListener/RequestProfilingListenerTest.php
git commit -m "refactor(#1159): profiling reads its config through its own interface; the grafana admin is admin only"
```

---

### Task B4: Mail read side: `MailSettingsSnapshot`, entity-free overview

**Files:**
- Create: `src/Service/Mail/Settings/MailSettingsSnapshot.php`
- Modify: `src/Service/Mail/Settings/MailSettingsOverview.php` (rewritten)
- Modify: `src/Service/Mail/Settings/MailSettings.php` (`overview()`)
- Modify: `src/Http/Admin/MailSettingsJson.php` (`from()`)
- Test: `tests/Service/Mail/Settings/MailSettingsSnapshotTest.php` (new)
- Test: `tests/Http/Admin/MailSettingsJsonTest.php` (rewritten)

**Interfaces:**
- Produces:
  - `final readonly class App\Service\Mail\Settings\MailSettingsSnapshot { public MailConnection $connection; public bool $hasPassword; public static function fromEntity(MailServerSettings): self }`.
  - `MailSettingsOverview(?MailSettingsSnapshot $saved, MailConnection $fallback, ?ProxyConnection $proxy)`. `$saved` stays nullable on purpose: no row means the env fallback seeds the form. The `AutowireWrongClass` suppression goes.
  - `MailSettingsJson::from()` reads the snapshot. `App\Http` no longer receives a `MailServerSettings`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Mail/Settings/MailSettingsSnapshotTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Enum\MailEncryption;
use App\Service\Crypto\SealedSecret;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettingsSnapshot;
use PHPUnit\Framework\TestCase;

final class MailSettingsSnapshotTest extends TestCase
{
    public function testItCarriesTheRowsConnectionAndWhetherAPasswordIsStored(): void
    {
        $connection = new MailConnection(
            true,
            'smtp.row.test',
            465,
            'user',
            MailEncryption::Tls,
            'a@row.test',
            'Row',
            true,
        );
        $row = new MailServerSettings();
        $row->apply($connection, new SealedSecret('Y2lwaGVy', 'bm9uY2U=', 'c2FsdA==', 1));

        $snapshot = MailSettingsSnapshot::fromEntity($row);

        self::assertEquals($connection, $snapshot->connection);
        self::assertTrue($snapshot->hasPassword);
    }

    public function testARowWithoutAPasswordSaysSo(): void
    {
        $row = new MailServerSettings();
        $row->applyWithoutPassword(
            new MailConnection(false, 'smtp.row.test', 587, null, MailEncryption::None, '', ''),
        );

        self::assertFalse(MailSettingsSnapshot::fromEntity($row)->hasPassword);
    }
}
```

`tests/Http/Admin/MailSettingsJsonTest.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Http\Admin;

use App\Enum\MailEncryption;
use App\Enum\ProxyType;
use App\Http\Admin\MailSettingsJson;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettingsOverview;
use App\Service\Mail\Settings\MailSettingsSnapshot;
use App\Service\Proxy\ProxyConnection;
use PHPUnit\Framework\TestCase;

final class MailSettingsJsonTest extends TestCase
{
    public function testWithNoRowThePayloadIsSeededFromTheEnvFallbackWithoutAPassword(): void
    {
        $fallback = new MailConnection(true, 'smtp.env.test', 2525, 'env-user', MailEncryption::Tls, 'a@env', 'Env');

        self::assertSame([
            'enabled' => true,
            'host' => 'smtp.env.test',
            'port' => 2525,
            'username' => 'env-user',
            'encryption' => 'tls',
            'fromAddress' => 'a@env',
            'fromName' => 'Env',
            'hasPassword' => false,
            'hasSavedConfig' => false,
            'envFallbackConfigured' => true,
            'useProxy' => false,
            'proxyConfigured' => false,
            'proxyLabel' => '',
        ], MailSettingsJson::from(new MailSettingsOverview(null, $fallback, null)));
    }

    public function testProxyAvailabilityIsExposedWhenAProxyIsConfigured(): void
    {
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');
        $proxy = new ProxyConnection(true, true, ProxyType::Socks5, 'proxy.example', 1080, null, true);

        $payload = MailSettingsJson::from(new MailSettingsOverview(null, $fallback, $proxy));

        self::assertTrue($payload['proxyConfigured']);
        self::assertSame('SOCKS5 · proxy.example:1080', $payload['proxyLabel']);
        self::assertFalse($payload['useProxy']);
    }

    public function testWithARowThePayloadIsTheRowPlusTheFallbackFlag(): void
    {
        $saved = new MailSettingsSnapshot(
            new MailConnection(false, 'smtp.row.test', 465, null, MailEncryption::None, 'a@row', 'Row'),
            true,
        );
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');

        $payload = MailSettingsJson::from(new MailSettingsOverview($saved, $fallback, null));

        self::assertArrayNotHasKey('passwordHint', $payload);
        self::assertSame([
            'enabled' => false,
            'host' => 'smtp.row.test',
            'port' => 465,
            'username' => null,
            'encryption' => 'none',
            'fromAddress' => 'a@row',
            'fromName' => 'Row',
            'hasPassword' => true,
            'hasSavedConfig' => true,
            'envFallbackConfigured' => false,
            'useProxy' => false,
            'proxyConfigured' => false,
            'proxyLabel' => '',
        ], $payload);
    }

    public function testASavedRowThatRoutesThroughTheProxySaysSo(): void
    {
        $saved = new MailSettingsSnapshot(
            new MailConnection(true, 'smtp.gmail.com', 587, 'alice', MailEncryption::Starttls, 'a@row', 'Row', true),
            false,
        );
        $fallback = new MailConnection(false, '', 587, null, MailEncryption::Starttls, '', '');
        $proxy = new ProxyConnection(false, true, ProxyType::Http, 'proxy.example', 3128, null);

        $payload = MailSettingsJson::from(new MailSettingsOverview($saved, $fallback, $proxy));

        self::assertTrue($payload['useProxy']);
        self::assertFalse($payload['hasPassword']);
        self::assertTrue($payload['proxyConfigured']);
        self::assertSame('HTTP · proxy.example:3128', $payload['proxyLabel']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Mail/Settings/MailSettingsSnapshotTest.php tests/Http/Admin/MailSettingsJsonTest.php`
Expected: FAIL. `MailSettingsSnapshot` does not exist.

- [ ] **Step 3: Implement**

`src/Service/Mail/Settings/MailSettingsSnapshot.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailServerSettings;

final readonly class MailSettingsSnapshot
{
    public function __construct(
        public MailConnection $connection,
        public bool $hasPassword,
    ) {
    }

    public static function fromEntity(MailServerSettings $settings): self
    {
        return new self($settings->connection(), $settings->hasPassword());
    }
}
```

`src/Service/Mail/Settings/MailSettingsOverview.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Service\Proxy\ProxyConnection;

final readonly class MailSettingsOverview
{
    public function __construct(
        public ?MailSettingsSnapshot $saved,
        public MailConnection $fallback,
        public ?ProxyConnection $proxy,
    ) {
    }
}
```

`src/Service/Mail/Settings/MailSettings.php`: replace A1's `overview()`
```php
    public function overview(): MailSettingsOverview
    {
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverview(
            $this->repository->findSingleton(),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }
```
with
```php
    public function overview(): MailSettingsOverview
    {
        $saved = $this->repository->findSingleton();
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverview(
            null === $saved ? null : MailSettingsSnapshot::fromEntity($saved),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }
```

`src/Http/Admin/MailSettingsJson.php`: replace the body of `from()`, from `$settings = $overview->saved;` down to its closing `];`, with:
```php
        $saved = $overview->saved;
        $fallback = $overview->fallback;
        $proxy = $overview->proxy;
        $connection = $saved?->connection ?? $fallback;

        return [
            'enabled' => $connection->enabled,
            'host' => $connection->host,
            'port' => $connection->port,
            'username' => $connection->username,
            'encryption' => $connection->encryption->value,
            'fromAddress' => $connection->fromAddress,
            'fromName' => $connection->fromName,
            'hasPassword' => $saved?->hasPassword ?? false,
            'hasSavedConfig' => null !== $saved,
            'envFallbackConfigured' => $fallback->enabled,
            'useProxy' => $saved?->connection->useProxy ?? false,
            'proxyConfigured' => null !== $proxy,
            'proxyLabel' => null !== $proxy
                ? \sprintf('%s · %s:%d', $proxy->type->value, $proxy->host, $proxy->port)
                : '',
        ];
```
The class docblock and its `@phpstan-type MailSettingsPayload` stay as they are.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Mail tests/Http/Admin/MailSettingsJsonTest.php tests/Controller/Admin/AdminMailControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Deletion checks**

Restore each by hand before the next.
1. In `MailSettingsJson::from()`, make `'useProxy' => false`. Expected: `testASavedRowThatRoutesThroughTheProxySaysSo` fails.
2. In `MailSettingsSnapshot::fromEntity()`, pass `true` for `hasPassword`. Expected: `testARowWithoutAPasswordSaysSo` fails.

- [ ] **Step 6: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on the changed files.
Expected: clean, with no `AutowireWrongClass` on `MailSettingsOverview`. If PhpStorm raises one on `MailSettingsSnapshot $saved`, stop and report. Do not suppress it.
Then run `git grep -n "AutowireWrongClass" -- src/Service/Grafana src/Service/Mail src/Service/Proxy`.
Expected: nothing.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Mail/Settings/MailSettingsSnapshot.php src/Service/Mail/Settings/MailSettingsOverview.php src/Service/Mail/Settings/MailSettings.php src/Http/Admin/MailSettingsJson.php tests/Service/Mail/Settings/MailSettingsSnapshotTest.php tests/Http/Admin/MailSettingsJsonTest.php
git commit -m "refactor(#1159): the mail read side is a snapshot, not an entity"
```

---

### Task B5: `MailSettingsUpdate`

**Files:**
- Create: `src/Service/Mail/Settings/MailSettingsUpdate.php`
- Modify: `src/Dto/Admin/MailSettingsRequest.php` (rewritten: #1167 A7b's version plus `toUpdate()`)
- Modify: `src/Service/Mail/Settings/MailSettings.php` (rewritten)
- Modify: `src/Controller/Admin/AdminMailController.php` (one line)
- Test: `tests/Dto/Admin/MailSettingsRequestTest.php` (five tests)
- Test (perl, `->toUpdate()` appended): `tests/Service/Mail/Settings/MailSettingsTest.php`, `tests/Service/Mail/Settings/MailConnectionTesterTest.php`, `tests/Service/Mail/Transport/DynamicMailTransportTest.php`

**Interfaces:**
- Consumes: `SecretChange` (A2).
- Produces:
  - `final readonly class App\Service\Mail\Settings\MailSettingsUpdate { public MailConnection $connection; public SecretChange $password }`.
  - `MailSettingsRequest::toUpdate(): MailSettingsUpdate`.
  - `MailSettings::update(MailSettingsUpdate $update): void`.
- The three guards read the update value. Their outcomes are unchanged:
  - Enabling with no host and no env transport is refused.
  - An enabled, authenticated row with no stored or sent password is refused, and so is `removePassword` on such a row.
  - `useProxy` with no proxy configured is refused.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Admin/MailSettingsRequestTest.php` (post-#1167):
- Add after `use App\Dto\Admin\MailSettingsRequest;`:
```php
use App\Enum\MailEncryption;
use App\Service\Crypto\SecretChange;
use App\Service\Mail\Settings\MailConnection;
```
- Add before the class's closing `}`:
```php
    public function testToUpdateCarriesTheConnection(): void
    {
        $update = SettingsRequests::mail(
            enabled: true,
            host: 'smtp.example',
            port: 465,
            username: 'user',
            encryption: 'tls',
            fromAddress: 'noreply@example.com',
            fromName: 'Example',
            useProxy: true,
        )->toUpdate();

        self::assertEquals(
            new MailConnection(
                true,
                'smtp.example',
                465,
                'user',
                MailEncryption::Tls,
                'noreply@example.com',
                'Example',
                true,
            ),
            $update->connection,
        );
    }

    public function testToUpdateTurnsABlankUsernameIntoNone(): void
    {
        self::assertNull(SettingsRequests::mail(host: 'smtp.example', username: '')->toUpdate()->connection->username);
    }

    public function testNoPasswordKeepsTheStoredOne(): void
    {
        self::assertEquals(SecretChange::keep(), SettingsRequests::mail(host: 'smtp.example')->toUpdate()->password);
    }

    public function testAPasswordReplacesTheStoredOne(): void
    {
        $update = SettingsRequests::mail(host: 'smtp.example', password: 'sw0rdfish')->toUpdate();

        self::assertEquals(SecretChange::replaceWith('sw0rdfish'), $update->password);
    }

    public function testRemovePasswordWinsOverASentPassword(): void
    {
        $update = SettingsRequests::mail(host: 'smtp.example', password: 'sw0rdfish', removePassword: true)->toUpdate();

        self::assertEquals(SecretChange::remove(), $update->password);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Dto/Admin/MailSettingsRequestTest.php`
Expected: FAIL. `toUpdate()` is undefined.

- [ ] **Step 3: Implement**

`src/Service/Mail/Settings/MailSettingsUpdate.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Service\Crypto\SecretChange;

final readonly class MailSettingsUpdate
{
    public function __construct(
        public MailConnection $connection,
        public SecretChange $password,
    ) {
    }
}
```

`src/Dto/Admin/MailSettingsRequest.php` (rewritten in full; the constructor and docblock are #1167 A7b's, unchanged):
```php
<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Enum\MailEncryption;
use App\Http\FullReplacePayload;
use App\Service\Crypto\SecretChange;
use App\Service\Mail\Settings\MailConnection;
use App\Service\Mail\Settings\MailSettingsUpdate;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every setting is required: its controller maps it with {@see FullReplacePayload::CONTEXT}, without which a
 * missing nullable setting reads as null. The password is an optional three-state intent: null keeps the stored
 * secret, a string replaces it, `removePassword` clears it.
 *
 * @SuppressWarnings("PHPMD.ExcessiveParameterList") pure data carrier that
 * mirrors the admin mail form field-for-field, not a behavioural method.
 */
final readonly class MailSettingsRequest
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled,
        #[Assert\Length(max: 255)]
        public string $host,
        #[Assert\Range(min: 1, max: 65535)]
        public int $port,
        #[Assert\Length(max: 255)]
        public ?string $username,
        #[Assert\Choice(choices: [
            MailEncryption::None->value,
            MailEncryption::Starttls->value,
            MailEncryption::Tls->value,
        ])]
        public string $encryption,
        #[Assert\Length(max: 255)]
        #[Assert\Email]
        public string $fromAddress,
        #[Assert\Length(max: 255)]
        public string $fromName,
        #[Assert\Type('bool')]
        public bool $useProxy,
        #[Assert\Length(max: 512)]
        public ?string $password = null,
        #[Assert\Type('bool')]
        public bool $removePassword = false,
    ) {
    }

    public function toUpdate(): MailSettingsUpdate
    {
        return new MailSettingsUpdate(
            new MailConnection(
                $this->enabled,
                $this->host,
                $this->port,
                '' === $this->username ? null : $this->username,
                MailEncryption::from($this->encryption),
                $this->fromAddress,
                $this->fromName,
                $this->useProxy,
            ),
            $this->passwordChange(),
        );
    }

    private function passwordChange(): SecretChange
    {
        if ($this->removePassword) {
            return SecretChange::remove();
        }

        return null === $this->password ? SecretChange::keep() : SecretChange::replaceWith($this->password);
    }
}
```
The constructor, the class docblock (with its PHPMD suppression) and the `App\Http\FullReplacePayload` import are #1167 A7b's as landed at `89c7e17f`; only the three new imports and the two methods are this task's.

`src/Service/Mail/Settings/MailSettings.php` (rewritten in full; B6 removes the runtime half):
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;

readonly class MailSettings
{
    public function __construct(
        private MailServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
        private ProxySettings $proxySettings,
    ) {
    }

    public function overview(): MailSettingsOverview
    {
        $saved = $this->repository->findSingleton();
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverview(
            null === $saved ? null : MailSettingsSnapshot::fromEntity($saved),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }

    public function resetToEnvironment(): void
    {
        $settings = $this->repository->findSingleton();
        if (null !== $settings) {
            $this->em->remove($settings);
            $this->em->flush();
        }
    }

    public function update(MailSettingsUpdate $update): void
    {
        $existing = $this->repository->findSingleton();
        $this->guardAgainstEnablingWithoutATransport($update->connection);
        $this->guardAgainstIncompleteAuthenticatedRow($update, $existing);
        $this->guardAgainstProxyRoutingWithoutAProxy($update->connection);

        $settings = $existing;
        if (null === $settings) {
            $settings = new MailServerSettings();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
    }

    /** The saved SMTP transport regardless of the enable switch — the tester and
     *  the dynamic transport resolve this. Null when nothing usable is saved. */
    public function configuredTransport(): ?ResolvedMailTransport
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ResolvedMailTransport(
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->getEncryption(),
            $settings->usesProxy(),
        );
    }

    public function activeTransportDsnFallback(): string
    {
        return $this->fallback->transportDsn();
    }

    public function hasEnvFallback(): bool
    {
        return $this->fallback->connection()->enabled;
    }

    public function identity(): MailIdentity
    {
        $settings = $this->repository->findSingleton();

        if (null !== $settings && '' !== $settings->getFromAddress()) {
            return new MailIdentity($settings->getFromAddress(), $settings->getFromName());
        }

        return $this->fallback->identity();
    }

    public function isSendingEnabled(): bool
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings ? $settings->isEnabled() : $this->fallback->connection()->enabled;
    }

    private function guardAgainstIncompleteAuthenticatedRow(
        MailSettingsUpdate $update,
        ?MailServerSettings $existing,
    ): void {
        $password = $update->password;
        $willHavePassword = !$password->isRemoval()
            && (null !== $password->replacement() || ($existing?->hasPassword() ?? false));
        $connection = $update->connection;
        $isAuthenticatedTransport = $connection->enabled
            && '' !== $connection->host
            && null !== $connection->username;

        if ($isAuthenticatedTransport && !$willHavePassword) {
            throw IncompleteMailConfigurationException::passwordMissing();
        }
    }

    private function guardAgainstEnablingWithoutATransport(MailConnection $connection): void
    {
        if ($connection->enabled && '' === $connection->host && !$this->fallback->connection()->enabled) {
            throw IncompleteMailConfigurationException::transportMissing();
        }
    }

    private function guardAgainstProxyRoutingWithoutAProxy(MailConnection $connection): void
    {
        if ($connection->useProxy && !$this->proxySettings->current()->isConfigured()) {
            throw IncompleteMailConfigurationException::proxyMissing();
        }
    }

    private function apply(MailSettingsUpdate $update, MailServerSettings $settings): void
    {
        $replacement = $update->password->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement));

            return;
        }

        $settings->applyWithoutPassword($update->connection);
        if ($update->password->isRemoval()) {
            $settings->clearStoredPassword();
        }
    }
}
```
The class stays non-final `readonly` until B6, because a dozen tests still stub it. Its docblock goes: after B6 the claim "the rest of the app depends on this" is false.

`src/Controller/Admin/AdminMailController.php`: replace `$this->settings->update($request);` with `$this->settings->update($request->toUpdate());`.

- [ ] **Step 4: Move the service tests to the update value**

```bash
perl -0pi -e 's/(SettingsRequests::mail(\((?:[^()]++|(?2))*\)))/$1->toUpdate()/g' tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php
perl -0ne 'while (/(SettingsRequests::mail(\((?:[^()]++|(?2))*\)))(?!->toUpdate\(\))/g) { print "$ARGV: $1\n" }' tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php
```
Expected: the second command prints nothing.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Dto/Admin/MailSettingsRequestTest.php tests/Service/Mail tests/Controller/Admin/AdminMailControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Deletion checks**

Restore each by hand before the next.
1. In `guardAgainstIncompleteAuthenticatedRow()`, drop `!$password->isRemoval() && `. Expected: `MailSettingsTest::testRemovingThePasswordOfAnEnabledAuthenticatedRowIsRejected` fails.
2. In `MailSettingsRequest::toUpdate()`, pass `$this->username` unwrapped. Expected: `testToUpdateTurnsABlankUsernameIntoNone` fails.
3. In `MailSettings::apply()`, delete the `isRemoval()` block. Expected: `MailSettingsTest::testRemovePasswordClearsTheStoredSecret` fails.

- [ ] **Step 7: Gates**

Run `composer check`, `composer md` and PhpStorm `lint_files` on the changed files.
Expected: clean.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Mail/Settings/MailSettingsUpdate.php src/Dto/Admin/MailSettingsRequest.php src/Service/Mail/Settings/MailSettings.php src/Controller/Admin/AdminMailController.php tests/Dto/Admin/MailSettingsRequestTest.php tests/Service/Mail/Settings/MailSettingsTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php tests/Service/Mail/Transport/DynamicMailTransportTest.php
git commit -m "refactor(#1159): the mail admin takes an update value the request builds"
```

---

### Task B6: `MailSendingSettings` / `EffectiveMailSettings`

**Files:**
- Create: `src/Service/Mail/MailSendingSettings.php`
- Create: `src/Service/Mail/Settings/EffectiveMailSettings.php`
- Modify: `src/Service/Mail/Settings/MailSettings.php` (rewritten: admin only, `final readonly`)
- Modify: `src/Service/Mail/MailCapability.php`, `src/Service/Mail/AccountMailer.php`, `src/Service/Mail/Digest/DigestMailBuilder.php`, `src/Service/Mail/Transport/DynamicMailTransport.php`, `src/Service/Mail/Settings/MailConnectionTester.php` (import and constructor type)
- Modify: `src/Http/Admin/InstanceSettingsJson.php` (one docblock word)
- Modify: `config/services.yaml` (one alias), `config/services_test.yaml` (one comment word)
- Test: `tests/Service/Mail/Settings/EffectiveMailSettingsTest.php` (new)
- Test: `tests/Service/Mail/Settings/MailSettingsTest.php` (rewritten)
- Test: `tests/Service/Mail/Settings/MailConnectionTesterTest.php` (`testerWithHealth()` and one import)
- Test: `tests/Service/Mail/MailCapabilityWiringTest.php` (docblock), `tests/Support/EnablesMailInTests.php` (docblock)
- Test (perl): the 13 files in Step 5

**Interfaces:**
- Consumes: `MailSettingsSnapshot` (B4), `MailSettingsUpdate` (B5), `ProxySettings::current()` (A1).
- Produces:
  - `interface App\Service\Mail\MailSendingSettings`:
    - `isSendingEnabled(): bool`,
    - `identity(): MailIdentity`,
    - `configuredTransport(): ?ResolvedMailTransport`,
    - `activeTransportDsnFallback(): string`,
    - `hasEnvFallback(): bool`.
    - These are today's names, unrenamed (D3). It is owned by the mail senders, and `config/services.yaml` aliases it to `EffectiveMailSettings`.
  - `final readonly class App\Service\Mail\Settings\EffectiveMailSettings implements MailSendingSettings`: `__construct(MailServerSettingsRepository $repository, MailPasswordCipher $cipher, MailFallback $fallback)`. The method bodies move unchanged from `MailSettings`.
  - `final readonly class MailSettings`: `overview()`, `resetToEnvironment()` and `update(MailSettingsUpdate)` only. The constructor is unchanged.
  - `MailCapability`, `AccountMailer`, `DigestMailBuilder`, `DynamicMailTransport` and `MailConnectionTester` take `MailSendingSettings` in the position where they took `MailSettings`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Mail/Settings/EffectiveMailSettingsTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Service\Mail\Settings\EffectiveMailSettings;
use App\Service\Mail\Settings\MailFallback;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Proxy\ProxySettings;
use App\Tests\Support\SettingsRequests;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EffectiveMailSettingsTest extends KernelTestCase
{
    private function effective(): EffectiveMailSettings
    {
        return self::getContainer()->get(EffectiveMailSettings::class);
    }

    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    public function testWithNoRowEverythingFollowsTheEnvFallback(): void
    {
        // The test env fallback is null://null, so mail derives to disabled.
        $effective = $this->effective();

        self::assertFalse($effective->isSendingEnabled());
        self::assertFalse($effective->hasEnvFallback());
        self::assertSame('null://null', $effective->activeTransportDsnFallback());
        self::assertNull($effective->configuredTransport());
    }

    public function testAnEnabledRowTurnsSendingOn(): void
    {
        $this->settings()->update(
            SettingsRequests::mail(enabled: true, host: 'smtp.relay.test', password: 'p')->toUpdate(),
        );

        self::assertTrue($this->effective()->isSendingEnabled());
    }

    public function testTheConfiguredTransportCarriesEveryFieldAndTheOpenedPassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.relay.test',
            port: 2525,
            username: 'postbox',
            encryption: MailEncryption::Tls->value,
            password: 'top-secret',
        )->toUpdate());

        $resolved = $this->effective()->configuredTransport();

        self::assertNotNull($resolved);
        self::assertSame('smtp.relay.test', $resolved->host);
        self::assertSame(2525, $resolved->port);
        self::assertSame('postbox', $resolved->username);
        self::assertSame('top-secret', $resolved->password);
        self::assertSame(MailEncryption::Tls, $resolved->encryption);
        self::assertFalse($resolved->useProxy);
    }

    public function testANullPasswordKeepsTheStoredSecret(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'h', password: 'keep-me')->toUpdate());
        $this->settings()->update(SettingsRequests::mail(host: 'h2', password: null)->toUpdate());

        self::assertSame('keep-me', $this->effective()->configuredTransport()?->password);
    }

    public function testASavedFromAddressWinsOverTheEnvIdentity(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'h',
            fromAddress: 'saved@reader.test',
            fromName: 'Saved',
            password: 'p',
        )->toUpdate());

        $identity = $this->effective()->identity();

        self::assertSame('saved@reader.test', $identity->address);
        self::assertSame('Saved', $identity->name);
    }

    public function testARowWithABlankFromAddressFallsBackToTheEnvIdentity(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'h', fromAddress: '', password: 'p')->toUpdate());

        self::assertSame(
            self::getContainer()->get(MailFallback::class)->identity()->address,
            $this->effective()->identity()->address,
        );
    }

    public function testUseProxyReachesTheResolvedTransport(): void
    {
        self::getContainer()->get(ProxySettings::class)->update(
            SettingsRequests::proxy(type: 'SOCKS5', host: 'proxy.example', port: 1080)->toUpdate(),
        );

        $this->settings()->update(
            SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true, password: 'app-pw')->toUpdate(),
        );

        self::assertTrue($this->effective()->configuredTransport()?->useProxy);
    }

    public function testAfterAResetTheEnvFallbackDecidesAgain(): void
    {
        $this->settings()->update(
            SettingsRequests::mail(enabled: true, host: 'smtp.relay.test', password: 'p')->toUpdate(),
        );

        $this->settings()->resetToEnvironment();

        self::assertFalse($this->effective()->isSendingEnabled());
        self::assertNull($this->effective()->configuredTransport());
    }
}
```

`tests/Service/Mail/Settings/MailSettingsTest.php` (rewritten in full; the runtime reads moved to `EffectiveMailSettingsTest`):
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Settings;

use App\Enum\MailEncryption;
use App\Http\Admin\MailSettingsJson;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Mail\Settings\MailSettings;
use App\Service\Proxy\ProxySettings;
use App\Tests\Support\SettingsRequests;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @phpstan-import-type MailSettingsPayload from MailSettingsJson
 */
final class MailSettingsTest extends KernelTestCase
{
    private function settings(): MailSettings
    {
        return self::getContainer()->get(MailSettings::class);
    }

    /** @return MailSettingsPayload */
    private function view(): array
    {
        return MailSettingsJson::from($this->settings()->overview());
    }

    private function repository(): MailServerSettingsRepository
    {
        return self::getContainer()->get(MailServerSettingsRepository::class);
    }

    private function configureAProxy(): void
    {
        self::getContainer()->get(ProxySettings::class)->update(
            SettingsRequests::proxy(type: 'SOCKS5', host: 'proxy.example', port: 1080)->toUpdate(),
        );
    }

    public function testNoRowReportsNoSavedConfigAndNoPassword(): void
    {
        $view = $this->view();

        self::assertFalse($view['hasSavedConfig']);
        self::assertFalse($view['hasPassword']);
    }

    public function testUpdateStoresTheConnectionAndHidesThePassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            port: 587,
            username: 'postbox',
            encryption: MailEncryption::Starttls->value,
            fromAddress: 'noreply@reader.test',
            fromName: 'Reader',
            password: 'top-secret',
        )->toUpdate());

        $view = $this->view();
        self::assertTrue($view['enabled']);
        self::assertSame('smtp.relay.test', $view['host']);
        self::assertTrue($view['hasPassword']);
        self::assertArrayNotHasKey('password', $view);
    }

    public function testResetToEnvironmentDeletesTheSavedRow(): void
    {
        $this->settings()->update(SettingsRequests::mail(host: 'smtp.relay.test', password: 'top-secret')->toUpdate());
        self::assertTrue($this->view()['hasSavedConfig']);

        $this->settings()->resetToEnvironment();

        $view = $this->view();
        self::assertFalse($view['hasSavedConfig']);
        self::assertFalse($view['envFallbackConfigured']);
        self::assertNull($this->repository()->findSingleton());
    }

    public function testUpdateRejectsAnEnabledAuthenticatedRowWithNoPassword(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::passwordMissing());

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        )->toUpdate());
    }

    public function testUpdateRejectsEnablingWithNoHostWhileTheEnvFallbackIsNull(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::transportMissing());

        $this->settings()->update(SettingsRequests::mail(enabled: true, host: '')->toUpdate());
    }

    public function testUpdateAcceptsAnEnabledAuthenticatedRowThatKeepsAStoredPassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: false,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: 'top-secret',
        )->toUpdate());

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        )->toUpdate());

        $view = $this->view();
        self::assertTrue($view['enabled']);
        self::assertTrue($view['hasPassword']);
    }

    public function testUpdateAcceptsAnEnabledUnauthenticatedRelayWithNoUsername(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.relay.test',
            username: null,
            password: null,
        )->toUpdate());

        self::assertTrue($this->view()['enabled']);
    }

    public function testADisabledAuthenticatedRowMayBeSavedWithoutAPassword(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: false,
            host: 'smtp.relay.test',
            username: 'postbox',
            password: null,
        )->toUpdate());

        self::assertTrue($this->view()['hasSavedConfig']);
    }

    public function testAnEnabledRowWithNoHostAndAUsernameIsRefusedForTheMissingTransportNotThePassword(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::transportMissing());

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: '',
            username: 'postbox',
            password: null,
        )->toUpdate());
    }

    public function testRemovePasswordClearsTheStoredSecretAndStillAppliesTheConnection(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.example.test',
            username: null,
            password: 'topsecret',
        )->toUpdate());
        self::assertTrue($this->view()['hasPassword']);

        $this->settings()->update(SettingsRequests::mail(
            host: 'smtp.moved.test',
            username: null,
            removePassword: true,
        )->toUpdate());

        $view = $this->view();
        self::assertFalse($view['hasPassword']);
        self::assertSame('smtp.moved.test', $view['host']);
    }

    public function testRemovingThePasswordOfAnEnabledAuthenticatedRowIsRejected(): void
    {
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.example.test',
            username: 'alice',
            password: 'topsecret',
        )->toUpdate());

        $this->expectException(IncompleteMailConfigurationException::class);

        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.example.test',
            username: 'alice',
            removePassword: true,
        )->toUpdate());
    }

    public function testUseProxyIsRejectedWhenNoEgressProxyIsConfigured(): void
    {
        $this->expectExceptionObject(IncompleteMailConfigurationException::proxyMissing());

        $this->settings()->update(SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true)->toUpdate());
    }

    public function testUseProxyIsPersistedWhenAProxyIsConfigured(): void
    {
        $this->configureAProxy();

        $this->settings()->update(
            SettingsRequests::mail(host: 'smtp.gmail.com', useProxy: true, password: 'app-pw')->toUpdate(),
        );

        self::assertTrue($this->repository()->findSingleton()?->usesProxy());
        self::assertTrue($this->view()['useProxy']);
    }
}
```
A renamed test documents what it pins: `testRemovePasswordClearsTheStoredSecret…` gains `AndStillAppliesTheConnection`, which replaces the old inline comment. `testUseProxyIsRejected…` now expects the exact `proxyMissing()` object, where the old test accepted any `IncompleteMailConfigurationException`.

`tests/Service/Mail/Settings/MailConnectionTesterTest.php`:
- Add `use App\Service\Mail\Settings\EffectiveMailSettings;` before `use App\Service\Mail\Settings\MailConnectionTester;`.
- In `testerWithHealth()`, replace the first constructor argument
```php
            $this->settings(),
```
with
```php
            self::getContainer()->get(EffectiveMailSettings::class),
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Mail/Settings`
Expected: FAIL.
- `EffectiveMailSettings` does not exist.
- `MailConnectionTesterTest` cannot resolve the class.

- [ ] **Step 3: The mail-owned interface and the runtime reader**

`src/Service/Mail/MailSendingSettings.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Mail\Settings\MailIdentity;
use App\Service\Mail\Settings\ResolvedMailTransport;

interface MailSendingSettings
{
    public function isSendingEnabled(): bool;

    public function identity(): MailIdentity;

    /**
     * The saved SMTP transport whether or not sending is enabled; null when none is saved.
     *
     * @throws SecretUnreadableException when its stored password cannot be opened
     */
    public function configuredTransport(): ?ResolvedMailTransport;

    public function activeTransportDsnFallback(): string;

    public function hasEnvFallback(): bool;
}
```

`src/Service/Mail/Settings/EffectiveMailSettings.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\MailSendingSettings;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;

final readonly class EffectiveMailSettings implements MailSendingSettings
{
    public function __construct(
        private MailServerSettingsRepository $repository,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
    ) {
    }

    public function isSendingEnabled(): bool
    {
        $settings = $this->repository->findSingleton();

        return null !== $settings ? $settings->isEnabled() : $this->fallback->connection()->enabled;
    }

    public function identity(): MailIdentity
    {
        $settings = $this->repository->findSingleton();

        if (null !== $settings && '' !== $settings->getFromAddress()) {
            return new MailIdentity($settings->getFromAddress(), $settings->getFromName());
        }

        return $this->fallback->identity();
    }

    public function configuredTransport(): ?ResolvedMailTransport
    {
        $settings = $this->repository->findSingleton();

        if (null === $settings || '' === $settings->getHost()) {
            return null;
        }

        return new ResolvedMailTransport(
            $settings->getHost(),
            $settings->getPort(),
            $settings->getUsername(),
            $settings->hasPassword() ? $this->cipher->open($settings->getSealedPassword()) : null,
            $settings->getEncryption(),
            $settings->usesProxy(),
        );
    }

    public function activeTransportDsnFallback(): string
    {
        return $this->fallback->transportDsn();
    }

    public function hasEnvFallback(): bool
    {
        return $this->fallback->connection()->enabled;
    }
}
```

`config/services.yaml`, add after `App\Service\Mail\Digest\DigestMailerInterface: '@App\Service\Mail\Digest\DigestMailer'`:
```yaml

    App\Service\Mail\MailSendingSettings: '@App\Service\Mail\Settings\EffectiveMailSettings'
```

- [ ] **Step 4: The admin service loses its runtime half; the senders switch**

`src/Service/Mail/Settings/MailSettings.php` (rewritten in full):
```php
<?php

declare(strict_types=1);

namespace App\Service\Mail\Settings;

use App\Entity\MailServerSettings;
use App\Repository\MailServerSettingsRepository;
use App\Service\Mail\Settings\Crypto\MailPasswordCipher;
use App\Service\Mail\Settings\Exception\IncompleteMailConfigurationException;
use App\Service\Proxy\ProxySettings;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MailSettings
{
    public function __construct(
        private MailServerSettingsRepository $repository,
        private EntityManagerInterface $em,
        private MailPasswordCipher $cipher,
        private MailFallback $fallback,
        private ProxySettings $proxySettings,
    ) {
    }

    public function overview(): MailSettingsOverview
    {
        $saved = $this->repository->findSingleton();
        $proxy = $this->proxySettings->current();

        return new MailSettingsOverview(
            null === $saved ? null : MailSettingsSnapshot::fromEntity($saved),
            $this->fallback->connection(),
            $proxy->isConfigured() ? $proxy->connection : null,
        );
    }

    public function resetToEnvironment(): void
    {
        $settings = $this->repository->findSingleton();
        if (null !== $settings) {
            $this->em->remove($settings);
            $this->em->flush();
        }
    }

    public function update(MailSettingsUpdate $update): void
    {
        $existing = $this->repository->findSingleton();
        $this->guardAgainstEnablingWithoutATransport($update->connection);
        $this->guardAgainstIncompleteAuthenticatedRow($update, $existing);
        $this->guardAgainstProxyRoutingWithoutAProxy($update->connection);

        $settings = $existing;
        if (null === $settings) {
            $settings = new MailServerSettings();
            $this->em->persist($settings);
        }

        $this->apply($update, $settings);
        $this->em->flush();
    }

    private function guardAgainstIncompleteAuthenticatedRow(
        MailSettingsUpdate $update,
        ?MailServerSettings $existing,
    ): void {
        $password = $update->password;
        $willHavePassword = !$password->isRemoval()
            && (null !== $password->replacement() || ($existing?->hasPassword() ?? false));
        $connection = $update->connection;
        $isAuthenticatedTransport = $connection->enabled
            && '' !== $connection->host
            && null !== $connection->username;

        if ($isAuthenticatedTransport && !$willHavePassword) {
            throw IncompleteMailConfigurationException::passwordMissing();
        }
    }

    private function guardAgainstEnablingWithoutATransport(MailConnection $connection): void
    {
        if ($connection->enabled && '' === $connection->host && !$this->fallback->connection()->enabled) {
            throw IncompleteMailConfigurationException::transportMissing();
        }
    }

    private function guardAgainstProxyRoutingWithoutAProxy(MailConnection $connection): void
    {
        if ($connection->useProxy && !$this->proxySettings->current()->isConfigured()) {
            throw IncompleteMailConfigurationException::proxyMissing();
        }
    }

    private function apply(MailSettingsUpdate $update, MailServerSettings $settings): void
    {
        $replacement = $update->password->replacement();
        if (null !== $replacement) {
            $settings->apply($update->connection, $this->cipher->seal($replacement));

            return;
        }

        $settings->applyWithoutPassword($update->connection);
        if ($update->password->isRemoval()) {
            $settings->clearStoredPassword();
        }
    }
}
```

The senders:
- `src/Service/Mail/MailCapability.php`: delete `use App\Service\Mail\Settings\MailSettings;`, and replace `private MailSettings $settings` with `private MailSendingSettings $settings`. The interface is in the same namespace.
- `src/Service/Mail/AccountMailer.php`: delete `use App\Service\Mail\Settings\MailSettings;`, and replace `private MailSettings $mailSettings,` with `private MailSendingSettings $mailSettings,`.
- `src/Service/Mail/Digest/DigestMailBuilder.php`: replace `use App\Service\Mail\Settings\MailSettings;` with `use App\Service\Mail\MailSendingSettings;`, and `private MailSettings $mailSettings,` with `private MailSendingSettings $mailSettings,`.
- `src/Service/Mail/Transport/DynamicMailTransport.php`: replace `use App\Service\Mail\Settings\MailSettings;` with `use App\Service\Mail\MailSendingSettings;`, and `private readonly MailSettings $settings,` with `private readonly MailSendingSettings $settings,`.
- `src/Service/Mail/Settings/MailConnectionTester.php`: add `use App\Service\Mail\MailSendingSettings;` after `use App\Service\Mail\MailFailureRecorder;`, and replace `private MailSettings $settings,` with `private MailSendingSettings $settings,`.

Names that pointed at the old class:
- `src/Http/Admin/InstanceSettingsJson.php`: replace `derived mail-sending state (MailSettings), not a toggle` with `derived mail-sending state (MailSendingSettings), not a toggle`.
- `config/services_test.yaml`: replace `resolves through the real container, including its MailSettings` with `resolves through the real container, including its MailSendingSettings`.
- `tests/Service/Mail/MailCapabilityWiringTest.php`: replace `the service still constructs and resolves its MailSettings collaborator.` with `the service still constructs and resolves its MailSendingSettings collaborator.`
- `tests/Support/EnablesMailInTests.php`: replace `so MailSettings::isSendingEnabled() reports true` with `so EffectiveMailSettings::isSendingEnabled() reports true`. The method it names moves there in this task.

- [ ] **Step 5: The tests that stub the sending settings double the interface**

```bash
perl -pi -e 's/^use App\\Service\\Mail\\Settings\\MailSettings;$/use App\\Service\\Mail\\MailSendingSettings;/; s/\bMailSettings::class\b/MailSendingSettings::class/g; s/\): MailSettings$/): MailSendingSettings/' tests/Service/Auth/RegistrationPolicyTest.php tests/Service/Auth/RegistrationServiceTest.php tests/Service/Mail/AccountMailerTest.php tests/Service/Mail/Digest/DigestMailBuilderTest.php tests/Service/Mail/Digest/DigestMailerTest.php tests/Service/Mail/Digest/MailGatedDigestMailerTest.php tests/Service/Mail/Digest/SendDueDigestsHealthTest.php tests/Service/Mail/Digest/SendDueDigestsTest.php tests/Service/Mail/Digest/SendTestDigestTest.php tests/Service/Mail/MailCapabilityTest.php tests/Service/Mail/MailGatedAccountMailerTest.php tests/Service/OAuth/OAuthAccountLinkerTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php
git grep -nw "MailSettings" -- tests/Service/Auth tests/Service/Mail/AccountMailerTest.php tests/Service/Mail/Digest tests/Service/Mail/MailCapabilityTest.php tests/Service/Mail/MailGatedAccountMailerTest.php tests/Service/OAuth/OAuthAccountLinkerTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php
git grep -nw "MailSettings" -- src
```
Expected:
- The first grep prints nothing.
- The second prints only three lines: `src/Controller/Admin/AdminMailController.php` (its import and its constructor), and the class declaration in `src/Service/Mail/Settings/MailSettings.php`.

The perl rewrites three things: the import, each `createStub(MailSettings::class)`, and the return type of the `mailIdentity()` helpers in `DigestMailBuilderTest`, `DigestMailerTest` and `SendTestDigestTest`.

- [ ] **Step 6: Verify finality and run the tests**

```bash
git grep -nE '^(readonly )?class ' -- src/Service/Mail src/Service/Proxy src/Service/Grafana src/Service/Fetch src/Service/Profiling
php bin/phpunit tests/Service/Mail tests/Service/Auth tests/Service/OAuth tests/Service/Worker tests/Controller/Admin tests/Controller/Api/MeControllerTest.php tests/Http
php bin/console lint:container
```
Expected:
- The grep prints nothing.
- The tests PASS.
- The container lints.

- [ ] **Step 7: Deletion checks**

Restore each by hand before the next.
1. In `EffectiveMailSettings::identity()`, drop `&& '' !== $settings->getFromAddress()`. Expected: `testARowWithABlankFromAddressFallsBackToTheEnvIdentity` fails.
2. In `EffectiveMailSettings::isSendingEnabled()`, return `$this->fallback->connection()->enabled` unconditionally. Expected: `testAnEnabledRowTurnsSendingOn` fails.
3. In `config/services.yaml`, delete the `MailSendingSettings` alias. Expected: `MailCapabilityWiringTest` still passes, because Symfony auto-aliases a single implementation. The explicit alias is house style, not load-bearing. Record the result, then restore the alias.

- [ ] **Step 8: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on every changed `src` and test file.
Expected: clean.

- [ ] **Step 9: Commit**

Run `git status --short` first. Every modified or untracked path it lists must belong to this task. Another session may share the checkout, and the directory-level `git add` below would sweep its files in. If anything else shows up, stage this task's files by explicit path instead.
```bash
git add src/Service/Mail config/services.yaml config/services_test.yaml src/Http/Admin/InstanceSettingsJson.php tests/Service/Mail tests/Support/EnablesMailInTests.php tests/Service/Auth/RegistrationPolicyTest.php tests/Service/Auth/RegistrationServiceTest.php tests/Service/OAuth/OAuthAccountLinkerTest.php tests/Service/Worker/SendDueDigestsHandlerTest.php
git commit -m "refactor(#1159): mail senders read their settings through their own interface; the mail admin is admin only"
```

---

### Task B7: An unreadable proxy password fails a proxied mail send like every other transport failure

**Files:**
- Modify: `src/Service/Mail/Transport/DynamicMailTransport.php` (one `catch`)
- Modify: `src/Service/Mail/Settings/MailConnectionTester.php` (the guard in `test()` covers the transport build too)
- Test: `tests/Service/Mail/Transport/DynamicMailTransportTest.php` (one test, three imports)
- Test: `tests/Service/Mail/Settings/MailConnectionTesterTest.php` (one test, four imports)

**Interfaces:**
- Consumes: `ConfiguredProxySource::configuredProxy()` (A3), which may throw `SecretUnreadableException`.
- Produces no new API. The behaviour change is listed under "Wire changes".
  - `DynamicMailTransport::activeTransport()` turns a `SecretUnreadableException` from building the proxied transport into `TransportException('The stored proxy password is unreadable: …')`. That is the pattern it already uses for an unreadable mail password and for a proxy that is gone.
  - `MailConnectionTester::test()` reports it as `MailTestFailure::SecretUnreadable` with the cipher's message, as it already does for the mail password. Nothing was sent, so the health log stays untouched.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Mail/Transport/DynamicMailTransportTest.php`:
- Add `use App\Entity\ProxyServerSettings;` after `use App\Entity\MailServerSettings;`.
- Add `use App\Enum\ProxyType;` after `use App\Enum\MailEncryption;`.
- Add `use App\Service\Proxy\ProxyConnection;` before `use App\Service\Proxy\ProxySettings;`.
- Add before `testActiveTransportUsesEsmtpForADirectRow()`:
```php
    public function testAProxiedRowWhoseProxyPasswordIsUnreadableSurfacesAsATransportFailure(): void
    {
        $proxy = new ProxyServerSettings();
        $proxy->apply(
            new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );
        $row = new MailServerSettings();
        $row->apply(
            new MailConnection(true, 'smtp.gmail.com', 587, 'alice', MailEncryption::Starttls, '', '', true),
            self::getContainer()->get(MailPasswordCipher::class)->seal('app-pw'),
        );
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($proxy);
        $em->persist($row);
        $em->flush();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage(
            'The stored proxy password is unreadable: Stored secret material is not valid base64.',
        );
        self::getContainer()->get(DynamicMailTransport::class)->activeTransport();
    }
```

`tests/Service/Mail/Settings/MailConnectionTesterTest.php`:
- Add `use App\Entity\ProxyServerSettings;` before `use App\Service\Mail\MailFailureRecorder;`. Also add `use App\Enum\ProxyType;` and `use App\Service\Crypto\SealedSecret;` directly after it, in that order.
- Add `use App\Service\Proxy\ProxyConnection;` before `use App\Tests\Support\InMemoryMailFailureRecorder;`.
- Add before `testAFailedSendRecordsATestFailureAgainstTheActingAdmin()`:
```php
    public function testAnUnreadableProxyPasswordIsReportedRatherThanThrown(): void
    {
        $this->authenticateAsAdmin();
        $proxy = new ProxyServerSettings();
        $proxy->apply(
            new ProxyConnection(false, true, ProxyType::Socks5, 'proxy.example', 1080, 'user'),
            new SealedSecret('not base64!', 'bm9uY2U=', 'c2FsdA==', 1),
        );
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($proxy);
        $entityManager->flush();
        $this->settings()->update(SettingsRequests::mail(
            enabled: true,
            host: 'smtp.gmail.com',
            username: 'u',
            password: 'p',
            fromAddress: 'from@x.test',
            useProxy: true,
        )->toUpdate());
        $health = new InMemoryMailFailureRecorder();

        $result = $this->testerWithHealth($health)->test();

        self::assertFalse($result->ok);
        self::assertSame(MailTestFailure::SecretUnreadable, $result->failure);
        self::assertSame('Stored secret material is not valid base64.', $result->detail);
        self::assertSame([], $health->recordedFailures());
    }
```
The `useProxy` save succeeds because, since A1, the update guard reads `ProxySettings::current()->isConfigured()` and never opens the proxy password.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php bin/phpunit tests/Service/Mail/Transport/DynamicMailTransportTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php`
Expected: FAIL. Both new tests see a raw `SecretUnreadableException` escape from `ActiveMailTransportFactory::forResolved()`.

- [ ] **Step 3: Implement**

`src/Service/Mail/Transport/DynamicMailTransport.php`, in `activeTransport()`, add a second `catch` to the `try` that builds the transport. Replace
```php
            throw new TransportException('The mail configuration is incomplete: ' . $e->getMessage(), 0, $e);
        }
```
with
```php
            throw new TransportException('The mail configuration is incomplete: ' . $e->getMessage(), 0, $e);
        } catch (SecretUnreadableException $e) {
            throw new TransportException('The stored proxy password is unreadable: ' . $e->getMessage(), 0, $e);
        }
```
`SecretUnreadableException` is already imported there.

`src/Service/Mail/Settings/MailConnectionTester.php`, in `test()`, replace
```php
        try {
            $resolved = $this->settings->configuredTransport();
        } catch (SecretUnreadableException $e) {
            // A config guard, not a failed send: nothing was ever attempted,
            // so the health log stays untouched.
            return MailTestResult::failed(MailTestFailure::SecretUnreadable, $e->getMessage());
        }

        $transport = $this->effectiveTransport($resolved);
```
with
```php
        try {
            $transport = $this->effectiveTransport($this->settings->configuredTransport());
        } catch (SecretUnreadableException $e) {
            // A config guard, not a failed send: nothing was ever attempted,
            // so the health log stays untouched.
            return MailTestResult::failed(MailTestFailure::SecretUnreadable, $e->getMessage());
        }
```
The existing guard now covers both passwords, the mail one and the proxy one, with no second copy of the `catch`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Mail tests/Controller/Admin/AdminMailControllerTest.php`
Expected: PASS. That includes the existing `testAnUnreadableStoredPasswordSurfacesAsATransportFailure` and the tester's other cases.

- [ ] **Step 5: Deletion checks**

Restore each by hand before the next.
1. Delete the new `catch (SecretUnreadableException $e)` in `DynamicMailTransport`. Expected: `testAProxiedRowWhoseProxyPasswordIsUnreadableSurfacesAsATransportFailure` fails on the raw exception.
2. In `MailConnectionTester::test()`, move `$this->effectiveTransport(…)` back out of the `try`. Resolve into `$resolved` inside the `try`, and build `$transport` after it. Expected: `testAnUnreadableProxyPasswordIsReportedRatherThanThrown` errors.

- [ ] **Step 6: Gates**

Run `composer check` and `composer md`, then PhpStorm `lint_files` on the four files.
Expected: clean. If PHPMD reports `activeTransport()`'s complexity, extract its second `try` into `private function buildTransport(?ResolvedMailTransport $resolved): TransportInterface`. Keep both `catch` arms in the new method. Do not raise the threshold.

- [ ] **Step 7: Commit**

```bash
git add src/Service/Mail/Transport/DynamicMailTransport.php src/Service/Mail/Settings/MailConnectionTester.php tests/Service/Mail/Transport/DynamicMailTransportTest.php tests/Service/Mail/Settings/MailConnectionTesterTest.php
git commit -m "refactor(#1159): an unreadable proxy password fails a proxied mail send as a transport failure"
```

---

### Finishing PR B

- [ ] **Step 1: /simplify**

Invoke the `simplify` skill over the branch diff (`git diff origin/develop...HEAD`). Apply what it proposes, unless it would reintroduce any of these:
- a dependency from `Service/Profiling` on `Service/Grafana`,
- an entity in an `*Overview`,
- a DTO in a service,
- a non-final settings class.

Rerun the affected tests and `composer check` after each fix, and commit the fixes as `refactor(#1159): simplify review`.

- [ ] **Step 2: SDD final review**

Dispatch one fresh reviewer subagent with the plan, the branch diff and these attack points. It reports findings. It does not fix.
1. **Cycles closed:**
   - `git grep -n 'App\\Service\\Grafana' -- src/Service/Profiling src/EventListener` is empty.
   - `git grep -n 'App\\Service\\Proxy' -- src/Service/Fetch` is still empty.
   - No `src/Service/Proxy` file imports `App\Service\Mail`.
2. **No DTO in a settings service:** `git grep -n 'App\\Dto' -- src/Service/Proxy src/Service/Grafana src/Service/Mail/Settings` is empty.
3. **No entity on the read side:**
   - No `*Overview` or `*Snapshot` exposes an entity.
   - `git grep -n AutowireWrongClass -- src/Service/Grafana src/Service/Mail src/Service/Proxy` is empty.
   - `GrafanaSettingsOverview::$stored` is non-nullable.
4. **Wire, byte for byte:** on develop and on the branch, capture the following with the same admin token:
   - `GET /api/admin/grafana` and `GET /api/admin/mail`, with no row and with a saved row,
   - `PUT /api/admin/grafana` and `PUT /api/admin/mail`,
   - `POST /api/admin/mail/reset`.

   Every body must be identical. The only exception is the PR A change for an unreadable proxy password.
5. **Cross-process profiling toggle (#1012):**
   - `refresh()` alone never queries.
   - An admin save forgets the shared pool and the saving process's memo.
   - The worker picks the save up at its next `refresh()`.
6. **D5:**
   - `ProfilingPolicy`'s catch is justified in one line.
   - Both failure paths are pinned.
   - `RequestProfilingListener` still calls `isEnabled()` outside its own `try`, so the catch stays load-bearing.
7. **Mail semantics unchanged:**
   - The identity falls back on a blank from-address.
   - Sending follows the row, else the env fallback.
   - The three update guards refuse exactly what they refused before.
   - `removePassword` wins.
8. **Finality:** `git grep -nE '^(readonly )?class ' -- src/Service/{Proxy,Grafana,Mail,Fetch,Profiling}` is empty, and each admin service's public API is only `overview()`/`current()`, `update()` and `resetToEnvironment()`.
9. **B7:** every path that builds a proxied mail transport maps `SecretUnreadableException`:
   - `DynamicMailTransport` throws `TransportException`.
   - `MailConnectionTester` returns `secret_unreadable`.
   - Grep for `forResolved(` and check each caller.
10. **Comment bar:** every new or touched comment is one to three lines and prevents a wrong edit. The trimmed `GrafanaSettingsCache` and `GrafanaSettingsSnapshot` docblocks still say why the cache crosses processes and why the array form is flat.

Fix what it confirms, one commit per finding (`refactor(#1159): review — <finding>`).

- [ ] **Step 3: Per-PR gates**

Run in order and paste each summary line into the report:
```bash
php bin/phpunit
docker compose exec php composer test
composer check
composer md
composer infection:diff
```
Lint every changed PHP file with `mcp__phpstorm__lint_files`. Scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.

- [ ] **Step 4: Push and open the PR**

```bash
git push -u origin refactor/1159-grafana-and-mail-settings-split
gh pr create --base develop --title "refactor(#1159): split the grafana and mail admin from the runtime they feed" --body "$(cat <<'EOF'
Closes #1159

Second of two PRs. The first one split the proxy settings.

- **Grafana:**
  - Profiling reads its toggle through `Profiling\ProfilingConfigSource`, which `Grafana\EffectiveGrafanaSettings` implements. That class also owns the per-process memo and the cross-process cache (#1012).
  - `GrafanaSettings` is only the admin service now, `final readonly`. It takes a `GrafanaSettingsUpdate` from `GrafanaSettingsRequest::toUpdate()`.
  - The Grafana ↔ Profiling cycle is gone: nothing under `Service/Profiling` or `EventListener` imports `Service/Grafana`.
- **Mail:**
  - The senders (`MailCapability`, `AccountMailer`, `DigestMailBuilder`, `DynamicMailTransport`, `MailConnectionTester`) read `Mail\MailSendingSettings`, which `Mail\Settings\EffectiveMailSettings` implements.
  - `MailSettings` is only the admin service now, `final readonly`. It takes a `MailSettingsUpdate` from `MailSettingsRequest::toUpdate()`.
- **Read side:**
  - The Grafana entity gains `connection()`, as the mail entity already had.
  - `GrafanaSettingsSnapshot` and the new `MailSettingsSnapshot` carry plain values.
  - Neither `*Overview` holds an entity any more, and both `AutowireWrongClass` suppressions are gone.
  - `GrafanaSettingsOverview::$stored` is non-nullable.
- **Decision:** a profiling config that cannot be read means profiling is off. `ProfilingPolicy` keeps its catch. It runs on every request, outside the listener's own `try`. Both failure paths are now pinned by tests.
- No settings service takes an `App\Dto` request any more. Every class in `Service/{Proxy,Grafana,Mail,Fetch,Profiling}` is `final`, and tests double the consumer-owned interfaces.

- **Fix:** a proxied mail send whose *proxy* password cannot be opened used to escape as a raw `SecretUnreadableException`. It now fails as a `TransportException` ("The stored proxy password is unreadable: …"), as a rotated mail password or a dead relay already does.

**Behaviour change:** `POST /api/admin/mail/test`, for a saved row routed through a proxy whose stored password cannot be opened, answered 500. It now answers `200 {"ok": false, "reason": "<cipher message>"}`, as it already did for an unreadable mail password. A digest or account mail send in that state now fails as a transport failure, where it used to throw a raw exception. Every other body, status code and header is unchanged. The first PR's mail-page change is listed there.
EOF
)"
```

- [ ] **Step 5: Merge when green**

Watch the checks with the Monitor tool. Do not use `--auto`.
```bash
PR=$(gh pr view --json number --jq .number)
until [ "$(gh pr checks "$PR" --json bucket --jq '[.[].bucket] | any(. == "pending")')" = "false" ]; do sleep 30; done
gh pr checks "$PR" --json name,bucket --jq '.[] | "\(.bucket)\t\(.name)"'
```
Merge only if every bucket is `pass` or `skipping`:
```bash
gh pr merge "$PR" --merge
```
On a failure:
- Read the failing job's log (`gh run view --log-failed`).
- For `composer tramp`, check `composer show larspohlmann/phptramp` first.
- Fix on the branch, push, and watch again.

- [ ] **Step 6: Verify the issue closed**

Run: `gh issue view 1159 --json state --jq .state`
Expected: `CLOSED`. Do not close it by hand. If it is still open, report it: the merge did not carry the keyword.
