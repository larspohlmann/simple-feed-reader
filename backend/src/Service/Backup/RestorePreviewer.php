<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Repository\EntryStateRepository;
use App\Repository\RecommendationRunRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;

/**
 * Assembles a restore preview: inspect the file, refuse it if it does not
 * fit, then describe what the account currently holds so the UI can show a
 * before/after. A non-fitting file throws BackupDoesNotFitException rather
 * than returning a preview — the caller shows the refusal instead of a
 * report the user could otherwise mistake for permission to proceed.
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

    public function preview(User $user, string $gzipBytes): RestorePreview
    {
        $inventory = $this->inspector->inspect($gzipBytes);
        $this->fitCheck->assertFits($inventory, $user);

        $userId = $user->getId() ?? 0;

        return new RestorePreview(
            header: $inventory->header,
            toLoad: $inventory,
            currentSubscriptions: $this->subscriptions->countForUser($userId),
            currentTags: $this->tags->countForUser($userId),
            currentEntryStates: $this->entryStates->countForUser($userId),
            currentRecommendationRuns: $this->recommendationRuns->countForUser($userId),
        );
    }
}
