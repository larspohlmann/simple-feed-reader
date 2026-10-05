<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\AiProviderSettingsFactory;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261005140100;
use Psr\Log\NullLogger;

/** The test schema comes from the mapping, so the rows are set back to how they stood before the backfill. */
final class Version20261005140100Test extends DbTestCase
{
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('kind-backfill@example.test');
    }

    public function testAJevConnectionAndAJevRunBecomeSystemOneScoringAndEveryOtherModelAnLlm(): void
    {
        $jev = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest')->requireId();
        $shouting = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'JEV-latest')->requireId();
        $chat = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o')->requireId();
        $modelless = AiProviderSettingsFactory::build($this->owner);
        $this->entityManager->persist($modelless);
        $jevRun = $this->fixtures->createRun($this->owner);
        $jevRun->snapshot(RecommendationEngineKind::Scoring, null, [[1]]);
        $llmRun = $this->fixtures->createRun($this->owner);
        $llmRun->snapshot(RecommendationEngineKind::Llm, null, [[2]]);
        $this->entityManager->flush();
        $this->setBackToBeforeTheBackfill($jevRun->requireId());

        $this->backfill();

        self::assertSame(['scoring', 'system_one'], $this->connectionTag($jev));
        self::assertSame(['llm', null], $this->connectionTag($shouting));
        self::assertSame(['llm', null], $this->connectionTag($chat));
        self::assertSame([null, null], $this->connectionTag($modelless->requireId()));
        self::assertSame(['scoring', 'system_one'], $this->runTag($jevRun->requireId()));
        self::assertSame(['llm', null], $this->runTag($llmRun->requireId()));
    }

    private function setBackToBeforeTheBackfill(int $jevRunId): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE user_ai_settings SET model_kind = NULL, scoring_protocol = NULL');
        $connection->executeStatement(
            "UPDATE recommendation_run SET engine_kind = 'jev', scoring_protocol = NULL WHERE id = ?",
            [$jevRunId],
        );
        $this->entityManager->clear();
    }

    private function backfill(): void
    {
        // Migration classes are deliberately excluded from Composer's autoloader (doctrine_migrations.yaml).
        require_once dirname(__DIR__, 2) . '/migrations/Version20261005140100.php';
        $connection = $this->entityManager->getConnection();
        $migration = new Version20261005140100($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    /** @return list<mixed> the connection's kind and protocol as stored */
    private function connectionTag(int $connectionId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT model_kind, scoring_protocol FROM user_ai_settings WHERE id = ?',
            [$connectionId],
        );
        self::assertIsArray($row);

        return [$row['model_kind'], $row['scoring_protocol']];
    }

    /** @return list<mixed> the run's kind and protocol as stored */
    private function runTag(int $runId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT engine_kind, scoring_protocol FROM recommendation_run WHERE id = ?',
            [$runId],
        );
        self::assertIsArray($row);

        return [$row['engine_kind'], $row['scoring_protocol']];
    }
}
