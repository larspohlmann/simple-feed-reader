# Detached Recommendation Drainer (#371) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a recommendation run starts on a worker-less install, the web request spawns a short-lived, detached CLI process (`app:recommendations:drain`) that drives every active run to completion at worker concurrency — independent of the browser and the external cron. If spawning is impossible, nothing changes: the existing poll/cron path carries the run.

**Architecture:** Extract the worker-regime sweep out of `AdvanceRecommendationRunsHandler` into a shared `WorkerRunSweep`. A new `app:recommendations:drain` command loops that sweep under a global Doctrine lock until no run is active. A `RecommendationDrainSpawner` launches the command via a best-effort `DetachedProcessLauncher` (shell `exec` with `</dev/null >/dev/null 2>&1 &`), but only when the `WorkerPresence` heartbeat is stale — so a Docker install with a live worker never spawns. Trigger points: run start, run resume, and the cron `/maintenance/tick` as a respawn safety net.

**Tech Stack:** Symfony 7.4 (console, lock with Doctrine store, clock), PHP 8.4, PHPUnit against SQLite (`php bin/phpunit` from `backend/`).

## Global Constraints

- All commands run from `backend/` unless stated otherwise.
- Every PHP file: `declare(strict_types=1)`, PSR-12, `final readonly class` with constructor promotion where possible.
- PHPStan level max over src and tests; no new baselines. Warm the cache first if stan complains oddly: `bin/console cache:warmup`.
- PHPMD: every touched src file must be PHPMD-clean (`composer md`), not just free of new findings.
- phptramp: no chain of 3+ methods forwarding a parameter none reads across 2+ classes (`composer tramp`).
- Controllers stay thin — this plan touches no controller.
- Tests are production code: same naming and standards. Long Prettier-style chains are a frontend rule; backend follows PSR-12 120-char lines.
- Errors are exceptions, typed and namespaced next to their service — but the launcher is explicitly **best-effort and never throws** (issue requirement), which is documented on its interface.
- No new config knob beyond `DRAIN_PHP_CLI_BINARY` (host fact, required by the issue).
- Commit messages: `feat(#371): …` / `test(#371): …` / `docs(#371): …` style (see `git log`).
- The branch is `feature/371-detached-recommendation-drainer` (already created).
- **Deliberate deviation from the issue text, to state in the PR body:** the issue says "promote `symfony/process` to a direct dependency" for `PhpExecutableFinder`. We do not need it: when the running SAPI is `cli`, the constant `\PHP_BINARY` *is* the CLI binary, and in every other SAPI `PhpExecutableFinder` would return the wrong (non-CLI) binary anyway — exactly the trap the issue warns about. So the builder uses the `DRAIN_PHP_CLI_BINARY` env var, falls back to `\PHP_BINARY` only under the `cli` SAPI, and otherwise refuses to build a command line (silent no-op). No `symfony/process` promotion, no `Process` class — the launch is a plain `exec()` of a backgrounded shell line, which is the recipe the live Strato smoke test validated.

---

### Task 1: Extract `WorkerRunSweep` from the worker handler

The worker handler's body (heartbeat marking, `findAllActive()` loop, per-run error ladder, `finally` EM clear) becomes a shared service. The handler delegates. New behavior: the sweep **returns how many active runs it attempted**, so the drain command can loop until it returns 0.

**Files:**
- Create: `backend/src/Service/Worker/WorkerRunSweep.php`
- Modify: `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php`
- Create: `backend/tests/Service/Worker/WorkerRunSweepTest.php`
- Modify: `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php` (only the hand-built constructor helpers)

**Interfaces:**
- Consumes: `RecommendationRunRepository::findAllActive(): list<RecommendationRun>`, `RecommendationRunAdvancer::advance(User $user, TickDriver $driver = TickDriver::Poll)`, `WorkerPresence::markRecommendationSweep(): void`.
- Produces: `WorkerRunSweep::sweep(): int` — marks the heartbeat (once up front plus once per run), advances each active run once at `TickDriver::Worker`, clears the EntityManager in a `finally`, returns the number of active runs it attempted. Constructor: `__construct(RecommendationRunRepository $runs, RecommendationRunAdvancer $advancer, WorkerPresence $presence, EntityManagerInterface $entityManager, LoggerInterface $logger)`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Worker/WorkerRunSweepTest.php`. Model fixtures on `AdvanceRecommendationRunsHandlerTest` (same directory) — copy its `seedSingleBatchFixture`, `user`, `runs`, `starter`, `presence` private helpers, trimmed to what these tests need:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Worker\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The shared worker-regime sweep (#371). Its coordination behavior --
 * heartbeat per run, error ladder, identity-map hygiene -- is pinned by
 * AdvanceRecommendationRunsHandlerTest, which now exercises it through the
 * handler's delegation. What is new here is the return value: the drain
 * command loops until sweep() reports no active run was attempted, so the
 * count is load-bearing, not informational.
 */
final class WorkerRunSweepTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    public function testSweepWithNoActiveRunsReturnsZeroAndStillReportsLiveness(): void
    {
        self::assertSame(0, $this->sweep()->sweep());
        self::assertTrue($this->presence()->isRecommendationWorkerAlive());
    }

    public function testSweepReturnsOneAttemptPerActiveRun(): void
    {
        $first = $this->user('sweep-count-first@example.test');
        $this->seedSingleBatchFixture($first);
        $this->starter()->start($first);

        $second = $this->user('sweep-count-second@example.test');
        $this->seedSingleBatchFixture($second);
        $this->starter()->start($second);

        // Both runs are PENDING; the sweep's snapshot tick advances each
        // without a provider call, so the count is observable without
        // stubbing replies.
        self::assertSame(2, $this->sweep()->sweep());
        foreach ([$first, $second] as $user) {
            $run = $this->runs()->findActiveForUser($user);
            self::assertNotNull($run);
            self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
        }
    }

    private function seedSingleBatchFixture(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);

        $feed = new Feed('https://example.com/' . $user->getEmail() . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $this->fixtures->entry($feed, $user->getEmail() . '-entry-' . $i, 60 - $i);
        }
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    /**
     * Built by hand, not fetched from the container: until the drain command
     * exists (a later task), the handler is this service's only reference,
     * and the compiler inlines single-reference private services away -- the
     * test container then cannot fetch it (the same caveat
     * config/services_test.yaml documents for StubChatClient's neighbours).
     */
    private function sweep(): WorkerRunSweep
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return new WorkerRunSweep($this->runs(), $advancer, $this->presence(), $this->em, new NullLogger());
    }
}
```

Add the imports this needs: `use App\Service\Recommendation\RecommendationRunAdvancer;` and `use Psr\Log\NullLogger;`.

