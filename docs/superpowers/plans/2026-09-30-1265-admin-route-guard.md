# Every Admin Route Sits Under `/api/admin/` and Refuses Non-Admins (#1265) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One route-wide test pins what each admin controller test checks only for its own actions. It walks the router's route collection and selects every route whose `_controller` is in `App\Controller\Admin`. Every selected path must start with `/api/admin/`, and an authenticated non-admin must get 403 from each of them. A new admin action with a forgotten prefix or access rule then fails the suite. Test-only: nothing in `src` changes.

**Architecture:** `tests/Controller/Admin/AdminRouteGuardTest.php` extends the existing `App\Tests\Support\ApiTestCase`. It reads the real `router` from the test container and selects the admin routes by the `_controller` default. An `assertNotEmpty` in the selector rules out an empty selection. The forward and reverse tests together make the selection equal to the `/api/admin/` paths, so a selector that finds only some routes fails too. The 403 walk sends one real request per declared method through `KernelBrowser`, with a JWT minted by `JWTTokenManagerInterface`, as every admin controller test does. Path parameters are filled with the non-admin's own id, generated through the router so each URL meets its route's requirements. A fourth assertion covers the reverse direction: no route under `/api/admin/` lives outside `App\Controller\Admin`.

**Tech Stack:** PHP 8.4, Symfony 7.4 (security-bundle v7.4.14, routing v7.4.13, http-kernel v7.4.14 per `composer.lock` at bf742411), LexikJWTAuthenticationBundle, PHPUnit 12, SQLite natively and MySQL in Docker.

**Spec:** GitHub issue #1265.

## Decisions

- **D-1: Access is enforced at the firewall, before any lookup, so any id that matches the route works.** No admin route has an `#[IsGranted]`. `git grep -nE 'IsGranted' bf742411 -- backend/src backend/config` prints nothing. Positive control: `git grep -nE 'ROLE_ADMIN' bf742411 -- backend/config` prints `backend/config/packages/security.yaml:84:        - { path: ^/api/admin/, roles: ROLE_ADMIN }`. The access check runs inside the firewall listener on `kernel.request` at priority 8 (`vendor/symfony/security-bundle/EventListener/FirewallListener.php:58-61`, `['onKernelRequest', 8]`). The router listener runs earlier, at priority 32 (`vendor/symfony/http-kernel/EventListener/RouterListener.php:178`, `KernelEvents::REQUEST => [['onKernelRequest', 32]]`). So the request must first *match* its route, or it gets 404/405 before the firewall runs. That means the route's own method, and an `{id}` that meets `\d+`. Argument resolution (`int $id`, `#[MapRequestPayload]`) and the repository `getById()` come after `kernel.request`. So a matching request needs no body, and no 404 can come before the 403. The test fills every path variable with the non-admin's `requireId()`. That is a real fixture id, as the issue asks, and it goes through `RouterInterface::generate()`, so a future route with a non-numeric requirement fails loudly (`InvalidParameterException`) instead of being skipped.
- **D-2: One request per declared method.** Every one of the 36 routes declares exactly one method at bf742411. A route that declares none (any method) gets a representative `GET`.
- **D-3: The selection is not pinned by count (planner ruling).** A pin would make every new admin action edit this test, and it adds nothing over the other checks. The forward test (admin namespace ⇒ `/api/admin/`) plus the reverse test (`/api/admin/` ⇒ admin namespace) already make the namespace selection equal to the `/api/admin/` paths. A selector that finds only some routes therefore fails the reverse test. The one remaining gap is an empty selection. `adminRoutes()`, the helper every admin-route test uses, guards it with `self::assertNotEmpty($adminRoutes, 'The admin route selector found no route.')`. The route list at bf742411 stays as the preflight evidence (36, file:line):

  | Controller:line | Route | Method |
  |---|---|---|
  | AdminCatalogCategoryController:26 | api_admin_catalog_category_create | POST |
  | AdminCatalogCategoryController:35 | api_admin_catalog_category_reorder | PATCH |
  | AdminCatalogCategoryController:43 | api_admin_catalog_category_update | PATCH |
  | AdminCatalogCategoryController:52 | api_admin_catalog_category_delete | DELETE |
  | AdminCatalogController:28 | api_admin_catalog_list | GET |
  | AdminCatalogController:41 | api_admin_catalog_warm_favicons | POST |
  | AdminCatalogFeedController:28 | api_admin_catalog_feed_create | POST |
  | AdminCatalogFeedController:37 | api_admin_catalog_feed_reorder | PATCH |
  | AdminCatalogFeedController:45 | api_admin_catalog_feed_update | PATCH |
  | AdminCatalogFeedController:54 | api_admin_catalog_feed_delete | DELETE |
  | AdminCatalogFeedController:67 | api_admin_catalog_feed_favicon | POST |
  | AdminCatalogImportController:33 | api_admin_catalog_bundled | GET |
  | AdminCatalogImportController:44 | api_admin_catalog_import_bundled | POST |
  | AdminCatalogImportController:53 | api_admin_catalog_import | POST |
  | AdminGrafanaController:22 | api_admin_grafana_get | GET |
  | AdminGrafanaController:28 | api_admin_grafana_update | PUT |
  | AdminMailController:26 | api_admin_mail_get | GET |
  | AdminMailController:32 | api_admin_mail_update | PUT |
  | AdminMailController:41 | api_admin_mail_test | POST |
  | AdminMailController:47 | api_admin_mail_reset | POST |
  | AdminMailController:55 | api_admin_mail_errors | GET |
  | AdminProxyController:24 | api_admin_proxy_get | GET |
  | AdminProxyController:30 | api_admin_proxy_update | PUT |
  | AdminProxyController:39 | api_admin_proxy_test | POST |
  | AdminSettingsController:26 | api_admin_settings_get | GET |
  | AdminSettingsController:32 | api_admin_settings_update | PUT |
  | AdminUserController:44 | api_admin_users_list | GET |
  | AdminUserController:75 | api_admin_users_detail | GET |
  | AdminUserController:97 | api_admin_users_approve | POST |
  | AdminUserController:106 | api_admin_users_reject | POST |
  | AdminUserController:115 | api_admin_users_suspend | POST |
  | AdminUserController:131 | api_admin_users_reset_password | POST |
  | AdminUserController:144 | api_admin_users_delete | DELETE |
  | AdminUserLimitsController:25 | api_admin_users_start_trial | POST |
  | AdminUserLimitsController:34 | api_admin_users_clear_trial | DELETE |
  | AdminUserLimitsController:45 | api_admin_users_set_subscription_limit | PUT |

  The only route loader is `config/routes.yaml`'s attribute loader over `src/Controller/`. `config/routes/*.yaml` add only `_security_logout` and the dev-only profiler and error routes, so the attribute list is the whole set.
