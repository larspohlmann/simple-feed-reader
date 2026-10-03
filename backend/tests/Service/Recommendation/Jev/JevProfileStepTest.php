<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Jev;

use App\Entity\RecommendationRun;
use App\Entity\StoredProfile;
use App\Entity\User;
use App\Enum\RecommendationEngineKind;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Exception\RecommendationRunCancelledException;
use App\Service\Recommendation\Jev\JevProfileStep;
use App\Service\Recommendation\Profile\ProfileDistiller\ProfileDistillerInterface;
use App\Service\Recommendation\Run\Model\BorrowedProfileModel;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFailure;
use App\Service\Recommendation\Run\RecommendationTickCheckpoint;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;
use App\Tests\DbTestCase;
use App\Tests\Support\BuildsTickContexts;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

/** What the pipeline cannot see within one entity manager: the step's own write and its cancellation checks. */
final class JevProfileStepTest extends DbTestCase
{
    use BuildsTickContexts;
    use SeedsUsers;

    private User $owner;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('jev-profile-step@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'jev-latest');
    }

    public function testTheFallbackProfileIsWrittenByTheStepItself(): void
    {
        $this->settingsWriter()->storeProfile($this->owner, new StoredProfile('Stored: likes Rust.', null, null, null));
        $run = $this->runningJevRun();

        $this->step($this->degradingDistiller())->advance($this->borrowingTick($run));

        $this->entityManager->clear();
        $stored = $this->entityManager->find(RecommendationRun::class, $run->requireId());
        self::assertNotNull($stored);
        self::assertSame('Stored: likes Rust.', $stored->getProfileText());
    }

    public function testARunCancelledDuringTheDistillationTakesNoFallbackProfile(): void
    {
        $this->settingsWriter()->storeProfile($this->owner, new StoredProfile('Stored: likes Rust.', null, null, null));
        $run = $this->runningJevRun();

        try {
            $this->step($this->degradingDistillerCancelling($run))->advance($this->borrowingTick($run));
            self::fail('A cancelled run must stop the tick.');
        } catch (RecommendationRunCancelledException) {
            self::assertNull($run->getProfileText());
        }
    }

    public function testARunCancelledMeanwhileIsNotFailedOverForWantOfAProfileConnection(): void
    {
        $run = $this->runningJevRun();
        $this->cancel($run);

        try {
            $this->step($this->degradingDistiller())->advance($this->tick($run));
            self::fail('A cancelled run must stop the tick.');
        } catch (RecommendationRunCancelledException) {
            self::assertSame(RunStatus::Cancelled, $run->getStatus());
        }
    }

    private function runningJevRun(): RecommendationRun
    {
        $run = $this->fixtures->createRun($this->owner);
        $run->snapshot(RecommendationEngineKind::Jev, [[101]]);
        $this->entityManager->flush();

        return $run;
    }

    private function borrowingTick(RecommendationRun $run): TickContext
    {
        $tick = $this->tick($run);

        return $tick->borrowingProfileFrom(
            new BorrowedProfileModel($tick->connection, RecommendationEngineKind::Llm, $tick->settings),
        );
    }

    private function degradingDistiller(): ProfileDistillerInterface
    {
        return $this->distillerRecordingNoProfileThen(function (): void {
            $this->entityManager->flush();
        });
    }

    private function degradingDistillerCancelling(RecommendationRun $run): ProfileDistillerInterface
    {
        return $this->distillerRecordingNoProfileThen(function () use ($run): void {
            $this->cancel($run);
        });
    }

    /**
     * The LLM's degraded ending: the run distilled, with no profile.
     *
     * @param \Closure(): void $afterRecording
     */
    private function distillerRecordingNoProfileThen(\Closure $afterRecording): ProfileDistillerInterface
    {
        return new readonly class ($afterRecording) implements ProfileDistillerInterface {
            /** @param \Closure(): void $afterRecording */
            public function __construct(private \Closure $afterRecording)
            {
            }

            public function advance(TickContext $tick): RecommendationRunReportModel
            {
                $tick->run->recordProfile(null);
                ($this->afterRecording)();

                return RecommendationRunReportModel::fromRun($tick->run);
            }
        };
    }

    private function cancel(RecommendationRun $run): void
    {
        $run->cancel(new \DateTimeImmutable('2026-10-02 09:00:00'));
        $this->entityManager->flush();
    }

    private function step(ProfileDistillerInterface $distiller): JevProfileStep
    {
        /** @var RecommendationTickCheckpoint $checkpoint */
        $checkpoint = self::getContainer()->get(RecommendationTickCheckpoint::class);

        return new JevProfileStep(
            $distiller,
            new RecommendationRunFailure($checkpoint, $this->entityManager, new MockClock('2026-10-02 09:00:00')),
            $checkpoint,
            $this->entityManager,
        );
    }

    private function settingsWriter(): RecommendationSettingsWriter
    {
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);

        return $writer;
    }
}