Note: `RecommendationRunStarter` gains a constructor dependency in Task 6; this test fetches it from the container, so it stays valid.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Service/Worker/WorkerRunSweepTest.php`
Expected: ERROR — `Class "App\Service\Worker\WorkerRunSweep" not found`.

- [ ] **Step 3: Create `WorkerRunSweep` by moving the handler's body**

Create `backend/src/Service/Worker/WorkerRunSweep.php`. Move the loop, the `advanceOne()` method, and their comments **verbatim** from `AdvanceRecommendationRunsHandler` (the comments record #311 review findings and must survive the move; log message strings stay byte-identical — tests pin them):

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\Exception\ApiKeyUnreadableException;
use App\Service\Ai\Exception\AiNotConfiguredException;
use App\Service\Ai\Exception\CredentialsRejectedException;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\TickDriver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * One worker-regime sweep over every active recommendation run (#371): mark
 * the heartbeat, advance each run once at TickDriver::Worker behind the
 * per-run error ladder, and leave the identity map clean. Shared by the
 * worker's ten-second AdvanceRecommendationRuns firing and the on-demand
 * drain command -- both ARE the worker regime, which is why the heartbeat is
 * marked here. ForYouSweep::sweepOnce() is NOT a third copy of this: the
 * cron/poll sweep runs the Poll regime and must never mark the heartbeat --
 * it is not a background worker.
 */
final readonly class WorkerRunSweep
{
    public function __construct(
        private RecommendationRunRepository $runs,
        private RecommendationRunAdvancer $advancer,
        private WorkerPresence $presence,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns how many active runs this sweep attempted, whether or not the
     * attempt succeeded: the drain command loops until a sweep finds nothing
     * to attempt, and a failed attempt still made progress (the advancer
     * recorded the failure against the run, which will drop out of
     * findAllActive() once its failure ceiling is hit).
     */
    public function sweep(): int
    {
        // Touched every sweep, work or not: the heartbeat is the liveness
        // signal the poll driver defers to, not a work log.
        $this->presence->markRecommendationSweep();
        $attemptedRuns = 0;

        try {
            foreach ($this->runs->findAllActive() as $run) {
                // Again before each run, because a sweep's duration is the
                // SUM over its runs and one run can spend a whole provider
                // timeout. Marking only once per sweep let the heartbeat go
                // stale mid-sweep, and the client then took the healthy
                // worker for a dead one (#311 final review, Critical 2).
                $this->presence->markRecommendationSweep();
                $this->advanceOne($run);
                ++$attemptedRuns;
            }
        } finally {
            // A long-running caller accumulates managed entities across
            // sweeps; the identity map is per-sweep state, not process
            // state. `finally` rather than a plain trailing call, so this
            // still runs even if something above ever escapes advanceOne()'s
            // own floor (#311 fix round 2) -- the identity map must never be
            // left dirty for the *next* sweep just because this one had a
            // run go wrong.
            $this->entityManager->clear();
        }

        return $attemptedRuns;
    }

    /**
     * The typed AI-provider cases are handled by exception type alone --
     * neither needs the run passed back out, because each case already
     * knows everything it needs to do. AiNotConfiguredException and
     * ApiKeyUnreadableException are no longer classified here at all: the
     * shared tick both drivers call (RecommendationRunAdvancer::tick(),
     * #311 fix) already failed and flushed the run before rethrowing, so
     * there is nothing left to record. That failure recording used to live
     * here too, split into "which failure to record" (classifyFailure) and
     * "record it"; duplicating that classification in only one driver is
     * exactly what left a poll-only install's run stuck forever, so it now
     * lives in the one place both drivers go through.
     */
    private function advanceOne(RecommendationRun $run): void
    {
        try {
            $this->advancer->advance($run->getUser(), TickDriver::Worker);
        } catch (AiNotConfiguredException | ApiKeyUnreadableException) {
            // Already failed and flushed by the shared tick; nothing to do.
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            // The advancer already counted this against the run's own
            // transport-failure ceiling; the sweep just moves on and the next
            // firing retries. One user's dead provider must not fail the
            // message and starve every other user's run.
            $this->logger->warning('Recommendation sweep: provider call failed.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        } catch (\Throwable $e) {
            // The floor beneath every case above: nothing that goes wrong
            // advancing one run may ever abort the sweep for every run
            // sorted after it. Logged at error level because, unlike the
            // typed cases above, nothing here already recorded the failure
            // anywhere else.
            $this->logger->error('Recommendation sweep: unexpected failure advancing a run.', [
                'runId' => $run->getId(),
                'exception' => $e,
            ]);
        }
    }
}
```

- [ ] **Step 4: Make the handler a thin delegate**

Replace the whole body of `backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Worker\Message\AdvanceRecommendationRuns;
use App\Service\Worker\WorkerRunSweep;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The worker side of the driver-agnostic tick (#311): every ten seconds this
 * runs one worker-regime sweep over the active runs. The sweep itself lives
 * in WorkerRunSweep (#371) because the on-demand drain command is the same
 * regime -- this handler only binds it to the messenger firing.
 */
#[AsMessageHandler]
final readonly class AdvanceRecommendationRunsHandler
{
    public function __construct(private WorkerRunSweep $sweep)
    {
    }

    public function __invoke(AdvanceRecommendationRuns $message): void
    {
        $this->sweep->sweep();
    }
}
```

- [ ] **Step 5: Update the handler test's hand-built constructors**

In `backend/tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`, the container-fetched `handler()` keeps working. Three private helpers construct the handler by hand and must now wrap a hand-built sweep. Add the import `use App\Service\Worker\WorkerRunSweep;` and change only these helpers:

```php
private function handlerWithFlushFailingEntityManager(LoggerInterface $logger): AdvanceRecommendationRunsHandler
{
    return new AdvanceRecommendationRunsHandler(new WorkerRunSweep(
        $this->runs(),
        $this->advancerWithFlushFailingEntityManager(),
        $this->presence(),
        $this->em,
        $logger,
    ));
}

private function handlerWithLogger(LoggerInterface $logger): AdvanceRecommendationRunsHandler
{
    return new AdvanceRecommendationRunsHandler(new WorkerRunSweep(
        $this->runs(),
        $this->advancer(),
        $this->presence(),
        $this->em,
        $logger,
    ));
}

private function handlerWithPresenceClock(ClockInterface $presenceClock): AdvanceRecommendationRunsHandler
{
    return new AdvanceRecommendationRunsHandler(new WorkerRunSweep(
        $this->runs(),
        $this->advancer(),
        new WorkerPresence($this->heartbeats(), $presenceClock),
        $this->em,
        new NullLogger(),
    ));
}
```

`testFiringClearsTheIdentityMapAfterwards` also constructs the handler inline with five arguments — wrap those five in `new WorkerRunSweep(...)` the same way. Keep the helpers' existing doc comments (adjust "handler" to "sweep" where they now describe the wrapped sweep).

- [ ] **Step 6: Run both test files**

Run: `php bin/phpunit tests/Service/Worker/WorkerRunSweepTest.php tests/Service/Worker/AdvanceRecommendationRunsHandlerTest.php`
Expected: all PASS.

- [ ] **Step 7: Lint and commit**

Run: `composer check && composer md` (from `backend/`). Fix anything reported (`composer cs:fix` for style).

```bash
git add backend/src/Service/Worker/WorkerRunSweep.php backend/src/Service/Worker/Handler/AdvanceRecommendationRunsHandler.php backend/tests/Service/Worker/
git commit -m "refactor(#371): extract the worker-regime sweep into WorkerRunSweep"
```

---

### Task 2: `DetachedConsoleCommandLine` — the argv builder with the CLI-binary policy

A pure builder that turns a console command name plus arguments into the exact shell line the Strato smoke test validated — or `null` when no CLI binary is known. This is the unit-testable seam the issue demands ("asserts the argv without forking").

**Files:**
- Create: `backend/src/Service/Process/DetachedConsoleCommandLine.php`
- Modify: `backend/.env` (add `DRAIN_PHP_CLI_BINARY=''`)
- Test: `backend/tests/Service/Process/DetachedConsoleCommandLineTest.php`

**Interfaces:**
- Produces: `DetachedConsoleCommandLine::forCommand(string $consoleCommandName, string ...$arguments): ?string`. Constructor: `__construct(string $configuredCliBinary /* %env(DRAIN_PHP_CLI_BINARY)% */, string $projectDir /* %kernel.project_dir% */, string $environment /* %kernel.environment% */, string $runningSapi = \PHP_SAPI, string $runningPhpBinary = \PHP_BINARY)`.
- Binary policy: configured env value wins; empty value falls back to `\PHP_BINARY` only when the running SAPI is `cli`; otherwise return `null` (a web SAPI's own binary is the wrong one to run `bin/console` with — Strato's is `cgi-fcgi`).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Process/DetachedConsoleCommandLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Process;

use App\Service\Process\DetachedConsoleCommandLine;
use PHPUnit\Framework\TestCase;

final class DetachedConsoleCommandLineTest extends TestCase
{
    public function testBuildsTheFullDetachedShellLineFromTheConfiguredBinary(): void
    {
        $line = $this->commandLine(configuredCliBinary: '/opt/RZphp84/bin/php-cli', runningSapi: 'cgi-fcgi')
            ->forCommand('app:recommendations:drain', '--detach');

        self::assertSame(
            "'/opt/RZphp84/bin/php-cli' '/srv/app/bin/console' 'app:recommendations:drain' '--detach'"
            . " --env='prod' </dev/null >/dev/null 2>&1 &",
            $line,
        );
    }