- **D-4: The reverse direction is asserted too.** A route under `/api/admin/` outside `App\Controller\Admin` fails. That makes the namespace selection equal to the set of `/api/admin/` paths, so the 403 walk covers every admin path. At bf742411 there is no such stray. The one Api route that names `admin` is `SetupController.php:46` `#[Route('/admin', name: 'api_setup_admin', methods: ['POST'])]` under `#[Route('/api/setup')]`, which is `/api/setup/admin`.
- **D-5: Real requests, not `security.access_map`.** `tests/Security/PasskeyEnrolmentAccessControlTest.php` asks the access map. The issue asks for a 403 on each route, and a real request also proves that the route's actual method and URL reach the firewall's refusal.
- **D-6: Statuses are collected, then asserted once.** `assertSame(array_fill_keys(keys, 403), $statuses)` makes a FAIL list every offending route in one diff, instead of stopping at the first. The prefix and reverse tests work the same way. The test asserts the status only. The problem+json shape of a 403 is already pinned by `tests/Http/Problem/ProblemContractTest.php` (`'type' => 'forbidden'`).
- **D-7: Authentication reuses the house pattern and adds no new login helper.** `ApiTestCase`'s docblock says *"Authentication stays in each test class: how a suite gets its token depends on what it proves."* Every admin controller test (`AdminProxyControllerTest.php:26`, `AdminGrafanaControllerTest.php:42`, `AdminMailControllerTest.php:39`, `AdminUserControllerTest.php:58`, …) has the same private `tokenFor(User $user)` over `JWTTokenManagerInterface::create()`. The new test does the same. The non-admin comes from `ApiTestCase::factory()->create()`, the `UserFactory` every controller test uses (active, no roles).
- **D-8: No `src` change.** The rule holds today for all 36 routes. The test is a guard, so it passes on first run, and the deletion checks prove it bites. `composer infection:diff` has no `src` file to mutate and passes through `--ignore-msi-with-no-mutations` (`composer.json` `infection:diff`).

## Questions for the planner

None.

## Global Constraints

