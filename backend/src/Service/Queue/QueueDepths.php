<?php

declare(strict_types=1);

namespace App\Service\Queue;

use App\Enum\ShareEnrichmentKind;
use App\Repository\DueFeedCriteria;
use App\Repository\EntryTranscriptRepository;
use App\Repository\FailedMessageRepository;
use App\Repository\FeedRepository;
use App\Repository\PendingImageVerificationRepository;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\ShareEnrichmentBacklogRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Queue\Model\QueueBacklogModel;
use App\Service\Queue\Model\QueueDepthModel;
use App\Service\Queue\Model\QueueName;

final readonly class QueueDepths
{
    public function __construct(
        private FailedMessageRepository $failedMessages,
        private RecommendationRunRepository $recommendationRuns,
        private ProfileRunRepository $profileRuns,
        private PendingImageVerificationRepository $pendingImages,
        private ShareEnrichmentBacklogRepository $shareEnrichments,
        private EntryTranscriptRepository $transcripts,
        private FeedRepository $feeds,
        private NaiveUtcClock $clock,
    ) {
    }

    /** @return list<QueueDepthModel> */
    public function all(): array
    {
        return array_map(
            fn (QueueName $queue): QueueDepthModel => $this->backlogOf($queue)->named($queue),
            QueueName::cases(),
        );
    }

    private function backlogOf(QueueName $queue): QueueBacklogModel
    {
        return match ($queue) {
            QueueName::FailedMessages => $this->failedMessages->backlog(),
            QueueName::RecommendationRuns => $this->recommendationRuns->activeBacklog(),
            QueueName::ProfileRuns => $this->profileRuns->activeBacklog(),
            QueueName::ImageVerification => $this->pendingImages->backlog(),
            QueueName::ShareEnrichmentAppView => $this->shareEnrichments->backlogOf(ShareEnrichmentKind::AppView),
            QueueName::ShareEnrichmentLinkCards => $this->shareEnrichments->backlogOf(ShareEnrichmentKind::LinkCards),
            QueueName::ShareEnrichmentSelfThread => $this->shareEnrichments->backlogOf(ShareEnrichmentKind::SelfThread),
            QueueName::Transcripts => $this->transcripts->pendingBacklog(),
            QueueName::DueFeeds => $this->feeds->dueBacklog(new DueFeedCriteria($this->clock->now())),
        };
    }
}
