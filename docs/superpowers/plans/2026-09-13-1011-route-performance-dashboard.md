# Route Performance Dashboard (#1011) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Retarget the Grafana "Application performance" dashboard from high-traffic rates to the performance of single routes and the PHP methods and SQL queries inside them, on a site that serves a handful of requests per hour.

**Architecture:** Three backend additions feed the dashboard: (1) `open-telemetry/opentelemetry-auto-doctrine` turns every DBAL query into a child span with the SQL text; (2) `#[WithSpan]` attributes on the entry-point service methods of the hot routes turn PHP methods into child spans, activated by `opentelemetry.attr_hooks_enabled=1` in the Docker PHP images; (3) a `route` label on the per-request Pyroscope profile lets the flame graph filter by route. Grafana moves from 11.5.2 to 13.2.1 for instant TraceQL metrics queries (one value per route over the whole range), and Tempo's metrics range limit rises from 3 h to 72 h. The dashboard is rewritten around a `route` variable: a per-route table, a per-request point chart, method and query tables for the selected route, a trace list, and a per-route flame graph.

**Tech Stack:** Symfony 7.4, PHP 8.4, OpenTelemetry PHP (`ext-opentelemetry` 1.4.1, SDK 1.15, `opentelemetry-auto-symfony` 1.4, `opentelemetry-auto-doctrine` 0.5), Grafana Tempo 2.7.1 (TraceQL metrics via `local-blocks`), Grafana 13.2.1, Pyroscope 2.3.1.