    /**
     * Under the cli SAPI, \PHP_BINARY IS a CLI binary, so no configuration
     * is needed -- this is what lets a developer machine and the Docker
     * containers spawn without setting the env var.
     */
    public function testFallsBackToTheRunningBinaryOnlyUnderTheCliSapi(): void
    {
        $line = $this->commandLine(configuredCliBinary: '', runningSapi: 'cli')
            ->forCommand('app:recommendations:drain');

        self::assertNotNull($line);
        self::assertStringStartsWith("'/usr/bin/php'", $line);
    }

    /**
     * A web SAPI's own binary (Strato: cgi-fcgi) cannot run bin/console, and
     * guessing a path would spawn garbage -- refusing to build a line is what
     * makes the whole feature silently self-disable on such a host until the
     * env var names the real CLI binary.
     */
    public function testRefusesToBuildALineUnderAWebSapiWithoutConfiguration(): void
    {
        self::assertNull(
            $this->commandLine(configuredCliBinary: '', runningSapi: 'cgi-fcgi')
                ->forCommand('app:recommendations:drain'),
        );
    }

    public function testShellArgumentsAreEscaped(): void
    {
        $line = (new DetachedConsoleCommandLine(
            configuredCliBinary: '/opt/php cli/php',
            projectDir: "/srv/o'app",
            environment: 'prod',
            runningSapi: 'cgi-fcgi',
            runningPhpBinary: '/usr/bin/php',
        ))->forCommand('app:recommendations:drain');

        self::assertNotNull($line);
        self::assertStringContainsString(escapeshellarg('/opt/php cli/php'), $line);
        self::assertStringContainsString(escapeshellarg("/srv/o'app/bin/console"), $line);
    }

