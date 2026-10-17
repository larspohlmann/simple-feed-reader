<?php

declare(strict_types=1);

namespace App\Service\SavedPosts;

use App\Entity\EntryGuid;
use App\Entity\Subscription;
use App\Repository\EntryRepository;
use App\Repository\SavedPostRepository;
use App\Service\SavedPosts\Model\SavedCopyModel;
use App\Service\SavedPosts\Pass\SavedCopyPlan;
use App\Service\SavedPosts\Support\SavedOrigin;
use App\Service\Search\EntryIndexer;

/** Runs before a subscription goes (#1666): a favourite or kept post moves to a feed the user keeps, else to a copy. */
final readonly class SavedPostRescue
{
    public function __construct(
        private SavedPostRepository $savedPosts,
        private SavedFeeds $savedFeeds,
        private SavedStateMover $stateMover,
        private EntryRepository $entries,
        private EntryIndexer $indexer,
    ) {
    }

    /**
     * @param list<Subscription> $leaving one unsubscribe call's subscriptions, none of them a saved feed
     *
     * @return list<int> the copies it created, to index once the caller's transaction committed
     */
    public function rescueFrom(array $leaving): array
    {
        $leavingFeedIds = array_map(
            static fn (Subscription $subscription): int => $subscription->getFeed()->requireId(),
            $leaving,
        );
        $copyIds = [];
        foreach ($leaving as $subscription) {
            array_push($copyIds, ...$this->rescue($subscription, $leavingFeedIds));
        }

        return $copyIds;
    }

    /** @param list<int> $copyIds */
    public function indexCopies(array $copyIds): void
    {
        $this->indexer->index($this->entries->findAllByIds($copyIds));
    }

    /**
     * @param list<int> $leavingFeedIds
     *
     * @return list<int> the copies created
     */
    private function rescue(Subscription $subscription, array $leavingFeedIds): array
    {
        $userId = $subscription->getUser()->requireId();
        $flagged = $this->savedPosts->flaggedIn($userId, $subscription->getFeed()->requireId());
        if ([] === $flagged) {
            return [];
        }
        $twins = $this->savedPosts->keptTwinsOf($userId, $flagged, $leavingFeedIds);
        $toCopy = array_keys(array_diff_key($flagged, $twins));
        if ([] === $toCopy) {
            $this->stateMover->move($userId, $twins);

            return [];
        }
        [$held, $created] = $this->copied($subscription, $toCopy);
        $this->stateMover->move($userId, $twins + $held + $created);

        return array_values($created);
    }

    /**
     * @param list<int> $entryIds
     *
     * @return array{array<int, int>, array<int, int>} the copies the saved feed held, and the ones made now
     */
    private function copied(Subscription $subscription, array $entryIds): array
    {
        $savedFeed = $this->savedFeeds->of($subscription->getUser(), $subscription->getFeed()->getNetwork());
        $copy = new SavedCopyModel($savedFeed->getFeed()->requireId(), SavedOrigin::posterOf($subscription));
        $origin = SavedOrigin::originOf($subscription);
        $held = [];
        $created = [];
        while ([] !== $entryIds) {
            $plan = $this->planFor($copy->savedFeedId, $entryIds);
            $made = $this->copiesOf($copy, $plan);
            $this->savedPosts->copyCompanions($made, $origin);
            $held += $plan->merged();
            $created += $made;
            $entryIds = $plan->deferred();
        }

        return [$held, $created];
    }

    /** @param list<int> $entryIds */
    private function planFor(int $savedFeedId, array $entryIds): SavedCopyPlan
    {
        $originals = $this->savedPosts->originalsOf($entryIds);

        return new SavedCopyPlan(
            $originals,
            $this->savedPosts->heldIn($savedFeedId, SavedCopyPlan::heldHashesToAsk($originals)),
        );
    }

    /** @return array<int, int> */
    private function copiesOf(SavedCopyModel $copy, SavedCopyPlan $plan): array
    {
        return $this->savedPosts->copyInto($copy, $plan->plain()) + $this->renamedCopies($copy, $plan->renamed());
    }

    /**
     * @param array<int, EntryGuid> $guidsByEntryId
     *
     * @return array<int, int>
     */
    private function renamedCopies(SavedCopyModel $copy, array $guidsByEntryId): array
    {
        $created = [];
        foreach ($guidsByEntryId as $entryId => $guid) {
            $created[$entryId] = $this->savedPosts->copyRenamed($copy, $entryId, $guid);
        }

        return $created;
    }
}