- **Paths and commands are relative to `backend/`**, except steps marked "from the repository root".
- **Read before you write.** Every edit names the exact text it replaces. If that text is not there, stop and report the file and the text you found.
- **Clean Code (CLAUDE.md) is mandatory**, and tests are production code: names reveal intent, no abbreviations (`AbbreviatedNameRule`), small methods, guard clauses, and the comment bar (default none, at most three lines).
- **PHPStan at level max:** no baseline entry, no `@phpstan-ignore`.
- **PSR-12, 120 columns, `declare(strict_types=1)`.**
- **Tests:** PHPUnit 12; `assertSame`, never `assertEquals`; persisted ids through `requireId()`.
- **Every new test gets a deletion check** that only its one edit makes fail. The step names the exact edit and quotes the expected FAIL. The implementer runs every deletion check, restores each with the Edit tool by re-applying the original text (never `git checkout --`), and quotes the FAIL output in the task report.
- **Every grep gets a positive control.** Use `git grep -E`, `(^|[^A-Za-z0-9_])` instead of `\b`, and never `-F` with backslashes.
- **Commits:** `type(#1265): <lower-case summary>`, no attribution or co-author lines.
- **The checkout is shared.** Run `git status --short && git branch --show-current` before any `switch`, `reset` or `stash`. Work in place, no worktrees.

---

### Task 1: Preflight, branch, plan copy

**Files:**
- Create: `docs/superpowers/plans/2026-09-30-1265-admin-route-guard.md` (this plan)

- [ ] **Step 1: The checkout is free and the issue is open (from the repository root)**