    private function commandLine(string $configuredCliBinary, string $runningSapi): DetachedConsoleCommandLine
    {
        return new DetachedConsoleCommandLine(
            configuredCliBinary: $configuredCliBinary,
            projectDir: '/srv/app',
            environment: 'prod',
            runningSapi: $runningSapi,
            runningPhpBinary: '/usr/bin/php',
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Process/DetachedConsoleCommandLineTest.php`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement the builder**

Create `backend/src/Service/Process/DetachedConsoleCommandLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Process;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds the shell line that launches a console command fully detached from
 * the current request: stdin/stdout/stderr point at /dev/null so no shared
 * pipe holds the request open, and the trailing `&` backgrounds the child so
 * exec() returns immediately. This exact recipe survived a live FastCGI
 * teardown on the production host (#371 smoke test, 2026-08-13).
 *
 * The binary is a policy, not a lookup: under a web SAPI the running binary
 * is the SAPI binary (Strato: cgi-fcgi) and must never be used to run
 * bin/console, so it has to be named explicitly via DRAIN_PHP_CLI_BINARY.
 * Only under the cli SAPI is \PHP_BINARY itself already the right answer,
 * which is what lets developer machines and the Docker containers spawn
 * without configuration. With neither, forCommand() returns null and the
 * caller must treat the launch as unavailable.
 */
final readonly class DetachedConsoleCommandLine
{
    public function __construct(
        #[Autowire('%env(DRAIN_PHP_CLI_BINARY)%')] private string $configuredCliBinary,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%kernel.environment%')] private string $environment,
        private string $runningSapi = \PHP_SAPI,
        private string $runningPhpBinary = \PHP_BINARY,
    ) {
    }

    public function forCommand(string $consoleCommandName, string ...$arguments): ?string
    {
        $cliBinary = $this->cliBinary();
        if (null === $cliBinary) {
            return null;
        }

        $argv = array_map(escapeshellarg(...), [
            $cliBinary,
            $this->projectDir . '/bin/console',
            $consoleCommandName,
            ...$arguments,
        ]);

        return sprintf(
            '%s --env=%s </dev/null >/dev/null 2>&1 &',
            implode(' ', $argv),
            escapeshellarg($this->environment),
        );
    }

    private function cliBinary(): ?string
    {
        if ('' !== $this->configuredCliBinary) {
            return $this->configuredCliBinary;
        }

        return 'cli' === $this->runningSapi ? $this->runningPhpBinary : null;
    }
}
```

- [ ] **Step 4: Register the env default**

In `backend/.env`, after the `MAINTENANCE_TOKEN` block (around line 38), add:

```dotenv
###> recommendation drainer (#371) ###
# Absolute path of a CLI-SAPI php binary, used to spawn the detached
# recommendation drainer out of a web request. Leave empty on installs whose
# web PHP already runs the cli SAPI or that have a background worker; on
# Strato this must be /opt/RZphp84/bin/php-cli.
DRAIN_PHP_CLI_BINARY=''
###< recommendation drainer (#371) ###
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Process/DetachedConsoleCommandLineTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Lint and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Service/Process/DetachedConsoleCommandLine.php backend/tests/Service/Process/ backend/.env
git commit -m "feat(#371): build the detached console shell line with the CLI-binary policy"
```

---

### Task 3: `DetachedProcessLauncher` — best-effort fire-and-forget launch

The launcher is the never-throwing wrapper: build the line, hand it to a one-line shell runner, swallow every `Throwable` (covers `exec` in `disable_functions`, fork failure). The shell runner is its own interface so the launcher's policy is unit-testable without forking.

**Files:**
- Create: `backend/src/Service/Process/ShellCommandRunnerInterface.php`
- Create: `backend/src/Service/Process/ExecShellCommandRunner.php`
- Create: `backend/src/Service/Process/DetachedProcessLauncherInterface.php`
- Create: `backend/src/Service/Process/DetachedProcessLauncher.php`
- Modify: `backend/config/services.yaml` (two interface aliases)
- Test: `backend/tests/Service/Process/DetachedProcessLauncherTest.php`

**Interfaces:**
- Produces: `ShellCommandRunnerInterface::runDetached(string $shellCommandLine): void`; `DetachedProcessLauncherInterface::launch(string $consoleCommandName, string ...$arguments): void` (documented: never throws).
- Consumes: `DetachedConsoleCommandLine::forCommand(string $consoleCommandName, string ...$arguments): ?string` from Task 2.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Process/DetachedProcessLauncherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Process;

use App\Service\Process\DetachedConsoleCommandLine;
use App\Service\Process\DetachedProcessLauncher;
use App\Service\Process\ShellCommandRunnerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DetachedProcessLauncherTest extends TestCase
{
    public function testLaunchHandsTheBuiltLineToTheShell(): void
    {
        $shell = $this->recordingShell();
        $this->launcher($shell)->launch('app:recommendations:drain', '--detach');

        self::assertSame(
            ["'/opt/php-cli' '/srv/app/bin/console' 'app:recommendations:drain' '--detach'"
                . " --env='prod' </dev/null >/dev/null 2>&1 &"],
            $shell->lines,
        );
    }

    /**
     * No CLI binary known means no line to run: the shell must not be
     * invoked at all, because any guessed line would spawn garbage.
     */
    public function testLaunchWithoutACliBinaryNeverTouchesTheShell(): void
    {
        $shell = $this->recordingShell();
        $launcher = new DetachedProcessLauncher(
            $this->commandLine(configuredCliBinary: '', runningSapi: 'cgi-fcgi'),
            $shell,
            new NullLogger(),
        );

        $launcher->launch('app:recommendations:drain');

        self::assertSame([], $shell->lines);
    }

    /**
     * The launcher's contract is best-effort and silent (#371): on a host
     * with exec() in disable_functions the call is an \Error, and the
     * request must fall back to today's one-step advance rather than 500.
     */
    public function testAThrowingShellIsSwallowed(): void
    {
        $shell = new class implements ShellCommandRunnerInterface {
            public function runDetached(string $shellCommandLine): void
            {
                throw new \Error('Call to undefined function exec()');
            }
        };

        $this->launcher($shell)->launch('app:recommendations:drain');

        $this->addToAssertionCount(1);
    }

    /**
     * @return ShellCommandRunnerInterface&object{lines: list<string>}
     */
    private function recordingShell(): ShellCommandRunnerInterface
    {
        return new class implements ShellCommandRunnerInterface {
            /** @var list<string> */
            public array $lines = [];

            public function runDetached(string $shellCommandLine): void
            {
                $this->lines[] = $shellCommandLine;
            }
        };
    }

    private function launcher(ShellCommandRunnerInterface $shell): DetachedProcessLauncher
    {
        return new DetachedProcessLauncher(
            $this->commandLine(configuredCliBinary: '/opt/php-cli', runningSapi: 'cgi-fcgi'),
            $shell,
            new NullLogger(),
        );
    }

    private function commandLine(string $configuredCliBinary, string $runningSapi): DetachedConsoleCommandLine
    {
        return new DetachedConsoleCommandLine(
            configuredCliBinary: $configuredCliBinary,
            projectDir: '/srv/app',
            environment: 'prod',
            runningSapi: $runningSapi,
            runningPhpBinary: '/usr/bin/php',
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Process/DetachedProcessLauncherTest.php`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement the four files**

`backend/src/Service/Process/ShellCommandRunnerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Process;

/**
 * The one real side effect of a detached launch, isolated so the launcher's
 * policy is testable without forking a process.
 */
interface ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void;
}
```

`backend/src/Service/Process/ExecShellCommandRunner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Process;

final readonly class ExecShellCommandRunner implements ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void
    {
        // The line's own redirects and trailing `&` make this return
        // immediately; the shell, not this process, owns the child.
        exec($shellCommandLine);
    }
}
```

`backend/src/Service/Process/DetachedProcessLauncherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Process;

/**
 * Fire-and-forget launch of a console command, fully detached from the
 * current request. Best-effort by contract (#371): implementations never
 * throw -- a host that cannot spawn (exec in disable_functions, a failed
 * fork, no known CLI binary) is a silent no-op, and the caller's existing
 * slow path must carry the work.
 */
interface DetachedProcessLauncherInterface
{
    public function launch(string $consoleCommandName, string ...$arguments): void;
}
```

`backend/src/Service/Process/DetachedProcessLauncher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Process;

use Psr\Log\LoggerInterface;

final readonly class DetachedProcessLauncher implements DetachedProcessLauncherInterface
{
    public function __construct(
        private DetachedConsoleCommandLine $commandLine,
        private ShellCommandRunnerInterface $shell,
        private LoggerInterface $logger,
    ) {
    }

    public function launch(string $consoleCommandName, string ...$arguments): void
    {
        try {
            $shellCommandLine = $this->commandLine->forCommand($consoleCommandName, ...$arguments);
            if (null === $shellCommandLine) {
                $this->logger->debug('Detached launch skipped: no CLI php binary is known on this host.', [
                    'command' => $consoleCommandName,
                ]);

                return;
            }

            $this->shell->runDetached($shellCommandLine);
        } catch (\Throwable $exception) {
            // Swallowing a Throwable is this class's documented contract,
            // not an oversight: the launch is a speed-up, and a host that
            // cannot spawn must degrade to the poll/cron path without the
            // user ever seeing an error (#371).
            $this->logger->info('Detached launch failed; the poll/cron path carries the work.', [
                'command' => $consoleCommandName,
                'exception' => $exception,
            ]);
        }
    }
}
```

- [ ] **Step 4: Register the interface aliases, and neuter the shell in the test environment**

In `backend/config/services.yaml`, next to the existing `App\Service\Fetch\FeedFetcherInterface:` style aliases, add:

```yaml
    App\Service\Process\ShellCommandRunnerInterface: '@App\Service\Process\ExecShellCommandRunner'
    App\Service\Process\DetachedProcessLauncherInterface: '@App\Service\Process\DetachedProcessLauncher'
```

The test environment must never actually fork: under phpunit the SAPI is `cli`, so the binary policy *would* resolve, and a container-wired code path that reaches the launcher would `exec` a real drainer against the test database — a child process racing the suite and outliving it. Create `backend/tests/Support/NullShellCommandRunner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Process\ShellCommandRunnerInterface;

/**
 * The test environment must never actually fork a drainer: a real child
 * process would race the suite's database and outlive the run. Swallowing
 * the line here keeps every container-wired code path identical to
 * production up to the final exec().
 */
final class NullShellCommandRunner implements ShellCommandRunnerInterface
{
    public function runDetached(string $shellCommandLine): void
    {
    }
}
```

and register the swap in `backend/config/services_test.yaml`, using the same alias pattern the file already uses for `App\Service\Recommendation\ChatCompletionClient` → `App\Tests\Support\StubChatClient` (around line 327 — put this next to it):

```yaml
    # Test environment only: no container-wired code path may ever exec() a
    # real drainer child process against the test database. Same swap pattern
    # as StubChatClient above.
    App\Tests\Support\NullShellCommandRunner:
        autowire: true
    App\Service\Process\ShellCommandRunnerInterface:
        alias: App\Tests\Support\NullShellCommandRunner
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Process/DetachedProcessLauncherTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Lint and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Service/Process/ backend/tests/Service/Process/ backend/tests/Support/NullShellCommandRunner.php backend/config/services.yaml backend/config/services_test.yaml
git commit -m "feat(#371): add the best-effort detached process launcher"
```

---### Task 4: `RecommendationDrainSpawner` — the single spawn policy

One method, called by every trigger site: launch the drain command only when no worker heartbeat is fresh. On a Docker install the real worker keeps the heartbeat fresh, so this correctly never fires there.

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationDrainSpawner.php`
- Test: `backend/tests/Service/Recommendation/RecommendationDrainSpawnerTest.php`

**Interfaces:**
- Produces: `RecommendationDrainSpawner::spawnIfNoWorker(): void`; constant `RecommendationDrainSpawner::DRAIN_COMMAND = 'app:recommendations:drain'`. Constructor: `__construct(WorkerPresence $presence, DetachedProcessLauncherInterface $launcher)`.
- Consumes: `WorkerPresence::isRecommendationWorkerAlive(): bool`; `DetachedProcessLauncherInterface::launch(string $consoleCommandName, string ...$arguments): void`.
- The spawner passes `--detach` — the flag Task 5's command uses to call `posix_setsid()` only when actually spawned into the background (an in-process test run must not steal the test runner's session).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Recommendation/RecommendationDrainSpawnerTest.php` (functional: the presence read is a real repository query; only the launcher is a recording stub):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Worker\WorkerPresence;
use App\Tests\DbTestCase;

final class RecommendationDrainSpawnerTest extends DbTestCase
{
    public function testSpawnsTheDetachedDrainerWhenNoWorkerIsAlive(): void
    {
        $launcher = $this->recordingLauncher();

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
    }

    /**
     * The Docker install's real worker keeps the heartbeat fresh, which is
     * exactly what must make the web request never spawn a second driver --
     * the feature self-disables where it is not needed (#371).
     */
    public function testAFreshWorkerHeartbeatSuppressesTheSpawn(): void
    {
        $launcher = $this->recordingLauncher();
        $this->presence()->markRecommendationSweep();

        (new RecommendationDrainSpawner($this->presence(), $launcher))->spawnIfNoWorker();

        self::assertSame([], $launcher->launches);
    }

    /**
     * @return DetachedProcessLauncherInterface&object{launches: list<list<string>>}
     */
    private function recordingLauncher(): DetachedProcessLauncherInterface
    {
        return new class implements DetachedProcessLauncherInterface {
            /** @var list<list<string>> */
            public array $launches = [];

            public function launch(string $consoleCommandName, string ...$arguments): void
            {
                $this->launches[] = [$consoleCommandName, ...$arguments];
            }
        };
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationDrainSpawnerTest.php`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement the spawner**

Create `backend/src/Service/Recommendation/RecommendationDrainSpawner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Worker\WorkerPresence;

/**
 * The one spawn policy for the on-demand drainer (#371): every trigger site
 * (run start, run resume, the cron tick's respawn net) goes through this
 * method, so "only when no worker is alive" is decided in exactly one place.
 * A stale read here is harmless -- the drain command's own global lock and
 * the per-user run lock are the real guards against double work; this check
 * only avoids pointlessly forking next to a healthy worker.
 */
final readonly class RecommendationDrainSpawner
{
    public const string DRAIN_COMMAND = 'app:recommendations:drain';

    public function __construct(
        private WorkerPresence $presence,
        private DetachedProcessLauncherInterface $launcher,
    ) {
    }

    public function spawnIfNoWorker(): void
    {
        if ($this->presence->isRecommendationWorkerAlive()) {
            return;
        }

        // --detach makes the spawned process leave the request's session
        // (posix_setsid); the flag exists so an in-process test run of the
        // command does not detach the test runner itself.
        $this->launcher->launch(self::DRAIN_COMMAND, '--detach');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationDrainSpawnerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Lint and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Service/Recommendation/RecommendationDrainSpawner.php backend/tests/Service/Recommendation/RecommendationDrainSpawnerTest.php
git commit -m "feat(#371): add the drain spawner gated on worker presence"
```

---

### Task 5: `app:recommendations:drain` — the drain command

Acquire the global `recommendation-drain` lock (exit 0 immediately if held — a concurrent spawn is expected and harmless), then loop `WorkerRunSweep::sweep()` until it returns 0 or a wall-clock cap is reached. `--detach` triggers `posix_setsid()`.

**Files:**
- Create: `backend/src/Command/RecommendationDrainCommand.php`
- Test: `backend/tests/Command/RecommendationDrainCommandTest.php`

**Interfaces:**
- Consumes: `WorkerRunSweep::sweep(): int` (Task 1); `Symfony\Component\Lock\LockFactory` (Doctrine store, `config/packages/lock.yaml`); `Symfony\Component\Clock\ClockInterface` (`now()` and `sleep()` — test clocks make `sleep()` a no-op).
- Produces: console command `app:recommendations:drain` with a `--detach` `VALUE_NONE` option; always exits `Command::SUCCESS`. Constants: `LOCK_NAME = 'recommendation-drain'`, `LOCK_TTL_SECONDS = 900.0`, `MAX_RUNTIME_SECONDS = 3600`, `SWEEP_PAUSE_SECONDS = 1.0`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Command/RecommendationDrainCommandTest.php`. It reuses the handler test's fixture approach; the command is built by hand so a test clock replaces real sleeping.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RecommendationDrainCommand;
use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Worker\WorkerPresence;
use App\Service\Worker\WorkerRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TickingClock;
use App\Tests\Support\UserFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationDrainCommandTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    /**
     * The whole point of the drainer: it does not advance once and exit, it
     * loops until nothing is active. A snapshotted single-batch run needs
     * one sweep to bank the batch and finish, and a second sweep to observe
     * that nothing is left -- completion proves both happened.
     */
    public function testDrainsAnActiveRunToCompletionAndReleasesTheLock(): void
    {
        $user = $this->user('drain-to-completion@example.test');
        $this->seedSingleBatchFixture($user);
        $run = $this->startAndSnapshot($user);
        $this->requeueCleanReplyFor($run->getCandidateBatches()[0]);

        $exitCode = $this->execute($this->command());

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        $persisted = $this->runs()->findLatestForUser($user);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted->getStatus());

        // The lock must be free again, or the next spawn could never drain.
        $lock = $this->lockFactory()->createLock(RecommendationDrainCommand::LOCK_NAME);
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    /**
     * Concurrent spawns are by design (start + cron racing); the loser must
     * neither wait nor advance anything -- the winner already owns the work.
     */
    public function testExitsImmediatelyWithoutAdvancingWhenTheLockIsHeld(): void
    {
        $user = $this->user('drain-lock-contention@example.test');
        $this->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        $heldByAnotherDrainer = $this->lockFactory()->createLock(RecommendationDrainCommand::LOCK_NAME);
        self::assertTrue($heldByAnotherDrainer->acquire());

        try {
            $exitCode = $this->execute($this->command());
        } finally {
            $heldByAnotherDrainer->release();
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);
        self::assertSame(RecommendationRun::STATUS_PENDING, $run->getStatus());
    }

    /**
     * A stuck run must never pin a process forever: the cap ends the loop
     * with the run still active, and the cron tick respawns later. The
     * TickingClock steps a full hour per reading, so the very first cap
     * check is already past MAX_RUNTIME_SECONDS.
     */
    public function testStopsAtTheWallClockCapWithTheRunStillActive(): void
    {
        $user = $this->user('drain-wall-cap@example.test');
        $this->seedSingleBatchFixture($user);
        $this->starter()->start($user);

        $hourPerReading = new TickingClock(
            new \DateTimeImmutable('2026-08-14 00:00:00'),
            RecommendationDrainCommand::MAX_RUNTIME_SECONDS,
        );
        $exitCode = $this->execute($this->command($hourPerReading));

        self::assertSame(Command::SUCCESS, $exitCode);
        $this->em->clear();
        self::assertNotNull($this->runs()->findActiveForUser($user));
    }

    private function execute(RecommendationDrainCommand $command): int
    {
        return (new CommandTester($command))->execute([]);
    }

    private function command(?TickingClock $clock = null): RecommendationDrainCommand
    {
        return new RecommendationDrainCommand(
            $this->lockFactory(),
            $this->sweep(),
            $clock ?? new TickingClock(new \DateTimeImmutable('2026-08-14 00:00:00'), 1),
        );
    }

    /**
     * Built by hand for the same inlining reason as WorkerRunSweepTest: a
     * private service with too few references may be inlined away, and this
     * command is hand-built anyway so a test clock can replace real sleeping.
     */
    private function sweep(): WorkerRunSweep
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return new WorkerRunSweep($this->runs(), $this->advancer(), $presence, $this->em, new NullLogger());
    }

    private function lockFactory(): LockFactory
    {
        /** @var LockFactory $factory */
        $factory = self::getContainer()->get(LockFactory::class);

        return $factory;
    }

    private function startAndSnapshot(User $user): RecommendationRun
    {
        $this->starter()->start($user);
        $this->advancer()->advance($user);
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);

        return $run;
    }

    /**
     * @param list<int> $batchIds
     */
    private function requeueCleanReplyFor(array $batchIds): void
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);
        $client->queueContent(json_encode([
            'recommendations' => array_map(
                static fn (int $id, int $index): array => [
                    'id' => $id,
                    'score' => 100 - $index,
                    'reason' => 'irrelevant',
                ],
                $batchIds,
                array_keys($batchIds),
            ),
        ], \JSON_THROW_ON_ERROR));
    }

    private function seedSingleBatchFixture(User $user): void
    {
        $this->fixtures->seedReadyAiSettings($user);

        $feed = new Feed('https://example.com/' . $user->getEmail() . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $this->fixtures->entry($feed, $user->getEmail() . '-entry-' . $i, 60 - $i);
        }
    }

    private function user(string $email): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit tests/Command/RecommendationDrainCommandTest.php`
Expected: ERROR — class not found.

- [ ] **Step 3: Implement the command**

Create `backend/src/Command/RecommendationDrainCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Worker\WorkerRunSweep;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * The on-demand drainer (#371): a short-lived worker that drives every
 * active recommendation run to completion at worker concurrency, spawned by
 * a web request on installs that have no persistent worker. Each sweep marks
 * the heartbeat, so open browsers demote to the read-only /current poll
 * while this runs; when it exits and the heartbeat goes stale, the poll and
 * cron paths take over again seamlessly.
 *
 * It only ever advances existing runs -- starting runs (and their spend
 * budget, #308) stays with the callers that already own it.
 */
#[AsCommand(
    name: 'app:recommendations:drain',
    description: 'Advance all active recommendation runs until none is left',
)]
final class RecommendationDrainCommand extends Command
{
    public const string LOCK_NAME = 'recommendation-drain';

    /**
     * Refreshed between sweeps, so it needs to outlive one sweep iteration,
     * not the whole drain. A crashed drainer therefore blocks a respawn for
     * at most this long -- and only the *fast* path: the per-minute cron
     * sweep keeps advancing the runs regardless, because it takes the
     * per-user run locks, never this one.
     */
    public const float LOCK_TTL_SECONDS = 900.0;

    /**
     * A stuck run must never pin a process forever: past the cap the
     * drainer exits and the next cron tick spawns a fresh one, which
     * resumes from the last committed checkpoint.
     */
    public const int MAX_RUNTIME_SECONDS = 3600;

    /**
     * The advancer blocks on provider calls, so the loop is naturally
     * paced; this only keeps the tail -- repeated sweeps over a run that is
     * finishing up -- from spinning hot.
     */
    public const float SWEEP_PAUSE_SECONDS = 1.0;

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly WorkerRunSweep $sweep,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'detach',
            null,
            InputOption::VALUE_NONE,
            'Leave the spawning request\'s session (used by the web spawner)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ((bool) $input->getOption('detach') && \function_exists('posix_setsid')) {
            // Survival does not depend on a setsid/nohup wrapper binary --
            // the production host has neither setsid nor a crontab, but it
            // does have ext-posix (#371 Strato probe). Behind --detach so an
            // in-process test run cannot detach the test runner's session.
            posix_setsid();
        }

        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            // Another drainer already owns the work; concurrent spawns
            // (start + cron racing) are expected and harmless by design.
            return Command::SUCCESS;
        }

        // A crash skips finally, and this CLI process has no request
        // timeout watching over it; same belt-and-braces as
        // RecommendationRunAdvancer::advance() -- the release is
        // token-scoped, so it can never free a lock this process no longer
        // owns, and SIGKILL still falls back to the TTL.
        register_shutdown_function(static function () use ($lock): void {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Best-effort: a failure to release during shutdown must
                // not raise a second fatal. The TTL still bounds the stall.
            }
        });

        try {
            $this->drainUntilDoneOrCapped($lock);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }

    private function drainUntilDoneOrCapped(LockInterface $lock): void
    {
        $startedAt = $this->clock->now();

        while ($this->sweep->sweep() > 0) {
            if ($this->clock->now()->getTimestamp() - $startedAt->getTimestamp() >= self::MAX_RUNTIME_SECONDS) {
                return;
            }

            $lock->refresh();
            $this->clock->sleep(self::SWEEP_PAUSE_SECONDS);
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Command/RecommendationDrainCommandTest.php`
Expected: PASS (3 tests). If the wall-cap test hangs, the cap check is being read from the wrong clock reading — the TickingClock advances per `now()` call: reading 0 (`$startedAt`) is t=0, reading 1 (first cap check) is t=MAX_RUNTIME_SECONDS, which must satisfy `>=`.

- [ ] **Step 5: Lint and commit**

Run: `composer check && composer md`.

```bash
git add backend/src/Command/RecommendationDrainCommand.php backend/tests/Command/RecommendationDrainCommandTest.php
git commit -m "feat(#371): add the lock-guarded app:recommendations:drain command"
```

---

### Task 6: Wire the trigger sites — run start, run resume, cron respawn net

`RecommendationRunStarter` spawns after `start()` and `resume()`; `MaintenanceTick` spawns after its sweep when runs are still active. The browser `/tick` path deliberately does **not** spawn (an open browser already drives the run).

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunStarter.php`
- Modify: `backend/src/Service/Maintenance/MaintenanceTick.php`
- Modify: `backend/tests/Service/Recommendation/RecommendationRunStarterTest.php` (add spawn cases)
- Modify: `backend/tests/Service/Maintenance/MaintenanceTickTest.php` (add respawn-net cases)

**Interfaces:**
- Consumes: `RecommendationDrainSpawner::spawnIfNoWorker(): void` (Task 4).
- Produces: `RecommendationRunStarter.__construct` gains a trailing `RecommendationDrainSpawner $drainSpawner` parameter; `MaintenanceTick.__construct` gains a trailing `RecommendationDrainSpawner $drainSpawner` parameter. No signature of any public method changes.

- [ ] **Step 1: Write the failing starter tests**

`backend/tests/Service/Recommendation/RecommendationRunStarterTest.php` already exists. It creates `$this->user` in `setUp()`, and it has the private helpers `seedReadyAiSettings(User $user)` (makes the account AI-ready) and `persistFailedRunWithOneWinner(): RecommendationRun` (persists a FAILED run for `$this->user`, resumable). Reuse them. Add these imports:

```php
use App\Service\Ai\AiProviderConfigurator;
use App\Service\Process\DetachedProcessLauncherInterface;
use App\Service\Recommendation\RecommendationDrainSpawner;
use App\Service\Worker\WorkerPresence;
use Symfony\Component\Clock\ClockInterface;
```

Add these test methods:

```php
public function testStartSpawnsTheDrainerWhenNoWorkerIsAlive(): void
{
    $this->seedReadyAiSettings($this->user);
    $launcher = $this->recordingLauncher();

    $this->starterWith($launcher)->start($this->user);

    self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
}

public function testStartReturningAnAlreadyActiveRunStillSpawns(): void
{
    $this->seedReadyAiSettings($this->user);
    $launcher = $this->recordingLauncher();
    $starter = $this->starterWith($launcher);

    $starter->start($this->user);
    $starter->start($this->user);

    // A second click while no drainer lives is exactly when a respawn
    // helps; the drain lock makes a duplicate spawn harmless.
    self::assertCount(2, $launcher->launches);
}

public function testStartDoesNotSpawnNextToAFreshWorkerHeartbeat(): void
{
    $this->seedReadyAiSettings($this->user);
    $launcher = $this->recordingLauncher();
    $this->presence()->markRecommendationSweep();

    $this->starterWith($launcher)->start($this->user);

    self::assertSame([], $launcher->launches);
}

public function testResumeSpawnsTheDrainer(): void
{
    $this->seedReadyAiSettings($this->user);
    $this->persistFailedRunWithOneWinner();
    $launcher = $this->recordingLauncher();

    $this->starterWith($launcher)->resume($this->user);

    self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
}
```

and these private helpers (the recording launcher is the same anonymous class as in Task 4's test):

```php
/**
 * @return DetachedProcessLauncherInterface&object{launches: list<list<string>>}
 */
private function recordingLauncher(): DetachedProcessLauncherInterface
{
    return new class implements DetachedProcessLauncherInterface {
        /** @var list<list<string>> */
        public array $launches = [];

        public function launch(string $consoleCommandName, string ...$arguments): void
        {
            $this->launches[] = [$consoleCommandName, ...$arguments];
        }
    };
}

/**
 * Built by hand so only the launcher is a stub: every other collaborator is
 * the container's real instance, and the spawner's presence read is a real
 * repository query.
 */
private function starterWith(DetachedProcessLauncherInterface $launcher): RecommendationRunStarter
{
    /** @var AiProviderConfigurator $configurator */
    $configurator = self::getContainer()->get(AiProviderConfigurator::class);
    /** @var ClockInterface $clock */
    $clock = self::getContainer()->get(ClockInterface::class);

    return new RecommendationRunStarter(
        $this->runs(),
        $configurator,
        $this->em,
        $clock,
        $this->runLogs(),
        new RecommendationDrainSpawner($this->presence(), $launcher),
    );
}

private function presence(): WorkerPresence
{
    /** @var WorkerPresence $presence */
    $presence = self::getContainer()->get(WorkerPresence::class);

    return $presence;
}
```

Note the file's existing `runs()` and `runLogs()` helpers already return the two repositories.

- [ ] **Step 2: Run the starter test to verify the new cases fail**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunStarterTest.php`
Expected: the new tests ERROR (constructor takes 5 arguments, not 6).

- [ ] **Step 3: Wire the starter**

In `backend/src/Service/Recommendation/RecommendationRunStarter.php`, add the constructor dependency and the calls:

```php
public function __construct(
    private RecommendationRunRepository $runs,
    private AiProviderConfigurator $configurator,
    private EntityManagerInterface $entityManager,
    private ClockInterface $clock,
    private RecommendationRunLogRepository $logs,
    private RecommendationDrainSpawner $drainSpawner,
) {
}
```

In `start()`, before **both** returns (the already-active early return and the fresh-run return), and in `resume()` before its return, insert:

```php
$this->drainSpawner->spawnIfNoWorker();
```

The already-active branch spawns on purpose: a click on a run whose drainer died is the moment a respawn helps, and the launch is a cheap heartbeat read on a worker install. Place the call **after** the `flush()` on the fresh-run and resume paths — the spawned drainer reads the database, so the run row must be committed before the fork. Add one comment at the first call site:

```php
// After the flush, so the detached drainer's first findAllActive() can
// already see this run. Fire-and-forget: on a worker install the
// heartbeat is fresh and this is a no-op (#371).
```

- [ ] **Step 4: Write the failing MaintenanceTick tests**

`backend/tests/Service/Maintenance/MaintenanceTickTest.php` has two tests today: `testRunProducesAReportCarryingBothHalves` (container tick — unaffected) and `testSkipsTheRecommendationSweepWhenRefreshAborts`, which hand-builds `new MaintenanceTick($refreshRunner, $forYouSweep)` at its end. That call gains a third argument (see Step 5) — give it a recording-launcher spawner and extend its assertions with the no-spawn proof:

```php
$launcher = $this->recordingLauncher();
$tick = new MaintenanceTick($refreshRunner, $forYouSweep, $this->spawnerWith($launcher));

$report = $tick->run()->toArray();

// ...existing assertions stay unchanged, then:
// The aborted path must not spawn either: the shared EntityManager is
// unusable this tick, so even the spawner's heartbeat read is off-limits.
self::assertSame([], $launcher->launches);
```

Add two new tests. Seeding an active run reuses the recommendation fixtures the worker tests use (`RecommendationRunFixtures` needs `ApiKeyCipher` from the container; `UserFactory` needs the password hasher — copy the `user()`/`seedReadyAiSettings`-style setup from `RecommendationRunStarterTest` or use `RecommendationRunFixtures::seedReadyAiSettings()` plus the container `RecommendationRunStarter` to start the run; the container starter's own spawn goes through the test container's null shell runner wired in Task 3, so it cannot interfere with the recording stub):

```php
public function testTickWithARemainingActiveRunSpawnsTheDrainerAsRespawnNet(): void
{
    $user = $this->userWithReadyAiSettings('tick-respawn-net@example.test');
    $starter = self::getContainer()->get(RecommendationRunStarter::class);
    self::assertInstanceOf(RecommendationRunStarter::class, $starter);
    $starter->start($user);

    $launcher = $this->recordingLauncher();
    $tick = new MaintenanceTick(
        $this->containerRefreshRunner(),
        $this->containerForYouSweep(),
        $this->spawnerWith($launcher),
    );

    $tick->run();

    // The sweep advanced the run one step (snapshot), it is still active,
    // and no worker heartbeat is fresh -- so the respawn net must fire.
    self::assertSame([['app:recommendations:drain', '--detach']], $launcher->launches);
}

public function testTickWithNoActiveRunsDoesNotSpawn(): void
{
    $launcher = $this->recordingLauncher();
    $tick = new MaintenanceTick(
        $this->containerRefreshRunner(),
        $this->containerForYouSweep(),
        $this->spawnerWith($launcher),
    );

    $tick->run();

    self::assertSame([], $launcher->launches);
}
```

with helpers (recording launcher = the same anonymous class as in Task 4's test; the container getters follow the file's existing `self::getContainer()->get()` + `assertInstanceOf` style):

```php
private function spawnerWith(DetachedProcessLauncherInterface $launcher): RecommendationDrainSpawner
{
    $presence = self::getContainer()->get(WorkerPresence::class);
    self::assertInstanceOf(WorkerPresence::class, $presence);

    return new RecommendationDrainSpawner($presence, $launcher);
}

private function containerRefreshRunner(): RefreshRunner
{
    $runner = self::getContainer()->get(RefreshRunner::class);
    self::assertInstanceOf(RefreshRunner::class, $runner);

    return $runner;
}

private function containerForYouSweep(): ForYouSweep
{
    $sweep = self::getContainer()->get(ForYouSweep::class);
    self::assertInstanceOf(ForYouSweep::class, $sweep);

    return $sweep;
}

private function userWithReadyAiSettings(string $email): User
{
    /** @var UserPasswordHasherInterface $hasher */
    $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
    $user = (new UserFactory($this->em, $hasher))->create($email);

    /** @var ApiKeyCipher $cipher */
    $cipher = self::getContainer()->get(ApiKeyCipher::class);
    (new RecommendationRunFixtures($this->em, $cipher))->seedReadyAiSettings($user);

    return $user;
}
```

New imports: `App\Service\Ai\Crypto\ApiKeyCipher`, `App\Service\Process\DetachedProcessLauncherInterface`, `App\Service\Recommendation\RecommendationDrainSpawner`, `App\Service\Recommendation\RecommendationRunStarter`, `App\Service\Worker\WorkerPresence`, `App\Tests\Support\RecommendationRunFixtures`, `App\Tests\Support\UserFactory`, `Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface`.

- [ ] **Step 5: Wire `MaintenanceTick`**

Modify `backend/src/Service/Maintenance/MaintenanceTick.php`:

```php
public function __construct(
    private RefreshRunner $refreshRunner,
    private ForYouSweep $forYouSweep,
    private RecommendationDrainSpawner $drainSpawner,
) {
}

public function run(): MaintenanceTickReport
{
    $refresh = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));
    if ($refresh->isAborted()) {
        return new MaintenanceTickReport($refresh->toArray(), $this->skippedRecommendations());
    }

    $recommendations = $this->forYouSweep->sweepOnce();
    if ($recommendations->activeRuns > 0) {
        // The respawn net (#371): a drainer that died leaves its runs to
        // this cron path; once the heartbeat is stale again, the next tick
        // brings a fresh drainer up rather than crawling at one step per
        // minute. Runs just started by this very sweep are covered too.
        $this->drainSpawner->spawnIfNoWorker();
    }

    return new MaintenanceTickReport($refresh->toArray(), $recommendations->toArray());
}
```

Keep `skippedRecommendations()` as it is. Add the imports (`App\Service\Recommendation\RecommendationDrainSpawner`). Update the class doc comment's last paragraph to mention the respawn net in one sentence.

- [ ] **Step 6: Run the touched suites**

Run: `php bin/phpunit tests/Service/Recommendation/RecommendationRunStarterTest.php tests/Service/Maintenance/MaintenanceTickTest.php tests/Service/Recommendation/RecommendationDrainSpawnerTest.php`
Expected: all PASS.

- [ ] **Step 7: Run the whole backend suite**

Run: `php bin/phpunit`
Expected: green. The starter gained a dependency, so anything that hand-constructs `RecommendationRunStarter` breaks loudly here — fix each call site by appending a real `RecommendationDrainSpawner` (container `WorkerPresence` + container `DetachedProcessLauncherInterface`, or a recording stub where a test cares). Likewise `MaintenanceTick` hand-constructions.

Any test that reaches `spawnIfNoWorker()` through container-wired services is safe: Task 3 aliased `ShellCommandRunnerInterface` to `NullShellCommandRunner` for the whole test environment, so the container launcher builds the line and discards it. Tests that need to *observe* a spawn use a hand-built spawner around the recording launcher stub, as above.

- [ ] **Step 8: Lint and commit**

Run: `composer check && composer md`.

```bash
git add backend/src backend/tests backend/config
git commit -m "feat(#371): spawn the drainer on run start/resume and from the cron respawn net"
```

---

### Task 7: Documentation — user doc, architecture note, deploy note

**Files:**
- Create: `docs/recommendations-runs.md` (user-facing, full deliverable per issue)
- Modify: `README.md` (docs index list, around line 64)
- Modify: `docs/for-you-scheduling.md` (short drainer architecture note)
- Modify: `deploy/strato/README.md` (the `DRAIN_PHP_CLI_BINARY` manual step)

- [ ] **Step 1: Write the user documentation**

Create `docs/recommendations-runs.md` with exactly this content (user-facing, no code — covers every bullet the issue lists):

```markdown
# How a "For you" run works

This page explains what happens when the reader generates your "For you"
recommendations, from the moment you press the button to the finished list.

## Pressing "Get recommendations"

Pressing **Get recommendations** starts a *run* on the server. A run reads
your recent articles, sends them to the AI model you configured in
**Settings → AI**, and turns the model's answers into your "For you" list.
The run belongs to the server, not to your browser tab: the tab only watches
progress.

## You can close the browser

Once a run has started, the calculation keeps going server-side and
finishes on its own. You do not have to keep the tab open, keep the screen
on, or stay on the page.

When you come back, the reader shows whatever is current: the run's live
progress if it is still working, or the finished "For you" list if it
completed while you were away. One small caveat: a run that finishes while
the tab is closed shows no notification toast when you return — the result
is simply there.

## How fast it runs

Speed depends on how the server drives the run:

- **Fast:** while a background worker or an on-demand drainer process is
  active, the run advances continuously at full speed and typically
  finishes in one go.
- **Slower fallback:** on a host that cannot spawn a background process,
  the run advances roughly one step per minute, driven by a scheduled
  maintenance ping. It still finishes on its own — it just takes longer.

The reader picks the fast path automatically whenever the host allows it;
there is nothing to configure in the UI.

## Stopping a run

**Stop** ends the active run. Stopping is not instant: a request to the AI
provider that is already in flight finishes first, which is why the status
shows *stopping* before it becomes *stopped*. A stopped run keeps the
recommendations it had already banked from completed batches.

## When a run fails

A run fails when the AI provider stays unreachable, rejects your
credentials, or the account's AI configuration is removed mid-run. A failed
run shows the reason in the "For you" view, and you can either:

- **Resume** — continue the failed run at the exact batch where it failed,
  keeping the work that already succeeded; or
- **Start a new run** — begin fresh with the newest articles.

Resume reuses the article snapshot from when the run first started, so if a
lot of time has passed, starting fresh gives more current recommendations.

## Install-dependent behavior

How the fast path is provided depends on the deployment:

- **Docker installs** run a persistent worker container that drives every
  run; nothing else is needed.
- **Worker-less installs** (for example, shared hosting such as Strato)
  rely on an on-demand drainer process that the server starts when your run
  begins, backed by a once-per-minute maintenance ping as the safety net.
  If the host cannot start processes at all, the ping alone carries the run
  at the slower pace described above.
```

- [ ] **Step 2: Link it from the docs index**

In `README.md`, in the docs link list (the block around line 64 that starts with `- [Architecture: client contract and native-client readiness](docs/architecture.md)`), add in a sensible position:

```markdown
- [How a "For you" run works](docs/recommendations-runs.md) — what happens
  after "Get recommendations", closing the browser, stopping, resuming.
```

- [ ] **Step 3: Add the architecture note to the scheduling doc**

In `docs/for-you-scheduling.md`, after the "Without a worker (external cron)" section, add:

```markdown
## The on-demand drainer

Worker-less installs do not depend on the cron cadence for interactive
runs. Starting or resuming a run from the web spawns a short-lived,
detached CLI process (`app:recommendations:drain`) that advances every
active run at full worker concurrency until none is left, then exits. The
spawn only happens while no worker heartbeat is fresh, a global
`recommendation-drain` lock guarantees at most one drainer, and the
maintenance tick respawns a drainer as a safety net if one died with runs
still active. If the host cannot spawn processes (`exec` disabled, no CLI
binary configured), the launch silently no-ops and the cron sweep above
carries the runs exactly as before.

The CLI binary is named by `DRAIN_PHP_CLI_BINARY`; a web SAPI must not run
`bin/console` with its own binary (on Strato the web SAPI is `cgi-fcgi`
with a 240 s execution cap, while `/opt/RZphp84/bin/php-cli` is unbounded).
Empty is fine wherever PHP already runs as `cli` or a real worker exists.
```

- [ ] **Step 4: Add the Strato deploy note**

Read `deploy/strato/README.md` first, find where `shared/.env.local` keys are documented, and add `DRAIN_PHP_CLI_BINARY=/opt/RZphp84/bin/php-cli` to that list with one sentence: it names the CLI binary the web request uses to spawn the recommendation drainer; without it, runs fall back to the per-minute cron pace. Do **not** add a check to `activate-release.sh` — a missing value degrades gracefully by design, and we keep deploy scripts free of guards for rare host failure modes.

- [ ] **Step 5: Commit**

```bash
git add docs/recommendations-runs.md README.md docs/for-you-scheduling.md deploy/strato/README.md
git commit -m "docs(#371): document how a For-you run is driven, incl. the drainer"
```

---

### Task 8: Full verification, mutation gate, PR

- [ ] **Step 1: Full native suite and gates**

From `backend/`:

```bash
composer check && composer md && php bin/phpunit
```

Expected: all green. `composer check` includes cs, stan (warm the cache with `bin/console cache:warmup` if stan behaves oddly), and tramp.

- [ ] **Step 2: PhpStorm inspections on the changed PHP files**

Run `mcp__phpstorm__lint_files` over every created/modified PHP file. Block on ERROR and WARNING; weak warnings are advisory.

- [ ] **Step 3: MySQL leg**

From the repo root (Docker stack must be up — `docker compose up -d`):

```bash
docker compose exec php vendor/bin/phpunit
```

Expected: green, modulo the known order-dependent rate-limiter flake (`mysql-suite-ratelimiter-flake`) — re-run a failing limiter test in isolation before blaming this branch.

- [ ] **Step 4: Mutation gate over the branch's files**

From `backend/` (needs pcov or xdebug):

```bash
composer infection:diff
```

Expected: MSI ≥ 80 on the changed files. Known likely escapes and their fixes:
- The `--detach` / `posix_setsid()` branch cannot be killed in-process (calling `posix_setsid()` under phpunit would steal the runner's session). If it escapes and drags MSI below the gate, exclude nothing — instead add a test asserting the option is *declared* (`$command->getDefinition()->hasOption('detach')`) and accept the remaining escape; if the gate still fails, discuss in the PR rather than lowering `minMsi`.
- `SWEEP_PAUSE_SECONDS` mutants: the TickingClock's `sleep()` is a no-op, so pause-value mutants may survive; a test asserting `sleep()` was called once per loop iteration (a tiny recording clock decorating TickingClock) kills them if needed.

- [ ] **Step 5: Scan the dev log**

Check `backend/var/log/dev.log` for new deprecations or swallowed errors from the suite runs.

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/371-detached-recommendation-drainer
```

Open a PR against `develop` titled `Spawn a detached CLI drainer to drive recommendation runs to completion (#371)`. The body must:
- say `Closes #371`;
- summarize the control flow (start/resume spawn → lock-guarded drain loop → cron respawn net → silent fallback);
- state the deliberate deviation: no `symfony/process` promotion — `\PHP_BINARY` under the `cli` SAPI replaces `PhpExecutableFinder`, and the launch is a plain `exec()` of the smoke-tested shell line (see Global Constraints for the full rationale);
- state that the `--detach` flag exists so tests can run the command in-process without `posix_setsid()` stealing the runner's session;
- walk the issue's acceptance criteria as a checklist.

Do **not** merge, tag, or deploy — Lars decides releases and deploys.

---

## Self-Review (performed while writing)

- **Spec coverage:** run-start/resume spawn (Task 6), cron respawn net (Task 6), single-drainer lock (Task 5), silent fallback (Task 3), heartbeat/worker-regime sharing without merging `ForYouSweep` (Task 1), CLI-binary policy + self-detach via `posix_setsid` (Tasks 2, 5), never starts runs / no spend budget (drain command only sweeps — Task 5), user documentation + docs index link (Task 7), gates incl. `infection:diff` (Task 8). Browser `/tick` not spawning: satisfied by *not* wiring the spawner into `RecommendationPollDriver` — no task touches it. In-app help link: the frontend has no help surface that references docs pages today, so the docs index link is the deliverable; if a reviewer wants an in-app link it is a separate frontend ticket.
- **Deviation from issue:** `symfony/process` not promoted (rationale in Global Constraints, restated in the PR body). `--detach` flag added beyond the issue text (test-isolation necessity, documented in the PR body).
- **Type consistency:** `WorkerRunSweep::sweep(): int` consumed by Task 5's loop; `forCommand(string, string ...): ?string` consumed by Task 3; `launch(string, string ...): void` consumed by Tasks 4 and 6's stubs; spawner constructor `(WorkerPresence, DetachedProcessLauncherInterface)` used in Tasks 4, 6.
