# For You Auto-Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user have their "For You" recommendations generated on a schedule (only manually / every 1, 3, 6, 12, or 24 hours), started automatically by the background worker or by an external cron endpoint on installs without a worker.

**Architecture:** A new nullable per-user field `autoGenerateIntervalHours` on `RecommendationSettings` records the cadence. A shared `ForYouSweep` service starts a run for every "due" user and advances active runs. The worker fires a new 5-minute `StartDueRecommendationRuns` message that calls the start half; a new token-guarded `POST /maintenance/recommendations/sweep` endpoint calls both halves so an external cron replaces the worker. The frontend adds a dropdown to the recommendation-settings card, plus a help note (shown only when no worker is alive) with the cron command.

**Tech Stack:** Symfony 7.4 (PHP 8.4), Doctrine ORM + Migrations, Symfony Messenger + Scheduler, PHPUnit; Angular 20 (standalone + signals), Transloco i18n, Jest.

## Global Constraints

- `declare(strict_types=1);` in every PHP file.
- PHP is Clean Code: names reveal intent, functions do one thing, guard clauses over nesting, `final readonly class` with constructor promotion, depend on injected interfaces, errors are typed exceptions. No boolean flag parameters.
- Controllers stay thin (`ThinControllerRule`): an action reads the request, delegates, returns a response. No private methods that carry responsibility.
- Every touched `src` file must be PHPMD-clean (`composer md`), PSR-12 clean (`composer cs`), PHPStan level max clean (`composer stan`), and PhpStorm-inspection clean (ERROR + WARNING) before commit.
- Frontend: standalone components + signals, no NgModules. Component styles in the sibling `.scss` (never inline). No hex colours, no raw `px`, no media-query literals outside `src/app/theme/` — use design tokens (`var(--space-*)`, `var(--border)`, `var(--fs-sm)`, …). `npm run check` is the gate.
- Native-iOS constraint: JSON in, `application/problem+json` out, bearer/token auth, stateless. No browser-only inputs on any endpoint.
- Datetimes are naive UTC — never introduce a local-timezone datetime.
- The maintenance test token in `backend/.env.test` is `test-maintenance-token`; the guard header is `X-Maintenance-Token` (server var `HTTP_X_MAINTENANCE_TOKEN`).
- Branch: `feature/333-for-you-auto-generate` (already created off `develop`). Commit per task. Never merge unasked.

---

### Task 1: Per-user `autoGenerateIntervalHours` field (value object → entity → resolver → effective → migration)

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsValues.php`
- Modify: `backend/src/Entity/RecommendationSettings.php`
- Modify: `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php`
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsResolver.php`
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsWriter.php`
- Create: `backend/migrations/Version20260809XXXXXX.php` (timestamp from the generator)
- Test: `backend/tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php`

**Interfaces:**
- Produces: `RecommendationSettingsValues::$autoGenerateIntervalHours` (`?int`), `EffectiveRecommendationSettings::$autoGenerateIntervalHours` (`?int`), entity column `user_recommendation_settings.auto_generate_interval_hours` (`INT DEFAULT NULL`). `null` = "only manually".

- [ ] **Step 1: Write the failing round-trip test**

Create `backend/tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\User;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsResolver;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecommendationSettingsRoundTripTest extends KernelTestCase
{
    private function values(?int $autoGenerateIntervalHours): RecommendationSettingsValues
    {
        return new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $autoGenerateIntervalHours,
        );
    }

    public function testTheIntervalPersistsAndResolves(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $resolver = self::getContainer()->get(RecommendationSettingsResolver::class);
        self::assertInstanceOf(RecommendationSettingsResolver::class, $resolver);

        $user = new User('interval-roundtrip@example.com', new \DateTimeImmutable());
        $em->persist($user);
        $em->flush();

        self::assertNull($resolver->forUser($user)->autoGenerateIntervalHours);

        $writer->save($user, $this->values(3));

        self::assertSame(3, $resolver->forUser($user)->autoGenerateIntervalHours);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php`
Expected: FAIL — `RecommendationSettingsValues::__construct()` has no `autoGenerateIntervalHours` argument.

- [ ] **Step 3: Add the field to `RecommendationSettingsValues`**

Add one promoted parameter at the end of the constructor (after `debugEnabled`). It is **trailing and defaulted to `null`** on purpose: three existing callers construct this value object (`RecommendationRunFixtures`, `RecommendationSettingsResolverTest`, `RecommendationRunAdvancerTest`), and a defaulted trailing param keeps them compiling while `null` is the correct "only manually" default.

```php
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
    ) {
    }
```

Note: this makes the constructor 10 parameters. If `composer md` reports `ExcessiveParameterList`, add `@SuppressWarnings("PHPMD.ExcessiveParameterList")` to the class with a one-line reason (it is a pure data carrier that mirrors the settings row 1:1, not a behavioural method).

- [ ] **Step 4: Add the column, `update()`, and `values()` to the entity**

In `backend/src/Entity/RecommendationSettings.php`, add the mapped field near the other columns:

```php
    /**
     * How often the background worker (or the maintenance cron endpoint)
     * starts a fresh run for this account. null means "only manually" (#333).
     */
    #[ORM\Column(nullable: true)]
    private ?int $autoGenerateIntervalHours = null;
```

In `update()`, copy it from the values object:

```php
        $this->autoGenerateIntervalHours = $values->autoGenerateIntervalHours;
```

Find the entity's `values(): RecommendationSettingsValues` builder and pass the field through (add as the last argument, matching the value object order):

```php
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
        );
```

- [ ] **Step 5: Add the field to `EffectiveRecommendationSettings`**

Add the promoted parameter at the end of the constructor (after `debugEnabled`), trailing and defaulted for the same reason as Step 3:

```php
        public bool $debugEnabled,
        public ?int $autoGenerateIntervalHours = null,
    ) {
    }
```

- [ ] **Step 6: Pass it through the resolver**

In `RecommendationSettingsResolver::forUser()`, add the final argument to the `new EffectiveRecommendationSettings(...)` call:

```php
            debugEnabled: $row?->values()->debugEnabled ?? false,
            autoGenerateIntervalHours: $row?->values()->autoGenerateIntervalHours,
        );
```

- [ ] **Step 7: Preserve the field in the writer's normalisation**

In `RecommendationSettingsWriter::withNormalisedGuidance()`, add the field to the reconstructed `new RecommendationSettingsValues(...)`:

```php
            debugEnabled: $values->debugEnabled,
            autoGenerateIntervalHours: $values->autoGenerateIntervalHours,
        );
```

- [ ] **Step 8: Run the test — it passes**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php`
Expected: PASS (the test schema is built from ORM metadata, so no migration is needed for the test to pass).

