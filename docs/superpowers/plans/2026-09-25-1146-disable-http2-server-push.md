# Disable HTTP/2 Server Push Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop HTTP/2 server pushes from crashing the PHP process, so utopia.de articles open in the reader again (#1146).

**Architecture:** FrameworkBundle builds `http_client.transport` with `HttpClient::create($defaultOptions, $maxHostConnections)`, which defaults `$maxPendingPushes` to 50 and so registers libcurl's push callback. With libcurl 8.22.0 (Docker image and Strato), accepting a push corrupts memory. The framework config exposes no push option, so a compiler pass sets the factory's named argument `$maxPendingPushes` to 0 on that definition. The pass runs before `ResolveNamedArgumentsPass`, so the name resolves against `HttpClient::create`'s signature. It doesn't depend on argument order. The app never requests the pushed assets, so nothing is lost.

**Tech Stack:** Symfony 7.4 DependencyInjection, symfony/http-client `CurlHttpClient`, PHPUnit 12.

**Spec:** GitHub issue #1146 (root cause, measurements on Docker and Strato).

## Global Constraints

- Every outbound client derived from `http_client` inherits the setting. No per-host exception.
- `declare(strict_types=1)`, `final` class, PSR-12, PHPStan max, PHPMD-clean.
- At most one comment line, and only where a future reader would otherwise undo the pass.

---

### Task 1: Compiler pass disabling server push, with kernel wiring

**Files:**
- Create: `backend/src/DependencyInjection/DisableHttpServerPushPass.php`
- Modify: `backend/src/Kernel.php` (add `build()`)
- Test: `backend/tests/DependencyInjection/DisableHttpServerPushPassTest.php`

**Interfaces:**
- Produces: `App\DependencyInjection\DisableHttpServerPushPass implements CompilerPassInterface`

- [ ] **Step 1: Write the failing test**

A kernel test against the real wiring, not a direct `process()` call (a pass test that bypasses the container proves nothing about registration). The test container exposes `http_client.transport` as the concrete `CurlHttpClient`. The push limit lives only in its private `CurlClientState`, so it is read by reflection.

```php
<?php

declare(strict_types=1);

namespace App\Tests\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\Internal\CurlClientState;

final class DisableHttpServerPushPassTest extends KernelTestCase
{
    public function testTheWiredTransportAcceptsNoServerPushes(): void
    {
        $transport = self::getContainer()->get('http_client.transport');

        self::assertInstanceOf(CurlHttpClient::class, $transport);
        self::assertSame(0, $this->maxPendingPushesOf($transport));
    }

    private function maxPendingPushesOf(CurlHttpClient $client): mixed
    {
        $state = (new \ReflectionProperty(CurlHttpClient::class, 'multi'))->getValue($client);
        self::assertInstanceOf(CurlClientState::class, $state);

        return (new \ReflectionProperty(CurlClientState::class, 'maxPendingPushes'))->getValue($state);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run (from `backend/`): `php bin/phpunit tests/DependencyInjection/DisableHttpServerPushPassTest.php`
Expected: FAIL, `Failed asserting that 50 is identical to 0.`

- [ ] **Step 3: Implement the pass and register it**

`backend/src/DependencyInjection/DisableHttpServerPushPass.php`:

```php
<?php

declare(strict_types=1);

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** libcurl 8.22 corrupts memory on an accepted HTTP/2 push and kills the worker (#1146). */
final class DisableHttpServerPushPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('http_client.transport')->setArgument('$maxPendingPushes', 0);
    }
}
```

`backend/src/Kernel.php`, adding:

```php
use App\DependencyInjection\DisableHttpServerPushPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
// …
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DisableHttpServerPushPass());
    }
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `php bin/phpunit tests/DependencyInjection/DisableHttpServerPushPassTest.php`
Expected: PASS

- [ ] **Step 5: Verify the guard by breaking it**

Comment out the `addCompilerPass` line and run the test: it must fail with `50`. Restore the line with an Edit, not `git checkout --`.

- [ ] **Step 6: Verify the real failure is gone**

```bash
docker compose restart php
docker compose exec -T php bin/console app:reader:audit --entries=542944   # exit 0, was 139
```

Then call `GET https://localhost:8443/api/entries/542944/reader` with a token from `lexik:jwt:generate-token`. Expected: 200 with the extracted article, and no `SIGSEGV` in `docker compose logs php`.

- [ ] **Step 7: Gates**

`composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test`, `composer infection:diff` (commit first: it ignores untracked files).

- [ ] **Step 8: Commit**

```bash
git add backend/src/DependencyInjection/DisableHttpServerPushPass.php backend/src/Kernel.php backend/tests/DependencyInjection/DisableHttpServerPushPassTest.php docs/superpowers/plans/2026-09-25-1146-disable-http2-server-push.md
git commit -m "fix(#1146): disable HTTP/2 server push on the HTTP client transport"
```
