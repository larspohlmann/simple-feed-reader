# Realtime Recommendation Debug View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** While the recommendation debug switch is on, the user watches — live, ~2 s granularity — what each provider call sends and what streams back, in a collapsible panel inside the "For you" view; with debug off, the same checkpoint mechanism still feeds a bytes-received liveness signal to the normal progress bar.

**Architecture:** Pseudo-streaming, no SSE relay (issue #309, decided 2026-08-07). The tick that calls the provider already consumes the provider's SSE stream chunk-by-chunk (#312's `OpenAiCompatibleChatClient`); a new observer hook lets a recorder checkpoint the accumulated partial response every ~2 s into a `recommendation_run_log` row via direct DBAL updates — visible to the separate, cheap `GET` poll while the tick request is still blocked on the provider. Request bodies are persisted the moment they are sent; every retry attempt is its own row; logs live only until the next run starts. A debug-independent `streamed_chars` column on `recommendation_run` carries the liveness signal.

**Tech Stack:** Symfony 7.4 / Doctrine (MySQL LONGTEXT, SQLite for native tests), Angular 20 standalone + signals, Transloco, Jest.

**Issue:** #309 (decisions recorded there from the 2026-08-07 design interview). Branch: `feature/309-recommendation-debug-view` (exists).

## Global Constraints

- All backend commands run from `backend/`; all frontend commands from `frontend/`.
- Every PHP file: `declare(strict_types=1);`, PSR-12, `final readonly class` house style, PHPStan level max (warm the cache first: `bin/console cache:warmup`), every touched `src` file PHPMD-clean.
- Doctrine naming strategy is `underscore_number_aware`: property `streamedChars` ⇒ column `streamed_chars`.
- **Migrations need their own verification** — no test executes them. After writing one: migrate-from-empty + `doctrine:schema:validate` on both MySQL (Docker) and SQLite.
- Datetimes are stored as naive UTC; normalise before persisting.
- Frontend: standalone components and signals; component styles in a sibling `.scss` (never inline); no hex colours / raw `px` outside `theme/`; Prettier 100-col; translations in `public/i18n/en.json` **and** `de.json`.
- New endpoints must pass the native-iOS checklist (docs/architecture.md §6): bearer JWT, stateless, JSON in, `application/problem+json` out — nothing here is browser-coupled.
- The suite must be green at the end of every task (`php bin/phpunit` for backend tasks, `npm run check` for frontend tasks).
- Commit messages: `feat(#309): …`, `test(#309): …`, `refactor(#309): …`.

## Decisions this plan locks in (from the issue's decision list)

- **One log row per provider-call attempt** (a corrective retry is a new row whose request body naturally contains the corrective tail). Row: `phase` (`batch`|`dedup`), `batchNumber` (1-based, null for dedup), `attempt` (1-based), `requestBody` (LONGTEXT, written at send time), `responseText` (LONGTEXT, grows by checkpoint, ends as the decoded assistant text), `verdict` (`null` while streaming, then `usable`|`unusable`|`transport-failed`), `updatedAt`.
- **Checkpoints bypass the EntityManager**: mid-call writes go through `Doctrine\DBAL\Connection::update()` so a checkpoint never flushes unrelated dirty entity state and is committed (visible to the read poll) immediately.
- **Liveness with debug off**: `recommendation_run.streamed_chars` (raw accumulated SSE bytes) is updated on the same ~2 s cadence regardless of the debug switch, reset to 0 when the call ends. `RecommendationRunReport` exposes it; the "For you" bar renders it.
- **Wipe on next run start**: `RecommendationRunStarter` deletes the user's log rows when it creates a *new* run. Resuming a failed run keeps and appends — it is the same run.
- **Cheap list poll**: the list endpoint hydrates no LONGTEXT except the currently-streaming row's `responseText`; sizes come from SQL `LENGTH()`. Full bodies load one row at a time via the detail endpoint when the user expands an entry.
- **Recording only while the switch is on**, checked per call via `RecommendationSettingsResolver->forUser()->debugEnabled`.
- **Labels say "Batch 2", not the issue mock-up's "Batch 2/5"**: the run's `batchesTotal` counts the dedup call as one extra step, so a "/5" built from it would be off by one against the batch numbers; showing the plain number avoids baking that confusion into the panel.

## File structure

| File | Responsibility |
|---|---|
| `backend/src/Entity/RecommendationRunLog.php` (new) | One provider-call attempt's record |
| `backend/src/Repository/RecommendationRunLogRepository.php` (new) | List metadata cheaply, find owned row, wipe per user |
| `backend/migrations/Version20260808120000.php` (new) | `recommendation_run_log` table + `recommendation_run.streamed_chars` |
| `backend/src/Service/Recommendation/CompletionStreamObserver.php` (new) | The streaming hook interface |
| `backend/src/Service/Recommendation/NullCompletionStreamObserver.php` (new) | No-op observer |
| `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php` | Calls the observer as the body grows |
| `backend/src/Service/Recommendation/RecommendationCallRecorder.php` (new) | Begins a recorded call (debug check, request persist) |
| `backend/src/Service/Recommendation/RecordedCall.php` (new) | Per-call observer: throttled checkpoints, verdicts, liveness |
| `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` | Wires a recorded call around each provider call |
| `backend/src/Service/Recommendation/RecommendationRunStarter.php` | Wipes logs when a new run starts |
| `backend/src/Service/Recommendation/RecommendationRunReport.php` | Carries `streamedChars` |
| `backend/src/Controller/Api/RecommendationDebugLogController.php` (new) | Two read endpoints |
| `backend/src/Http/RecommendationDebugLogJson.php` (new) | Response mapping |
| `frontend/src/app/reader/models.ts`, `reader-api.ts` | Report field + two API reads |
| `frontend/src/app/reader/for-you-debug-panel.component.{ts,html,scss}` (new) | The collapsible panel |
| `frontend/src/app/reader/reader-shell.component.{ts,html}` | Panel mount + liveness in the for-you bar |
| `frontend/public/i18n/{en,de}.json` | Strings |

---

### Task 1: `RecommendationRunLog` entity, repository, and the migration

**Files:**
- Create: `backend/src/Entity/RecommendationRunLog.php`
- Create: `backend/src/Repository/RecommendationRunLogRepository.php`
- Modify: `backend/src/Entity/RecommendationRun.php` (add `streamedChars`)
- Create: `backend/migrations/Version20260808120000.php`
- Test: `backend/tests/Repository/RecommendationRunLogRepositoryTest.php`

**Interfaces:**
- Consumes: `RecommendationRun`, `User`.
- Produces: entity `RecommendationRunLog` with constructor `__construct(RecommendationRun $run, string $phase, ?int $batchNumber, int $attempt, string $requestBody, \DateTimeImmutable $updatedAt)`, constants `PHASE_BATCH = 'batch'`, `PHASE_DEDUP = 'dedup'`, `VERDICT_USABLE = 'usable'`, `VERDICT_UNUSABLE = 'unusable'`, `VERDICT_TRANSPORT_FAILED = 'transport-failed'`; getters `getId(): ?int`, `getRun(): RecommendationRun`, `getPhase(): string`, `getBatchNumber(): ?int`, `getAttempt(): int`, `getRequestBody(): string`, `getResponseText(): string`, `getVerdict(): ?string`; mutator `finish(string $responseText, string $verdict, \DateTimeImmutable $when): void`. Repository methods `listForUser(User $user): list<array{id: int, phase: string, batchNumber: ?int, attempt: int, verdict: ?string, requestBytes: int, responseBytes: int}>`, `streamingTextForUser(User $user): array<int, string>` (id ⇒ current `responseText` of verdict-null rows), `findOwned(int $id, User $user): ?RecommendationRunLog`, `deleteForUser(User $user): void`. `RecommendationRun::getStreamedChars(): int`.

- [ ] **Step 1: Write the failing repository test**

Create `backend/tests/Repository/RecommendationRunLogRepositoryTest.php`. Model the fixture bootstrapping on `RecommendationRunRepositoryTest` (same `DbTestCase` + `UserFactory` pattern):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\User;
use App\Repository\RecommendationRunLogRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RecommendationRunLogRepositoryTest extends DbTestCase
{
    private User $user;
    private User $otherUser;
    private RecommendationRunLogRepository $logs;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $this->user = $factory->create('log-owner@example.test');
        $this->otherUser = $factory->create('log-other@example.test');
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);
        $this->logs = $logs;
    }

    public function testListReturnsMetadataWithByteSizesButNoBodies(): void
    {
        $run = $this->run($this->user);
        $finished = $this->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'req-body-a');
        $finished->finish('decoded text', RecommendationRunLog::VERDICT_USABLE, new \DateTimeImmutable('2026-08-08T10:00:05Z'));
        $this->log($run, RecommendationRunLog::PHASE_DEDUP, null, 1, 'req-body-longer');
        $this->em->flush();

        $rows = $this->logs->listForUser($this->user);

        self::assertSame(
            [
                [
                    'id' => $finished->getId(),
                    'phase' => 'batch',
                    'batchNumber' => 1,
                    'attempt' => 1,
                    'verdict' => 'usable',
                    'requestBytes' => \strlen('req-body-a'),
                    'responseBytes' => \strlen('decoded text'),
                ],
                [
                    'id' => $rows[1]['id'],
                    'phase' => 'dedup',
                    'batchNumber' => null,
                    'attempt' => 1,
                    'verdict' => null,
                    'requestBytes' => \strlen('req-body-longer'),
                    'responseBytes' => 0,
                ],
            ],
            $rows,
        );
    }

    public function testStreamingTextReturnsOnlyVerdictlessRows(): void
    {
        $run = $this->run($this->user);
        $done = $this->log($run, RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $done->finish('finished text', RecommendationRunLog::VERDICT_UNUSABLE, new \DateTimeImmutable('2026-08-08T10:00:05Z'));
        $streaming = $this->log($run, RecommendationRunLog::PHASE_BATCH, 2, 1, 'r');
        $this->em->flush();

        $streamingId = $streaming->getId();
        self::assertNotNull($streamingId);
        self::assertSame([$streamingId => ''], $this->logs->streamingTextForUser($this->user));
    }

    public function testFindOwnedRefusesAnotherUsersRow(): void
    {
        $mine = $this->log($this->run($this->user), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $theirs = $this->log($this->run($this->otherUser), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $this->em->flush();
        $mineId = $mine->getId();
        $theirsId = $theirs->getId();
        self::assertNotNull($mineId);
        self::assertNotNull($theirsId);

        self::assertSame($mine, $this->logs->findOwned($mineId, $this->user));
        self::assertNull($this->logs->findOwned($theirsId, $this->user));
    }

    public function testDeleteForUserLeavesOtherUsersRows(): void
    {
        $this->log($this->run($this->user), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $kept = $this->log($this->run($this->otherUser), RecommendationRunLog::PHASE_BATCH, 1, 1, 'r');
        $this->em->flush();
        $keptId = $kept->getId();
        self::assertNotNull($keptId);

        $this->logs->deleteForUser($this->user);

        // Bulk DQL bypasses the identity map: clear before asserting survival,
        // or find() serves the stale in-memory row (see the #237 lesson).
        $this->em->clear();
        self::assertSame([], $this->logs->listForUser($this->user));
        self::assertNotNull($this->em->find(RecommendationRunLog::class, $keptId));
    }

    private function run(User $user): RecommendationRun
    {
        $run = new RecommendationRun($user, new \DateTimeImmutable('2026-08-08T10:00:00Z'));
        $this->em->persist($run);

        return $run;
    }

    private function log(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $phase,
            $batchNumber,
            $attempt,
            $requestBody,
            new \DateTimeImmutable('2026-08-08T10:00:01Z'),
        );
        $this->em->persist($log);

        return $log;
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit --filter RecommendationRunLogRepositoryTest`
Expected: ERROR — `RecommendationRunLog` not found.

- [ ] **Step 3: Create the entity**

`backend/src/Entity/RecommendationRunLog.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecommendationRunLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One provider-call attempt of a recommendation run, recorded for the debug
 * view (#309): the request body the moment it was sent, the response text as
 * it streams in (checkpointed every ~2 s by RecordedCall via direct DBAL
 * updates), and the parser's verdict once the call ended. Rows exist only
 * while the debug switch is on and only for the latest run — the next run
 * start wipes them.
 *
 * LONGTEXT length: a #308 batch request over a large context window is
 * hundreds of KB, past MySQL TEXT's 64 KB.
 */
#[ORM\Entity(repositoryClass: RecommendationRunLogRepository::class)]
#[ORM\Table(name: 'recommendation_run_log')]
#[ORM\Index(name: 'idx_recommendation_run_log_run', columns: ['run_id'])]
class RecommendationRunLog
{
    public const string PHASE_BATCH = 'batch';
    public const string PHASE_DEDUP = 'dedup';

    public const string VERDICT_USABLE = 'usable';
    public const string VERDICT_UNUSABLE = 'unusable';
    public const string VERDICT_TRANSPORT_FAILED = 'transport-failed';

    private const int LONGTEXT_LENGTH = 4_294_967_295;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RecommendationRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: false, onDelete: 'CASCADE')]
    private RecommendationRun $run;

    #[ORM\Column(length: 16)]
    private string $phase;

    #[ORM\Column(nullable: true)]
    private ?int $batchNumber;

    #[ORM\Column]
    private int $attempt;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $requestBody;

    #[ORM\Column(type: Types::TEXT, length: self::LONGTEXT_LENGTH)]
    private string $responseText = '';

    /** Null while the call is still streaming. */
    #[ORM\Column(length: 24, nullable: true)]
    private ?string $verdict = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        int $attempt,
        string $requestBody,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->run = $run;
        $this->phase = $phase;
        $this->batchNumber = $batchNumber;
        $this->attempt = $attempt;
        $this->requestBody = $requestBody;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): RecommendationRun
    {
        return $this->run;
    }

    public function getPhase(): string
    {
        return $this->phase;
    }

    public function getBatchNumber(): ?int
    {
        return $this->batchNumber;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getRequestBody(): string
    {
        return $this->requestBody;
    }

    public function getResponseText(): string
    {
        return $this->responseText;
    }

    public function getVerdict(): ?string
    {
        return $this->verdict;
    }

    /**
     * The call ended: the final decoded text replaces whatever partial state
     * the checkpoints wrote, and the verdict says how the reply was judged.
     */
    public function finish(string $responseText, string $verdict, \DateTimeImmutable $when): void
    {
        $this->responseText = $responseText;
        $this->verdict = $verdict;
        $this->updatedAt = $when;
    }
}
```

In `RecommendationRun.php`, add below the `$lastInvalidReply` property:

```php
    /**
     * Raw SSE bytes received so far by the provider call currently in
     * flight, checkpointed every ~2 s by RecordedCall via direct DBAL
     * updates and reset to 0 when the call ends. Deliberately written
     * outside the EntityManager — this entity only ever reads it — so the
     * value is visible to the cheap status poll while the tick request is
     * still blocked on the provider. Debug-independent: this is the
     * progress indicator's liveness signal (#309), not debug data.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $streamedChars = 0;
```

and next to `getLastInvalidReply()`:

```php
    public function getStreamedChars(): int
    {
        return $this->streamedChars;
    }
```

- [ ] **Step 4: Create the repository**

`backend/src/Repository/RecommendationRunLogRepository.php` (mirror the constructor style of the sibling repositories):

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecommendationRunLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads are shaped for the ~2 s debug poll: the list query hydrates no
 * LONGTEXT at all (sizes come from SQL LENGTH()), and only verdict-null
 * rows — the one call currently streaming — ship their partial text. Full
 * bodies load one row at a time via findOwned() when the user expands an
 * entry.
 *
 * @extends ServiceEntityRepository<RecommendationRunLog>
 */
class RecommendationRunLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecommendationRunLog::class);
    }

    /**
     * @return list<array{id: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int}>
     */
    public function listForUser(User $user): array
    {
        /** @var list<array{id: int, phase: string, batchNumber: ?int, attempt: int,
         *     verdict: ?string, requestBytes: int|string, responseBytes: int|string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select(
                'l.id AS id',
                'l.phase AS phase',
                'l.batchNumber AS batchNumber',
                'l.attempt AS attempt',
                'l.verdict AS verdict',
                'LENGTH(l.requestBody) AS requestBytes',
                'LENGTH(l.responseText) AS responseBytes',
            )
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        // LENGTH() comes back as a string on some drivers; the contract is int.
        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'phase' => $row['phase'],
                'batchNumber' => $row['batchNumber'],
                'attempt' => $row['attempt'],
                'verdict' => $row['verdict'],
                'requestBytes' => (int) $row['requestBytes'],
                'responseBytes' => (int) $row['responseBytes'],
            ],
            $rows,
        );
    }

    /**
     * The partial text of the call(s) still streaming — at most one row in
     * practice, since a run makes one provider call at a time.
     *
     * @return array<int, string> log id => response text so far
     */
    public function streamingTextForUser(User $user): array
    {
        /** @var list<array{id: int, responseText: string}> $rows */
        $rows = $this->createQueryBuilder('l')
            ->select('l.id AS id', 'l.responseText AS responseText')
            ->join('l.run', 'r')
            ->where('r.user = :user')
            ->andWhere('l.verdict IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $textById = [];
        foreach ($rows as $row) {
            $textById[$row['id']] = $row['responseText'];
        }

        return $textById;
    }

    public function findOwned(int $id, User $user): ?RecommendationRunLog
    {
        /** @var RecommendationRunLog|null $log */
        $log = $this->createQueryBuilder('l')
            ->join('l.run', 'r')
            ->where('l.id = :id')
            ->andWhere('r.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $log;
    }

    /**
     * Two-step (select ids, then delete) rather than a DELETE with a
     * subquery: portable across both suite dialects and trivially testable.
     */
    public function deleteForUser(User $user): void
    {
        /** @var list<int> $ids */
        $ids = array_column(
            $this->createQueryBuilder('l')
                ->select('l.id AS id')
                ->join('l.run', 'r')
                ->where('r.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->getArrayResult(),
            'id',
        );

        if ([] === $ids) {
            return;
        }

        $this->getEntityManager()->createQuery(
            'DELETE FROM App\Entity\RecommendationRunLog l WHERE l.id IN (:ids)',
        )->setParameter('ids', $ids)->execute();
    }
}
```

- [ ] **Step 5: Run the repository test**

Run: `php bin/phpunit --filter RecommendationRunLogRepositoryTest`
Expected: PASS (the test schema is built from ORM metadata, so no migration is needed for the suite).

- [ ] **Step 6: Write the migration**

`backend/migrations/Version20260808120000.php`, platform-aware like `Version20260807140000` (read that file first and copy its up/down structure):

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recommendation debug view (#309): the per-call run log and the
 * debug-independent liveness counter on the run row.
 *
 * PLATFORM-AWARE DDL: tests build their schema from ORM metadata and never
 * execute a migration; CI's migrate-from-empty leg is the only runtime check.
 */
final class Version20260808120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recommendation debug view (#309): recommendation_run_log table and recommendation_run.streamed_chars.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('recommendation_run_log'), 'recommendation_run_log already exists; nothing to do.');

        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE recommendation_run_log (
                    id INT AUTO_INCREMENT NOT NULL,
                    run_id INT NOT NULL,
                    phase VARCHAR(16) NOT NULL,
                    batch_number INT DEFAULT NULL,
                    attempt INT NOT NULL,
                    request_body LONGTEXT NOT NULL,
                    response_text LONGTEXT NOT NULL,
                    verdict VARCHAR(24) DEFAULT NULL,
                    updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_recommendation_run_log_run (run_id),
                    PRIMARY KEY (id),
                    CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id)
                        REFERENCES recommendation_run (id) ON DELETE CASCADE
                ) DEFAULT CHARACTER SET utf8mb4
                SQL);
            $this->addSql('ALTER TABLE recommendation_run ADD streamed_chars INT DEFAULT 0 NOT NULL');

            return;
        }

        if ($platform instanceof SQLitePlatform) {
            $this->addSql(<<<'SQL'
                CREATE TABLE recommendation_run_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                    run_id INTEGER NOT NULL,
                    phase VARCHAR(16) NOT NULL,
                    batch_number INTEGER DEFAULT NULL,
                    attempt INTEGER NOT NULL,
                    request_body CLOB NOT NULL,
                    response_text CLOB NOT NULL,
                    verdict VARCHAR(24) DEFAULT NULL,
                    updated_at DATETIME NOT NULL,
                    CONSTRAINT FK_recommendation_run_log_run FOREIGN KEY (run_id)
                        REFERENCES recommendation_run (id) ON DELETE CASCADE
                )
                SQL);
            $this->addSql('CREATE INDEX idx_recommendation_run_log_run ON recommendation_run_log (run_id)');
            $this->addSql('ALTER TABLE recommendation_run ADD COLUMN streamed_chars INTEGER DEFAULT 0 NOT NULL');

            return;
        }

        $this->abortIf(true, sprintf('Unsupported platform %s.', $platform::class));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recommendation_run_log');
        $this->addSql('ALTER TABLE recommendation_run DROP COLUMN streamed_chars');
    }
}
```

Before finishing, compare the exact column DDL against what the ORM expects: run the verification below and reconcile any mismatch **in the migration**, not with an ignore.

- [ ] **Step 7: Verify the migration on both dialects**

From the repo root:

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: migration runs, schema validates ("The database schema is in sync"). If `schema:validate` reports a diff, fix the migration DDL to match the metadata.

- [ ] **Step 8: Full native suite, then commit**

Run: `php bin/phpunit`
Expected: PASS.

```bash
git add src/Entity/RecommendationRunLog.php src/Entity/RecommendationRun.php src/Repository/RecommendationRunLogRepository.php migrations/Version20260808120000.php tests/Repository/RecommendationRunLogRepositoryTest.php
git commit -m "feat(#309): run-log table and liveness counter for the debug view"
```

---

### Task 2: The stream observer hook in the chat client

**Files:**
- Create: `backend/src/Service/Recommendation/CompletionStreamObserver.php`
- Create: `backend/src/Service/Recommendation/NullCompletionStreamObserver.php`
- Modify: `backend/src/Service/Recommendation/ChatCompletionClient.php`
- Modify: `backend/src/Service/Recommendation/OpenAiCompatibleChatClient.php`
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php` (pass the null observer for now)
- Modify: `backend/tests/Support/StubChatClient.php`
- Test: `backend/tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php`

**Interfaces:**
- Produces:

```php
interface CompletionStreamObserver
{
    /** Called after every received chunk with the whole body accumulated so far. */
    public function bodyGrew(string $accumulatedBody): void;
}
```

`ChatCompletionClient::complete(ProviderCredentials $credentials, string $model, array $messages, CompletionStreamObserver $observer): string` — the observer is a required fourth parameter (no nullable default: a caller that does not care says so explicitly with `NullCompletionStreamObserver`). `StubChatClient` gains `public function observerBodies(): list<string>`… no — the stub does not stream; it simply accepts and ignores the observer. Task 4 drives real observer behavior through `RecordedCall` directly.

- [ ] **Step 1: Write the failing client test**

In `OpenAiCompatibleChatClientTest`, find how the existing tests build a `MockHttpClient` with body chunks (the #312 streaming tests already do), then add:

```php
    public function testObserverSeesTheAccumulatingBodyChunkByChunk(): void
    {
        $client = $this->clientAnswering(chunks: ["data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n\n", "data: [DONE]\n\n"]);
        $seen = new class implements CompletionStreamObserver {
            /** @var list<string> */
            public array $bodies = [];

            public function bodyGrew(string $accumulatedBody): void
            {
                $this->bodies[] = $accumulatedBody;
            }
        };

        $client->complete($this->credentials(), 'm', [['role' => 'user', 'content' => 'x']], $seen);

        self::assertCount(2, $seen->bodies);
        self::assertStringContainsString('"He"', $seen->bodies[0]);
        // Each call carries everything so far, not just the newest chunk.
        self::assertStringStartsWith($seen->bodies[0], $seen->bodies[1]);
    }
```

Adapt `clientAnswering(...)`/`credentials()` to whatever the file's existing helper methods are actually named — read the file first and reuse its helpers instead of inventing parallel ones. Update every existing `->complete(` call in this file to pass `new NullCompletionStreamObserver()` as the fourth argument.

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit --filter OpenAiCompatibleChatClientTest`
Expected: ERROR — `CompletionStreamObserver` not found.

- [ ] **Step 3: Implement**

`CompletionStreamObserver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * Streaming hook for one /chat/completions call (#309): the client reports
 * the growing SSE body after every chunk, and the observer decides what any
 * of it means — throttling, decoding and persistence are its business, so
 * the transport stays dumb.
 */
interface CompletionStreamObserver
{
    /** Called after every received chunk with the whole body accumulated so far. */
    public function bodyGrew(string $accumulatedBody): void;
}
```

`NullCompletionStreamObserver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

/**
 * The observer for callers with nothing to observe — an explicit argument
 * instead of a nullable parameter, so every call site states its intent.
 */
final readonly class NullCompletionStreamObserver implements CompletionStreamObserver
{
    public function bodyGrew(string $accumulatedBody): void
    {
    }
}
```

In `ChatCompletionClient::complete()`, add the fourth parameter `CompletionStreamObserver $observer` (update the docblock: "reports the accumulating streamed body to $observer chunk by chunk").

In `OpenAiCompatibleChatClient`: thread the observer from `complete()` through `readBody()` into `streamedBody()`, and inside the chunk loop, after `$body .= $chunk->getContent();`, add:

```php
            $observer->bodyGrew($body);
```

In `StubChatClient::complete()`, add the parameter and ignore it (the stub answers whole replies; streaming behavior is `RecordedCall`'s to test).

In `RecommendationRunAdvancer::callProvider()`, pass `new NullCompletionStreamObserver()` as the fourth argument for now (Task 4 replaces it). House style forbids `new` on a *collaborator* inside a method — this is a stateless null object standing in for "no observer", the same litmus as `new \DateTimeImmutable`; it moves into the recorder in Task 4 anyway.

- [ ] **Step 4: Run the client tests, then the full suite**

Run: `php bin/phpunit --filter OpenAiCompatibleChatClientTest` then `php bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/ tests/Support/StubChatClient.php tests/Service/Recommendation/OpenAiCompatibleChatClientTest.php
git commit -m "feat(#309): chat client reports the growing SSE body to an observer"
```

---

### Task 3: `RecommendationCallRecorder` and `RecordedCall`

The recorder decides at call start whether debug is on and persists the request body; the returned `RecordedCall` is the per-call observer that throttles checkpoints (~2 s), decodes the partial SSE transcript, writes the log row **and** the debug-independent liveness counter via DBAL, and records the verdict at the end.

**Files:**
- Create: `backend/src/Service/Recommendation/RecommendationCallRecorder.php`
- Create: `backend/src/Service/Recommendation/RecordedCall.php`
- Test: `backend/tests/Service/Recommendation/RecommendationCallRecorderTest.php`

**Interfaces:**
- Consumes: `RecommendationRunLog` + repository (Task 1), `CompletionStreamObserver` (Task 2), `CompletionBodyDecoder` (existing), `RecommendationSettingsResolver` (existing, `->forUser($user)->debugEnabled`), `Doctrine\DBAL\Connection`, `EntityManagerInterface`, `ClockInterface`.
- Produces:

```php
RecommendationCallRecorder::begin(RecommendationRun $run, string $phase, ?int $batchNumber, array $messages, string $model): RecordedCall
```

`RecordedCall implements CompletionStreamObserver` with `bodyGrew(string $accumulatedBody): void`, `finishUsable(string $content): void`, `finishUnusable(string $content): void`, `abortAfterTransportFailure(): void`. Task 4 wires these into the advancer.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Recommendation/RecommendationCallRecorderTest.php` — a `DbTestCase` (checkpoints must be provable through a *fresh* read, since their whole point is out-of-EM visibility). Use `Symfony\Component\Clock\MockClock` for the throttle. Seed a user + run like `RecommendationRunLogRepositoryTest` does; enable debug by persisting a `RecommendationSettings` row (copy the `RecommendationSettings`/`RecommendationSettingsValues` construction from `RecommendationRunAdvancerTest::seedMultiBatchFixture`, with `debugEnabled: true`).

```php
final class RecommendationCallRecorderTest extends DbTestCase
{
    // setUp: $this->user, $this->run (persisted+flushed), $this->clock = new MockClock('2026-08-08T10:00:00Z'),
    // $this->recorder built from the container's services but with the MockClock:
    //   new RecommendationCallRecorder(
    //       $this->em,
    //       self::getContainer()->get(RecommendationRunLogRepository::class),
    //       $this->em->getConnection(),
    //       self::getContainer()->get(RecommendationSettingsResolver::class),
    //       self::getContainer()->get(CompletionBodyDecoder::class),
    //       $this->clock,
    //   )
    // plus helpers seedDebugSettings(bool $enabled) and freshLog(int $id): RecommendationRunLog
    // (freshLog = $this->em->clear() then find()).

    public function testBeginWithDebugOnPersistsTheRequestBodyImmediately(): void
    {
        $this->seedDebugSettings(true);

        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 2, [['role' => 'user', 'content' => 'hi']], 'm');

        $rows = $this->logs()->listForUser($this->user);
        self::assertCount(1, $rows);
        self::assertSame('batch', $rows[0]['phase']);
        self::assertSame(2, $rows[0]['batchNumber']);
        self::assertSame(1, $rows[0]['attempt']);
        self::assertNull($rows[0]['verdict']);
        $log = $this->freshLog($rows[0]['id']);
        self::assertStringContainsString('"model": "m"', $log->getRequestBody());
        self::assertStringContainsString('"content": "hi"', $log->getRequestBody());
    }

    public function testBeginWithDebugOffWritesNoRow(): void
    {
        $this->seedDebugSettings(false);

        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        self::assertSame([], $this->logs()->listForUser($this->user));
    }

    public function testCheckpointsAreThrottledToTheInterval(): void
    {
        $this->seedDebugSettings(true);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];

        $call->bodyGrew("data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n");
        self::assertSame('', $this->freshLog($logId)->getResponseText(), 'first growth inside the interval is not written');

        $this->clock->modify('+3 seconds');
        $call->bodyGrew("data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\ndata: {\"choices\":[{\"delta\":{\"content\":\"llo\"}}]}\n");

        self::assertSame('Hello', $this->freshLog($logId)->getResponseText());
    }

    public function testCheckpointUpdatesTheLivenessCounterEvenWithDebugOff(): void
    {
        $this->seedDebugSettings(false);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        $this->clock->modify('+3 seconds');
        $body = "data: {\"choices\":[{\"delta\":{\"content\":\"He\"}}]}\n";
        $call->bodyGrew($body);

        $runId = $this->run->getId();
        self::assertNotNull($runId);
        $this->em->clear();
        $freshRun = $this->em->find(RecommendationRun::class, $runId);
        self::assertSame(\strlen($body), $freshRun?->getStreamedChars());
    }

    public function testFinishUsableStoresTextVerdictAndResetsLiveness(): void
    {
        $this->seedDebugSettings(true);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->bodyGrew('data: partial');

        $call->finishUsable('{"recommendations": []}');

        $log = $this->freshLog($logId);
        self::assertSame('{"recommendations": []}', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_USABLE, $log->getVerdict());
        $freshRun = $this->em->find(RecommendationRun::class, $this->run->getId());
        self::assertSame(0, $freshRun?->getStreamedChars());
    }

    public function testAbortKeepsThePartialTextWithTransportVerdict(): void
    {
        $this->seedDebugSettings(true);
        $call = $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');
        $logId = $this->logs()->listForUser($this->user)[0]['id'];
        $this->clock->modify('+3 seconds');
        $call->bodyGrew("data: {\"choices\":[{\"delta\":{\"content\":\"cut off\"}}]}\n");

        $call->abortAfterTransportFailure();

        $log = $this->freshLog($logId);
        self::assertSame('cut off', $log->getResponseText());
        self::assertSame(RecommendationRunLog::VERDICT_TRANSPORT_FAILED, $log->getVerdict());
    }

    public function testASecondBeginForTheSamePhaseCountsTheAttempt(): void
    {
        $this->seedDebugSettings(true);
        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm')->finishUnusable('bad');
        $this->recorder->begin($this->run, RecommendationRunLog::PHASE_BATCH, 1, [], 'm');

        $rows = $this->logs()->listForUser($this->user);
        self::assertSame([1, 2], array_column($rows, 'attempt'));
    }
}
```

Write the helpers concretely (they are part of this step, not left to taste): `logs()` returns the container repository; `seedDebugSettings` persists + flushes a `RecommendationSettings` for `$this->user`; `freshLog` clears the EM and `find()`s — clearing matters because checkpoints bypass the EM by design.

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit --filter RecommendationCallRecorderTest`
Expected: ERROR — `RecommendationCallRecorder` not found.

- [ ] **Step 3: Implement**

`RecommendationCallRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Repository\RecommendationRunLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Opens the debug record for one provider call (#309): decides — per call,
 * so a mid-run settings flip takes effect on the next call — whether the
 * debug switch is on, persists the request body the moment it is sent, and
 * hands back the RecordedCall the advancer threads through the chat client
 * as its stream observer. With debug off the RecordedCall still exists,
 * because the liveness counter it maintains is not debug data.
 */
final readonly class RecommendationCallRecorder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RecommendationRunLogRepository $logs,
        private Connection $connection,
        private RecommendationSettingsResolver $settingsResolver,
        private CompletionBodyDecoder $decoder,
        private ClockInterface $clock,
    ) {
    }

    /** @param list<array{role: string, content: string}> $messages */
    public function begin(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        array $messages,
        string $model,
    ): RecordedCall {
        $log = $this->settingsResolver->forUser($run->getUser())->debugEnabled
            ? $this->persistedLog($run, $phase, $batchNumber, $messages, $model)
            : null;

        return new RecordedCall(
            $this->connection,
            $this->decoder,
            $this->clock,
            $run->getId() ?? throw new \LogicException('Cannot record a call for an unsaved run.'),
            $log?->getId(),
        );
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function persistedLog(
        RecommendationRun $run,
        string $phase,
        ?int $batchNumber,
        array $messages,
        string $model,
    ): RecommendationRunLog {
        $log = new RecommendationRunLog(
            $run,
            $phase,
            $batchNumber,
            $this->nextAttempt($run, $phase, $batchNumber),
            $this->renderedRequest($messages, $model),
            $this->clock->now(),
        );
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        return $log;
    }

    /**
     * Attempts are derived from what is already recorded rather than passed
     * in, so the recorder cannot disagree with its own rows.
     */
    private function nextAttempt(RecommendationRun $run, string $phase, ?int $batchNumber): int
    {
        $sameCall = array_filter(
            $this->logs->listForUser($run->getUser()),
            static fn (array $row): bool => $row['phase'] === $phase && $row['batchNumber'] === $batchNumber,
        );

        return \count($sameCall) + 1;
    }

    /**
     * Pretty-printed for the human the debug view exists for; this is the
     * payload as sent, minus transport framing.
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private function renderedRequest(array $messages, string $model): string
    {
        return json_encode(
            ['model' => $model, 'messages' => $messages],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }
}
```

`RecordedCall.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Recommendation;

use App\Entity\RecommendationRunLog;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * The stream observer for one recorded provider call (#309). Checkpoints go
 * through the DBAL connection, not the EntityManager, on purpose: they must
 * commit immediately (the cheap status poll reads them while the tick
 * request is still blocked on the provider), and they must not flush
 * whatever else the advancer's EntityManager holds dirty mid-tick.
 *
 * Deliberately not readonly: a call is a short-lived session whose one piece
 * of state is when it last checkpointed. `$logId` null means debug is off —
 * the liveness counter is still maintained, the transcript is not.
 */
final class RecordedCall implements CompletionStreamObserver
{
    /** The issue's ~2 s pseudo-streaming cadence. */
    private const int CHECKPOINT_SECONDS = 2;

    private \DateTimeImmutable $lastCheckpointAt;

    public function __construct(
        private readonly Connection $connection,
        private readonly CompletionBodyDecoder $decoder,
        private readonly ClockInterface $clock,
        private readonly int $runId,
        private readonly ?int $logId,
    ) {
        // The interval is armed at begin() time: begin() already persisted
        // everything worth persisting at time zero, so the first checkpoint
        // is due CHECKPOINT_SECONDS after the call went out.
        $this->lastCheckpointAt = $clock->now();
    }

    public function bodyGrew(string $accumulatedBody): void
    {
        $now = $this->clock->now();
        if ($now->getTimestamp() - $this->lastCheckpointAt->getTimestamp() < self::CHECKPOINT_SECONDS) {
            return;
        }
        $this->lastCheckpointAt = $now;

        $this->connection->update(
            'recommendation_run',
            ['streamed_chars' => \strlen($accumulatedBody)],
            ['id' => $this->runId],
        );

        if (null === $this->logId) {
            return;
        }

        $this->connection->update(
            'recommendation_run_log',
            [
                'response_text' => $this->decoder->assistantContent($accumulatedBody) ?? '',
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ],
            ['id' => $this->logId],
        );
    }

    public function finishUsable(string $content): void
    {
        $this->finish($content, RecommendationRunLog::VERDICT_USABLE);
    }

    public function finishUnusable(string $content): void
    {
        $this->finish($content, RecommendationRunLog::VERDICT_UNUSABLE);
    }

    /**
     * The stream died mid-answer: whatever the checkpoints salvaged stays,
     * stamped with the transport verdict so the panel can say so.
     */
    public function abortAfterTransportFailure(): void
    {
        $this->resetLiveness();

        if (null === $this->logId) {
            return;
        }

        $this->connection->update(
            'recommendation_run_log',
            ['verdict' => RecommendationRunLog::VERDICT_TRANSPORT_FAILED],
            ['id' => $this->logId],
        );
    }

    private function finish(string $content, string $verdict): void
    {
        $this->resetLiveness();

        if (null === $this->logId) {
            return;
        }

        $this->connection->update(
            'recommendation_run_log',
            [
                'response_text' => $content,
                'verdict' => $verdict,
                'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
            ['id' => $this->logId],
        );
    }

    private function resetLiveness(): void
    {
        $this->connection->update('recommendation_run', ['streamed_chars' => 0], ['id' => $this->runId]);
    }
}
```


- [ ] **Step 4: Run the recorder tests, then the full suite**

Run: `php bin/phpunit --filter RecommendationCallRecorderTest` then `php bin/phpunit`
Expected: PASS. If the SQLite leg balks at the `updated_at` string format, it is stored naive UTC by design — `MockClock('…Z')` produces UTC; keep the `format('Y-m-d H:i:s')`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationCallRecorder.php src/Service/Recommendation/RecordedCall.php tests/Service/Recommendation/RecommendationCallRecorderTest.php
git commit -m "feat(#309): record a provider call with throttled streaming checkpoints"
```

---

### Task 4: Wire recording into the advancer and the wipe into the starter

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunAdvancer.php`
- Modify: `backend/src/Service/Recommendation/RecommendationRunStarter.php`
- Test: `backend/tests/Service/Recommendation/RecommendationRunAdvancerTest.php`, `backend/tests/Service/Recommendation/RecommendationRunStarterTest.php`

**Interfaces:**
- Consumes: `RecommendationCallRecorder::begin(...)`, `RecordedCall` verdict methods (Task 3), `RecommendationRunLogRepository::deleteForUser()` (Task 1).
- Produces: every provider call in both phases is recorded; `RecommendationRunStarter` wipes logs when it creates a **new** run (not on resume).

- [ ] **Step 1: Write the failing advancer tests**

Add to `RecommendationRunAdvancerTest` (reuse its existing fixtures; enable debug by extending `seedMultiBatchFixture` — add a `bool $debugEnabled = false` default…no: **no boolean flag parameters**. Add a small private helper `enableDebug(): void` that loads the user's `RecommendationSettings` row created by `seedMultiBatchFixture` and re-`update()`s it with `debugEnabled: true`, flushing after):

```php
    public function testBatchAndDedupCallsAreLoggedWithVerdictsWhenDebugIsOn(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
        $run = $this->startAndSnapshot();
        $firstBatch = $run->getCandidateBatches()[0];
        $secondBatch = $run->getCandidateBatches()[1];

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $firstBatch[0], 'score' => 70, 'reason' => 'r1']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $secondBatch[0], 'score' => 90, 'reason' => 'r2']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode(['duplicates' => []], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame(
            [['batch', 1, 'usable'], ['batch', 2, 'usable'], ['dedup', null, 'usable']],
            array_map(
                static fn (array $row): array => [$row['phase'], $row['batchNumber'], $row['verdict']],
                $rows,
            ),
        );
        $firstLog = $this->freshRunLog($rows[0]['id']);
        self::assertStringContainsString('You score candidate posts', $firstLog->getRequestBody());
        self::assertStringContainsString('"score": 70', $firstLog->getResponseText());
    }

    public function testACorrectiveRetryGetsItsOwnLogRowWithTheUnusableVerdict(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueContent('not json');
        $this->advancer()->advance($this->user);
        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $this->activeRun()->getCandidateBatches()[0][0], 'score' => 50, 'reason' => 'r']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame([1, 2], array_column($rows, 'attempt'));
        self::assertSame(['unusable', 'usable'], array_column($rows, 'verdict'));
        self::assertSame('not json', $this->freshRunLog($rows[0]['id'])->getResponseText());
        self::assertStringContainsString(
            'Your previous reply was not usable.',
            $this->freshRunLog($rows[1]['id'])->getRequestBody(),
        );
    }

    public function testATransportFailureStampsItsLogRow(): void
    {
        $this->seedMultiBatchFixture();
        $this->enableDebug();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueFailure(new ProviderUnreachableException('gone'));
        try {
            $this->advancer()->advance($this->user);
            self::fail('The transport failure must propagate.');
        } catch (ProviderUnreachableException) {
        }

        $rows = $this->runLogs()->listForUser($this->user);
        self::assertSame(['transport-failed'], array_column($rows, 'verdict'));
    }

    public function testNoLogRowsAreWrittenWithDebugOff(): void
    {
        $this->seedMultiBatchFixture();
        $this->startAndSnapshot();

        $this->stubChatClient()->queueContent(json_encode([
            'recommendations' => [['id' => $this->activeRun()->getCandidateBatches()[0][0], 'score' => 50, 'reason' => 'r']],
        ], \JSON_THROW_ON_ERROR));
        $this->advancer()->advance($this->user);

        self::assertSame([], $this->runLogs()->listForUser($this->user));
    }
```

Add the two tiny helpers at the bottom of the class: `runLogs()` (container's `RecommendationRunLogRepository`) and `freshRunLog(int $id)` (`$this->em->clear()` then `find()`). Note the stub client never streams, so these tests exercise begin/finish, not checkpoints — Task 3 already proved those.

And in `RecommendationRunStarterTest` (read the file first and reuse its existing helpers for a ready AI account and for creating runs in a given status — it already exercises the resume path; the snippets below name the fixtures generically where that file already has an equivalent):

```php
    public function testANewRunWipesTheDebugLogOfThePreviousRun(): void
    {
        $this->seedReadyAiSettings($this->user);
        $previous = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $previous->snapshot([]);
        $previous->complete(new \DateTimeImmutable('2026-08-08T09:01:00Z'));
        $this->em->persist($previous);
        $this->em->persist(new RecommendationRunLog(
            $previous,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'old request',
            new \DateTimeImmutable('2026-08-08T09:00:30Z'),
        ));
        $this->em->flush();

        $this->starter()->start($this->user);

        self::assertSame([], $this->runLogs()->listForUser($this->user));
    }

    public function testResumingAFailedRunKeepsItsDebugLog(): void
    {
        $this->seedReadyAiSettings($this->user);
        $failed = new RecommendationRun($this->user, new \DateTimeImmutable('2026-08-08T09:00:00Z'));
        $failed->snapshot([[1], [2]]);
        $failed->fail('provider gone', new \DateTimeImmutable('2026-08-08T09:01:00Z'));
        $this->em->persist($failed);
        $this->em->persist(new RecommendationRunLog(
            $failed,
            RecommendationRunLog::PHASE_BATCH,
            1,
            1,
            'kept request',
            new \DateTimeImmutable('2026-08-08T09:00:30Z'),
        ));
        $this->em->flush();

        $report = $this->starter()->start($this->user);

        self::assertSame(RecommendationRun::STATUS_RUNNING, $report->status);
        // The wipe is bulk DQL when it runs, so clear before asserting survival.
        $this->em->clear();
        self::assertCount(1, $this->runLogs()->listForUser($this->user));
    }
```

Add a `runLogs()` helper (container's `RecommendationRunLogRepository`) here too, and swap `seedReadyAiSettings`/`starter()` for that file's actual helper names when they differ.

- [ ] **Step 2: Run to verify the new tests fail**

Run: `php bin/phpunit --filter 'RecommendationRunAdvancerTest|RecommendationRunStarterTest'`
Expected: the new tests FAIL (no rows are written / rows survive the new-run start); everything else passes.

- [ ] **Step 3: Wire the advancer**

In `RecommendationRunAdvancer`:

1. Constructor: append `private readonly RecommendationCallRecorder $callRecorder,` (fifteenth collaborator; the `ExcessiveParameterList` suppression stands, update the docblock's "fourteen" to "fifteen").

2. `callProvider()` gains the recorded call and settles the transport verdict; replace the method with:

```php
    /**
     * The one provider call a tick makes, recorded for the debug view from
     * the moment the request goes out (#309). A transport failure … [keep the
     * existing paragraph about ceilings verbatim] …
     *
     * @param list<array{role: string, content: string}> $messages
     */
    private function callProvider(
        RecommendationRun $run,
        AiProviderSettings $settings,
        array $messages,
        RecordedCall $recordedCall,
    ): string {
        try {
            return $this->chat->complete(
                $this->configurator->credentials($settings),
                $settings->getModel() ?? '',
                $messages,
                $recordedCall,
            );
        } catch (ProviderUnreachableException | CredentialsRejectedException $e) {
            $recordedCall->abortAfterTransportFailure();
            $this->recordTransportFailure($run, $settings);

            throw $e;
        }
    }
```

3. In `providerTick()`, replace the call/parse/record block with:

```php
        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_BATCH,
            $run->progress()->nextBatchIndex + 1,
            $messages,
            $settings->getModel() ?? '',
        );

        $content = $this->callProvider($run, $settings, $messages, $recordedCall);

        $result = $this->parser->parse($content, $validIds);
        $this->settleVerdict($recordedCall, $content, $result->usable);

        return $this->recordReply($run, $content, $result);
```

4. In `dedupTick()`, likewise:

```php
        $recordedCall = $this->callRecorder->begin(
            $run,
            RecommendationRunLog::PHASE_DEDUP,
            null,
            $messages,
            $settings->getModel() ?? '',
        );

        $content = $this->callProvider($run, $settings, $messages, $recordedCall);

        $result = $this->duplicateParser->parse($content, array_column($pool, 'id'));
        $this->settleVerdict($recordedCall, $content, $result->usable);
```

5. Add the shared verdict helper (both parsers expose `->usable`, so one helper serves both phases):

```php
    private function settleVerdict(RecordedCall $recordedCall, string $content, bool $usable): void
    {
        if ($usable) {
            $recordedCall->finishUsable($content);

            return;
        }

        $recordedCall->finishUnusable($content);
    }
```

6. Remove the Task 2 `NullCompletionStreamObserver` import/usage from this class; add the `RecommendationRunLog` import.

In `RecommendationRunStarter`: add constructor parameter `private RecommendationRunLogRepository $logs,` and, in `start()`, immediately before `$run = new RecommendationRun(...)`:

```php
        // The debug log lives only for the latest run (#309): a resumed run
        // above keeps appending to its own log, a genuinely new run starts
        // its record clean.
        $this->logs->deleteForUser($user);
```

- [ ] **Step 4: Run the affected suites, then the full suite**

Run: `php bin/phpunit --filter 'RecommendationRunAdvancerTest|RecommendationRunStarterTest'` then `php bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationRunAdvancer.php src/Service/Recommendation/RecommendationRunStarter.php tests/Service/Recommendation/RecommendationRunAdvancerTest.php tests/Service/Recommendation/RecommendationRunStarterTest.php
git commit -m "feat(#309): record every provider call and wipe the log on a new run"
```

---

### Task 5: `streamedChars` in the report, and the two read endpoints

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationRunReport.php`
- Create: `backend/src/Controller/Api/RecommendationDebugLogController.php`
- Create: `backend/src/Http/RecommendationDebugLogJson.php`
- Test: `backend/tests/Controller/Api/RecommendationDebugLogControllerTest.php`, plus one assertion touch-up in `backend/tests/Controller/Api/RecommendationRunControllerTest.php`

**Interfaces:**
- Consumes: `RecommendationRunLogRepository` reads (Task 1), `RecommendationRun::getStreamedChars()` (Task 1).
- Produces:
  - `RecommendationRunReport` gains `public int $streamedChars` (constructor-last with default `0`; `fromRun()` passes `$run->getStreamedChars()`; `toArray()` gains the key — update its `@return` shape).
  - `GET /api/recommendations/runs/debug-log` → `{"entries": [{"id", "phase", "batchNumber", "attempt", "verdict", "requestBytes", "responseBytes", "streamingText"}]}` where `streamingText` is the partial text for verdict-null rows and `null` otherwise.
  - `GET /api/recommendations/runs/debug-log/{id}` → `{"id", "phase", "batchNumber", "attempt", "verdict", "requestBody", "responseText"}`; unknown or foreign id → 404 `application/problem+json` (throw `Symfony\Component\HttpKernel\Exception\NotFoundHttpException`, which the existing problem-JSON error layer maps).
  - No rate limiter on either route — plain reads, same stance as `/current`.

- [ ] **Step 1: Write the failing controller test**

Model `RecommendationDebugLogControllerTest` on the authentication/JSON patterns in `RecommendationRunControllerTest` (read it first; reuse its login helper and fixture style). Cases:

```php
    public function testListReturnsEntriesWithStreamingTextOnlyForTheOpenCall(): void
    // seed: run + one finished row (finish('done text', VERDICT_USABLE, …)) + one open row;
    // GET /api/recommendations/runs/debug-log
    // assert: 200; entries[0] verdict 'usable', streamingText null, responseBytes strlen('done text');
    //         entries[1] verdict null, streamingText '' (the open row's current text).

    public function testListIsEmptyForAUserWithoutLogs(): void
    // assert: 200 with {"entries": []}.

    public function testDetailReturnsFullBodies(): void
    // seed one finished row with requestBody 'req' / responseText 'res';
    // GET /api/recommendations/runs/debug-log/{id} → 200, requestBody 'req', responseText 'res'.

    public function testDetailOfAnotherUsersRowIs404ProblemJson(): void
    // seed a row for a second user; GET its id as the first user
    // assert: 404 and content-type application/problem+json.

    public function testTickReportCarriesStreamedChars(): void
    // in RecommendationRunControllerTest: extend the existing current-endpoint test's
    // expected JSON with "streamedChars": 0.
```

Write all five in full, following the host file's conventions.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter 'RecommendationDebugLogControllerTest|RecommendationRunControllerTest'`
Expected: new tests FAIL (404 route / missing key).

- [ ] **Step 3: Implement**

`RecommendationRunReport`: add `public int $streamedChars = 0` as the last constructor promotion; `fromRun()` passes `$run->getStreamedChars()`; `toArray()` adds `'streamedChars' => $this->streamedChars` (and its `@return` shape gains `streamedChars: int`). `none()`/`busy()`/`inBackground()` compile unchanged apart from `inBackground()` forwarding the value: `new self($this->status, $this->batchesTotal, $this->batchesDone, $this->error, true, $this->streamedChars)` — check the real constructor order when editing.

`RecommendationDebugLogJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\RecommendationRunLog;

/**
 * Response shapes for the recommendation debug log (#309). The list shape is
 * poll-cheap by construction: bodies never ride along, only sizes — except
 * the one call still streaming, whose growing text IS the live view.
 */
final class RecommendationDebugLogJson
{
    /**
     * @param list<array{id: int, phase: string, batchNumber: ?int, attempt: int,
     *     verdict: ?string, requestBytes: int, responseBytes: int}> $rows
     * @param array<int, string> $streamingTextById
     *
     * @return array{entries: list<array<string, mixed>>}
     */
    public static function list(array $rows, array $streamingTextById): array
    {
        return [
            'entries' => array_map(
                static fn (array $row): array => [...$row, 'streamingText' => $streamingTextById[$row['id']] ?? null],
                $rows,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(RecommendationRunLog $log): array
    {
        return [
            'id' => $log->getId(),
            'phase' => $log->getPhase(),
            'batchNumber' => $log->getBatchNumber(),
            'attempt' => $log->getAttempt(),
            'verdict' => $log->getVerdict(),
            'requestBody' => $log->getRequestBody(),
            'responseText' => $log->getResponseText(),
        ];
    }

    private function __construct()
    {
    }
}
```

`RecommendationDebugLogController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\RecommendationDebugLogJson;
use App\Repository\RecommendationRunLogRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Read side of the recommendation debug view (#309). Both routes are plain
 * reads with no limiter — the ~2 s panel poll is the whole point, same
 * stance as the run status `current` route.
 */
#[Route('/api/recommendations/runs/debug-log')]
final readonly class RecommendationDebugLogController
{
    public function __construct(private RecommendationRunLogRepository $logs)
    {
    }

    #[Route('', name: 'api_recommendations_debug_log', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(RecommendationDebugLogJson::list(
            $this->logs->listForUser($user),
            $this->logs->streamingTextForUser($user),
        ));
    }

    #[Route('/{id}', name: 'api_recommendations_debug_log_entry', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function entry(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $log = $this->logs->findOwned($id, $user)
            ?? throw new NotFoundHttpException('No such debug log entry.');

        return new JsonResponse(RecommendationDebugLogJson::detail($log));
    }
}
```

Route ordering caveat: `/api/recommendations/runs/debug-log` must not be swallowed by any existing `/api/recommendations/runs/{...}` pattern — the existing controller uses only literal segments (`/tick`, `/current`), so there is no conflict; the `\d+` requirement protects the reverse direction.

- [ ] **Step 4: Run the controller tests, then the full suite**

Run: `php bin/phpunit --filter 'RecommendationDebugLogControllerTest|RecommendationRunControllerTest'` then `php bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Recommendation/RecommendationRunReport.php src/Controller/Api/RecommendationDebugLogController.php src/Http/RecommendationDebugLogJson.php tests/Controller/Api/
git commit -m "feat(#309): debug-log read endpoints and liveness in the run report"
```

---

### Task 6: Frontend — report field, API reads, liveness in the for-you bar

**Files:**
- Modify: `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/reader-api.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.ts`, `.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/reader-api.spec.ts`, `frontend/src/app/reader/reader-shell.component.spec.ts`

**Interfaces:**
- Produces (Task 7 relies on these):

```ts
export interface DebugLogEntry {
  id: number;
  phase: 'batch' | 'dedup';
  batchNumber: number | null;
  attempt: number;
  verdict: 'usable' | 'unusable' | 'transport-failed' | null;
  requestBytes: number;
  responseBytes: number;
  streamingText: string | null;
}

export interface DebugLogDetail {
  id: number;
  phase: 'batch' | 'dedup';
  batchNumber: number | null;
  attempt: number;
  verdict: string | null;
  requestBody: string;
  responseText: string;
}
```

`RecommendationRunReport` gains `streamedChars: number`. `ReaderApi` gains `debugLog(): Observable<{ entries: DebugLogEntry[] }>` (GET `/api/recommendations/runs/debug-log`) and `debugLogEntry(id: number): Observable<DebugLogDetail>` (GET `/api/recommendations/runs/debug-log/${id}`).

- [ ] **Step 1: Write the failing tests**

In `reader-api.spec.ts`, follow the file's existing HttpTestingController pattern for the recommendations endpoints and add two cases: `debugLog()` GETs the list URL, `debugLogEntry(7)` GETs `/api/recommendations/runs/debug-log/7`.

In `reader-shell.component.spec.ts`, find the existing for-you bar progress test and add one case: with a report of `{ status: 'running', batchesTotal: 5, batchesDone: 2, error: null, background: false, streamedChars: 12288 }`, the rendered bar contains `12 KB` (the new liveness fragment); with `streamedChars: 0` the fragment is absent.

- [ ] **Step 2: Run to verify failure**

Run: `npx jest src/app/reader/reader-api.spec.ts src/app/reader/reader-shell.component.spec.ts`
Expected: FAIL — missing methods/field.

- [ ] **Step 3: Implement**

- `models.ts`: add `streamedChars: number;` to `RecommendationRunReport`; add the two interfaces above.
- `reader-api.ts`: add next to the existing recommendations methods:

```ts
  debugLog(): Observable<{ entries: DebugLogEntry[] }> {
    return this.http.get<{ entries: DebugLogEntry[] }>(
      `${this.base}/api/recommendations/runs/debug-log`,
    );
  }

  debugLogEntry(id: number): Observable<DebugLogDetail> {
    return this.http.get<DebugLogDetail>(`${this.base}/api/recommendations/runs/debug-log/${id}`);
  }
```

- `reader-shell.component.ts`: add beside `forYouProgress`:

```ts
  /** Bytes of the in-flight provider answer, for the liveness fragment the
   *  bar shows during the long silent stretch of a single call. 0 (shown as
   *  null) between calls -- the server resets the counter when a call ends. */
  readonly forYouStreamedKb = computed(() => {
    const chars = this.recs.report()?.streamedChars ?? 0;
    return chars > 0 ? Math.max(1, Math.round(chars / 1024)) : null;
  });
```

- `reader-shell.component.html`, inside the `#forYouBar` template right after the progress line:

```html
          @if (forYouStreamedKb(); as kb) {
            <span class="for-you-bar__streamed">{{
              'reader.forYouStreamed' | transloco: { kb }
            }}</span>
          }
```

- i18n: `en.json` reader section: `"forYouStreamed": "{{ kb }} KB received"`; `de.json`: `"forYouStreamed": "{{ kb }} KB empfangen"`.

- [ ] **Step 4: Run the frontend gate**

Run: `npm run check`
Expected: PASS (Prettier will hold the 100-col line; break chains as needed).

- [ ] **Step 5: Commit**

```bash
git add src/app/reader/models.ts src/app/reader/reader-api.ts src/app/reader/reader-shell.component.ts src/app/reader/reader-shell.component.html src/app/reader/reader-api.spec.ts src/app/reader/reader-shell.component.spec.ts public/i18n/en.json public/i18n/de.json
git commit -m "feat(#309): liveness bytes in the for-you bar and debug-log API reads"
```

---

### Task 7: Frontend — the collapsible debug panel

**Files:**
- Create: `frontend/src/app/reader/for-you-debug-panel.component.ts`, `.html`, `.scss`
- Modify: `frontend/src/app/reader/reader-shell.component.ts` (import), `.html` (mount)
- Modify: `frontend/public/i18n/en.json`, `de.json`
- Test: `frontend/src/app/reader/for-you-debug-panel.component.spec.ts`

**Interfaces:**
- Consumes: `ReaderApi.debugLog()` / `debugLogEntry()` and `DebugLogEntry`/`DebugLogDetail` (Task 6), `RecommendationsService.running` / `completedStamp` (existing).
- Produces: `<app-for-you-debug-panel />`, self-hiding when there are no entries. Read `docs/design-language.md` before styling; tokens only, no hex, no raw px.

- [ ] **Step 1: Write the failing component test**

`for-you-debug-panel.component.spec.ts`, with `ReaderApi` and `RecommendationsService` replaced by test doubles (follow the TestBed override style of `reader-shell.component.spec.ts`). Use `jest.useFakeTimers()`. Cases, all written out concretely against the component below:

- renders nothing when `debugLog()` answers `{ entries: [] }`;
- renders one row per entry with the composed label (entry `{phase: 'batch', batchNumber: 2, attempt: 1, requestBytes: 421903, …}` ⇒ text matches `Batch 2` and `412 KB`; a `{phase: 'dedup', attempt: 2}` entry ⇒ matches `Dedup` and the attempt-2 marker);
- while `running()` is true, advancing timers by 2 s triggers another `debugLog()` call; when `running()` flips false, timers no longer trigger fetches (one final fetch on the flip is fine and asserted);
- clicking a row's request toggle calls `debugLogEntry(id)` once and renders `requestBody` inside a `<pre>`; a second toggle collapses without a second fetch;
- the streaming row (verdict null, `streamingText: 'partial…'`) shows its text without any detail fetch.

- [ ] **Step 2: Run to verify failure**

Run: `npx jest src/app/reader/for-you-debug-panel.component.spec.ts`
Expected: FAIL — component does not exist.

- [ ] **Step 3: Implement the component**

`for-you-debug-panel.component.ts`:

```ts
// src/app/reader/for-you-debug-panel.component.ts
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  WritableSignal,
  effect,
  inject,
  signal,
} from '@angular/core';
import { TranslocoModule } from '@jsverse/transloco';
import { ReaderApi } from './reader-api';
import { DebugLogDetail, DebugLogEntry } from './models';
import { RecommendationsService } from './recommendations.service';

const POLL_MS = 2000;

/** The #309 debug panel: what each provider call sent and what streamed
 *  back, ~2 s fresh while a run is in flight. Server-side truth only -- the
 *  panel never talks to the provider; it re-reads the run log the tick is
 *  checkpointing. Self-hiding: no log rows (debug switch off, or no run
 *  yet) means no panel, so the reader area needs no settings lookup. */
@Component({
  selector: 'app-for-you-debug-panel',
  standalone: true,
  imports: [TranslocoModule],
  templateUrl: './for-you-debug-panel.component.html',
  styleUrl: './for-you-debug-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForYouDebugPanelComponent implements OnInit {
  private readonly api = inject(ReaderApi);
  private readonly recs = inject(RecommendationsService);
  private readonly destroyRef = inject(DestroyRef);

  readonly entries = signal<DebugLogEntry[]>([]);
  /** Fetched bodies by entry id; an id maps once, expanding is then local. */
  readonly details = signal<Map<number, DebugLogDetail>>(new Map());
  readonly expandedRequests = signal<ReadonlySet<number>>(new Set());
  readonly expandedResponses = signal<ReadonlySet<number>>(new Set());

  private timer: ReturnType<typeof setInterval> | null = null;

  /** Fetches on creation and again whenever a run completes, so the last
   *  call's verdict and final text replace the mid-stream snapshot the last
   *  interval poll saw. */
  private readonly refetchOnCompletion = effect(() => {
    this.recs.completedStamp();
    this.fetch();
  });

  ngOnInit(): void {
    this.timer = setInterval(() => {
      if (this.recs.running()) this.fetch();
    }, POLL_MS);
    this.destroyRef.onDestroy(() => this.stopPolling());
  }

  toggleRequest(id: number): void {
    this.toggle(this.expandedRequests, id);
  }

  toggleResponse(id: number): void {
    this.toggle(this.expandedResponses, id);
  }

  copy(text: string): void {
    void navigator.clipboard.writeText(text);
  }

  requestText(entry: DebugLogEntry): string | null {
    return this.details().get(entry.id)?.requestBody ?? null;
  }

  responseText(entry: DebugLogEntry): string | null {
    if (entry.verdict === null) return entry.streamingText;
    return this.details().get(entry.id)?.responseText ?? null;
  }

  kb(bytes: number): number {
    return Math.max(1, Math.round(bytes / 1024));
  }

  private toggle(expanded: WritableSignal<ReadonlySet<number>>, id: number): void {
    const next = new Set(expanded());
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
      this.ensureDetail(id);
    }
    expanded.set(next);
  }

  private ensureDetail(id: number): void {
    if (this.details().has(id)) return;
    this.api.debugLogEntry(id).subscribe((detail) => {
      const next = new Map(this.details());
      next.set(id, detail);
      this.details.set(next);
    });
  }

  private fetch(): void {
    this.api.debugLog().subscribe({
      next: (r) => this.entries.set(r.entries),
      error: () => {
        // The panel is best-effort diagnostics; a failed poll shows stale
        // rows rather than an error state of its own.
      },
    });
  }

  private stopPolling(): void {
    if (this.timer !== null) clearInterval(this.timer);
  }
}
```

Keep component instantiation inside the for-you view only, so the interval does not run app-wide.

`for-you-debug-panel.component.html`:

```html
@if (entries().length > 0) {
  <details class="debug-panel">
    <summary class="debug-panel__title">{{ 'reader.debugPanelTitle' | transloco }}</summary>
    <ol class="debug-panel__list">
      @for (entry of entries(); track entry.id) {
        <li class="debug-panel__entry">
          <div class="debug-panel__label">
            @if (entry.phase === 'batch') {
              <span>{{ 'reader.debugBatch' | transloco: { n: entry.batchNumber } }}</span>
            } @else {
              <span>{{ 'reader.debugDedup' | transloco }}</span>
            }
            @if (entry.attempt > 1) {
              <span class="debug-panel__attempt">{{
                'reader.debugAttempt' | transloco: { n: entry.attempt }
              }}</span>
            }
            @if (entry.verdict; as verdict) {
              <span class="debug-panel__verdict debug-panel__verdict--{{ verdict }}">{{
                verdict
              }}</span>
            }
          </div>
          <button type="button" class="debug-panel__toggle" (click)="toggleRequest(entry.id)">
            {{ 'reader.debugRequest' | transloco: { kb: kb(entry.requestBytes) } }}
          </button>
          @if (expandedRequests().has(entry.id) && requestText(entry); as text) {
            <div class="debug-panel__body">
              <button type="button" (click)="copy(text)">{{ 'reader.debugCopy' | transloco }}</button>
              <pre>{{ text }}</pre>
            </div>
          }
          <button type="button" class="debug-panel__toggle" (click)="toggleResponse(entry.id)">
            {{ 'reader.debugResponse' | transloco: { kb: kb(entry.responseBytes) } }}
          </button>
          @if (entry.verdict === null && entry.streamingText !== null) {
            <pre class="debug-panel__stream">{{ entry.streamingText }}</pre>
          } @else if (expandedResponses().has(entry.id) && responseText(entry); as text) {
            <div class="debug-panel__body">
              <button type="button" (click)="copy(text)">{{ 'reader.debugCopy' | transloco }}</button>
              <pre>{{ text }}</pre>
            </div>
          }
        </li>
      }
    </ol>
  </details>
}
```

`for-you-debug-panel.component.scss`: style with the theme tokens only (spacing scale, `--color-*` variables, monospace stack from the design language). `pre` blocks: `max-height` via a spacing token multiple, `overflow: auto`, `white-space: pre-wrap`, `overflow-wrap: anywhere`. Read `docs/design-language.md` and reuse the closest existing collapsed-surface pattern rather than inventing one.

Mount: in `reader-shell.component.html`, inside the `#forYouBar` `@if (selection().kind === 'for-you')` block, after the bar markup add `<app-for-you-debug-panel />`; import `ForYouDebugPanelComponent` in `reader-shell.component.ts`'s `imports` array.

i18n additions (reader section):

```json
"debugPanelTitle": "Debug log",
"debugBatch": "Batch {{ n }}",
"debugDedup": "Dedup",
"debugAttempt": "attempt {{ n }}",
"debugRequest": "Request ({{ kb }} KB)",
"debugResponse": "Response ({{ kb }} KB)",
"debugCopy": "Copy"
```

German:

```json
"debugPanelTitle": "Debug-Protokoll",
"debugBatch": "Batch {{ n }}",
"debugDedup": "Dedup",
"debugAttempt": "Versuch {{ n }}",
"debugRequest": "Anfrage ({{ kb }} KB)",
"debugResponse": "Antwort ({{ kb }} KB)",
"debugCopy": "Kopieren"
```

- [ ] **Step 4: Run the frontend gate**

Run: `npm run check`
Expected: PASS, including the new spec.

- [ ] **Step 5: Commit**

```bash
git add src/app/reader/for-you-debug-panel.component.ts src/app/reader/for-you-debug-panel.component.html src/app/reader/for-you-debug-panel.component.scss src/app/reader/for-you-debug-panel.component.spec.ts src/app/reader/reader-shell.component.ts src/app/reader/reader-shell.component.html public/i18n/en.json public/i18n/de.json
git commit -m "feat(#309): collapsible live debug panel in the for-you view"
```

---

### Task 8: Gates, MySQL leg, live smoke, PR

**Files:** verification and delivery only.

- [ ] **Step 1: Backend static gates**

From `backend/`:

```bash
composer cs
bin/console cache:warmup && composer stan
composer md
```

Every touched `src` file must be PHPMD-clean outright.

- [ ] **Step 2: PhpStorm inspections**

Run `mcp__phpstorm__lint_files` on every created/modified PHP file; block on ERROR and WARNING.

- [ ] **Step 3: Both phpunit legs + migration leg + dev.log**

```bash
php bin/phpunit
```

From the repo root:

```bash
docker compose up -d
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
docker compose exec php vendor/bin/phpunit
```

(The order-dependent rate-limiter flake in the full MySQL leg is pre-existing; re-run a limiter failure in isolation before blaming this branch.) Scan `backend/var/log/dev.log` for new deprecations or swallowed errors.

- [ ] **Step 4: Mutation gate**

```bash
composer infection:diff
```

Kill escaped mutants with targeted tests; never lower `minMsi`.

- [ ] **Step 5: Manual live smoke against the Docker stack**

With the stack up and a configured provider (or the stub via a local Ollama): switch the debug toggle on in Settings, start a "For you" run, and confirm in the app that (a) request entries appear the moment a call starts, (b) the streaming row's text grows every ~2 s, (c) the bar shows "NN KB received" while a call is in flight even with debug off, (d) starting a second run clears the previous log. This is the one behavior no automated layer proves end to end — the checkpoint visibility depends on real concurrent requests.

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feature/309-recommendation-debug-view
```

PR against `develop`, title `Realtime debug view for recommendation runs (#309)`, body linking this plan and the issue's decision list, `Closes #309`. After merge, verify the issue closed itself.
