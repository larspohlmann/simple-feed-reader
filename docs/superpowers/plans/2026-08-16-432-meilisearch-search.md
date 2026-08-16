# Meilisearch-backed Entry Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make entry search fast, typo-tolerant and content-wide by answering it from a Meilisearch index, while the `LIKE` search stays the permanent answer on any install that runs no search container.

**Architecture:** `EntrySearchInterface` — the seam #408 left behind — gains a second implementation. `IndexedEntrySearch` asks a Meilisearch index for entry ids and hydrates them through the same row projection the list uses, so per-user read state and the subscription IDOR gate are unchanged. `EntrySearchWithFallback` is what the container injects: it calls the engine and drops to `LikeEntrySearch` when the engine is not configured or does not answer. Index writes are explicit calls at the two ingest flush boundaries and in the pruner, repaired by `app:search:reindex`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM, Meilisearch v1.x in Docker, Angular 20 with signals, PHPUnit, Jest, bash.

**Issue:** [#432](https://github.com/larspohlmann/simple-feed-reader/issues/432). **Branch:** `feature/432-meilisearch-search`.

## Global Constraints

- `declare(strict_types=1)` in every PHP file. PSR-12. `final readonly class` with constructor promotion is the house style.
- PHPStan level max over `src` and `tests`. No new baseline, no `@phpstan-ignore` without a reason comment.
- Every `src` file touched must be PHPMD-clean (codesize ruleset) before commit, not merely free of new findings.
- Controllers carry no private method that does real work — `ThinControllerRule` enforces it.
- Comments explain *why*, never *what*.
- phptramp: a value forwarded through 4+ methods across 2+ classes fails the build. The index gateway takes value objects (`IndexSearch`, `IndexedEntry`), never loose parameters, for exactly this reason.
- Frontend: standalone components and signals. Prettier at 100 columns. No hex colours, no raw `px` outside `src/app/theme/`.
- Shell: `shellcheck scripts/*.sh scripts/test/*.sh` must be clean at **info** level too — CI fails on any finding. Guard clauses, never `A && B || C`.
- Commit after every task. Never merge and never tag a deploy.

## Deviations from the issue body, already decided

1. **No `meilisearch/meilisearch-php` dependency.** The SDK needs PSR-18 discovery and drags its own transport. This project already talks to an external JSON API over `Symfony\Contracts\HttpClient\HttpClientInterface` (`Service/Recommendation/OpenAiCompatibleChatClient`), the four endpoints needed here are trivial, and `MockHttpClient` makes the adapter unit-testable without a container. One adapter class touches Meilisearch's wire format; nothing else in `src/` knows the engine exists.
2. **Two env vars, not one DSN.** `MEILISEARCH_URL` and `MEILISEARCH_KEY`. A DSN would have to hide the key in userinfo and be parsed back out; two names need no parsing and read plainly in `.env.prod`. `MEILISEARCH_URL` empty is what "no engine" means. The container's own `MEILI_MASTER_KEY` is fed from `MEILISEARCH_KEY` in compose, so the key exists once.
3. **`matchedWords` is a page-level field, not per entry.** The engine's highlights are needed only so the client can mark a typo-corrected hit. One flat list of the words the engine actually matched, for the whole page, does that with one optional response key — instead of threading highlight data through `EntryJson` into every row. A word only enters that list because some row matched it, and marking it in another row of the same result set is a true match, not a false one.
4. **`EntrySearchInterface` returns `EntrySearchResult`, not `list<EntryListRow>`.** It has to carry `matchedWords` beside the rows. `LikeEntrySearch` returns an empty word list, which is exactly the degraded mode: the client keeps marking the literal terms it already knows.
5. **No `depends_on` between php/worker and meilisearch.** The whole point of the fallback is that the app runs correctly while the engine is absent, so an ordering constraint would assert something the design says is false.

## Considered and rejected

- **An `indexed_at` column on `entry`,** with a sweep step indexing whatever is unindexed. Self-healing without any command, and no failure path at ingest — but it needs a migration, a column, and its own CI migration leg for a repair that `app:search:reindex` already performs. Revisit if index drift turns out to be common in practice.
- **A Doctrine `postFlush` listener** instead of explicit calls. It would miss the pruner, which deletes with bulk DQL and therefore fires no ORM events, so the explicit call is needed there regardless — and then two mechanisms would do one job.
- **Relevance ordering in this iteration.** Recorded in #432 as a follow-up: it needs a cursor that is not `(effectiveDate, id)`.

---

### Task 1: `EntrySearchResult` and the `matchedWords` response key

A pure refactor, shipped before the engine exists, so the response shape is stable from the first commit and every later task adds behaviour rather than changing contracts.

**Files:**
- Create: `backend/src/Service/Search/EntrySearchResult.php`
- Create: `backend/src/Http/SearchPage.php`
- Modify: `backend/src/Service/Search/EntrySearchInterface.php`
- Modify: `backend/src/Service/Search/LikeEntrySearch.php`
- Modify: `backend/src/Controller/Api/EntrySearchController.php`
- Test: `backend/tests/Http/SearchPageTest.php`
- Test: `backend/tests/Service/Search/LikeEntrySearchTest.php` (adjust to the new return type)
- Test: `backend/tests/Controller/Api/EntrySearchControllerTest.php` (assert the new key)

**Interfaces:**
- `EntrySearchResult` — `final readonly`, promoted `list<EntryListRow> $rows` and `list<string> $matchedWords`; a named constructor `EntrySearchResult::rowsOnly(array $rows)` for an engine-less answer.
- `EntrySearchInterface::search(EntrySearchQuery $query): EntrySearchResult`.
- `SearchPage::of(EntrySearchResult $result, int $limit): array{entries: …, nextCursor: …, matchedWords: list<string>}` — delegates to `EntryPage::of` and adds the key.

- [ ] **Step 1: Write the failing test for `SearchPage`**

`tests/Http/SearchPageTest.php`: an empty result yields `entries: []`, `nextCursor: null`, `matchedWords: []`; a result carrying matched words puts them in the page verbatim; the `nextCursor` behaviour is `EntryPage`'s and is not re-tested here.

- [ ] **Step 2: Run and watch it fail**

```bash
php bin/phpunit tests/Http/SearchPageTest.php
```
Expected: FAIL — `Class "App\Http\SearchPage" not found`.

- [ ] **Step 3: Add the value object, the page and the new return type**

`EntrySearchResult::rowsOnly()` exists so an implementation with no highlights says so in one word instead of passing `[]` at every call site.

- [ ] **Step 4: Update `LikeEntrySearch` and the controller**

`LikeEntrySearch::search` wraps its rows in `EntrySearchResult::rowsOnly(...)`. The controller becomes `SearchPage::of($this->search->search($query), $query->limit)`.

- [ ] **Step 5: Run the search suites**

```bash
php bin/phpunit tests/Http tests/Service/Search tests/Controller/Api/EntrySearchControllerTest.php
```
Expected: PASS, with the controller test asserting `matchedWords` is present and empty.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Search backend/src/Http backend/src/Controller/Api/EntrySearchController.php backend/tests
git commit -m "refactor(#432): carry matched words beside the rows of a search result"
```

---

### Task 2: The Meilisearch container in the dev stack, and a probe

The wire format decides the adapter, so run the real engine before writing code against it. Nothing here is guesswork carried forward.

**Files:**
- Modify: `docker-compose.yml` (add `meilisearch`, add the volume, add the two env vars to `php` and `worker`)
- Modify: `backend/.env` (document `MEILISEARCH_URL` / `MEILISEARCH_KEY`, both empty)

- [ ] **Step 1: Add the service**

Dev uses literal values, never `${…}` interpolation. Publish the dashboard port on `127.0.0.1` only, matching mailpit's convention.

```yaml
  # The search engine, always on in dev so development runs against the same
  # path production does. No depends_on anywhere: the app answers searches from
  # the database whenever this container is absent or down, and an ordering
  # constraint would claim otherwise.
  meilisearch:
    image: getmeili/meilisearch:v1.13
    environment:
      MEILI_MASTER_KEY: "dev-master-key-not-a-secret"
      MEILI_ENV: "development"
    volumes:
      - meili-data:/meili_data
    ports:
      # Dashboard and API; internal traffic uses the compose network.
      - "127.0.0.1:7700:7700"
```

Add `meili-data:` to `volumes:`, and to **both** `php` and `worker` environments:

```yaml
      MEILISEARCH_URL: "http://meilisearch:7700"
      MEILISEARCH_KEY: "dev-master-key-not-a-secret"
```

`backend/.env` gets both names with empty values, in a commented block in the house style, saying that empty means the database search and that the native (non-Docker) run therefore uses it.

- [ ] **Step 2: Start it and probe the real API**

```bash
docker compose up -d meilisearch
```

Then, against `http://127.0.0.1:7700` with the dev key, verify by hand and **write the answers into this plan** before Task 3:

1. `PATCH /indexes/entries/settings` with `searchableAttributes`, `filterableAttributes`, `sortableAttributes` — accepted on a not-yet-existing index?
2. `POST /indexes/entries/documents` with one document — does it create the index and what does the task response look like?
3. `POST /indexes/entries/search` with `filter`, `sort`, `matchingStrategy: "all"`, `attributesToRetrieve: ["id"]`, `attributesToHighlight: ["title","summary"]`, `highlightPreTag`/`highlightPostTag` — **does `_formatted` carry `title` when `title` is not in `attributesToRetrieve`?** This decides whether the adapter must retrieve the title too.
4. A compound keyset filter: `feedId IN [1,2] AND (effectiveDate < 100 OR (effectiveDate = 100 AND id < 5))` — accepted?
5. `POST /indexes/entries/documents/delete-batch` with a list of ids.

- [ ] **Step 3: Record the findings in this file**

Add a `## Probed wire format` section below with the exact request and response bodies. Task 3 is written against it, not against memory.

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml backend/.env docs/superpowers/plans/2026-08-16-432-meilisearch-search.md
git commit -m "feat(#432): run Meilisearch in the development stack"
```

---

### Task 3: The index gateway

One adapter over the engine's HTTP API, behind two narrow interfaces so the reindex command depends only on writing and the search only on reading.

**Files:**
- Create: `backend/src/Service/Search/Index/SearchIndexReader.php` (interface)
- Create: `backend/src/Service/Search/Index/SearchIndexWriter.php` (interface)
- Create: `backend/src/Service/Search/Index/IndexSearch.php` (value)
- Create: `backend/src/Service/Search/Index/IndexMatches.php` (value)
- Create: `backend/src/Service/Search/Index/IndexedEntry.php` (value)
- Create: `backend/src/Service/Search/Index/MeilisearchIndex.php`
- Create: `backend/src/Service/Search/Exception/SearchEngineUnavailableException.php`
- Modify: `backend/config/services.yaml` (bind the two env vars, alias both interfaces)
- Test: `backend/tests/Service/Search/Index/MeilisearchIndexTest.php` (`MockHttpClient`)

**Interfaces:**
- `IndexSearch` — `list<string> $terms`, `list<int> $feedIds`, `?EntryCursor $cursor`, `int $limit`.
- `IndexMatches` — `list<int> $entryIds`, `list<string> $matchedWords`.
- `IndexedEntry` — `int $id`, `int $feedId`, `string $title`, `?string $summary`, `?string $content`, `?string $feedTitle`, `\DateTimeImmutable $effectiveDate`.
- `SearchIndexReader::find(IndexSearch $search): IndexMatches` — throws `SearchEngineUnavailableException`.
- `SearchIndexWriter::configure(): void`, `::upsert(array $entries): void`, `::forget(array $entryIds): void`, `::clear(): void` — all throw the same exception.

- [ ] **Step 1: Write the failing tests**

`MockHttpClient` with canned responses. Cover: `find` posts `matchingStrategy: "all"` and the terms joined by a space; the filter carries `feedId IN [...]`; a cursor adds the compound `effectiveDate`/`id` predicate and no cursor adds none; `sort` is `effectiveDate:desc` then `id:desc`; the hit ids come back in the engine's order; matched words are extracted from the highlight tags, deduplicated, case preserved; a transport failure becomes `SearchEngineUnavailableException`; a 5xx becomes the same; `upsert` posts the documents with `effectiveDate` as a unix timestamp; `forget` posts the id list; an empty `upsert` or `forget` performs **no** HTTP call at all.

- [ ] **Step 2: Run and watch them fail**

```bash
php bin/phpunit tests/Service/Search/Index/
```

- [ ] **Step 3: Implement the values and the adapter**

The sentinel highlight tags are constants on the adapter and must be strings no article can contain — `'[[sfr:hl]]'` and `'[[/sfr:hl]]'`. Extraction is one `preg_match_all` over the formatted title and summary.

Content is indexed as plain text: the caller passes `PlainText::from($entry->getContentHtml())`, so nothing in this namespace knows about HTML.

Every request carries `Authorization: Bearer <key>` and a short timeout — the engine is a container on the same network, and a request must never outlive the user's patience when the answer is a database query away. Wrap `ExceptionInterface` and any non-2xx status in `SearchEngineUnavailableException`.

- [ ] **Step 4: Bind the configuration**

In `services.yaml`, beside the existing binds, pass `%env(MEILISEARCH_URL)%` and `%env(MEILISEARCH_KEY)%` into `MeilisearchIndex`, and alias:

```yaml
    App\Service\Search\Index\SearchIndexReader: '@App\Service\Search\Index\MeilisearchIndex'
    App\Service\Search\Index\SearchIndexWriter: '@App\Service\Search\Index\MeilisearchIndex'
```

- [ ] **Step 5: Run and watch them pass, then commit**

```bash
php bin/phpunit tests/Service/Search/
git add backend/src/Service/Search backend/config/services.yaml backend/tests/Service/Search
git commit -m "feat(#432): talk to the Meilisearch index behind a reader and a writer"
```

---

### Task 4: `IndexedEntrySearch`

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php` (add `rowsByIdsForUser`)
- Modify: `backend/src/Repository/SubscriptionRepository.php` (add `feedIdsForUser`)
- Create: `backend/src/Service/Search/IndexedEntrySearch.php`
- Test: `backend/tests/Service/Search/IndexedEntrySearchTest.php` (`DbTestCase`, fake `SearchIndexReader`)
- Test: `backend/tests/Repository/EntryRowsByIdsTest.php`

**Interfaces:**
- `EntryRepository::rowsByIdsForUser(array $entryIds, int $userId): list<EntryListRow>` — reuses `rowQueryBuilder`, orders `effectiveDate DESC, id DESC`. The subscription join stays the IDOR gate, so an id the engine returns for a feed the caller does not subscribe to is dropped here even if the engine's filter were wrong.
- `SubscriptionRepository::feedIdsForUser(int $userId): list<int>`.
- `IndexedEntrySearch implements EntrySearchInterface`.

- [ ] **Step 1: Write the failing repository test**

Covers: returns the rows for the given ids; orders newest first regardless of the id order asked for; drops an id in a feed the user does not subscribe to; an empty id list returns `[]` **without** a query.

- [ ] **Step 2: Write the failing search test**

A fake `SearchIndexReader` returning fixed ids and words. Covers: the query's terms and limit reach the reader; the caller's subscribed feed ids reach the reader; the cursor is passed through; the returned rows are the hydrated ids; `matchedWords` is carried through; a user with no subscriptions returns an empty result **without** asking the engine.

- [ ] **Step 3: Implement both**

- [ ] **Step 4: Run and commit**

```bash
php bin/phpunit tests/Repository tests/Service/Search
git add backend/src/Repository backend/src/Service/Search backend/tests
git commit -m "feat(#432): answer a search from the index and hydrate the rows"
```

---

### Task 5: The fallback, and what the container injects

**Files:**
- Create: `backend/src/Service/Search/EntrySearchWithFallback.php`
- Modify: `backend/config/services.yaml` (re-alias `EntrySearchInterface`)
- Test: `backend/tests/Service/Search/EntrySearchWithFallbackTest.php`

**Interfaces:**
- `EntrySearchWithFallback implements EntrySearchInterface`, constructed with `IndexedEntrySearch`, `LikeEntrySearch`, `LoggerInterface`, and the configured URL. Two concrete collaborators on purpose: this class's whole job is to combine *these two* strategies, and typing them by the shared interface would be ambiguous to the container and meaningless to the reader.

- [ ] **Step 1: Write the failing tests**

Covers: with no URL configured the engine is never called and the database answers; with a URL configured the engine answers; when the engine throws `SearchEngineUnavailableException` the database answers and a warning is logged; the unconfigured case logs **nothing** — that is a permanent, correct install (Strato), not an incident, and a line per search would be pure noise; an unexpected exception is **not** swallowed.

- [ ] **Step 2: Run and watch them fail, then implement**

- [ ] **Step 3: Re-alias, and prove the alias is load-bearing**

```yaml
    App\Service\Search\EntrySearchInterface: '@App\Service\Search\EntrySearchWithFallback'
```

Comment the alias out, run `tests/Controller/Api/EntrySearchControllerTest.php`, confirm it fails on the container, restore it. Say so in the report.

- [ ] **Step 4: Run every backend gate and commit**

```bash
php bin/console cache:warmup && composer check && composer md && php bin/phpunit
git add backend/src/Service/Search backend/config/services.yaml backend/tests/Service/Search
git commit -m "feat(#432): fall back to the database when no engine answers"
```

---

### Task 6: Keeping the index current

**Files:**
- Create: `backend/src/Service/Search/EntryIndexer.php`
- Modify: `backend/src/Service/EntryIngestor.php` (`ingest` returns the created entries)
- Modify: `backend/src/Service/Refresh/RefreshRunner.php` (index after its flush)
- Modify: `backend/src/Service/Subscription/FirstFetchRecorder.php` (index after its flush)
- Modify: `backend/src/Service/EntryPruner.php` (forget the ids it deletes)
- Test: `backend/tests/Service/Search/EntryIndexerTest.php`
- Test: existing ingest/prune tests adjusted to the new return type

**Interfaces:**
- `EntryIndexer::index(array $entries): void` and `::forget(array $entryIds): void` — both swallow `SearchEngineUnavailableException` and log it. Indexing is a side effect of storing an entry; it must never be able to fail a refresh, and `app:search:reindex` is the repair.
- `EntryIngestor::ingest(...): list<Entry>` — callers use `\count(...)` where they used the int.

- [ ] **Step 1: Write the failing indexer tests**

Covers: `index` calls `configure` then `upsert` (configure is idempotent and cheap, and it is what makes a freshly enabled container work without any command); the mapped document carries the plain-text content and the feed title; an engine failure is logged and swallowed; an empty list does nothing; `forget` passes the ids through and swallows a failure the same way.

- [ ] **Step 2: Change `ingest`'s return type and fix its callers**

`RefreshRunner` keeps `$created` as a count via `\count($createdEntries)` for `recordSuccess`, then calls the indexer after `$this->em->flush()` — ids exist only after the flush. `FirstFetchRecorder` does the same and still returns its int.

- [ ] **Step 3: Hook the pruner**

`EntryPruner::deleteByIds` already holds the ids; forget each chunk after its delete.

- [ ] **Step 4: Run the whole backend suite**

```bash
php bin/phpunit
```
Expected: PASS. The ingest tests will need their `assertSame(2, …)` turned into a count.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#432): index entries as they are ingested and forget them as they are pruned"
```

---

### Task 7: `app:search:reindex`

**Files:**
- Create: `backend/src/Command/SearchReindexCommand.php`
- Modify: `backend/src/Repository/EntryRepository.php` (id-keyset batch iteration for the whole table)
- Test: `backend/tests/Command/SearchReindexCommandTest.php`

- [ ] **Step 1: Write the failing test**

Covers: configures the index, clears it, then indexes every entry in batches; reports the count; exits non-zero with a readable message when no engine is configured — a command asked to reindex nothing configured has failed, unlike a search, which has a database to fall back to.

- [ ] **Step 2: Implement**

Batch by id keyset (`WHERE e.id > :last ORDER BY e.id ASC`), `clear()` first so a reindex also removes documents whose entries are gone. `$em->clear()` between batches to keep the identity map from growing over a full table.

- [ ] **Step 3: Run it for real against the dev stack**

```bash
docker compose exec php bin/console app:search:reindex
```
Then search in the browser at `https://localhost:8443` and confirm a typo finds the entry. Record the entry count and the wall-clock time.

- [ ] **Step 4: Commit**

```bash
git add backend/src/Command backend/src/Repository backend/tests
git commit -m "feat(#432): rebuild the search index from the database"
```

---

### Task 8: The production container and the install question

**Files:**
- Modify: `docker-compose.prod.yml` (profiled `meilisearch` service, volume, two env vars in the anchor)
- Modify: `scripts/lib.sh` (`prod_uses_search_engine`, `prod_compose_profiles`, `ensure_meilisearch_key`, `configure_search_engine`, the summary line)
- Modify: `scripts/install.sh` (call it, and extend the header docstring)
- Modify: `.env.prod.example`
- Modify: `.github/workflows/ci.yml` (a step for the new test)
- Create: `scripts/test/configure-search-engine.test.sh` (executable)

**Interfaces:**
- `MEILISEARCH_URL` empty ⇒ no engine, no container. Set ⇒ the `meilisearch` profile.
- `prod_compose_profiles` must keep printing exactly `mysql` and exactly `''` in the two cases `configure-database.test.sh` asserts, and print a comma-separated list when both are on.

- [ ] **Step 1: Write the failing shell test first**

Model it byte-for-byte on `scripts/test/configure-database.test.sh` — same header shape, same stubs, same numbered sections. Cover: pressing return enables the engine (**default yes**) and writes the URL plus a generated key; answering no leaves the URL empty and generates nothing; the profile list is `mysql,meilisearch` with the bundled database and `meilisearch` with SQLite; an existing key is never regenerated; no terminal changes nothing.

The profile-order assertions are the reason this test exists: a wrong list either starts a container nobody talks to or leaves a configured install without its engine.

- [ ] **Step 2: Run and watch it fail**

```bash
scripts/test/configure-search-engine.test.sh
```

- [ ] **Step 3: Implement the shell side**

`configure_search_engine` asks a yes/no question whose default is **yes**, so it cannot use `prompt_confirm` (its default is no). Use `prompt_with_default 'Enable search engine? (y/n)' 'y'` and treat anything but a no as yes, matching how `configure_database` reads its choice.

`prod_compose_profiles` builds a list:

```bash
prod_compose_profiles() {
  local profiles=''
  if prod_uses_bundled_mysql; then
    profiles='mysql'
  fi
  if prod_uses_search_engine; then
    profiles="${profiles:+${profiles},}meilisearch"
  fi
  printf '%s' "${profiles}"
}
```

`ensure_meilisearch_key` follows `ensure_ai_key_secret` exactly: generate only when empty, never regenerate.

- [ ] **Step 4: The prod service**

```yaml
  meilisearch:
    image: getmeili/meilisearch:v1.13
    profiles: ["meilisearch"]
    restart: unless-stopped
    environment:
      MEILI_MASTER_KEY: ${MEILISEARCH_KEY:-}
      MEILI_ENV: "production"
    volumes:
      - meili-data:/meili_data
```

**`:-`, never `:?`.** Compose resolves every variable in the file before it filters services by profile, so a `:?` here would stop every install that runs no engine — the same trap the `mysql` block documents. No published port: only the app talks to it.

Add `MEILISEARCH_URL: ${MEILISEARCH_URL:-}` and `MEILISEARCH_KEY: ${MEILISEARCH_KEY:-}` to the `x-app-environment` anchor, and `meili-data:` to `volumes:`.

- [ ] **Step 5: Document the names in `.env.prod.example`**

An `# --- Search engine (optional) ---` block in the house style: empty URL means the database answers searches, which is correct and permanent for an install that cannot run the container; enabling it later means setting both values and running `app:search:reindex`.

- [ ] **Step 6: Add the CI step, `chmod +x` the test, and run shellcheck**

```bash
shellcheck scripts/*.sh scripts/test/*.sh docker/php/entrypoint-prod.sh docker/web/10-select-mode.sh
scripts/test/configure-search-engine.test.sh && scripts/test/configure-database.test.sh
```
Both tests must pass — the second proves the profile change did not break the existing contract.

- [ ] **Step 7: Commit**

```bash
git add docker-compose.prod.yml scripts .env.prod.example .github/workflows/ci.yml
git commit -m "feat(#432): offer the search engine at install time and run it behind a profile"
```

---

### Task 9: The client marks what the engine matched

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntriesPage` gains `matchedWords?: string[]`)
- Modify: `frontend/src/app/reader/entries-store.ts` (hold the page's matched words)
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts` (`searchTerms` prefers them)
- Test: the matching specs

- [ ] **Step 1: Write the failing tests**

Store spec: a search response carrying `matchedWords` exposes them; a response without the key exposes none; loading a further page replaces them rather than accumulating stale words from an earlier query.

List spec: with matched words present the rows receive those; with none present the rows receive the terms split from the selection, exactly as today.

- [ ] **Step 2: Implement**

`searchTerms` becomes: the page's matched words when the list is non-empty, else the selection's own terms. One computed, one comment saying why — the engine tolerates typos, so the literal term the user typed may appear nowhere in a row that legitimately matched.

- [ ] **Step 3: Run the gate**

```bash
cd frontend && npm run check
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src
git commit -m "feat(#432): mark the words the engine matched, not only the words typed"
```

---

### Task 10: Docs, verification and the pull request

- [ ] **Step 1: README**

Extend the install walk-through: the installer now also asks whether to run the search engine, the default is yes, and answering no (or running outside Docker) means the database answers searches. Keep it to the two sentences that section's voice allows.

- [ ] **Step 2: `docs/local-docker.md` and `docs/docker-production.md`**

The dev stack gains a container and a port; production gains an optional one and a volume to back up. Both need the `app:search:reindex` line — after enabling the engine on an existing install, the index is empty until it runs.

- [ ] **Step 3: The architecture checklist**

Run `docs/architecture.md` §6 against the extended search response and paste the answers into the PR body. `matchedWords` is a plain JSON array of strings; nothing browser-coupled enters the API.

- [ ] **Step 4: Every gate, both database legs**

```bash
cd backend && php bin/console cache:warmup && composer check && composer md && php bin/phpunit && composer infection:diff
```
```bash
docker compose exec php vendor/bin/phpunit
```
```bash
cd frontend && npm run check
```
```bash
shellcheck scripts/*.sh scripts/test/*.sh docker/php/entrypoint-prod.sh docker/web/10-select-mode.sh
```

- [ ] **Step 5: Prove the fallback by hand**

```bash
docker compose stop meilisearch
```
Search in the browser: results still arrive, `backend/var/log/dev.log` carries one warning per search and no error. Start it again and confirm the engine answers. This is the one behaviour no unit test can prove end to end.

- [ ] **Step 6: Scan the log**

Read `backend/var/log/dev.log` for deprecations and swallowed errors from the new code.

- [ ] **Step 7: Open the pull request**

```bash
git push -u origin feature/432-meilisearch-search
```

PR into `develop`, body containing `Closes #432`, the reindex measurement from Task 7, the §6 answers, and the fallback proof. Do not merge and do not tag a deploy.
