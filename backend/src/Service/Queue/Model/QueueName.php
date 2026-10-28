<?php

declare(strict_types=1);

namespace App\Service\Queue\Model;

enum QueueName: string
{
    case FailedMessages = 'failed_messages';
    case RecommendationRuns = 'recommendation_runs';
    case ProfileRuns = 'profile_runs';
    case ImageVerification = 'image_verification';
    case ShareEnrichmentAppView = 'share_enrichment_appview';
    case ShareEnrichmentLinkCards = 'share_enrichment_link_cards';
    case ShareEnrichmentSelfThread = 'share_enrichment_self_thread';
    case Transcripts = 'transcripts';
    case DueFeeds = 'due_feeds';
}