```bash
git status --short && git branch --show-current
git fetch origin
gh issue view 1265 --repo larspohlmann/simple-feed-reader --json state --jq .state
```
Expected: a clean tree (or only another session's files, which you leave alone), then `OPEN`.

- [ ] **Step 2: The route set and the access rule are as this plan found them (from `backend/`)**

```bash
git grep -hE "name: 'api_admin_" origin/develop -- src/Controller/Admin | wc -l
git grep -lE "name: 'api_admin_" origin/develop -- src/Controller/Admin | wc -l
git grep -nE "name: 'api_admin_" origin/develop -- src/Controller/Api src/Controller/MaintenanceController.php
git grep -nE "Route\('/api/admin" origin/develop -- src | wc -l
git grep -nE "Route\('[^']*admin" origin/develop -- src/Controller/Api src/Controller/MaintenanceController.php
git grep -nE 'IsGranted' origin/develop -- src config
git grep -nE 'ROLE_ADMIN' origin/develop -- config
```
Expected:
- `36`: the admin route count at bf742411 (D-3); the test does not pin it. The multi-line `#[Route(` blocks at `AdminUserController.php:131` and `AdminUserLimitsController.php:45` carry `name:` on a line of their own, which this pattern matches.
- `10`: the admin controller files. This is the positive control for the pattern and pathspec.
- No output: no admin-named route outside `App\Controller\Admin`. Positive control: the first line printed `36` with the same pattern.
- `10`: one class-level `#[Route('/api/admin/…')]` per admin controller, all inside `src/Controller/Admin`.
- Exactly one line, `origin/develop:src/Controller/Api/SetupController.php:46:    #[Route('/admin', name: 'api_setup_admin', methods: ['POST'])]`. It exists on purpose and is `/api/setup/admin`, not under `/api/admin/` (D-4).
- No output: nothing uses `#[IsGranted]` (D-1).
- One line, `origin/develop:config/packages/security.yaml:84:        - { path: ^/api/admin/, roles: ROLE_ADMIN }`. This is the positive control for the previous grep and the rule that D-1 relies on.

A different count (an admin route added or removed since bf742411) is fine: report the new count and the route, and carry on; the test pins no number. Any other difference is a reconcile gap. Stop and report it with the output.

- [ ] **Step 3: Branch and commit the plan copy (from the repository root)**

```bash
git switch -c test/1265-admin-route-guard origin/develop
cp <the plan file the planner handed you> docs/superpowers/plans/2026-09-30-1265-admin-route-guard.md
git add docs/superpowers/plans/2026-09-30-1265-admin-route-guard.md
git commit -m "docs(#1265): add plan"
```

---

### Task 2: `AdminRouteGuardTest`

**Files:**
- Create: `tests/Controller/Admin/AdminRouteGuardTest.php`

- [ ] **Step 1: Write the test**

`tests/Controller/Admin/AdminRouteGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

final class AdminRouteGuardTest extends ApiTestCase
{
    private const string ADMIN_NAMESPACE = 'App\\Controller\\Admin\\';
    private const string ADMIN_PREFIX = '/api/admin/';

    public function testEveryAdminRouteSitsUnderTheAdminPrefix(): void
    {
        $outsideThePrefix = array_filter(
            array_map(static fn (Route $route): string => $route->getPath(), $this->adminRoutes()),
            static fn (string $path): bool => !str_starts_with($path, self::ADMIN_PREFIX),
        );

        self::assertSame([], $outsideThePrefix, 'Admin routes outside ' . self::ADMIN_PREFIX);
    }

    public function testEveryRouteUnderTheAdminPrefixIsAnAdminController(): void
    {
        $strays = array_filter(
            $this->router()->getRouteCollection()->all(),
            static fn (Route $route): bool => str_starts_with($route->getPath(), self::ADMIN_PREFIX)
                && !self::isAdminController($route),
        );

        self::assertSame(
            [],
            array_keys($strays),
            'Routes under ' . self::ADMIN_PREFIX . ' outside ' . self::ADMIN_NAMESPACE,
        );
    }

    public function testEveryAdminRouteRefusesANonAdmin(): void
    {
        $client = self::createClient();
        $member = $this->factory()->create('member@example.com');
        $authorization = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($member)];

        $statuses = [];
        foreach ($this->oneRequestPerMethod($member->requireId()) as [$method, $url]) {
            $client->request($method, $url, server: $authorization);
            $statuses[$method . ' ' . $url] = $client->getResponse()->getStatusCode();
        }

        self::assertSame(array_fill_keys(array_keys($statuses), Response::HTTP_FORBIDDEN), $statuses);
    }

    /** @return array<string, Route> */
    private function adminRoutes(): array
    {
        $adminRoutes = array_filter(
            $this->router()->getRouteCollection()->all(),
            self::isAdminController(...),
        );

        self::assertNotEmpty($adminRoutes, 'The admin route selector found no route.');

        return $adminRoutes;
    }

    /** @return list<array{string, string}> */
    private function oneRequestPerMethod(int $fixtureId): array
    {
        $router = $this->router();
        $requests = [];
        foreach ($this->adminRoutes() as $name => $route) {
            $url = $router->generate($name, array_fill_keys($route->compile()->getPathVariables(), $fixtureId));
            foreach ($route->getMethods() ?: ['GET'] as $method) {
                $requests[] = [$method, $url];
            }
        }

        return $requests;
    }

    private static function isAdminController(Route $route): bool
    {
        $controller = $route->getDefault('_controller');

        return \is_string($controller) && str_starts_with($controller, self::ADMIN_NAMESPACE);
    }

    private function router(): RouterInterface
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }

    private function tokenFor(User $user): string
    {
        /** @var JWTTokenManagerInterface $manager */
        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);

        return $manager->create($user);
    }
}
```

Notes for the implementer (not for the file):
- `self::getContainer()` boots the kernel when no kernel is booted, so the first two tests need no explicit `bootKernel()`. The third calls `createClient()` first, because `WebTestCase` refuses `createClient()` after `bootKernel()`.
- The request list is built before the first request. `KernelBrowser` reboots the kernel between requests, and the member row survives the reboot because `UserFactory::create()` flushes it.
- `'router'` is FrameworkBundle's public alias of `router.default`.

- [ ] **Step 2: Run it: PASS (the rule holds today, D-8)**

Run: `php bin/phpunit tests/Controller/Admin/AdminRouteGuardTest.php`
Expected: `OK (3 tests, 9 assertions)`. The first test makes 3 assertions (`router()`'s instance check, the non-empty selection, the prefix map), the second makes 2 (instance check, strays), and the third makes 4 (two instance checks, the non-empty selection, the status map). A different assertion count with `OK` means a helper changed shape: re-read the file against Step 1.

- [ ] **Step 3: Deletion check A: an admin route loses its prefix**

Edit `src/Controller/Admin/AdminGrafanaController.php`.
Before:
```php
#[Route('/api/admin/grafana')]
final readonly class AdminGrafanaController
```
After:
```php
#[Route('/api/grafana')]
final readonly class AdminGrafanaController
```
Run: `bin/console cache:clear --env=test && php bin/phpunit tests/Controller/Admin/AdminRouteGuardTest.php`
Expected: `FAILURES! Tests: 3, … Failures: 2.`
- `testEveryAdminRouteSitsUnderTheAdminPrefix`: `Admin routes outside /api/admin/` / `Failed asserting that two arrays are identical.`, with `+` lines for `'api_admin_grafana_get' => '/api/grafana'` and `'api_admin_grafana_update' => '/api/grafana'`.
- `testEveryAdminRouteRefusesANonAdmin`: `Failed asserting that two arrays are identical.`, with `-    'GET /api/grafana' => 403` against `+    'GET /api/grafana' => 200`. The path now falls to `^/api/, IS_AUTHENTICATED_FULLY`, and `AdminGrafanaControllerTest::testGetAsAdminReportsDefaultsAndNoOverrides` shows that GET answers 200. There is also a `'PUT /api/grafana'` line whose actual status is not 403: the unguarded controller rejects the bodiless PUT.
- `testEveryRouteUnderTheAdminPrefixIsAnAdminController` passes.

Restore by editing After back to Before (`#[Route('/api/admin/grafana')]`), never `git checkout --`. Quote the FAIL output in the task report.

- [ ] **Step 4: Deletion check B: one admin route loses its access rule**

Edit `config/packages/security.yaml`.
Before:
```yaml
        - { path: ^/api/admin/, roles: ROLE_ADMIN }
        - { path: ^/api/, roles: IS_AUTHENTICATED_FULLY }
```
After:
```yaml
        - { path: '^/api/admin/(?!catalog/bundled$)', roles: ROLE_ADMIN }
        - { path: ^/api/, roles: IS_AUTHENTICATED_FULLY }
```
Only `GET /api/admin/catalog/bundled` escapes the admin rule. It is the one route at that path (`/api/admin/catalog/import/bundled` still matches).
Run: `bin/console cache:clear --env=test && php bin/phpunit tests/Controller/Admin/AdminRouteGuardTest.php`
Expected: `FAILURES! Tests: 3, … Failures: 1.` The failure is in `testEveryAdminRouteRefusesANonAdmin`: `Failed asserting that two arrays are identical.`, with the single changed pair `-    'GET /api/admin/catalog/bundled' => 403` / `+    'GET /api/admin/catalog/bundled' => 200`. `AdminCatalogImportControllerTest::testTheBundledDocumentIsDescribedWithoutImportingIt` shows that GET succeeds once the firewall lets it through. The other two tests pass.

Restore by editing After back to Before (`        - { path: ^/api/admin/, roles: ROLE_ADMIN }`), never `git checkout --`. Quote the FAIL output.

- [ ] **Step 5: Deletion check C: a non-admin route moves under `/api/admin/`**

Edit `src/Controller/Api/HealthController.php`.
Before:
```php
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
```
After:
```php
    #[Route('/api/admin/health', name: 'api_health', methods: ['GET'])]
```
Run: `bin/console cache:clear --env=test && php bin/phpunit tests/Controller/Admin/AdminRouteGuardTest.php`
Expected: `FAILURES! Tests: 3, … Failures: 1.` The failure is in `testEveryRouteUnderTheAdminPrefixIsAnAdminController`: `Routes under /api/admin/ outside App\Controller\Admin\` / `Failed asserting that two arrays are identical.`, with the `+` line `0 => 'api_health'`. The other two tests pass, because the admin selection is still the admin namespace's routes, all under `/api/admin/`.

Restore by editing After back to Before (`#[Route('/api/health', …)]`), never `git checkout --`. Quote the FAIL output.

- [ ] **Step 6: Deletion check D: a broken selector cannot pass vacuously**

Edit `tests/Controller/Admin/AdminRouteGuardTest.php`.
Before:
```php
    private const string ADMIN_NAMESPACE = 'App\\Controller\\Admin\\';
```
After:
```php
    private const string ADMIN_NAMESPACE = 'App\\Controller\\Admn\\';
```
Run: `php bin/phpunit tests/Controller/Admin/AdminRouteGuardTest.php`
Expected: `FAILURES! Tests: 3, … Failures: 3.`
- `testEveryAdminRouteSitsUnderTheAdminPrefix` and `testEveryAdminRouteRefusesANonAdmin`: `The admin route selector found no route.` / `Failed asserting that an array is not empty.`
- `testEveryRouteUnderTheAdminPrefixIsAnAdminController`: `Routes under /api/admin/ outside App\Controller\Admn\` / `Failed asserting that two arrays are identical.`, with one `+` line per admin route (36 at bf742411). The first is `0 => 'api_admin_catalog_category_create'`, because the attribute loader sorts files by path (`vendor/symfony/routing/Loader/AttributeDirectoryLoader.php:44`, `usort`).

Restore by editing After back to Before (`'App\\Controller\\Admin\\'`), never `git checkout --`. Quote the FAIL output.

- [ ] **Step 7: Back to green, and the per-task gates**

```bash
git status --short
php -l tests/Controller/Admin/AdminRouteGuardTest.php
vendor/bin/phpcs tests/Controller/Admin/AdminRouteGuardTest.php
bin/console cache:clear --env=test && php bin/phpunit tests/Controller/Admin
```
Expected:
- `git status --short` lists only `?? tests/Controller/Admin/AdminRouteGuardTest.php`. `AdminGrafanaController.php`, `security.yaml` and `HealthController.php` are unmodified, so all three restores landed. Positive control: the new test file is listed.
- `No syntax errors detected in tests/Controller/Admin/AdminRouteGuardTest.php`.
- phpcs prints nothing and exits 0.
- phpunit `OK`, with the existing admin controller tests plus the 3 new tests.

Run PhpStorm inspections (`mcp__phpstorm__lint_files`) on `backend/tests/Controller/Admin/AdminRouteGuardTest.php`: no ERROR or WARNING.

- [ ] **Step 8: Commit (from the repository root)**

```bash
git add backend/tests/Controller/Admin/AdminRouteGuardTest.php
git commit -m "test(#1265): every admin route sits under /api/admin/ and refuses a non-admin"
```

---

### Task 3: Finishing: PR gates and the PR

- [ ] **Step 1: PR gates (from `backend/`, one after another)**

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
composer tramp
php bin/phpunit
```
Expected: every command exits 0. `composer md` reads only `src`, which this PR does not touch. `composer stan` covers the new test at level max, including `AbbreviatedNameRule`, `CommentBlockLengthRule` and `InvocationMatchersOnThisRule`. If `composer tramp` alone fails, run `composer show larspohlmann/phptramp` before looking at this branch (CLAUDE.md).

After the native leg finishes, check that the Docker containers are current, then run the MySQL leg from the repository root:
```bash
docker compose exec php composer test -- --filter=AdminRouteGuardTest
docker compose exec php composer test
```
Expected: `OK` for both.

```bash
composer infection:diff
```
Expected: exit 0. No `src` file changed, so there are no mutations, and `--ignore-msi-with-no-mutations` skips the MSI gate (D-8).

PhpStorm inspections (`mcp__phpstorm__lint_files`) on `backend/tests/Controller/Admin/AdminRouteGuardTest.php`: no ERROR or WARNING.

Frontend: untouched, no gate.

- [ ] **Step 2: Push and open the PR (from the repository root)**

```bash
git push -u origin test/1265-admin-route-guard && gh pr create --repo larspohlmann/simple-feed-reader --base develop --head test/1265-admin-route-guard --title "test(#1265): every admin route sits under /api/admin/ and refuses a non-admin" --body "$(cat <<'EOF'
## Summary
- `AdminRouteGuardTest` walks the router's route collection and selects every route whose controller is in `App\Controller\Admin` (36 today). The selection is not pinned by count: the forward and reverse checks equate it with the `/api/admin/` paths, and an `assertNotEmpty` rules out an empty selection.
- Every selected path starts with `/api/admin/`, and no route under `/api/admin/` lives outside `App\Controller\Admin`.
- An authenticated non-admin gets 403 from each admin route: one real request per declared method, path parameters filled with the member's own id through the router. The `^/api/admin/` access_control rule runs at the firewall on `kernel.request`, before argument resolution and any lookup.
- Test-only; no `src` change.

## Deletion checks
- `AdminGrafanaController` moved to `/api/grafana`: the prefix test and the 403 walk fail.
- `security.yaml` rule narrowed to `^/api/admin/(?!catalog/bundled$)`: the 403 walk fails on `GET /api/admin/catalog/bundled` with 200.
- `HealthController` moved to `/api/admin/health`: the reverse test fails on `api_health`.
- The namespace selector broken: every test fails, on the empty selection or on the stray list.

Closes #1265
EOF
)"
```
The PR body ends with `Closes #1265`. `develop` is the default branch, so the issue closes on merge. 
- [ ] **Step 3: Merge and report**

Merge when CI is green: watch it with the Monitor tool, one command, `gh pr checks <PR> --watch --fail-fast`; when it exits 0, `gh pr merge <PR> --merge`. Never `--auto`. Then verify `gh issue view 1265 --repo larspohlmann/simple-feed-reader --json state --jq .state` prints `CLOSED`, without closing it by hand. Report to the planner: the PR URL, the merge SHA, the quoted FAILs of deletion checks A–D, and the gate results.