- [ ] **Step 9: Generate and verify the migration**

Run: `cd backend && bin/console doctrine:migrations:diff --no-interaction`
Open the generated `migrations/Version20260809XXXXXX.php`. Its `up()` must add exactly the one nullable column; edit it down to this if the diff pulled in anything else:

```php
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_recommendation_settings ADD auto_generate_interval_hours INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_recommendation_settings DROP auto_generate_interval_hours');
    }
```

Verify it applies on a scratch database (never the dev DB — see the memory note "Never clear the dev database"):

```bash
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migration-check.sqlite" bin/console doctrine:migrations:migrate --no-interaction && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migration-check.sqlite" bin/console doctrine:schema:validate && rm backend/var/migration-check.sqlite
```

Expected: migrate succeeds; `schema:validate` reports the mapping and database are in sync.

- [ ] **Step 10: Commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add backend/src/Service/Recommendation/RecommendationSettingsValues.php backend/src/Entity/RecommendationSettings.php backend/src/Service/Recommendation/EffectiveRecommendationSettings.php backend/src/Service/Recommendation/RecommendationSettingsResolver.php backend/src/Service/Recommendation/RecommendationSettingsWriter.php backend/migrations/Version20260809*.php backend/tests/Service/Recommendation/RecommendationSettingsRoundTripTest.php
git commit -m "feat(#333): store a per-user auto-generate interval for For You"
```

---

### Task 2: API surface — expose the interval and `workerAlive`, accept the interval on save

**Files:**
- Modify: `backend/src/Http/RecommendationSettingsJson.php`
- Modify: `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`
- Modify: `backend/src/Controller/Api/RecommendationSettingsController.php`
- Test: `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php` (create if absent; otherwise add the cases)

**Interfaces:**
- Consumes: `EffectiveRecommendationSettings::$autoGenerateIntervalHours` (Task 1), `WorkerPresence::isRecommendationWorkerAlive(): bool`.
- Produces: GET/PUT JSON gains `autoGenerateIntervalHours` (`?int`) and `workerAlive` (`bool`). `RecommendationSettingsJson::state(EffectiveRecommendationSettings $effective, bool $workerAlive): array`.

- [ ] **Step 1: Write the failing functional test**

Create `backend/tests/Controller/Api/RecommendationSettingsControllerTest.php`. It authenticates with a minted JWT exactly like `RecommendationRunControllerTest::auth()` (which is the house pattern — `UserFactory` + `JWTTokenManagerInterface`). The settings endpoint is not behind the recommendation rate limiter, so no limiter reset is needed.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationSettingsControllerTest extends WebTestCase
{
    /** @return array<string, string> */
    private function authHeaders(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];
    }

    /** @return array<string, mixed> */
    private function saveBody(?int $autoGenerateIntervalHours): array
    {
        return [
            'guidancePrompt' => null,
            'favoritesCap' => 40, 'keptCap' => 40, 'viewedCap' => 80,
            'candidatePoolSize' => 500, 'picksLimit' => 50,
            'contextWindow' => null, 'batchCount' => null, 'debugEnabled' => false,
            'autoGenerateIntervalHours' => $autoGenerateIntervalHours,
        ];
    }

    public function testShowExposesTheIntervalAndWorkerLiveness(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/me/ai/recommendations', server: $this->authHeaders('reco-settings-show@example.com'));

        self::assertResponseIsSuccessful();
        /** @var array{autoGenerateIntervalHours: int|null, workerAlive: bool} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($payload['autoGenerateIntervalHours']);
        self::assertArrayHasKey('workerAlive', $payload);
        self::assertIsBool($payload['workerAlive']);
    }

    public function testSaveAcceptsAnAllowedInterval(): void
    {
        $client = self::createClient();
        $client->request(
            'PUT',
            '/api/me/ai/recommendations',
            server: $this->authHeaders('reco-settings-save@example.com') + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->saveBody(6), JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(6, $payload['autoGenerateIntervalHours']);
    }

    public function testSaveRejectsADisallowedInterval(): void
    {
        $client = self::createClient();
        $client->request(
            'PUT',
            '/api/me/ai/recommendations',
            server: $this->authHeaders('reco-settings-bad@example.com') + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->saveBody(5), JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(422);
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/RecommendationSettingsControllerTest.php`
Expected: FAIL — payload has no `autoGenerateIntervalHours`/`workerAlive`; the disallowed value is accepted.

- [ ] **Step 3: Add both fields to the JSON mapper**

In `RecommendationSettingsJson`, change the signature and body:

```php
    /**
     * @return array<string, mixed>
     */
    public static function state(EffectiveRecommendationSettings $effective, bool $workerAlive): array
    {
        return [
            // ... every existing key unchanged ...
            'debugEnabled' => $effective->debugEnabled,
            'autoGenerateIntervalHours' => $effective->autoGenerateIntervalHours,
            'workerAlive' => $workerAlive,
        ];
    }
```

- [ ] **Step 4: Add the validated field to the request DTO**

In `SaveRecommendationSettingsRequest`, add the promoted parameter (after `debugEnabled`) with a `Choice` that also permits `null`:

```php
        public bool $debugEnabled,
        #[Assert\Choice(choices: [null, 1, 3, 6, 12, 24])]
        public ?int $autoGenerateIntervalHours,
    ) {
    }
```

And pass it through `values()`:

```php
            debugEnabled: $this->debugEnabled,
            autoGenerateIntervalHours: $this->autoGenerateIntervalHours,
        );
```

- [ ] **Step 5: Feed `workerAlive` from the controller**

In `RecommendationSettingsController`, inject `WorkerPresence` and pass its reading to both responses:

```php
use App\Service\Worker\WorkerPresence;
// ...
    public function __construct(
        private RecommendationSettingsResolver $resolver,
        private RecommendationSettingsWriter $writer,
        private WorkerPresence $presence,
    ) {
    }

    #[Route('', name: 'api_me_ai_recommendations_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationSettingsJson::state(
            $this->resolver->forUser($user),
            $this->presence->isRecommendationWorkerAlive(),
        ));
    }

    #[Route('', name: 'api_me_ai_recommendations_save', methods: ['PUT'])]
    public function save(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SaveRecommendationSettingsRequest $request,
    ): JsonResponse {
        $this->writer->save($user, $request->values());

        return new JsonResponse(RecommendationSettingsJson::state(
            $this->resolver->forUser($user),
            $this->presence->isRecommendationWorkerAlive(),
        ));
    }
```

