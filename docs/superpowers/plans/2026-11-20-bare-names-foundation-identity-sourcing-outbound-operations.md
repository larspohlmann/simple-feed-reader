# Verbs for Bare `…For/In/At/Among/OrNull` Names: Foundation, Identity, Sourcing, Outbound, Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In `Service/Foundation`, `Identity`, `Sourcing`, `Outbound` and `Operations`, no method is a bare noun phrase ending in `For`, `In`, `At`, `Among`, `OrNull` or `OrRefuse`, apart from the kept typed-field families.

**Architecture:** Renames only, the first of five PRs for #2400 (then Ingestion/Library/Repository, Content, Curation, Social). The table below is the decision. Callers in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2400 and the method-name paragraph in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock or comment changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables, string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Several classes share an old name (`keyFor`, `optionsFor`, `rungsFor`, `candidateFor`, `dateOrNull`). Rename per class through the type, never by a tree-wide text replace: the same name in a module outside this batch stays until its own batch.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class, no `CLAUDE.md` edit.
- Commits `refactor(#2400): …`. The PR says `Refs #2400` (the last batch closes it). No attribution lines.
- No tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these groups that is a bare name with one of the endings but is in no table and not under Kept names, a new name that collides in its class, or a name that does not say what the body does: stop and report the row to the planner; do not pick a name.

## Decisions

- Every row was named from the method's full body and its callers. A `CLAUDE.md` verb where its meaning matches; otherwise the plain verb the tree already uses for that act. The verbs #2360 settled apply: `build…` assembles, `parse…` interprets a string or decoded array into a value that may be absent, `find…` looks up (null when absent), `resolve…` settles between sources or falls back, `read…` is a plain access with a default, `collect…` gathers from a list, `keepIf…` passes a value through or drops it, `try…` swallows failure.
- In scope is a name that does not start with a verb. `findSubscriptionFor`, `getCreatedAt`, `isDueAt` and the like are not touched.
- **Kept names: typed field readers.** `JsonNodeModel::listAt`, `LineField::stringOrNull`/`intOrNull`/`dateOrNull`/`objectOrNull`, `BackupLineFormat::dateOrNull`, `StateFileFields::dateOrNull`/`integerOrNull`. In these classes the type is the method name (`string()`, `int()`, `date()`, `node()`) and `…OrNull` marks the nullable twin. Renaming one member would split the family; the families as a whole are named in the PR as a follow-up question.
- `HostKey::forUrlOrNull` becomes `tryForUrl`: the twin of `forUrl` that answers null when the URL has no host.

## The renames

Paths are under `backend/src/Service/`. The last column is what the reviewer checks the name against.

### Foundation

| File | Old | New | What it does |
|---|---|---|---|
| `Foundation/Html/Support/JsonLd.php` | `nodesIn` | `walkNodes` | Yields a decoded block and, recursively, every array nested in it |
| `Foundation/Html/Support/JsonLd.php` | `scriptsIn` | `findScripts` | Yields the JSON-LD `<script>` elements of a document |
| `Foundation/Text/StopWords/BundledStopWords.php` | `noListFor` | `buildMissingListException` | Returns the exception "holds no word list for this language"; the caller throws it |
| `Foundation/Url/Support/AbsoluteHttpUrl.php` | `httpsOrNull` | `keepIfHttps` | Passes a URL through when it is an absolute `https://` URL, else null |

### Identity

| File | Old | New | What it does |
|---|---|---|---|
| `Identity/OAuth/Factory/OAuthUserFactory.php` | `loginIdentifierFor` | `resolveLoginIdentifier` | The identity's e-mail when it is linkable, else a placeholder address |
| `Identity/OAuth/LoginCodeStore.php` | `keyFor` | `buildKey` | Cache key: prefix plus the digest of the code |
| `Identity/OAuth/OAuthStateStore.php` | `keyFor` | `buildKey` | Cache key: prefix plus the digest of the state |
| `Identity/OAuth/OAuthStateStore.php` | `challengeFor` | `computeChallenge` | The PKCE S256 challenge: base64url of the verifier's SHA-256 |
| `Identity/Passkey/PasskeyChallengeStore.php` | `keyFor` | `buildKey` | Cache key: prefix plus the handle's SHA-256 |
| `Identity/Passkey/AssertionVerifier.php` | `credentialRecordFor` | `buildCredentialRecord` | Assembles the library's `CredentialRecord` from a stored passkey |
| `Identity/Passkey/AssertionVerifier.php` | `aaguidOrNil` | `deserializeAaguid` | The inverse of `serializeAaguid`: a stored null becomes the all-zero AAGUID, a stored string its `Uuid` |
| `Identity/Passkey/Factory/UserPasskeyFactory.php` | `aaguidOrNull` | `serializeAaguid` | The all-zero AAGUID becomes null, any other its RFC 4122 string |
| `Identity/Passkey/Factory/AssertionOptionsFactory.php` | `optionsFor` | `buildOptions` | Assembles the request options for a login ceremony |
| `Identity/Passkey/Factory/RegistrationOptionsFactory.php` | `optionsFor` | `buildOptions` | Assembles the creation options for a registration ceremony |
| `Identity/Passkey/Factory/RegistrationOptionsFactory.php` | `userEntityFor` | `buildUserEntity` | Assembles the library's user entity from a user and a handle |
| `Identity/Passkey/PasskeyCredentials.php` | `userHandleFor` | `resolveUserHandle` | The handle the user's credentials already share, else a freshly minted one |
| `Identity/Passkey/PasskeyCredentials.php` | `excludeListFor` | `loadExcludeList` | Queries the user's stored passkeys and builds a credential descriptor for each |

