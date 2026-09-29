<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Backup\Model\RestorePreviewModel;

/**
 * Inspects the file, refuses it if it does not fit, then describes what the account holds now, for a before/after.
 * A misfit throws BackupDoesNotFitException rather than returning a preview a user could read as permission.
 */
final readonly class RestorePreviewer
{
    public function __construct(
        private BackupInspector $inspector,
        private BackupFitCheck $fitCheck,
        private SubscriptionRepository $subscriptions,
        private TagRepository $tags,
        private EntryStateRepository $entryStates,
        private RecommendationRunRepository $recommendationRuns,
    ) {
    }

    public function preview(User $user, string $gzipBytes): RestorePreviewModel
    {
        $inventory = $this->inspector->inspect($gzipBytes);
        $this->fitCheck->assertFits($inventory, $user);

        $userId = $user->requireId();

        return new RestorePreviewModel(
            source: $inventory->source,
            toLoad: $inventory,
            currentSubscriptions: $this->subscriptions->countForUser($userId),
            currentTags: $this->tags->countForUser($userId),
            currentEntryStates: $this->entryStates->countForUser($userId),
            currentRecommendationRuns: $this->recommendationRuns->countForUser($userId),
        );
    }
}