- [ ] **Step 6: Run the tests — they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/RecommendationSettingsControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add backend/src/Http/RecommendationSettingsJson.php backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php backend/src/Controller/Api/RecommendationSettingsController.php backend/tests/Controller/Api/RecommendationSettingsControllerTest.php
git commit -m "feat(#333): expose the auto-generate interval and worker liveness on the settings API"
```

---

### Task 3: `DueRecommendationRunFinder` (which users are due for an auto-run)

**Files:**
- Modify: `backend/src/Repository/RecommendationSettingsRepository.php`
- Create: `backend/src/Service/Recommendation/DueRecommendationRunFinder.php`
- Test: `backend/tests/Service/Recommendation/DueRecommendationRunFinderTest.php`

**Interfaces:**
- Consumes: `RecommendationSettingsRepository::findWithAutoGenerateInterval(): list<RecommendationSettings>` (new), `RecommendationRunRepository::findActiveForUser`, `RecommendationRunRepository::findLatestForUser`, `AiProviderConfigurator::settingsFor(User): ?AiProviderSettings`, `AiSettingsJson::isReady(?AiProviderSettings): bool`, `ClockInterface`.
- Produces: `DueRecommendationRunFinder::due(): list<User>`.

- [ ] **Step 1: Add the repository query**

In `RecommendationSettingsRepository`:

```php
    /**
     * Every account that opted into a scheduled run (#333); the finder decides
     * which of them are actually due right now.
     *
     * @return list<RecommendationSettings>
     */
    public function findWithAutoGenerateInterval(): array
    {
        /** @var list<RecommendationSettings> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.autoGenerateIntervalHours IS NOT NULL')
            ->getQuery()
            ->getResult();

        return $rows;
    }
```

- [ ] **Step 2: Write the failing integration test**

The finder's collaborators (`RecommendationSettingsRepository`, `RecommendationRunRepository`, `AiProviderConfigurator`) are all `final` and cannot be doubled — the house style is a `DbTestCase` with real container services and real fixtures (see `AdvanceRecommendationRunsHandlerTest`). Because the SQLite database is shared across the process, assert **membership** for the specific email under test (`assertContains` / `assertNotContains`), never the whole result set. Create `backend/tests/Service/Recommendation/DueRecommendationRunFinderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\DueRecommendationRunFinder;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DueRecommendationRunFinderTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function setCadence(User $user, int $hours): void
    {
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->save($user, new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $hours,
        ));
    }

    /** A terminal (non-active) run with a chosen start time, so the anchor is testable. fail() is reachable from PENDING. */
    private function pastFailedRun(User $user, string $ago): void
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable($ago));
        $run->fail('irrelevant', new \DateTimeImmutable($ago));
        $this->em->persist($run);
        $this->em->flush();
    }

    /** @return list<string> */
    private function dueEmails(): array
    {
        $finder = self::getContainer()->get(DueRecommendationRunFinder::class);
        self::assertInstanceOf(DueRecommendationRunFinder::class, $finder);

        return array_map(static fn (User $user): string => $user->getEmail(), $finder->due());
    }

    public function testDueWhenTheAnchorElapsed(): void
    {
        $user = $this->user('finder-due@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 3);
        $this->pastFailedRun($user, '-5 hours');
        $this->em->clear();

        self::assertContains('finder-due@example.test', $this->dueEmails());
    }

    public function testNotDueInsideTheInterval(): void
    {
        $user = $this->user('finder-fresh@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 6);
        $this->pastFailedRun($user, '-1 hour');
        $this->em->clear();

        self::assertNotContains('finder-fresh@example.test', $this->dueEmails());
    }

    public function testNotDueWhileARunIsActive(): void
    {
        $user = $this->user('finder-active@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 1);
        $starter = self::getContainer()->get(RecommendationRunStarter::class);
        self::assertInstanceOf(RecommendationRunStarter::class, $starter);
        $starter->start($user); // a PENDING (active) run
        $this->em->clear();

        self::assertNotContains('finder-active@example.test', $this->dueEmails());
    }

    public function testSkippedWhenAiIsNotReady(): void
    {
        $user = $this->user('finder-no-ai@example.test');
        $this->setCadence($user, 1); // deliberately no seedReadyAiSettings
        $this->em->clear();

        self::assertNotContains('finder-no-ai@example.test', $this->dueEmails());
    }

    public function testDueWhenNoPriorRunExists(): void
    {
        $user = $this->user('finder-never-ran@example.test');
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 24);
        $this->em->clear();

        self::assertContains('finder-never-ran@example.test', $this->dueEmails());
    }
}
```

- [ ] **Step 3: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/DueRecommendationRunFinderTest.php`
Expected: FAIL — `DueRecommendationRunFinder` does not exist.

- [ ] **Step 4: Implement the finder**

Create `backend/src/Service/Recommendation/DueRecommendationRunFinder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Http\AiSettingsJson;
use App\Repository\RecommendationRunRepository;
use App\Repository\RecommendationSettingsRepository;
use App\Service\Ai\AiProviderConfigurator;
use Symfony\Component\Clock\ClockInterface;

/**
 * The accounts a scheduled sweep should start a run for right now (#333). A
 * user qualifies when they chose a cadence, their AI is ready, they have no
 * run in flight, and their newest run is at least one interval old. The
 * newest run's start time is the anchor, so any run — manual, worker, or cron
 * — resets the clock; a failed run therefore waits a full interval before the
 * next attempt rather than hammering a broken provider.
 */
final readonly class DueRecommendationRunFinder
{
    public function __construct(
        private RecommendationSettingsRepository $settings,
        private RecommendationRunRepository $runs,
        private AiProviderConfigurator $configurator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<User>
     */
    public function due(): array
    {
        $due = [];

        foreach ($this->settings->findWithAutoGenerateInterval() as $row) {
            $user = $row->getUser();

            if ($this->isDue($row, $user)) {
                $due[] = $user;
            }
        }

        return $due;
    }

    private function isDue(RecommendationSettings $row, User $user): bool
    {
        if (!AiSettingsJson::isReady($this->configurator->settingsFor($user))) {
            return false;
        }

        if (null !== $this->runs->findActiveForUser($user)) {
            return false;
        }

        $anchor = $this->runs->findLatestForUser($user)?->getCreatedAt();
        if (null === $anchor) {
            return true;
        }

        $intervalHours = $row->values()->autoGenerateIntervalHours;

        return $this->clock->now() >= $anchor->modify(\sprintf('+%d hours', $intervalHours));
    }
}
```

- [ ] **Step 5: Run the test — it passes**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/DueRecommendationRunFinderTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add backend/src/Repository/RecommendationSettingsRepository.php backend/src/Service/Recommendation/DueRecommendationRunFinder.php backend/tests/Service/Recommendation/DueRecommendationRunFinderTest.php
git commit -m "feat(#333): find the accounts due for a scheduled For You run"
```

---

### Task 4: `ForYouSweep` service and report

**Files:**
- Create: `backend/src/Service/Recommendation/ForYouSweepReport.php`
- Create: `backend/src/Service/Recommendation/ForYouSweep.php`
- Test: `backend/tests/Service/Recommendation/ForYouSweepTest.php`

**Interfaces:**
- Consumes: `DueRecommendationRunFinder::due()` (Task 3), `RecommendationRunStarter::start(User): RecommendationRunReport`, `RecommendationRunAdvancer::advance(User): RecommendationRunReport`, `RecommendationRunRepository::findAllActive(): list<RecommendationRun>`, `EntityManagerInterface`, `LoggerInterface`.
- Produces: `ForYouSweep::startDueRuns(): int`, `ForYouSweep::sweepOnce(): ForYouSweepReport`, `ForYouSweepReport{int $startedRuns, int $advancedRuns, int $activeRuns}` with `toArray()`.

- [ ] **Step 1: Write the failing tests**

Two files. The report is a plain value object — unit-test it directly (no doubles). The sweep's collaborators are all `final`, so test it as a `DbTestCase` integration with real services and fixtures, and assert **side effects on the specific user** plus `>=` bounds on the counts (the SQLite DB is shared across the process).

Create `backend/tests/Service/Recommendation/ForYouSweepReportTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Service\Recommendation\ForYouSweepReport;
use PHPUnit\Framework\TestCase;

final class ForYouSweepReportTest extends TestCase
{
    public function testToArrayCarriesTheThreeCounts(): void
    {
        self::assertSame(
            ['startedRuns' => 2, 'advancedRuns' => 3, 'activeRuns' => 1],
            (new ForYouSweepReport(2, 3, 1))->toArray(),
        );
    }
}
```

Create `backend/tests/Service/Recommendation/ForYouSweepTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\ForYouSweep;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ForYouSweepTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function sweep(): ForYouSweep
    {
        $sweep = self::getContainer()->get(ForYouSweep::class);
        self::assertInstanceOf(ForYouSweep::class, $sweep);

        return $sweep;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function setCadence(User $user, int $hours): void
    {
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
        self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
        $writer->save($user, new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
            keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
            viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
            candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
            picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            autoGenerateIntervalHours: $hours,
        ));
    }

    /** Ready AI + a feed with unread entries, so a snapshot has candidates and the run stays RUNNING. */
    private function seedDueUserWithCandidates(string $email): User
    {
        $user = $this->user($email);
        $this->fixtures->seedReadyAiSettings($user);
        $this->setCadence($user, 1);

        $feed = new Feed('https://example.com/' . $email . '/feed.xml');
        $feed->setTitle('Example');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();

        for ($i = 0; $i < 5; $i++) {
            $this->fixtures->entry($feed, $email . '-entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }

        return $user;
    }

    public function testStartDueRunsStartsARunForEachDueUser(): void
    {
        $first = $this->user('sweep-start-a@example.test');
        $this->fixtures->seedReadyAiSettings($first);
        $this->setCadence($first, 1);
        $second = $this->user('sweep-start-b@example.test');
        $this->fixtures->seedReadyAiSettings($second);
        $this->setCadence($second, 1);

        $started = $this->sweep()->startDueRuns();
        $this->em->clear();

        self::assertGreaterThanOrEqual(2, $started);
        self::assertNotNull($this->runs()->findActiveForUser($first));
        self::assertNotNull($this->runs()->findActiveForUser($second));
    }

    public function testSweepOnceStartsThenSnapshotsADueUsersRun(): void
    {
        $user = $this->seedDueUserWithCandidates('sweep-once@example.test');

        $report = $this->sweep()->sweepOnce();
        $this->em->clear();

        self::assertGreaterThanOrEqual(1, $report->startedRuns);
        self::assertGreaterThanOrEqual(1, $report->advancedRuns);

        // One advance is the snapshot step (no provider call), so the run is
        // now RUNNING rather than PENDING or completed.
        $run = $this->runs()->findActiveForUser($user);
        self::assertNotNull($run);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $run->getStatus());
    }
}
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/ForYouSweepReportTest.php tests/Service/Recommendation/ForYouSweepTest.php`
Expected: FAIL — `ForYouSweep`/`ForYouSweepReport` do not exist.

- [ ] **Step 3: Implement the report**

Create `backend/src/Service/Recommendation/ForYouSweepReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The outcome of one For You sweep (#333): how many runs it started, how many
 * active runs it advanced by one tick, and how many are still active after.
 */
final readonly class ForYouSweepReport
{
    public function __construct(
        public int $startedRuns,
        public int $advancedRuns,
        public int $activeRuns,
    ) {
    }

    /**
     * @return array{startedRuns: int, advancedRuns: int, activeRuns: int}
     */
    public function toArray(): array
    {
        return [
            'startedRuns' => $this->startedRuns,
            'advancedRuns' => $this->advancedRuns,
            'activeRuns' => $this->activeRuns,
        ];
    }
}
```

- [ ] **Step 4: Implement the sweep**

Create `backend/src/Service/Recommendation/ForYouSweep.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Exception\AiNotConfiguredException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The scheduled generation of "For you" (#333), shared by the worker's
 * StartDueRecommendationRuns handler and the maintenance cron endpoint.
 *
 * `startDueRuns()` is the worker's half: on a worker-equipped install the
 * ten-second AdvanceRecommendationRuns sweep drives the started runs to the
 * finish, so the worker only needs to start them. `sweepOnce()` is the cron
 * half: a worker-less install has no advance sweep, so one call both starts
 * due runs and advances every active run once. It advances one tick per run —
 * one provider call, which the advancer flushes — so a request the gateway
 * kills still leaves committed progress and the next call resumes.
 */
final readonly class ForYouSweep
{
    public function __construct(
        private DueRecommendationRunFinder $finder,
        private RecommendationRunStarter $starter,
        private RecommendationRunAdvancer $advancer,
        private RecommendationRunRepository $runs,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function startDueRuns(): int
    {
        $started = 0;

        foreach ($this->finder->due() as $user) {
            try {
                $this->starter->start($user);
                ++$started;
            } catch (AiNotConfiguredException) {
                // The finder already filters unready accounts; this is the
                // defensive floor for a race where the config changed between
                // the query and the start. Skip, do not fail the sweep.
            }
        }

        return $started;
    }

    public function sweepOnce(): ForYouSweepReport
    {
        $startedRuns = $this->startDueRuns();

        $advancedRuns = 0;
        foreach ($this->runs->findAllActive() as $run) {
            $advancedRuns += $this->advanceOne($run);
        }

        // The identity map is per-sweep state, not request state; clear it so
        // the remaining-active count below is a fresh read from the database.
        $this->entityManager->clear();

        return new ForYouSweepReport($startedRuns, $advancedRuns, \count($this->runs->findAllActive()));
    }

    private function advanceOne(RecommendationRun $run): int
    {
        try {
            $this->advancer->advance($run->getUser());

            return 1;
        } catch (\Throwable $exception) {
            // The advancer already recorded the failure against the run before
            // rethrowing; a broken provider for one account must not abort the
            // sweep for the rest. Log and move on.
            $this->logger->warning('For You sweep: advancing a run failed.', [
                'runId' => $run->getId(),
                'exception' => $exception,
            ]);

            return 0;
        }
    }
}
```

- [ ] **Step 5: Run the tests — they pass**

Run: `cd backend && php bin/phpunit tests/Service/Recommendation/ForYouSweepReportTest.php tests/Service/Recommendation/ForYouSweepTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add backend/src/Service/Recommendation/ForYouSweepReport.php backend/src/Service/Recommendation/ForYouSweep.php backend/tests/Service/Recommendation/ForYouSweepReportTest.php backend/tests/Service/Recommendation/ForYouSweepTest.php
git commit -m "feat(#333): add the shared For You sweep that starts and advances due runs"
```

---

### Task 5: Worker schedule entry — `StartDueRecommendationRuns` message, handler, wiring

**Files:**
- Create: `backend/src/Service/Worker/Message/StartDueRecommendationRuns.php`
- Create: `backend/src/Service/Worker/Handler/StartDueRecommendationRunsHandler.php`
- Modify: `backend/src/Service/Worker/WorkerSchedule.php`
- Modify: `backend/tests/Service/Worker/WorkerScheduleWiringTest.php`
- Test: `backend/tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php`

**Interfaces:**
- Consumes: `ForYouSweep::startDueRuns()` (Task 4).
- Produces: `StartDueRecommendationRuns` message class; a 4th recurring schedule entry at `every('5 minutes')`.

- [ ] **Step 1: Update the wiring test to expect four entries**

In `WorkerScheduleWiringTest::testTheWorkerScheduleCarriesExactlyTheDecidedEntries`, import the new message and change the two assertions:

```php
use App\Service\Worker\Message\StartDueRecommendationRuns;
// ...
        self::assertCount(4, $recurringMessages);
        // ...
        self::assertSame(
            [
                AdvanceRecommendationRuns::class,
                StartDueRecommendationRuns::class,
                RefreshDueFeeds::class,
                PurgeFailedMessages::class,
            ],
            $classes,
        );
        // ...
        self::assertSame(
            ['every 10 seconds', 'every 5 minutes', 'every 5 minutes', 'every 1 day'],
            $frequencies,
        );
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd backend && php bin/phpunit tests/Service/Worker/WorkerScheduleWiringTest.php`
Expected: FAIL — count is 3 and the message class is unknown.

- [ ] **Step 3: Create the message**

Create `backend/src/Service/Worker/Message/StartDueRecommendationRuns.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Message;

/**
 * Start a fresh recommendation run for every account whose chosen cadence has
 * elapsed (#333). Property-less like its siblings: a copy stuck in the failure
 * transport can never go stale, because the work is "whatever is due now".
 */
final readonly class StartDueRecommendationRuns
{
}
```

- [ ] **Step 4: Create the handler**

Create `backend/src/Service/Worker/Handler/StartDueRecommendationRunsHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Worker\Handler;

use App\Service\Recommendation\ForYouSweep;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Starts the due runs every five minutes (#333). Advancing them to completion
 * stays the ten-second AdvanceRecommendationRuns sweep's job, so this handler
 * only starts — the two concerns never merge into one message.
 */
#[AsMessageHandler]
final readonly class StartDueRecommendationRunsHandler
{
    public function __construct(
        private ForYouSweep $sweep,
    ) {
    }

    public function __invoke(StartDueRecommendationRuns $message): void
    {
        $this->sweep->startDueRuns();
    }
}
```

- [ ] **Step 5: Wire it into the schedule**

In `WorkerSchedule.php`, import the message and add the entry as the second one, and extend the class doc comment to record the reversal of `#308`:

```php
use App\Service\Worker\Message\StartDueRecommendationRuns;
// ...
        return (new Schedule())
            ->add(RecurringMessage::every('10 seconds', new AdvanceRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new StartDueRecommendationRuns()))
            ->add(RecurringMessage::every('5 minutes', new RefreshDueFeeds()))
            ->add(RecurringMessage::every('1 day', new PurgeFailedMessages()))
            ->stateful($this->schedulerStateCache)
            ->processOnlyLastMissedRun(true);
```

In the class doc comment, replace the sentence "Scheduled recommendation runs stay out (#308: manual button only)." with:

```
     * The recommendation START sweep (#333) supersedes #308's "manual button
     * only" as an opt-in: it starts a run only for an account that chose a
     * cadence in its For You settings, and the ten-second sweep above then
     * advances it. An account that never chose one is never started.
```

- [ ] **Step 6: Write the handler's functional test**

Create `backend/tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php`. It extends `DbTestCase` (giving `$this->em`) like `AdvanceRecommendationRunsHandlerTest`, seeds ready AI settings with `RecommendationRunFixtures::seedReadyAiSettings`, and sets the cadence through `RecommendationSettingsWriter`. A run needs only ready AI settings to *start* (candidates load later), so this asserts a run becomes active, not that it completes:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\EffectiveRecommendationSettings;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Service\Recommendation\RecommendationSettingsWriter;
use App\Service\Worker\Handler\StartDueRecommendationRunsHandler;
use App\Service\Worker\Message\StartDueRecommendationRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class StartDueRecommendationRunsHandlerTest extends DbTestCase
{
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        self::assertInstanceOf(ApiKeyCipher::class, $cipher);
        $this->fixtures = new RecommendationRunFixtures($this->em, $cipher);
    }

    private function aiReadyUserWithCadence(string $email, ?int $hours): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($this->em, $hasher))->create($email);
        $this->fixtures->seedReadyAiSettings($user);

        if (null !== $hours) {
            $writer = self::getContainer()->get(RecommendationSettingsWriter::class);
            self::assertInstanceOf(RecommendationSettingsWriter::class, $writer);
            $writer->save($user, new RecommendationSettingsValues(
                guidancePrompt: null,
                favoritesCap: EffectiveRecommendationSettings::DEFAULT_FAVORITES_CAP,
                keptCap: EffectiveRecommendationSettings::DEFAULT_KEPT_CAP,
                viewedCap: EffectiveRecommendationSettings::DEFAULT_VIEWED_CAP,
                candidatePoolSize: EffectiveRecommendationSettings::DEFAULT_CANDIDATE_POOL_SIZE,
                picksLimit: EffectiveRecommendationSettings::DEFAULT_PICKS_LIMIT,
                contextWindow: null,
                batchCount: null,
                debugEnabled: false,
                autoGenerateIntervalHours: $hours,
            ));
        }

        return $user;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(\App\Entity\RecommendationRun::class);

        return $repository;
    }

    private function handler(): StartDueRecommendationRunsHandler
    {
        $handler = self::getContainer()->get(StartDueRecommendationRunsHandler::class);
        self::assertInstanceOf(StartDueRecommendationRunsHandler::class, $handler);

        return $handler;
    }

    public function testItStartsARunForADueOptedInUser(): void
    {
        $user = $this->aiReadyUserWithCadence('start-due-opted-in@example.test', 1);

        $this->handler()->__invoke(new StartDueRecommendationRuns());
        $this->em->clear();

        self::assertNotNull($this->runs()->findActiveForUser($user));
    }

    public function testItStartsNothingForAUserWithoutACadence(): void
    {
        $user = $this->aiReadyUserWithCadence('start-due-no-cadence@example.test', null);

        $this->handler()->__invoke(new StartDueRecommendationRuns());
        $this->em->clear();

        self::assertNull($this->runs()->findActiveForUser($user));
    }
}
```

- [ ] **Step 7: Run the worker tests — they pass**

Run: `cd backend && php bin/phpunit tests/Service/Worker/`
Expected: PASS (wiring test sees four entries; handler test starts exactly the opted-in run).

- [ ] **Step 8: Commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add backend/src/Service/Worker/Message/StartDueRecommendationRuns.php backend/src/Service/Worker/Handler/StartDueRecommendationRunsHandler.php backend/src/Service/Worker/WorkerSchedule.php backend/tests/Service/Worker/WorkerScheduleWiringTest.php backend/tests/Service/Worker/StartDueRecommendationRunsHandlerTest.php
git commit -m "feat(#333): start due For You runs from the worker every five minutes"
```

---

### Task 6: Cron endpoint — `POST /maintenance/recommendations/sweep`

**Files:**
- Modify: `backend/src/Controller/MaintenanceController.php`
- Test: `backend/tests/Controller/MaintenanceControllerTest.php`

**Interfaces:**
- Consumes: `MaintenanceTokenGuard::isAuthorized(Request): bool`, `ForYouSweep::sweepOnce(): ForYouSweepReport` (Task 4).
- Produces: `POST /maintenance/recommendations/sweep` → `200` with the sweep report JSON, or `403` on a bad/missing token.

- [ ] **Step 1: Write the failing tests**

Add to `backend/tests/Controller/MaintenanceControllerTest.php`:

```php
    public function testRecommendationSweepRejectsMissingToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/recommendations/sweep');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRecommendationSweepRunsWithValidToken(): void
    {
        $client = self::createClient();

        $client->request('POST', '/maintenance/recommendations/sweep', server: [
            'HTTP_X_MAINTENANCE_TOKEN' => 'test-maintenance-token',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        // Shared SQLite DB: other test classes may have left runs, so assert
        // the report's shape, not exact zero counts.
        /** @var array{startedRuns: int, advancedRuns: int, activeRuns: int} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsInt($payload['startedRuns']);
        self::assertIsInt($payload['advancedRuns']);
        self::assertIsInt($payload['activeRuns']);
    }

    public function testRecommendationSweepGetMethodIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/maintenance/recommendations/sweep?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(405);
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `cd backend && php bin/phpunit tests/Controller/MaintenanceControllerTest.php`
Expected: FAIL — the route is 404, so the valid-token case does not return 200.

- [ ] **Step 3: Add the action to `MaintenanceController`**

Inject `ForYouSweep` and add the route. Keep the action thin — guard, delegate, return:

```php
use App\Service\Recommendation\ForYouSweep;
// ...
    public function __construct(
        private MaintenanceTokenGuard $tokenGuard,
        private RefreshRunner $refreshRunner,
        private ForYouSweep $forYouSweep,
    ) {
    }

    /**
     * Starts the accounts that are due and advances every active run once, so
     * an install without the background worker can drive scheduled generation
     * from an external cron (#333). One tick per run keeps the request bounded.
     */
    #[Route('/maintenance/recommendations/sweep', name: 'maintenance_recommendations_sweep', methods: ['POST'])]
    public function sweepRecommendations(Request $request): JsonResponse
    {
        if (!$this->tokenGuard->isAuthorized($request)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->forYouSweep->sweepOnce()->toArray());
    }
```

- [ ] **Step 4: Run the tests — they pass**

Run: `cd backend && php bin/phpunit tests/Controller/MaintenanceControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd backend && composer cs:fix && composer check && composer md
git add backend/src/Controller/MaintenanceController.php backend/tests/Controller/MaintenanceControllerTest.php
git commit -m "feat(#333): add a token-guarded maintenance endpoint to sweep For You runs"
```

---

### Task 7: Frontend service — carry the new fields

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings.service.ts`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts` (extend the shared `STATE`)

**Interfaces:**
- Produces: `RecommendationSettingsState.autoGenerateIntervalHours: number | null`, `RecommendationSettingsState.workerAlive: boolean`, `SaveRecommendationSettings.autoGenerateIntervalHours: number | null`.

- [ ] **Step 1: Add the fields to the interfaces**

In `recommendation-settings.service.ts`, add to `RecommendationSettingsState` (after `debugEnabled`):

```ts
  readonly debugEnabled: boolean;
  /** Chosen auto-generate cadence in hours; null = only manually (#333). */
  readonly autoGenerateIntervalHours: number | null;
  /** Whether a background worker heartbeat is fresh; false hides the schedule's
   *  external-cron help note. */
  readonly workerAlive: boolean;
```

And to `SaveRecommendationSettings` (after `debugEnabled`):

```ts
  readonly debugEnabled: boolean;
  readonly autoGenerateIntervalHours: number | null;
```

- [ ] **Step 2: Extend the test's shared `STATE`**

In `recommendation-settings-card.component.spec.ts`, add the two fields to the `STATE` constant so it still satisfies the interface:

```ts
    debugEnabled: false,
    autoGenerateIntervalHours: null,
    workerAlive: true,
  };
```

- [ ] **Step 3: Run the frontend checks**

Run: `cd frontend && npx tsc --noEmit && npx jest src/app/settings/recommendation-settings-card.component.spec.ts`
Expected: PASS (types compile; existing card spec still green).

- [ ] **Step 4: Commit**

```bash
cd frontend && npx prettier --write src/app/settings/recommendation-settings.service.ts src/app/settings/recommendation-settings-card.component.spec.ts
git add frontend/src/app/settings/recommendation-settings.service.ts frontend/src/app/settings/recommendation-settings-card.component.spec.ts
git commit -m "feat(#333): carry the auto-generate interval and worker liveness in the settings service"
```

---

### Task 8: Frontend dropdown, help note, styles, i18n

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.ts`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.html`
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.scss`
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`
- Test: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts`

**Interfaces:**
- Consumes: `RecommendationSettingsState.autoGenerateIntervalHours`, `.workerAlive` (Task 7).

- [ ] **Step 1: Write the failing component tests**

Add to `recommendation-settings-card.component.spec.ts`:

```ts
  const select = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLSelectElement | null =>
    fixture.nativeElement.querySelector('select[data-testid="auto-generate"]');

  const cronNote = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLElement | null => fixture.nativeElement.querySelector('.cron-example');

  it('shows the auto-generate dropdown reflecting the saved interval', () => {
    const fixture = mount({ ...STATE, autoGenerateIntervalHours: 3, workerAlive: true });
    const dropdown = select(fixture);
    expect(dropdown).not.toBeNull();
    expect(dropdown!.value).toBe('3');
  });

  it('hides the cron help note while a worker is alive', () => {
    const fixture = mount({ ...STATE, workerAlive: true });
    expect(cronNote(fixture)).toBeNull();
  });

  it('shows the cron help note when no worker is alive', () => {
    const fixture = mount({ ...STATE, workerAlive: false });
    expect(cronNote(fixture)).not.toBeNull();
  });

  it('sends the chosen interval on save', () => {
    const fixture = mount({ ...STATE, autoGenerateIntervalHours: null, workerAlive: true });
    const dropdown = select(fixture)!;
    dropdown.value = '12';
    dropdown.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    fixture.nativeElement.querySelector('app-button[variant="primary"] button')?.click();
    const put = http.expectOne('/api/me/ai/recommendations');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.autoGenerateIntervalHours).toBe(12);
    put.flush({ ...STATE, autoGenerateIntervalHours: 12, workerAlive: true });
  });
```

(If clicking the primary `app-button` does not trigger `save()` in this test harness, call the component instance's `save()` directly the way neighbouring save tests in this file do.)

- [ ] **Step 2: Run them and watch them fail**

Run: `cd frontend && npx jest src/app/settings/recommendation-settings-card.component.spec.ts`
Expected: FAIL — no `select[data-testid="auto-generate"]`, no `.cron-example`.

- [ ] **Step 3: Add signals, options, and the change handler to the component**

In `recommendation-settings-card.component.ts` add, alongside the other `linkedSignal`s:

```ts
  readonly autoGenerateIntervalHours = linkedSignal<number | null>(
    () => this.svc.state()?.autoGenerateIntervalHours ?? null,
  );
  readonly workerAlive = computed<boolean>(() => this.svc.state()?.workerAlive ?? false);

  /** The six cadence choices; null is "only manually". */
  readonly intervalOptions: ReadonlyArray<{ readonly value: number | null; readonly key: string }> = [
    { value: null, key: 'settings.ai.recommendations.autoGenerateManual' },
    { value: 1, key: 'settings.ai.recommendations.autoGenerate1' },
    { value: 3, key: 'settings.ai.recommendations.autoGenerate3' },
    { value: 6, key: 'settings.ai.recommendations.autoGenerate6' },
    { value: 12, key: 'settings.ai.recommendations.autoGenerate12' },
    { value: 24, key: 'settings.ai.recommendations.autoGenerate24' },
  ];
```

Add the change handler near `setNumber`:

```ts
  setAutoGenerate(event: Event): void {
    const raw = (event.target as HTMLSelectElement).value;
    this.autoGenerateIntervalHours.set(raw === '' ? null : +raw);
  }
```

Add the field to the `save()` payload:

```ts
      debugEnabled: this.debugEnabled(),
      autoGenerateIntervalHours: this.autoGenerateIntervalHours(),
    });
```

- [ ] **Step 4: Add the dropdown and help note to the template**

In `recommendation-settings-card.component.html`, inside the `@if (svc.state(); as state) {` block, above the `.group` guidance block, add:

```html
  <app-field [label]="'settings.ai.recommendations.autoGenerate' | transloco">
    <select data-testid="auto-generate" (change)="setAutoGenerate($event)">
      @for (opt of intervalOptions; track opt.value) {
        <option [value]="opt.value ?? ''" [selected]="autoGenerateIntervalHours() === opt.value">
          {{ opt.key | transloco }}
        </option>
      }
    </select>
  </app-field>

  @if (!workerAlive()) {
    <p class="no-worker">{{ 'settings.ai.recommendations.autoGenerateNoWorker' | transloco }}</p>
    <pre class="cron-example">curl -X POST https://YOUR_HOST/maintenance/recommendations/sweep
  -H "X-Maintenance-Token: &lt;MAINTENANCE_TOKEN&gt;"</pre>
  }
```

- [ ] **Step 5: Add the styles**

In `recommendation-settings-card.component.scss`, add (reusing the existing tokens the file already uses):

```scss
select {
  width: 100%;
}

.no-worker {
  margin: var(--space-2) 0 0;
  font-size: var(--fs-sm);
  color: var(--text-muted);
}

.cron-example {
  margin: var(--space-2) 0 var(--space-3);
  padding: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--surface-0);
  color: var(--text-muted);
  font-size: var(--fs-sm);
  white-space: pre-wrap;
}
```

- [ ] **Step 6: Add the i18n keys**

In `frontend/public/i18n/en.json`, under `settings.ai.recommendations`, add:

```json
      "autoGenerate": "Auto-generate For you",
      "autoGenerateManual": "Only manually",
      "autoGenerate1": "Every hour",
      "autoGenerate3": "Every 3 hours",
      "autoGenerate6": "Every 6 hours",
      "autoGenerate12": "Every 12 hours",
      "autoGenerate24": "Every 24 hours",
      "autoGenerateNoWorker": "No background worker is running, so the schedule cannot start on its own. Trigger it from an external cron (for example a GitHub Actions schedule) by calling this endpoint with your maintenance token:",
```

In `frontend/public/i18n/de.json`, under `settings.ai.recommendations`, add:

```json
      "autoGenerate": "„Für dich“ automatisch erzeugen",
      "autoGenerateManual": "Nur manuell",
      "autoGenerate1": "Jede Stunde",
      "autoGenerate3": "Alle 3 Stunden",
      "autoGenerate6": "Alle 6 Stunden",
      "autoGenerate12": "Alle 12 Stunden",
      "autoGenerate24": "Alle 24 Stunden",
      "autoGenerateNoWorker": "Es läuft kein Hintergrund-Worker, daher kann der Zeitplan nicht von selbst starten. Löse ihn über einen externen Cron (zum Beispiel einen GitHub-Actions-Zeitplan) aus, indem du diesen Endpunkt mit deinem Wartungs-Token aufrufst:",
```

- [ ] **Step 7: Run the component tests — they pass**

Run: `cd frontend && npx jest src/app/settings/recommendation-settings-card.component.spec.ts`
Expected: PASS.

- [ ] **Step 8: Run the full frontend gate**

Run: `cd frontend && npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all clean (no hex, no raw `px`).

- [ ] **Step 9: Commit**

```bash
cd frontend
git add src/app/settings/recommendation-settings-card.component.ts src/app/settings/recommendation-settings-card.component.html src/app/settings/recommendation-settings-card.component.scss public/i18n/en.json public/i18n/de.json src/app/settings/recommendation-settings-card.component.spec.ts
git commit -m "feat(#333): add the For You auto-generate dropdown and cron help note"
```

---

### Task 9: Documentation

**Files:**
- Create: `docs/for-you-scheduling.md`
- Modify: `docs/local-docker.md` (one cross-reference line)

**Interfaces:** none.

- [ ] **Step 1: Write the doc**

Create `docs/for-you-scheduling.md`:

```markdown
# Scheduling "For You" generation

Each account can have its "For You" recommendations generated on a schedule.
The cadence lives in **Settings → AI → Recommendations → Auto-generate**: *only
manually* (default), or every 1, 3, 6, 12, or 24 hours. A run is due when the
account's newest run is at least one interval old.

## With the background worker

On an install that runs the `worker` container, nothing else is needed. The
worker starts due runs every five minutes and advances them to completion.

## Without a worker (external cron)

An install without the worker exposes a token-guarded endpoint that does the
same work — start due runs, then advance each active run one step:

    POST /maintenance/recommendations/sweep
    Header: X-Maintenance-Token: <MAINTENANCE_TOKEN>

It reuses the same `MAINTENANCE_TOKEN` as the feed-refresh pinger. An empty
token keeps the endpoint closed. Each call advances one step per active run, so
call it on a schedule; a long-running provider call is flushed per step, so a
timed-out request still keeps its progress.

Example GitHub Actions schedule (store the token as the repository secret
`MAINTENANCE_TOKEN`):

    name: for-you-sweep
    on:
      schedule:
        - cron: '*/15 * * * *'
    jobs:
      sweep:
        runs-on: ubuntu-latest
        steps:
          - run: |
              curl -fsS -X POST "https://YOUR_HOST/maintenance/recommendations/sweep" \
                -H "X-Maintenance-Token: ${{ secrets.MAINTENANCE_TOKEN }}"

The response is JSON: `{ "startedRuns": n, "advancedRuns": m, "activeRuns": k }`.
```

- [ ] **Step 2: Cross-reference from the Docker doc**

Add one line to `docs/local-docker.md` near the worker description:

```markdown
See [for-you-scheduling.md](for-you-scheduling.md) for how the worker (or an external cron) auto-generates "For You".
```

- [ ] **Step 3: Commit**

```bash
git add docs/for-you-scheduling.md docs/local-docker.md
git commit -m "docs(#333): document For You scheduling and the cron endpoint"
```

---

## Final verification (after all tasks)

- [ ] **Backend full suite (SQLite):** `cd backend && php bin/phpunit` — green.
- [ ] **Backend gates:** `cd backend && composer check && composer md` — clean. Run PhpStorm inspections (`mcp__phpstorm__lint_files`) on every changed PHP file; block on ERROR + WARNING.
- [ ] **Migration on MySQL:** bring up the Docker stack and run `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` then `docker compose exec php bin/console doctrine:schema:validate` — in sync.
- [ ] **Backend suite (MySQL leg):** `docker compose exec php vendor/bin/phpunit` — green.
- [ ] **Frontend gate:** `cd frontend && npm run check` — green.
- [ ] **Mutation (changed files):** `cd backend && composer infection:diff` — at or above the `minMsi` gate.
- [ ] **Live check (not a gate — the deliverable):** set `autoGenerateIntervalHours` for an AI-ready account, run the sweep once (worker up, or `curl` the maintenance endpoint), and confirm a run starts and completes with zero transport failures. See the memory note "Verify recommendation work with a real run".
- [ ] **PR:** open against `develop` with `Closes #333` in the body. Do not merge unasked.

## Self-Review notes (author)

- **Spec coverage:** data model → Task 1; API surface (interval + `workerAlive`, validation) → Task 2; due-ness finder → Task 3; shared sweep → Task 4; worker trigger → Task 5; external-cron endpoint → Task 6; frontend dropdown always-visible + help-note-when-no-worker → Tasks 7–8; docs → Task 9; `#308` reversal comment → Task 5 Step 5.
- **Type consistency:** `autoGenerateIntervalHours` is `?int` end to end (value object, entity, effective, DTO, JSON) and `number | null` in TS; `workerAlive` is `bool`/`boolean` and appears only in the JSON + TS state, never persisted. `ForYouSweep::startDueRuns(): int` and `sweepOnce(): ForYouSweepReport` are used exactly as declared by Tasks 5 and 6.
- **Anchor:** `RecommendationRunRepository::findLatestForUser()` already exists; no new query for the anchor. Only `findWithAutoGenerateInterval()` is added (Task 3).
- **Final classes / test style:** every domain collaborator here is `final` (repositories, configurator, finder, sweep, starter, advancer), so PHPUnit cannot double them. Tasks 3–6 therefore test through real container services with `DbTestCase` + `RecommendationRunFixtures`, matching `AdvanceRecommendationRunsHandlerTest`. Because the SQLite database is shared across a process, those tests assert per-user side effects and `>=` bounds, never exact totals. The one pure value object (`ForYouSweepReport`) is unit-tested directly.
- **New value-object params are trailing and defaulted (`= null`):** `RecommendationSettingsValues` and `EffectiveRecommendationSettings` gain `autoGenerateIntervalHours` without breaking the three existing constructors (`RecommendationRunFixtures`, `RecommendationSettingsResolverTest`, `RecommendationRunAdvancerTest`). The wire DTO `SaveRecommendationSettingsRequest` keeps it required (no default), matching the full-replace PUT contract the frontend always satisfies.
```