### Sourcing

| File | Old | New | What it does |
|---|---|---|---|
| `Sourcing/Discovery/AccountOnlyFeed/AccountOnlyFeedInterface.php` and every implementation | `candidateFor` | `findCandidate` | The feed candidate this source offers for a URL, null when the URL is not its own |
| `Sourcing/Discovery/Pass/LinkLabels.php` | `nameFor` | `resolveName` | The link's own label, or the page name when that label is generic |
| `Sourcing/Discovery/WellKnownFeedProbe.php` | `feedsAmong` | `findFeeds` | Asks every candidate URL and returns those that answered with a parseable feed, keyed by rank; failures are skipped |
| `Sourcing/FeedDirectory/FeedDirectoryFiles.php` | `countryCodesIn` | `collectCountryCodes` | Gathers the string `code` of each country entry of the index |
| `Sourcing/FeedDirectory/FeedDirectoryFiles.php` | `languageCodesIn` | `collectLanguageCodes` | Gathers the string keys of the index's languages |
| `Sourcing/FeedDirectory/FeedDirectoryFiles.php` | `entriesAt` | `readEntries` | The array under a key of the index, `[]` when absent or no array |

### Outbound

| File | Old | New | What it does |
|---|---|---|---|
| `Outbound/Fetch/FaviconResolver/FaviconResolver.php` | `iconOrNull` | `tryFindIcon` | Finds the icon in a fetch outcome via `iconFrom`; logs and answers null when that throws |
| `Outbound/Fetch/ConcurrentRequestEngine.php` | `exchangeFor` (private) | `selectExchange` | Picks the response exchange registered for the attempt's exchange kind; throws `FeedUnreachableException` when none is |
| `Outbound/Fetch/FetchRetryPolicy.php` | `directFallbackFor` | `findDirectFallback` | The same attempt without its proxy when the proxy allows a direct fallback, else null |
| `Outbound/Fetch/GuardedApiClient.php` | `optionsFor` | `buildOptions` | Assembles the HTTP client options for a request and its budget |
| `Outbound/Fetch/Model/RequestSlot/RequestSlotModelInterface.php` and every implementation | `keyFor` | `buildKey` | The slot key a URL falls under |
| `Outbound/Fetch/Pass/RequestSlots.php` | `inFlightIn` | `readInFlight` | How many requests are in flight in a slot, read from the in-memory map; 0 when none |
| `Outbound/Fetch/Support/EgressOptions.php` | `arrayAt` | `readArray` | The array under an option key, `[]` when absent or no array |
| `Outbound/Fetch/Support/HostKey.php` | `forUrlOrNull` | `tryForUrl` | The host key of a URL (lower case, no `www.`), null when the URL has no host |
| `Outbound/Fetch/Support/ProxyHandshakeFailure.php` | `reasonFor` | `parseReason` | Reads the failure reason out of a transport message; null when the message is not a proxy handshake failure |
| `Outbound/Image/GuessedSizeRungs.php` | `rungsFor` | `buildRungs` | The renditions to probe for a lead image, by walking guessed sizes; `[]` when the URL gives no basis |
| `Outbound/Image/RenditionProbe.php` | `rungsFor` | `buildRungs` | The renditions to probe: none when every target is covered, else from the resizer or the guessed sizes |
| `Outbound/Image/ResizerRungs.php` | `rungsFor` | `buildRungs` | One rendition per uncovered target width not above the lead's own width |
| `Outbound/Image/OriginCookies.php` | `cookiesFor` | `buildCookieHeader` | The `Cookie` header from a landed response's `Set-Cookie`, `''` unless it succeeded on the root's host |
| `Outbound/Image/Pass/SizeWalk.php` | `candidateFor` | `buildCandidate` | The basis URL resized to a width, null when the width is not below the basis, already covered, or yields the same URL |
| `Outbound/Mail/Digest/Model/DigestImageSetModel.php` | `cidFor` | `findCid` | The content id an image URL was attached under, null when none |
| `Outbound/Mail/Transport/DynamicMailTransport.php` | `transportFor` | `resolveTransport` | The transport for the resolved settings or the fallback, reusing the cached one while its signature is unchanged |

### Operations

