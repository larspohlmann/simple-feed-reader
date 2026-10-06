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

    public function testAJevConnectionSpeaksSystemOneAndAJevRunBecomesScoring(): void
    {
        $jev = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'jev-latest')->requireId();
        $shouting = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'JEV-latest')->requireId();
        $chat = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'gpt-4o')->requireId();
        $modelless = AiProviderSettingsFactory::build($this->owner);
        $this->entityManager->persist($modelless);
        $jevRun = $this->fixtures->createRun($this->owner);
        $jevRun->snapshot(RecommendationEngineKind::Scoring, [[1]]);
        $llmRun = $this->fixtures->createRun($this->owner);
        $llmRun->snapshot(RecommendationEngineKind::Llm, [[2]]);
        $this->entityManager->flush();
        $this->setBackToBeforeTheBackfill($jevRun->requireId());

        $this->backfill();

        self::assertSame('system_one', $this->connectionProtocol($jev));
        self::assertNull($this->connectionProtocol($shouting));
        self::assertNull($this->connectionProtocol($chat));
        self::assertNull($this->connectionProtocol($modelless->requireId()));
        self::assertSame('scoring', $this->runKind($jevRun->requireId()));
        self::assertSame('llm', $this->runKind($llmRun->requireId()));
    }

    private function setBackToBeforeTheBackfill(int $jevRunId): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('UPDATE user_ai_settings SET scoring_protocol = NULL');
        $connection->executeStatement(
            "UPDATE recommendation_run SET engine_kind = 'jev' WHERE id = ?",
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

    private function connectionProtocol(int $connectionId): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT scoring_protocol FROM user_ai_settings WHERE id = ?',
            [$connectionId],
        );
    }

    private function runKind(int $runId): mixed
    {
        return $this->entityManager->getConnection()->fetchOne(
            'SELECT engine_kind FROM recommendation_run WHERE id = ?',
            [$runId],
        );
    }
}
