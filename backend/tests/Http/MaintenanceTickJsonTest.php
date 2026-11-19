<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Enum\TrendingWindow;
use App\Http\MaintenanceTickJson;
use App\Service\Outbound\Image\Model\ImageVerificationReportModel;
use App\Service\Content\LinkCard\Model\LinkCardSweepReportModel;
use App\Service\Content\LinkResolution\Model\LinkResolutionReportModel;
use App\Service\Operations\Logging\Loki\Model\LokiSpoolReportModel;
use App\Service\Outbound\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Operations\Maintenance\Model\MaintenanceSweepsModel;
use App\Service\Operations\Maintenance\Model\MaintenanceTickReportModel;
use App\Service\Curation\Recommendation\Run\Model\ForYouSweepReportModel;
use App\Service\Ingestion\Refresh\Model\RefreshReportModel;
use App\Service\Library\Search\Membership\Model\SavedSearchMembershipSweepReportModel;
use App\Service\Social\Bluesky\Model\HiddenProfileSwitchReportModel;
use App\Service\Social\Notification\Model\NotificationPollReportModel;
use App\Service\Social\Social\Model\PrivateFeedSyncReportModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Content\Transcript\Model\TranscriptSweepReportModel;
use App\Service\Curation\Trending\Model\StoryMatchModel;
use App\Service\Curation\Trending\Model\TrendingBuildReportModel;
use App\Service\Curation\Trending\Model\TrendingRebuildReportModel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MaintenanceTickJsonTest extends KernelTestCase
{
    public function testACompletedTickSendsEveryHalfUnderItsKey(): void
    {
        $report = new MaintenanceTickReportModel(
            RefreshReportModel::finished(9, 1, 2, 3, 4, 5, 0, 7),
            MaintenanceSweepsModel::ran([
                'recommendations' => new ForYouSweepReportModel(1, 2, 3),
                'digests' => new DigestSweepReportModel(4, 5, 6),
                'imageVerification' => new ImageVerificationReportModel(7, 8, 9, 10),
                'linkCards' => new LinkCardSweepReportModel(16, 17),
                'linkResolutions' => new LinkResolutionReportModel(31, 32, 33, 34, 35, 1.5),
                'transcripts' => new TranscriptSweepReportModel(26, 27),
                'profileImages' => new ProfileImageSweepReportModel(18, 19),
                'hiddenProfiles' => new HiddenProfileSwitchReportModel(24, 25),
                'savedSearchMemberships' => new SavedSearchMembershipSweepReportModel(11, 12, 13, true),
                'privateFeedSync' => new PrivateFeedSyncReportModel(20, 21, 22, 23),
                'notificationPoll' => new NotificationPollReportModel(40, 41, 42, 43),
                'trending' => new TrendingRebuildReportModel([
                    new TrendingBuildReportModel(
                        7,
                        TrendingWindow::Day,
                        new \DateTimeImmutable('2031-05-14 12:00:00'),
                        300,
                        12,
                        28,
                        400,
                        5_000,
                        new StoryMatchModel([], [], 0),
                        0,
                    ),
                    new TrendingBuildReportModel(
                        7,
                        TrendingWindow::Week,
                        new \DateTimeImmutable('2031-05-14 12:00:00'),
                        900,
                        12,
                        29,
                        800,
                        9_000,
                        new StoryMatchModel([], [], 0),
                        0,
                    ),
                ]),
            ]),
            new LokiSpoolReportModel(14, 15),
        );

        self::assertSame(
            [
                'refresh' => [
                    'status' => 'completed',
                    'total' => 9,
                    'fetched' => 1,
                    'notModified' => 2,
                    'failed' => 3,
                    'throttled' => 4,
                    'skippedForBudget' => 5,
                    'remaining' => 0,
                    'pruned' => 7,
                ],
                'recommendations' => [
                    'startedRuns' => 1,
                    'advancedRuns' => 2,
                    'activeRuns' => 3,
                    'startedProfileRuns' => 0,
                    'advancedProfileRuns' => 0,
                    'activeProfileRuns' => 0,
                ],
                'digests' => ['considered' => 4, 'sent' => 5, 'skippedEmpty' => 6],
                'imageVerification' => ['measured' => 7, 'kept' => 8, 'dropped' => 9, 'retried' => 10],
                'linkCards' => ['considered' => 16, 'completed' => 17],
                'linkResolutions' => [
                    'considered' => 31,
                    'resolved' => 32,
                    'unchanged' => 33,
                    'unresolved' => 34,
                    'deferred' => 35,
                ],
                'transcripts' => ['claimed' => 26, 'filled' => 27],
                'profileImages' => ['checked' => 18, 'changed' => 19],
                'hiddenProfiles' => ['started' => 24, 'finished' => 25],
                'savedSearchMemberships' => [
                    'searchesSwept' => 11,
                    'entriesScanned' => 12,
                    'matchesInserted' => 13,
                    'caughtUp' => true,
                ],
                'privateFeedSync' => ['accountsChecked' => 20, 'accountsSkipped' => 21, 'added' => 22, 'removed' => 23],
                'notificationPoll' => ['accountsChecked' => 40, 'failed' => 41, 'newRows' => 42, 'pruned' => 43],
                'trending' => ['windowsRebuilt' => 2, 'storiesBuilt' => 57],
                'logShipping' => ['shipped' => 14, 'failed' => 15],
            ],
            $this->json()->report($report),
        );
    }

    public function testSweepsSkippedAfterAnAbortedRefreshSayWhy(): void
    {
        $report = new MaintenanceTickReportModel(
            RefreshReportModel::aborted(5, 1, 1, 1, 0, 2),
            MaintenanceSweepsModel::skippedAfterAbortedRefresh([
                'linkCards' => LinkCardSweepReportModel::none(),
                'linkResolutions' => LinkResolutionReportModel::none(),
                'savedSearchMemberships' => SavedSearchMembershipSweepReportModel::none(),
            ]),
            new LokiSpoolReportModel(0, 0),
        );
        $reason = 'refresh aborted: the shared EntityManager is unusable this tick';

        self::assertSame(
            [
                'refresh' => [
                    'status' => 'aborted',
                    'total' => 5,
                    'fetched' => 1,
                    'notModified' => 1,
                    'failed' => 1,
                    'throttled' => 0,
                    'skippedForBudget' => 0,
                    'remaining' => 2,
                    'pruned' => 0,
                ],
                'linkCards' => ['considered' => 0, 'completed' => 0, 'skipped' => $reason],
                'linkResolutions' => [
                    'considered' => 0,
                    'resolved' => 0,
                    'unchanged' => 0,
                    'unresolved' => 0,
                    'deferred' => 0,
                    'skipped' => $reason,
                ],
                'savedSearchMemberships' => [
                    'searchesSwept' => 0,
                    'entriesScanned' => 0,
                    'matchesInserted' => 0,
                    'caughtUp' => false,
                    'skipped' => $reason,
                ],
                'logShipping' => ['shipped' => 0, 'failed' => 0],
            ],
            $this->json()->report($report),
        );
    }

    public function testAReportNoMapperClaimsIsAWiringError(): void
    {
        $report = new MaintenanceTickReportModel(
            RefreshReportModel::finished(0, 0, 0, 0, 0, 0, 0, 0),
            MaintenanceSweepsModel::ran(['unknown' => new \stdClass()]),
            new LokiSpoolReportModel(0, 0),
        );

        $this->expectExceptionObject(new \LogicException('No tick JSON mapper claims stdClass.'));

        $this->json()->report($report);
    }

    private function json(): MaintenanceTickJson
    {
        $json = self::getContainer()->get(MaintenanceTickJson::class);
        self::assertInstanceOf(MaintenanceTickJson::class, $json);

        return $json;
    }
}