| File | Old | New | What it does |
|---|---|---|---|
| `Operations/Backup/Pass/RestoreEntryLoader.php` | `stateFor` | `buildState` | Builds the user's entry state for a backup line and an entry id |
| `Operations/Grafana/EffectiveGrafanaSettings.php` | `defaultOrNull` | `keepIfNotEmpty` | Passes a default through unless it is `''`, then null |
| `Operations/Grafana/Model/GrafanaSettingsSnapshotModel.php` | `connectionFromArrayOrNull` | `parseConnection` | Reads the connection out of a stored array; null when a field has the wrong type |
| `Operations/Grafana/Model/GrafanaSettingsSnapshotModel.php` | `sealedTokenFromArrayOrNull` | `parseSealedToken` | Reads the sealed token out of a stored array; null when a field is missing or mistyped |
| `Operations/Logging/Factory/HostLogFileHandlerFactory.php` | `logFileFor` | `buildLogFilePath` | The log file path for an environment, with the host name when there is one |
| `Operations/Logging/Loki/LokiSpoolShipper.php` | `linesIn` | `loadLines` | Reads and decodes a spool file; throws `CorruptSpoolFileException` when unreadable or no list |
| `Operations/SelfUpdate/UpdateStateStore/UpdateStateStoreInterface.php` and every implementation | `heartbeatAt` | `findHeartbeat` | When the updater last reported, null when no usable heartbeat is stored |

### Foundation: pass-through URL helpers

Ruled after review. The three classes share the old name `orNull`; rename through the type. Paths under `backend/src/Service/Foundation/Url/Support/`.

| File | Old | New | What it does |
|---|---|---|---|
| `AbsoluteHttpUrl.php` | `orNull` | `keepIfAbsolute` | Passes a URL through when it starts with `http://` or `https://`, else null |
| `AbsoluteHttpUrl.php` | `orNullWithin` | `keepIfAbsoluteWithin` | `keepIfAbsolute`, and null when the URL is longer than the given length |
| `HttpsImageUrl.php` | `orNull` | `parseUrl` | Reads an https image URL out of a string: an `https://` or protocol-relative `//` URL becomes `https://…`, anything else or one longer than `MAX_LENGTH` is null |
| `HttpsImageUrl.php` | `orNullUpgrading` | `parseUrlUpgradingHttp` | `parseUrl`, except that an `http://` URL is rewritten to `https://` instead of rejected |
| `TrackingFreeUrl.php` | `orNull` | `stripTrackingIfSet` | `stripTracking` for a URL, null for null |

## Review Focus

- A shared old name replaced tree-wide: `keyFor` also exists in `RefreshRunStore` and others outside this batch; they must be unchanged after this PR.
- String method names in mocks (`->method('candidateFor')`, `->method('heartbeatAt')`): search for the bare name in `backend/tests`.
- Test doubles and in-memory implementations of `UpdateStateStoreInterface`, `RequestSlotModelInterface` and `AccountOnlyFeedInterface` under `backend/tests`.
- `HiddenProfileAccountOnlyFeed` (Social/Bluesky) implements `AccountOnlyFeedInterface` and has its own private `reasonFor`: the interface method follows, the private one waits for the Social batch.
- The comment in `AssertionVerifier` that names `UserPasskeyFactory::aaguidOrNull()`.

---

### Task 1: Foundation and Identity

- [ ] **Step 1:** Rename the Foundation and Identity rows and every caller.
- [ ] **Step 2:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): Foundation and Identity methods start with their verb`.

### Task 2: Sourcing and Outbound

- [ ] **Step 1:** Rename the Sourcing and Outbound rows, the interface implementations and test doubles included.
- [ ] **Step 2:** Run the same four commands. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): Sourcing and Outbound methods start with their verb`.

### Task 3: Operations

- [ ] **Step 1:** Rename the Operations rows, the interface implementations and test doubles included.
- [ ] **Step 2:** Run `grep -rnoE 'function\s+&?\w+(OrNull|OrRefuse|OrNil|For|In|At|Among)\s*\(' backend/src/Service/{Foundation,Identity,Sourcing,Outbound,Operations}`. Expected: only names that start with a verb, and the kept typed field readers.
- [ ] **Step 3:** Run the same four commands. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2400): Operations methods start with their verb`.

### Task 3b: Pass-through URL helpers

- [ ] **Step 1:** Rename the five rows of "Foundation: pass-through URL helpers" and every caller, through the type; update the `{@see orNull}` in `HttpsImageUrl`'s docblock.
- [ ] **Step 2:** Run `grep -rnE 'function\s+(orNull|orNullWithin|orNullUpgrading)\s*\(' backend/src/Service/Foundation`. Expected: no output.
- [ ] **Step 3:** Run the same four commands. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2400): URL helpers start with their verb`.

### Task 4: Gates and PR

- [ ] **Step 1:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; escaped mutants only on lines whose logic this PR did not change, listed in the PR.
- [ ] **Step 2:** Open the PR into `develop` with the rename tables, the kept names and `Refs #2400`; merge when green.