**Spec:** GitHub issue [#1011](https://github.com/larspohlmann/simple-feed-reader/issues/1011). The design was settled in chat (bounded path); the issue body carries the agreed scope. This plan is the design document.

## Global Constraints

- **Tracing runs only where `ext-opentelemetry` is loaded**: dev Docker and Docker prod. CI and the Strato release build run WITHOUT the extension and MUST stay green. `OTEL_PHP_DISABLED_INSTRUMENTATIONS=all` (CI, `composer infection*`) also disables the new Doctrine instrumentation, because its `_register.php` checks `Sdk::isInstrumentationDisabled()` first, like `opentelemetry-auto-symfony` does today.
- **Strato never activates tracing.** Nothing in this plan touches the Strato deploy.
- **Worker runs stay out of the dashboard.** Worker profile labels (`ProfileLabels::forWorker`) are unchanged. Every dashboard query filters `kind = server`.
- **Do not log in to Grafana with a password, and do not call Grafana's HTTP API with basic auth.** Lars logs in himself in the Browser pane (`http://127.0.0.1:3000`, `admin`/`admin`); after that the session cookie lets you navigate. Verify data via the Tempo HTTP API from inside the `php` container (`docker compose exec -T php curl -s 'http://tempo:3200/...'`) — Tempo has no auth and port 3200 is not published to the host.
- **Tempo TraceQL metrics in 2.7.1** offer `rate`, `count_over_time`, `min_over_time`, `max_over_time`, `avg_over_time`, `quantile_over_time`, `histogram_over_time`. There is NO `sum_over_time`. Structural operators work in metrics queries: `{ kind = server && span.http.route = "x" } >> { kind = internal } | count_over_time() by (name)` returns the descendant spans grouped by their own attributes (verified on the live stack).
- Instant metrics: `GET /api/metrics/query?q=...&start=&end=` returns `{"series":[{"labels":[...],"value":N,"promLabels":"..."}]}` — the value sits on the series, not in `samples`.
- Clean Code rules of `CLAUDE.md` apply: no comments unless a future reader would get the code wrong, one-to-three-line docblocks at most, `final readonly` by default, PHPMD-clean touched files, PHPStan level max, PSR-12.
- Commit format: `type(#1011): subject` (`feat`, `fix`, `chore`, `docs`, `refactor`, `test`). No attribution lines.
- Gates before the PR: `composer check`, `composer md`, `php bin/phpunit` (SQLite, native), `docker compose exec php vendor/bin/phpunit` (MySQL leg), `composer infection:diff`, PhpStorm inspections on changed PHP (`mcp__phpstorm__lint_files`), `docker compose config` for both compose files, and a scan of today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`).
- **Another Claude session shares this checkout** and has uncommitted edits in `frontend/src/app/core/client-error-reporter.ts` and its spec. Never `git add -A`, never `stash`, never `reset`, never `checkout --` those files. Stage only the paths each task names.

---

### Task 0: Branch

**Files:** none

- [ ] **Step 1: Confirm the checkout state.** Run `git status --short` from the repo root. Expected: only the two `client-error-reporter*` files from the other session, plus this plan file (untracked). If anything else is modified, stop and ask Lars.

- [ ] **Step 2: Create the feature branch off `develop`.** The other session's modified files ride along untouched; that is fine.

```bash
git checkout -b feature/1011-route-performance-dashboard develop
```

- [ ] **Step 3: Commit the plan.**

```bash
git add docs/superpowers/plans/2026-09-13-1011-route-performance-dashboard.md
git commit -m "docs(#1011): plan the route performance dashboard"
```

---

### Task 1: Grafana 13.2.1 and a 72 h Tempo metrics range

**Files:**
- Modify: `docker-compose.yml` (grafana `image:` line, currently `grafana/grafana:11.5.2` at ~line 202)
- Modify: `docker-compose.prod.yml` (grafana `image:` line, currently `grafana/grafana:11.5.2` at ~line 176)
- Modify: `docker/tempo/tempo-config.yaml`

**Why:** Grafana 11.5.2's Tempo plugin has no instant TraceQL metrics query; Grafana 11.6+ has it, and 13.2.1 is current. The image was pulled and inspected: `/usr/share/grafana/data/plugins-bundled/{tempo,loki,grafana-pyroscope-datasource}` exist and the Tempo plugin (13.1.5) handles `metricsQueryType: instant`. Tempo rejects metrics queries over a range longer than `query_frontend.metrics.max_duration` (default 3 h) with `range specified by start and end (24h0m0s) exceeds 3h0m0s`; the dashboard defaults to 24 h.

- [ ] **Step 1: Bump both compose pins.** Replace `grafana/grafana:11.5.2` with `grafana/grafana:13.2.1` in `docker-compose.yml` and `docker-compose.prod.yml`. Nothing else in those blocks changes.

- [ ] **Step 2: Raise the Tempo metrics range.** In `docker/tempo/tempo-config.yaml`, add a top-level `query_frontend` block after `distributor:` and before `storage:`. `72h` equals `compactor.compaction.block_retention`, so the limit never bites inside retention.

```yaml
query_frontend:
  metrics:
    max_duration: 72h
```

- [ ] **Step 3: Validate the compose files.**

```bash
docker compose config -q && docker compose -f docker-compose.prod.yml --profile grafana config -q && echo ok
```

Expected: `ok`. (The prod file may warn about unset variables; a warning is fine, an error is not.)

- [ ] **Step 4: Roll the two containers.**

```bash
docker compose up -d grafana tempo
sleep 8
docker compose logs --tail 30 tempo | grep -i -E 'error|failed|level=error' ; echo "tempo grep exit $?"
curl -s http://127.0.0.1:3000/api/health
```

Expected: the tempo grep prints nothing (exit 1), and `/api/health` reports `"version":"13.2.1"`.

- [ ] **Step 5: Prove the 24 h metrics query now succeeds.**

```bash
S=$(( $(date +%s) - 86400 )); E=$(date +%s)
docker compose exec -T php curl -s "http://tempo:3200/api/metrics/query?q=%7B%20kind%20%3D%20server%20%7D%20%7C%20count_over_time()%20by%20(span.http.route)&start=$S&end=$E" | jq -c '[.series[] | {route: .labels[0].value.stringValue, requests: .value}]'
```

Expected: a JSON list with one entry per route and a numeric `requests`, no `exceeds 3h0m0s` error.

- [ ] **Step 6: Ask Lars to log in once.** Tell him: "Please open http://127.0.0.1:3000 in the Browser pane and log in, so I can check the provisioned dashboards on Grafana 13." Then open `http://127.0.0.1:3000/d/application-logs/application-logs` and `http://127.0.0.1:3000/d/application-performance/application-performance` and screenshot each. Expected: both dashboards render with data (the old performance panels still, for now) and no red "plugin not found" panels.

- [ ] **Step 7: Commit.**

```bash
git add docker-compose.yml docker-compose.prod.yml docker/tempo/tempo-config.yaml
git commit -m "chore(#1011): Grafana 13.2.1 for instant TraceQL metrics; 72h Tempo metrics range"
```

---

### Task 2: `route` label on the per-request profile

**Files:**
- Modify: `backend/src/Service/Profiling/ProfileLabels.php`
- Modify: `backend/src/EventListener/RequestProfilingListener.php`
- Test: `backend/tests/Service/Profiling/ProfileLabelsTest.php`
- Test: `backend/tests/EventListener/RequestProfilingListenerTest.php`

**Interfaces:**
- Produces: `ProfileLabels::withRoute(string $route): self` — returns a new instance with a `route` label appended. `forWebRequest` and `forWorker` keep their signatures.
- Produces: `RequestProfilingListener::onKernelTerminate(TerminateEvent $event): void` — now takes the event; it reads `_route` from the event's request.

**Why the route is read at terminate, not at request:** the listener starts sampling at `kernel.request` priority 4096, which runs BEFORE Symfony's `RouterListener` (priority 32) sets `_route`. At `kernel.terminate` the same `Request` object carries `_route`. Trace and span ids are still captured at request time, because the server span is already ended when terminate runs.

- [ ] **Step 1: Write the failing `ProfileLabels` test.** Add to `ProfileLabelsTest`:

```php
    public function testWithRouteAppendsARouteLabelToTheWebRequestLabels(): void
    {
        $name = ProfileLabels::forWebRequest('abc', 'def')
            ->withRoute('api_entries_list')
            ->toNameParameter('simple-feed-reader');

        self::assertSame(
            'simple-feed-reader{service_name=simple-feed-reader,process=web,trace_id=abc,span_id=def,route=api_entries_list}',
            $name,
        );
    }

    public function testWithRouteLeavesTheOriginalLabelsUntouched(): void
    {
        $original = ProfileLabels::forWebRequest('abc', 'def');
        $original->withRoute('api_entries_list');

        self::assertStringNotContainsString('route=', $original->toNameParameter('simple-feed-reader'));
    }
```

- [ ] **Step 2: Run it, expect failure.**

```bash
cd backend && php bin/phpunit tests/Service/Profiling/ProfileLabelsTest.php
```

Expected: FAIL, `Call to undefined method ... withRoute()`.

- [ ] **Step 3: Implement `withRoute`.** In `ProfileLabels`, after `forWorker`:

```php
    public function withRoute(string $route): self
    {
        return new self([...$this->labels, 'route' => $route]);
    }
```

- [ ] **Step 4: Run it, expect pass.** Same command. Expected: PASS, 4 tests.

- [ ] **Step 5: Write the failing listener tests.** In `RequestProfilingListenerTest`:

Replace the two event helpers and add a terminate helper, so one `Request` flows through both events:

```php
    private function mainRequestEvent(Request $request = new Request()): RequestEvent
    {
        return new RequestEvent($this->kernel(), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function subRequestEvent(): RequestEvent
    {
        return new RequestEvent($this->kernel(), new Request(), HttpKernelInterface::SUB_REQUEST);
    }

    private function terminateEvent(Request $request = new Request()): TerminateEvent
    {
        return new TerminateEvent($this->kernel(), $request, new Response());
    }

    private function routedRequest(string $route): Request
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return $request;
    }
```

Add `use Symfony\Component\HttpFoundation\Response;` and `use Symfony\Component\HttpKernel\Event\TerminateEvent;`.

Change every existing `$listener->onKernelTerminate();` call to `$listener->onKernelTerminate($this->terminateEvent());`.

Change the first test so the request is routed and assert the label:

```php
    public function testMainRequestWithinPolicyStartsTheSamplerAndTerminatePushesWithSpanLabels(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);
        $request = $this->routedRequest('api_entries_list');

        $listener->onKernelRequest($this->mainRequestEvent($request));
        $listener->onKernelTerminate($this->terminateEvent($request));

        self::assertSame([RequestProfilingListener::SAMPLE_PERIOD_SECONDS], $sampler->startedWithPeriods);
        self::assertCount(1, $pushes);
        self::assertStringContainsString('trace_id=' . self::TRACE_ID, $pushes[0]['name']);
        self::assertStringContainsString('span_id=' . self::SPAN_ID, $pushes[0]['name']);
        self::assertStringContainsString('route=api_entries_list', $pushes[0]['name']);
    }

    public function testARequestThatNeverReachedTheRouterIsLabelledUnrouted(): void
    {
        $sampler = new RecordingProfileSampler();
        $pushes = [];
        $listener = $this->listener($sampler, $pushes, enabled: true, traceId: self::TRACE_ID, spanId: self::SPAN_ID);

        $listener->onKernelRequest($this->mainRequestEvent());
        $listener->onKernelTerminate($this->terminateEvent());

        self::assertStringContainsString('route=unrouted', $pushes[0]['name']);
    }
```

- [ ] **Step 6: Run them, expect failure.**

```bash
cd backend && php bin/phpunit tests/EventListener/RequestProfilingListenerTest.php
```

Expected: FAIL — `onKernelTerminate()` rejects the argument or the assertion on `route=` fails.

- [ ] **Step 7: Implement the listener change.** In `RequestProfilingListener`:

```php
use Symfony\Component\HttpFoundation\Request;
```

```php
    private const string UNROUTED = 'unrouted';
```

```php
    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (null === $this->labels) {
            return;
        }
        $labels = $this->labels->withRoute(self::routeOf($event->getRequest()));
        $this->labels = null;
        try {
            $profile = $this->sampler->stop();
            if (null !== $profile) {
                $this->client->push($profile, $labels);
            }
        } catch (\Throwable) {
        }
    }

    private static function routeOf(Request $request): string
    {
        return $request->attributes->getString('_route', self::UNROUTED);
    }
```

The `#[AsEventListener(event: TerminateEvent::class, method: 'onKernelTerminate', priority: 16)]` attribute stays; Symfony now passes the event.

- [ ] **Step 8: Run the two test files, expect pass.**

```bash
cd backend && php bin/phpunit tests/EventListener/RequestProfilingListenerTest.php tests/Service/Profiling/ProfileLabelsTest.php
```

Expected: PASS.

- [ ] **Step 9: Lint.** `cd backend && composer check && composer md` — both clean. Run `mcp__phpstorm__lint_files` on the two `src` files; fix ERROR/WARNING.

- [ ] **Step 10: Prove it in Docker.** Profiling must be ON (admin Settings → Grafana → Profiling; ask Lars if it is off — do not PUT the admin endpoint yourself without a token). The `php` container bind-mounts `backend/`, so no rebuild is needed; clear the cache so the listener attribute is re-read:

```bash
docker compose exec -T php bin/console cache:clear
curl -sk https://localhost:8443/api/setup/status >/dev/null
sleep 3
docker compose exec -T php curl -s 'http://pyroscope:4040/querier.v1.QuerierService/LabelValues' -H 'content-type: application/json' -d "{\"name\":\"route\",\"start\":$(( ($(date +%s) - 600) * 1000 )),\"end\":$(( $(date +%s) * 1000 ))}"
```

Expected: the label values include `api_setup_status`. If Pyroscope answers with an error about the request shape, use the Pyroscope UI at http://localhost:4040 instead: pick the `simple-feed-reader` app and confirm a `route` label appears in the label selector.

- [ ] **Step 11: Commit.**

```bash
git add backend/src/Service/Profiling/ProfileLabels.php backend/src/EventListener/RequestProfilingListener.php backend/tests/Service/Profiling/ProfileLabelsTest.php backend/tests/EventListener/RequestProfilingListenerTest.php
git commit -m "feat(#1011): label per-request profiles with the Symfony route"
```

---

### Task 3: Database spans via `opentelemetry-auto-doctrine`

**Files:**
- Modify: `backend/composer.json`, `backend/composer.lock`

**Why:** The package hooks `Doctrine\DBAL\Driver::connect`, `Driver\Connection::{query,exec,prepare,beginTransaction,commit,rollBack}` and `Driver\Statement::execute`, emitting one `client` span per call with `db.query.text`, `db.operation.name` and `db.collection.name` (prepared statements carry placeholders, never values). Its `_register.php` returns early when `Sdk::isInstrumentationDisabled('doctrine')` or when the extension is absent, exactly like `opentelemetry-auto-symfony`, so CI (`OTEL_PHP_DISABLED_INSTRUMENTATIONS=all`) and Strato are unaffected. The `config.platform.ext-opentelemetry` pin in `composer.json` makes the require resolve without the extension.

- [ ] **Step 1: Require the package natively.**

```bash
cd backend && composer require open-telemetry/opentelemetry-auto-doctrine:^0.5 --no-scripts --no-interaction
```

Expected: resolves WITHOUT `--ignore-platform-req`. `git diff composer.json` shows exactly one new `require` line. `git diff config/bundles.php` is empty (the package is not a bundle).

- [ ] **Step 2: Prove the no-extension path stays green.**

```bash
cd backend && php bin/console cache:clear && php bin/phpunit 2>&1 | tail -5
```

Expected: the full suite passes. Any stderr line about the opentelemetry extension must be one that `develop` already prints (the auto-symfony `_register.php` warning); a second, Doctrine-flavoured warning means the new package skipped its `isInstrumentationDisabled` check — investigate before going on.

- [ ] **Step 3: Install in the container and restart FPM.**

```bash
docker compose exec -T php composer install --no-interaction --no-scripts
docker compose exec -T php bin/console cache:clear
docker compose restart php
sleep 3
docker compose ps php
```

Expected: `php` is `Up`. (`restart` keeps the container IP, so nginx does not need a restart.)

- [ ] **Step 4: Prove database spans arrive in Tempo.** `api_setup_status` needs no token and reads the database.

```bash
curl -sk https://localhost:8443/api/setup/status >/dev/null; sleep 5
S=$(( $(date +%s) - 600 )); E=$(date +%s)
docker compose exec -T php curl -s "http://tempo:3200/api/search?q=%7B%20kind%20%3D%20server%20%26%26%20span.http.route%20%3D%20%22api_setup_status%22%20%7D%20%3E%3E%20%7B%20span.db.query.text%20!%3D%20%22%22%20%7D&start=$S&end=$E&limit=1&spss=5" | jq -c '.traces[0].spanSets[]?.spans[] | {name, durationNanos, attrs: [.attributes[] | select(.key|startswith("db.")) | .key + "=" + (.value.stringValue // "")]}'
```

Expected: at least one span whose attributes include `db.query.text=SELECT ...`. Note which span names carry `db.query.text` (expect the `prepare` and/or `execute` spans); Task 5's query table filters on `span.db.query.text != ""` and groups by it.

- [ ] **Step 5: Commit.**

```bash
git add backend/composer.json backend/composer.lock
git commit -m "feat(#1011): trace every DBAL query as a span with its SQL text"
```

---

### Task 4: Service spans with `#[WithSpan]` on the hot routes

**Files:**
- Modify: `docker/php/conf.d/app.ini`, `docker/php/conf.d/prod.ini`
- Modify (attribute only, one `use` + one attribute line per method):
  - `backend/src/Repository/EntryListRepository.php` — `listForUser`, `findOneSubscribedByUser`
  - `backend/src/Service/Recommendation/ForYouFeedResponder.php` — `page`
  - `backend/src/Service/Recommendation/RecommendationFeedPager.php` — `page`
  - `backend/src/Service/Reader/ArticleExtractor.php` — `extract`, `richestArticle`
  - `backend/src/Service/Reader/HtmlPageFetcher.php` — `fetch`
  - `backend/src/Service/Reader/FetchedPageNormalizer.php` — `normalize`
  - `backend/src/Service/Reader/Media/PageMediaScanner.php` — `scan`
  - `backend/src/Service/Reader/ReaderBodyCleaner.php` — `clean`
  - `backend/src/Service/Sanitize/EntrySanitizer.php` — `sanitize`
  - `backend/src/Repository/SubscriptionRepository.php` — `findForUserWithTags`
  - `backend/src/Repository/EntryStateRepository.php` — `unreadCountsForUser`, `stateCountsForUser`
  - `backend/src/Repository/TagRepository.php` — `findForUser`
  - `backend/src/Repository/SavedSearchRepository.php` — `findForUser`
  - `backend/src/Service/Search/SavedSearchMatchIds.php` — `forAll`
  - `backend/src/Service/Recommendation/RecommendationPollDriver.php` — `current`
  - `backend/src/Service/Recommendation/RecommendationRunStatusPayload.php` — `forReport`
- Test: `backend/tests/Service/Tracing/TracedServiceMethodsTest.php` (create)

**Route → methods.** `api_entries_list`: `EntryListRepository::listForUser` plus the for-you branch `ForYouFeedResponder::page` → `RecommendationFeedPager::page`. `api_entries_reader`: `EntryListRepository::findOneSubscribedByUser`, then `ArticleExtractor::extract` with its stages `HtmlPageFetcher::fetch`, `FetchedPageNormalizer::normalize`, `PageMediaScanner::scan`, `ArticleExtractor::richestArticle` (the readability pass), `ReaderBodyCleaner::clean`, `EntrySanitizer::sanitize`. `api_subscriptions_list`: `SubscriptionRepository::findForUserWithTags`, `EntryStateRepository::unreadCountsForUser`, `EntryStateRepository::stateCountsForUser`. `api_tags_list`: `TagRepository::findForUser`. `api_saved_searches_list`: `SavedSearchRepository::findForUser`, `SavedSearchMatchIds::forAll`. `api_recommendations_current`: `RecommendationPollDriver::current`, `RecommendationRunStatusPayload::forReport`. `api_me` calls no service (a static JSON mapper on the authenticated user), so it gets no span.

If one of the listed files does not declare the method under exactly that name (the list was read from the controllers on 2026-09-13), find the method the controller actually calls and use that; update the test list to match.

**How `#[WithSpan]` works:** with `opentelemetry.attr_hooks_enabled=1`, the extension calls `OpenTelemetry\API\Instrumentation\WithSpanHandler::pre/post` around every function that carries the attribute (the handler names are the extension's defaults, visible in `php --ri opentelemetry`). The span is `kind = internal`, named `Class::method`. Without the extension the attribute is inert metadata: no code path, no dependency in the class beyond the `use` of an attribute class that ships in `open-telemetry/api` (already a production dependency).

- [ ] **Step 1: Write the failing test.** Create `backend/tests/Service/Tracing/TracedServiceMethodsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracing;

use App\Repository\EntryListRepository;
use App\Repository\EntryStateRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Recommendation\ForYouFeedResponder;
use App\Service\Recommendation\RecommendationFeedPager;
use App\Service\Recommendation\RecommendationPollDriver;
use App\Service\Recommendation\RecommendationRunStatusPayload;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Search\SavedSearchMatchIds;
use OpenTelemetry\API\Instrumentation\WithSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entry-point methods of the hot API routes are traced as child spans, so the
 * performance dashboard can show where a route spends its time (#1011).
 */
final class TracedServiceMethodsTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function tracedMethods(): iterable
    {
        yield 'entries list' => [EntryListRepository::class, 'listForUser'];
        yield 'entries list, for-you responder' => [ForYouFeedResponder::class, 'page'];
        yield 'entries list, for-you pager' => [RecommendationFeedPager::class, 'page'];
        yield 'reader, ownership lookup' => [EntryListRepository::class, 'findOneSubscribedByUser'];
        yield 'reader, extraction' => [ArticleExtractor::class, 'extract'];
        yield 'reader, fetch' => [HtmlPageFetcher::class, 'fetch'];
        yield 'reader, normalise' => [FetchedPageNormalizer::class, 'normalize'];
        yield 'reader, media scan' => [PageMediaScanner::class, 'scan'];
        yield 'reader, readability' => [ArticleExtractor::class, 'richestArticle'];
        yield 'reader, body clean' => [ReaderBodyCleaner::class, 'clean'];
        yield 'reader, sanitise' => [EntrySanitizer::class, 'sanitize'];
        yield 'subscriptions list' => [SubscriptionRepository::class, 'findForUserWithTags'];
        yield 'subscriptions list, unread counts' => [EntryStateRepository::class, 'unreadCountsForUser'];
        yield 'subscriptions list, state counts' => [EntryStateRepository::class, 'stateCountsForUser'];
        yield 'tags list' => [TagRepository::class, 'findForUser'];
        yield 'saved searches list' => [SavedSearchRepository::class, 'findForUser'];
        yield 'saved searches list, matches' => [SavedSearchMatchIds::class, 'forAll'];
        yield 'recommendations current, poll' => [RecommendationPollDriver::class, 'current'];
        yield 'recommendations current, payload' => [RecommendationRunStatusPayload::class, 'forReport'];
    }

    /** @param class-string $class */
    #[DataProvider('tracedMethods')]
    public function testTheMethodOpensASpan(string $class, string $method): void
    {
        $attributes = new \ReflectionMethod($class, $method)->getAttributes(WithSpan::class);

        self::assertCount(1, $attributes, sprintf('%s::%s must carry #[WithSpan]', $class, $method));
    }
}
```

- [ ] **Step 2: Run it, expect 19 failures.**

```bash
cd backend && php bin/phpunit tests/Service/Tracing/TracedServiceMethodsTest.php
```

Expected: FAIL on every case with `must carry #[WithSpan]`. A `ReflectionException` instead means a method name in the list is wrong — fix the list from the real controller call, not by guessing.

- [ ] **Step 3: Add the attribute to each method.** In every listed file add `use OpenTelemetry\API\Instrumentation\WithSpan;` (alphabetically among the `use` lines) and put `#[WithSpan]` on its own line directly above the method signature, below any docblock. Example for `EntryListRepository::listForUser`:

```php
    #[WithSpan]
    public function listForUser(EntryQuery $query): array
    {
```

Nothing else in the method changes. No comments.

- [ ] **Step 4: Run the test, expect pass.** Same command. Expected: 19 passes.

- [ ] **Step 5: Enable attribute hooks in both Docker PHP images.** Append to `docker/php/conf.d/app.ini` and `docker/php/conf.d/prod.ini`:

```ini
; #[WithSpan] methods become child spans (#1011). Off by default in the extension.
opentelemetry.attr_hooks_enabled = 1
```

Keep the existing `post_max_size` lines byte-identical: `RequestBodyLimitAgreementTest` compares them across five files.

- [ ] **Step 6: Lint and test everything touched.**

```bash
cd backend && composer check && composer md && php bin/phpunit 2>&1 | tail -3
```

Expected: all clean, suite green. Run `mcp__phpstorm__lint_files` over the touched `src` files; fix ERROR/WARNING.

- [ ] **Step 7: Rebuild the PHP images and prove the spans exist.**

```bash
docker compose build php worker
docker compose up -d php worker
docker compose restart nginx
sleep 5
docker compose exec -T php php -r 'echo ini_get("opentelemetry.attr_hooks_enabled"), PHP_EOL;'
```

Expected: `1`. (`up -d` recreated `php` with a new IP; the nginx restart is what makes 8443 reachable again.)

Now the authenticated routes need real traffic. Ask Lars: "Please open the app at https://localhost:8443 (or :4200) in the Browser pane and log in, then open the entry list and one article in the reader." After that:

```bash
S=$(( $(date +%s) - 900 )); E=$(date +%s)
docker compose exec -T php curl -s "http://tempo:3200/api/metrics/query?q=%7B%20kind%20%3D%20server%20%26%26%20span.http.route%20%3D%20%22api_entries_list%22%20%7D%20%3E%3E%20%7B%20kind%20%3D%20internal%20%7D%20%7C%20count_over_time()%20by%20(name)&start=$S&end=$E" | jq -c '[.series[] | {method: .labels[0].value.stringValue, calls: .value}]'
docker compose exec -T php curl -s "http://tempo:3200/api/metrics/query?q=%7B%20kind%20%3D%20server%20%26%26%20span.http.route%20%3D%20%22api_entries_reader%22%20%7D%20%3E%3E%20%7B%20kind%20%3D%20internal%20%7D%20%7C%20count_over_time()%20by%20(name)&start=$S&end=$E" | jq -c '[.series[] | {method: .labels[0].value.stringValue, calls: .value}]'
```

Expected: the first list contains `App\Repository\EntryListRepository::listForUser`; the second contains `App\Service\Reader\ArticleExtractor::extract` and its stages. If `richestArticle` (private) is missing while the public ones are present, move that one attribute to `ArticleExtractor::parse` and update the test list; report the change in the PR.

- [ ] **Step 8: Commit.**

```bash
git add docker/php/conf.d/app.ini docker/php/conf.d/prod.ini backend/tests/Service/Tracing/TracedServiceMethodsTest.php backend/src/Repository/EntryListRepository.php backend/src/Repository/EntryStateRepository.php backend/src/Repository/SavedSearchRepository.php backend/src/Repository/SubscriptionRepository.php backend/src/Repository/TagRepository.php backend/src/Service/Reader/ArticleExtractor.php backend/src/Service/Sanitize/EntrySanitizer.php backend/src/Service/Reader/FetchedPageNormalizer.php backend/src/Service/Reader/HtmlPageFetcher.php backend/src/Service/Reader/Media/PageMediaScanner.php backend/src/Service/Reader/ReaderBodyCleaner.php backend/src/Service/Recommendation/ForYouFeedResponder.php backend/src/Service/Recommendation/RecommendationFeedPager.php backend/src/Service/Recommendation/RecommendationPollDriver.php backend/src/Service/Recommendation/RecommendationRunStatusPayload.php backend/src/Service/Search/SavedSearchMatchIds.php
git commit -m "feat(#1011): open a span on the entry-point methods of the hot API routes"
```

---

### Task 5: Rewrite the dashboard around a `route` variable

**Files:**
- Rewrite: `docker/grafana/dashboards/application-performance.json`

**Datasource query shapes (Grafana 13 Tempo plugin):** `{"refId":"A","queryType":"traceql","query":"...","metricsQueryType":"instant"}` for one value per series; `"metricsQueryType":"range","step":"1m"` for a time series; `{"queryType":"traceql","query":"...","tableType":"traces","limit":20,"spss":1}` for a trace list. Instant results arrive as one frame per series carrying the `by (...)` labels.

- [ ] **Step 1: Write the new dashboard file.** Replace the whole content of `docker/grafana/dashboards/application-performance.json` with:

```json
{
  "uid": "application-performance",
  "title": "Application performance",
  "tags": ["simple-feed-reader"],
  "schemaVersion": 39,
  "version": 3,
  "editable": true,
  "id": null,
  "time": { "from": "now-24h", "to": "now" },
  "refresh": "1m",
  "links": [
    { "type": "dashboards", "tags": ["simple-feed-reader"], "asDropdown": true, "title": "Dashboards" }
  ],
  "templating": {
    "list": [
      {
        "name": "route",
        "label": "Route",
        "type": "query",
        "datasource": { "type": "tempo", "uid": "tempo" },
        "query": { "refId": "TempoVariableQueryEditor-VariableQuery", "type": 1, "label": "span.http.route" },
        "refresh": 2,
        "sort": 1,
        "multi": false,
        "includeAll": false,
        "current": { "selected": true, "text": "api_entries_list", "value": "api_entries_list" }
      }
    ]
  },
  "panels": [
    {
      "id": 1,
      "type": "table",
      "title": "Routes in range",
      "description": "One row per route over the selected time range. Median and max are wall-clock durations of the server span.",
      "datasource": { "type": "tempo", "uid": "tempo" },
      "gridPos": { "h": 10, "w": 24, "x": 0, "y": 0 },
      "targets": [
        { "refId": "A", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server } | count_over_time() by (span.http.route)" },
        { "refId": "B", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server } | quantile_over_time(duration, .5) by (span.http.route)" },
        { "refId": "C", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server } | max_over_time(duration) by (span.http.route)" },
        { "refId": "D", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && status = error } | count_over_time() by (span.http.route)" }
      ],
      "transformations": [
        { "id": "labelsToFields", "options": { "mode": "columns" } },
        { "id": "merge", "options": {} },
        {
          "id": "organize",
          "options": {
            "excludeByName": {},
            "renameByName": {
              "span.http.route": "Route",
              "Value #A": "Requests",
              "Value #B": "Median",
              "Value #C": "Max",
              "Value #D": "Errors"
            }
          }
        }
      ],
      "fieldConfig": {
        "defaults": { "custom": { "align": "auto", "filterable": false } },
        "overrides": [
          { "matcher": { "id": "byName", "options": "Median" }, "properties": [{ "id": "unit", "value": "s" }, { "id": "decimals", "value": 3 }] },
          { "matcher": { "id": "byName", "options": "Max" }, "properties": [{ "id": "unit", "value": "s" }, { "id": "decimals", "value": 3 }] },
          { "matcher": { "id": "byName", "options": "Errors" }, "properties": [{ "id": "thresholds", "value": { "mode": "absolute", "steps": [{ "color": "text", "value": null }, { "color": "red", "value": 1 }] } }, { "id": "custom.cellOptions", "value": { "type": "color-text" } }] }
        ]
      },
      "options": { "sortBy": [{ "displayName": "Max", "desc": true }], "showHeader": true }
    },
    {
      "id": 2,
      "type": "timeseries",
      "title": "Requests of $route",
      "description": "One point per minute that saw a request: the slowest request that started in that minute. At this traffic that is every request.",
      "datasource": { "type": "tempo", "uid": "tempo" },
      "gridPos": { "h": 9, "w": 24, "x": 0, "y": 10 },
      "targets": [
        { "refId": "A", "queryType": "traceql", "metricsQueryType": "range", "step": "1m", "query": "{ kind = server && span.http.route = \"$route\" } | max_over_time(duration)" }
      ],
      "fieldConfig": {
        "defaults": {
          "unit": "s",
          "displayName": "duration",
          "custom": { "drawStyle": "points", "pointSize": 7, "showPoints": "always", "spanNulls": false, "lineWidth": 0 }
        },
        "overrides": []
      },
      "options": { "legend": { "showLegend": false }, "tooltip": { "mode": "single" } }
    },
    {
      "id": 3,
      "type": "table",
      "title": "Methods inside $route",
      "description": "Child spans opened by #[WithSpan] methods under this route's requests, over the selected range.",
      "datasource": { "type": "tempo", "uid": "tempo" },
      "gridPos": { "h": 10, "w": 12, "x": 0, "y": 19 },
      "targets": [
        { "refId": "A", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && span.http.route = \"$route\" } >> { kind = internal } | count_over_time() by (name)" },
        { "refId": "B", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && span.http.route = \"$route\" } >> { kind = internal } | avg_over_time(duration) by (name)" },
        { "refId": "C", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && span.http.route = \"$route\" } >> { kind = internal } | max_over_time(duration) by (name)" }
      ],
      "transformations": [
        { "id": "labelsToFields", "options": { "mode": "columns" } },
        { "id": "merge", "options": {} },
        { "id": "organize", "options": { "renameByName": { "name": "Method", "Value #A": "Calls", "Value #B": "Avg", "Value #C": "Max" } } }
      ],
      "fieldConfig": {
        "defaults": {},
        "overrides": [
          { "matcher": { "id": "byName", "options": "Avg" }, "properties": [{ "id": "unit", "value": "s" }, { "id": "decimals", "value": 3 }] },
          { "matcher": { "id": "byName", "options": "Max" }, "properties": [{ "id": "unit", "value": "s" }, { "id": "decimals", "value": 3 }] }
        ]
      },
      "options": { "sortBy": [{ "displayName": "Max", "desc": true }], "showHeader": true }
    },
    {
      "id": 4,
      "type": "table",
      "title": "Queries inside $route",
      "description": "DBAL statements executed under this route's requests. Prepared statements show placeholders, never values.",
      "datasource": { "type": "tempo", "uid": "tempo" },
      "gridPos": { "h": 10, "w": 12, "x": 12, "y": 19 },
      "targets": [
        { "refId": "A", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && span.http.route = \"$route\" } >> { span.db.query.text != \"\" } | count_over_time() by (span.db.query.text)" },
        { "refId": "B", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && span.http.route = \"$route\" } >> { span.db.query.text != \"\" } | avg_over_time(duration) by (span.db.query.text)" },
        { "refId": "C", "queryType": "traceql", "metricsQueryType": "instant", "query": "{ kind = server && span.http.route = \"$route\" } >> { span.db.query.text != \"\" } | max_over_time(duration) by (span.db.query.text)" }
      ],
      "transformations": [
        { "id": "labelsToFields", "options": { "mode": "columns" } },
        { "id": "merge", "options": {} },
        { "id": "organize", "options": { "renameByName": { "span.db.query.text": "SQL", "Value #A": "Calls", "Value #B": "Avg", "Value #C": "Max" } } }
      ],
      "fieldConfig": {
        "defaults": { "custom": { "inspect": true } },
        "overrides": [
          { "matcher": { "id": "byName", "options": "Avg" }, "properties": [{ "id": "unit", "value": "s" }, { "id": "decimals", "value": 3 }] },
          { "matcher": { "id": "byName", "options": "Max" }, "properties": [{ "id": "unit", "value": "s" }, { "id": "decimals", "value": 3 }] },
          { "matcher": { "id": "byName", "options": "SQL" }, "properties": [{ "id": "custom.width", "value": 520 }] }
        ]
      },
      "options": { "sortBy": [{ "displayName": "Max", "desc": true }], "showHeader": true }
    },
    {
      "id": 5,
      "type": "table",
      "title": "Traces of $route, slowest first",
      "datasource": { "type": "tempo", "uid": "tempo" },
      "gridPos": { "h": 10, "w": 24, "x": 0, "y": 29 },
      "targets": [
        { "refId": "A", "queryType": "traceql", "tableType": "traces", "limit": 50, "spss": 1, "query": "{ kind = server && span.http.route = \"$route\" }" }
      ],
      "fieldConfig": { "defaults": {}, "overrides": [] },
      "options": { "sortBy": [{ "displayName": "Duration", "desc": true }], "showHeader": true }
    },
    {
      "id": 6,
      "type": "flamegraph",
      "title": "Profile of $route (CPU/wall time, all sampled requests in range)",
      "datasource": { "type": "grafana-pyroscope-datasource", "uid": "pyroscope" },
      "gridPos": { "h": 12, "w": 24, "x": 0, "y": 39 },
      "targets": [
        {
          "refId": "A",
          "queryType": "profile",
          "profileTypeId": "process_cpu:cpu:nanoseconds:cpu:nanoseconds",
          "labelSelector": "{service_name=\"simple-feed-reader\", route=\"$route\"}"
        }
      ]
    }
  ]
}
```

- [ ] **Step 2: Validate the JSON.**

```bash
jq -e '.panels | length == 6' docker/grafana/dashboards/application-performance.json && jq -r '.templating.list[0].name' docker/grafana/dashboards/application-performance.json
```

Expected: `true` and `route`.

- [ ] **Step 3: Reload the provisioned dashboard.** The dashboards folder is bind-mounted read-only; the provider re-reads it within 10 s. If Grafana keeps showing the old panels, `docker compose restart grafana`.

- [ ] **Step 4: Verify in the browser (Lars is logged in from Task 1).** Open `http://127.0.0.1:3000/d/application-performance/application-performance?orgId=1&from=now-24h&to=now` in the Browser pane, take a screenshot, and check:
  - The `Route` variable lists the routes Tempo knows (compare with `docker compose exec -T php curl -s 'http://tempo:3200/api/v2/search/tag/span.http.route/values' | jq -c '.tagValues|map(.value)'`).
  - "Routes in range" shows one row per route with Requests, Median, Max, Errors. Cross-check Requests against the instant count query from Task 1 Step 5.
  - "Requests of $route" shows dots, one per request minute.
  - "Methods inside $route" lists `App\...::method` names for `api_entries_list` and `api_entries_reader`.
  - "Queries inside $route" lists SQL text.
  - "Traces of $route" lists traces with a Duration column; the trace ID cell links into Explore.
  - The flame graph renders for a route that has sampled requests (profiling on).

  **If a table shows the four values as separate rows instead of columns**, open the panel editor, fix the transformation chain in the UI until the columns are right (the likely fix is dropping `labelsToFields` when the instant frames already arrive as tables, or renaming `Value #A` to the actual field names shown in the Table view), then copy the panel's JSON model back into the file. Keep `"id": null` at the top level and the `uid`. The file, not Grafana's database, is the source of truth.

- [ ] **Step 5: Commit.**

```bash
git add docker/grafana/dashboards/application-performance.json
git commit -m "feat(#1011): performance dashboard per route: table, request points, methods, queries, traces, profile"
```

---

### Task 6: Documentation

**Files:**
- Modify: `docs/local-docker.md` (the Grafana row in the services table near line 33, and the "Profiling (Pyroscope)" section near line 366)

- [ ] **Step 1: Describe the dashboard in the services table.** Change the Grafana row's first cell to: `Grafana — provisioned with "Application logs" and "Application performance" (per-route timings, methods, queries, traces, profile) dashboards`.

- [ ] **Step 2: Update the profiling bullets.** Replace the first bullet under "See the profiles two ways:" with: `- The **Application performance** dashboard's flame graph, filtered to the route picked at the top (profiles carry a `route` label).`

- [ ] **Step 3: Add a short "Application performance" paragraph** at the end of the Profiling section:

```markdown
## Application performance dashboard

Pick a route at the top. The first table lists every route seen in the time
range with request count, median and max duration and error count. The panels
below it belong to the picked route: one point per request, the `#[WithSpan]`
methods and the DBAL statements that ran under it (call count, average, max),
the slowest traces, and the flame graph of its sampled requests. Requests per
second and p95 are absent on purpose: at a handful of requests per hour they
are empty buckets.
```

- [ ] **Step 4: Commit.**

```bash
git add docs/local-docker.md
git commit -m "docs(#1011): describe the per-route performance dashboard"
```

---

### Task 8: Profiling on by default in the dev stack

**Run this task BEFORE Task 7**; Task 7's gates and PR cover it.

**Files:**
- Create: `backend/src/Service/Profiling/ProfilingDefault.php`
- Create: `backend/src/Service/Profiling/ProfilingStatus.php`
- Modify: `backend/src/Service/Grafana/GrafanaSettings.php`
- Modify: `backend/src/Http/Admin/GrafanaSettingsJson.php`
- Test: `backend/tests/Service/Profiling/ProfilingDefaultTest.php` (create)
- Test: `backend/tests/Service/Grafana/GrafanaSettingsTest.php`
- Test: `backend/tests/Http/Admin/GrafanaSettingsJsonTest.php`
- Modify: `docs/local-docker.md`, `docker-compose.yml` (pyroscope comment), `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Produces: `ProfilingDefault::isOn(): bool` — true only when `kernel.environment` is `dev` AND `GrafanaEnvDefaults::$pyroscopePushUrl` is non-empty.
- Produces: `ProfilingStatus(bool $available, bool $enabled)` — value object the JSON mapper reads instead of the entity flag.
- Changes: `GrafanaSettingsJson::from(?GrafanaSettings $settings, GrafanaEnvDefaults $defaults, ProfilingStatus $profiling): array` (third parameter was `bool $profilerAvailable`).
- Changes: `GrafanaSettings::__construct(..., ProfileSampler $sampler, ProfilingDefault $profilingDefault)`.

**Rule:** a fresh dev-stack install (no `grafana_settings` row yet) profiles from the first request. The moment an admin saves the Grafana settings, the stored row decides, so the toggle can still turn it off. Docker prod (`APP_ENV=prod`) and the native test suite (`APP_ENV=test`) keep "off until switched on". The dev compose file already sets `PYROSCOPE_PUSH_URL`; no new env variable, no installer change.

- [ ] **Step 1: Write the failing `ProfilingDefault` test.** Create `backend/tests/Service/Profiling/ProfilingDefaultTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Grafana\GrafanaEnvDefaults;
use App\Service\Profiling\ProfilingDefault;
use PHPUnit\Framework\TestCase;

final class ProfilingDefaultTest extends TestCase
{
    public function testOnInDevWhenAPyroscopeContainerIsConfigured(): void
    {
        self::assertTrue(new ProfilingDefault('dev', $this->defaults('http://pyroscope:4040'))->isOn());
    }

    public function testOffInDevWithoutAPyroscopeContainer(): void
    {
        self::assertFalse(new ProfilingDefault('dev', $this->defaults(''))->isOn());
    }

    public function testOffOutsideDevEvenWithAPyroscopeContainer(): void
    {
        self::assertFalse(new ProfilingDefault('prod', $this->defaults('http://pyroscope:4040'))->isOn());
        self::assertFalse(new ProfilingDefault('test', $this->defaults('http://pyroscope:4040'))->isOn());
    }

    private function defaults(string $pyroscopePushUrl): GrafanaEnvDefaults
    {
        return new GrafanaEnvDefaults('', '', $pyroscopePushUrl);
    }
}
```

- [ ] **Step 2: Run it, expect failure.**

```bash
cd backend && php bin/phpunit tests/Service/Profiling/ProfilingDefaultTest.php
```

Expected: FAIL, class `ProfilingDefault` not found.

- [ ] **Step 3: Create the two value classes.**

`backend/src/Service/Profiling/ProfilingDefault.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Profiling;

use App\Service\Grafana\GrafanaEnvDefaults;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** What the profiling toggle means before an admin has ever saved the Grafana settings. */
final readonly class ProfilingDefault
{
    private const string DEV_ENVIRONMENT = 'dev';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
        private GrafanaEnvDefaults $defaults,
    ) {
    }

    public function isOn(): bool
    {
        return self::DEV_ENVIRONMENT === $this->environment && '' !== $this->defaults->pyroscopePushUrl;
    }
}
```

`backend/src/Service/Profiling/ProfilingStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Profiling;

final readonly class ProfilingStatus
{
    public function __construct(
        public bool $available,
        public bool $enabled,
    ) {
    }
}
```

- [ ] **Step 4: Run the test, expect pass.** Same command. Expected: PASS, 3 tests.

- [ ] **Step 5: Write the failing service tests.** In `backend/tests/Service/Grafana/GrafanaSettingsTest.php`:

Add `use App\Service\Profiling\ProfilingDefault;` to the imports.

Give the factory an environment parameter and pass the default to the constructor. The factory currently ends with:

```php
        $defaults = new GrafanaEnvDefaults($lokiPushUrlDefault, $grafanaUrlDefault, $pyroscopePushUrlDefault);

        return new GrafanaSettings($repository, $em, $cipher, $defaults, new NullProfileSampler());
```

Change the signature to add `string $environment = 'test',` after `string $pyroscopePushUrlDefault = '',` and the return to:

```php
        return new GrafanaSettings(
            $repository,
            $em,
            $cipher,
            $defaults,
            new NullProfileSampler(),
            new ProfilingDefault($environment, $defaults),
        );
```

Three tests construct the service inline (around lines 163, 189 and 214, each ending with `new NullProfileSampler(),`). Append one argument to each of them:

```php
            new ProfilingDefault('test', new GrafanaEnvDefaults('', '', '')),
```

Add two tests next to `testProfilingEnabledReflectsTheStoredRow`:

```php
    public function testProfilingIsOnInDevWithAPyroscopeContainerUntilAnAdminSaves(): void
    {
        $settings = $this->service($stored, pyroscopePushUrlDefault: 'http://pyroscope:4040', environment: 'dev');
        self::assertTrue($settings->profilingEnabled());
        self::assertTrue($settings->view()['profilingEnabled']);

        $settings->update(new GrafanaSettingsRequest(profilingEnabled: false));

        self::assertFalse($settings->profilingEnabled());
        self::assertFalse($settings->view()['profilingEnabled']);
    }

    public function testProfilingStaysOffByDefaultOutsideDev(): void
    {
        $settings = $this->service($stored, pyroscopePushUrlDefault: 'http://pyroscope:4040', environment: 'prod');

        self::assertFalse($settings->profilingEnabled());
    }
```

- [ ] **Step 6: Write the failing JSON mapper tests.** In `backend/tests/Http/Admin/GrafanaSettingsJsonTest.php` add `use App\Service\Profiling\ProfilingStatus;` and replace the third argument of every `GrafanaSettingsJson::from(...)` call:

- `false` becomes `new ProfilingStatus(available: false, enabled: false)`
- in `testProfilingOverrideToggleAndAvailabilityAreReported`, `true` becomes `new ProfilingStatus(available: true, enabled: true)`

The assertions stay as they are; `profilingEnabled` now echoes the status, not the entity flag.

- [ ] **Step 7: Run the two files, expect failure.**

```bash
cd backend && php bin/phpunit tests/Service/Grafana/GrafanaSettingsTest.php tests/Http/Admin/GrafanaSettingsJsonTest.php
```

Expected: FAIL (constructor arity, `from()` type error).

- [ ] **Step 8: Change the service.** In `backend/src/Service/Grafana/GrafanaSettings.php`:

Imports: add `use App\Service\Profiling\ProfilingDefault;` and `use App\Service\Profiling\ProfilingStatus;`.

Replace the memo field:

```php
    private ?GrafanaSettingsEntity $memoisedRow = null;
    private bool $rowResolved = false;
```

Constructor: append `private readonly ProfilingDefault $profilingDefault,` after the sampler.

`view()`:

```php
    public function view(): array
    {
        return GrafanaSettingsJson::from($this->settings(), $this->defaults, $this->profilingStatus());
    }
```

`profilingEnabled()`:

```php
    public function profilingEnabled(): bool
    {
        return $this->storedRow()?->isProfilingEnabled() ?? $this->profilingDefault->isOn();
    }
```

`refresh()`:

```php
    public function refresh(): void
    {
        $this->memoisedRow = null;
        $this->rowResolved = false;
    }
```

`settings()` keeps its docblock and becomes:

```php
    private function settings(): GrafanaSettingsEntity
    {
        return $this->storedRow() ?? new GrafanaSettingsEntity();
    }

    private function storedRow(): ?GrafanaSettingsEntity
    {
        if (!$this->rowResolved) {
            $this->memoisedRow = $this->repository->findSingleton();
            $this->rowResolved = true;
        }

        return $this->memoisedRow;
    }

    private function profilingStatus(): ProfilingStatus
    {
        return new ProfilingStatus($this->sampler->isAvailable(), $this->profilingEnabled());
    }
```

In the class docblock, change "settings() memoises the resolved row" to "storedRow() memoises the resolved row". Nothing else in that docblock changes.

- [ ] **Step 9: Change the mapper.** In `backend/src/Http/Admin/GrafanaSettingsJson.php` add `use App\Service\Profiling\ProfilingStatus;`, change the signature to

```php
    public static function from(
        ?GrafanaSettings $settings,
        GrafanaEnvDefaults $defaults,
        ProfilingStatus $profiling,
    ): array {
```

and the two array entries to

```php
            'profilingEnabled' => $profiling->enabled,
            'profilerAvailable' => $profiling->available,
```

- [ ] **Step 10: Run the Grafana and Profiling tests, expect pass.**

```bash
cd backend && php bin/phpunit --filter 'Grafana|Profiling'
```

Expected: PASS, including `AdminGrafanaControllerTest` (kernel env `test`, so the default stays off there).

- [ ] **Step 11: Update the copy that says "off by default".**

`docs/local-docker.md`, the sentence starting `code-level profiles. Profiling is **off by default**:` becomes:

```markdown
code-level profiles. In this dev stack profiling is **on by default** (`APP_ENV=dev`
with a Pyroscope URL, #1011) until an admin saves the Grafana settings; Docker prod
starts **off**. Toggle it in the admin **Settings → Grafana → Profiling** switch (or
`PUT /api/admin/grafana` with `{"profilingEnabled": true}`). While it is on, each traced HTTP request and the
```

(keep the rest of the paragraph as it is; only the first sentences change).

`docker-compose.yml`, the comment above the `pyroscope:` service:

```yaml
  # Continuous profiling store. Receives Excimer samples from the app while the
  # admin "Profiling" toggle is on (on by default in this dev stack, #1011); the
  # pusher fails open.
```

`frontend/public/i18n/en.json`, key `settings.grafana.profiling.caption`: replace `Off by default.` with `On by default in the dev stack, off in production.`

`frontend/public/i18n/de.json`, same key: replace `Standardmäßig aus.` with `Im Dev-Stack standardmäßig an, in Produktion aus.`

- [ ] **Step 12: Lint everything touched.**

```bash
cd backend && composer check && composer md
docker compose exec -T frontend npx prettier --check public/i18n/en.json public/i18n/de.json
```

Expected: clean. Run `mcp__phpstorm__lint_files` on the four `src` files; fix ERROR/WARNING.

- [ ] **Step 13: Prove it on the dev stack.** The dev database already holds a `grafana_settings` row if anyone ever saved the admin form, so the live check uses the row-less path deliberately: ask Lars whether the row may be dropped, or skip this step and rely on the unit tests. If he agrees:

```bash
docker compose exec -T php bin/console dbal:run-sql 'DELETE FROM grafana_settings'
docker compose exec -T php bin/console cache:clear
curl -sk https://localhost:8443/api/setup/status >/dev/null; sleep 3
docker compose logs --since 1m php | grep -i pyroscope | head -3
```

Expected: a Pyroscope push for `api_setup_status` without anyone touching the toggle (or the label check from Task 2 Step 10 succeeds). Then reload the admin Grafana settings page: the toggle shows on.

- [ ] **Step 14: Commit.**

```bash
git add backend/src/Service/Profiling/ProfilingDefault.php backend/src/Service/Profiling/ProfilingStatus.php backend/src/Service/Grafana/GrafanaSettings.php backend/src/Http/Admin/GrafanaSettingsJson.php backend/tests/Service/Profiling/ProfilingDefaultTest.php backend/tests/Service/Grafana/GrafanaSettingsTest.php backend/tests/Http/Admin/GrafanaSettingsJsonTest.php docs/local-docker.md docker-compose.yml frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1011): profile from the first request on a fresh dev stack"
```

---

### Task 7: Gates, MySQL leg, mutation diff, PR

- [ ] **Step 1: Backend gates natively.**

```bash
cd backend && composer check && composer md && php bin/phpunit 2>&1 | tail -3
```

Expected: all green.

- [ ] **Step 2: MySQL leg in Docker.**

```bash
docker compose exec -T php vendor/bin/phpunit 2>&1 | tail -3
```

Expected: green. (`docker compose exec php` runs against the rebuilt image from Task 4 with the bind-mounted source.)

- [ ] **Step 3: Mutation diff.**

```bash
cd backend && composer infection:diff 2>&1 | tail -15
```

Expected: MSI at or above `minMsi` in `infection.json5`. Untracked files are ignored by the diff filter, so everything must be committed first (it is). Escaped mutants in `ProfileLabels::withRoute` or `RequestProfilingListener::routeOf` mean a missing test: add it rather than accepting.

- [ ] **Step 4: Dev log scan.**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -r 'select(.level_name == "WARNING" or .level_name == "ERROR" or .level_name == "CRITICAL") | .message' | sort | uniq -c | sort -rn | head
```

Expected: nothing new related to opentelemetry, doctrine instrumentation, or profiling.

- [ ] **Step 5: Push and open the PR against `develop`.**

```bash
git push -u origin feature/1011-route-performance-dashboard
```

PR title: `feat(#1011): performance dashboard per route, with method and query spans`. Body:

```markdown
Closes #1011

## What

- Grafana 11.5.2 → 13.2.1 (instant TraceQL metrics: one value per route over the whole range). Tempo metrics range limit 3h → 72h.
- `open-telemetry/opentelemetry-auto-doctrine`: every DBAL statement is a child span with its SQL text.
- `#[WithSpan]` on the entry-point methods of the hot routes (list in `TracedServiceMethodsTest`); `opentelemetry.attr_hooks_enabled=1` in both Docker PHP images. Inert without the extension (CI, Strato).
- Per-request Pyroscope profiles carry a `route` label, read at `kernel.terminate` (the sampler starts before the router runs).
- Dev stack: profiling is on from the first request until an admin saves the Grafana settings (`ProfilingDefault`: `APP_ENV=dev` + Pyroscope URL). Docker prod unchanged.
- Dashboard rewritten around a `route` variable: routes table (count/median/max/errors), one point per request, methods and queries under the route, slowest traces, per-route flame graph. Requests/s, error rate/s and p95 panels removed; worker runs stay out.

## Verified

- native + MySQL phpunit legs, `composer check`, `composer md`, `composer infection:diff` (MSI: fill in the number)
- Live: Tempo API shows `db.query.text` spans and `App\…::method` internal spans under `api_entries_list` / `api_entries_reader`; Pyroscope shows the `route` label; dashboard screenshot attached.
```

Task 8 must be committed before this task. Attach the dashboard screenshot from Task 5 Step 4 to the PR. After the merge, verify #1011 closed itself.
