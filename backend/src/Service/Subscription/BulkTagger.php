<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\Model\TagStyleModel;
use App\Service\Subscription\Pass\BulkSubscribeBatch;
use App\Service\Subscription\Pass\BulkSubscribePositions;
use App\Service\Tag\Factory\TagFactory;
use App\Service\Tag\Model\TagDetailsModel;
use Doctrine\ORM\EntityManagerInterface;

/** Tags a bulk subscription with its item's path; the placement rule decides silently, so an import never fails on it. */
final readonly class BulkTagger
{
    private const int MAX_TAG_NAME = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private TagFactory $tagFactory,
    ) {
    }

    public function tag(BulkSubscribeBatch $batch, Subscription $subscription, BulkSubscribeItemModel $item): void
    {
        $outerNames = $this->keptPath($item->tagPath);
        $innermostName = array_pop($outerNames);
        if ($innermostName === null) {
            return;
        }
        $tag = $this->existingTag($batch, $innermostName) ?? $this->newTag($batch, $innermostName, $item->tagStyle);
        $batch->rememberTag($innermostName, $tag);
        $this->joinTag($batch->positions, $subscription, $tag);
        $this->nestUnder($batch, $tag, array_reverse($outerNames));
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    private function keptPath(array $path): array
    {
        return array_map(
            static fn (string $name): string => mb_substr($name, 0, self::MAX_TAG_NAME),
            array_slice($path, 0, Tag::MAX_DEPTH + 1),
        );
    }

    /** @param list<string> $parentNames innermost first */
    private function nestUnder(BulkSubscribeBatch $batch, Tag $tag, array $parentNames): void
    {
        foreach ($parentNames as $parentName) {
            $parent = $this->placedParent($batch, $tag, $parentName);
            if ($parent === null) {
                return;
            }
            $tag = $parent;
        }
    }

    /** The tag $tag now sits under, or null where the user's own arrangement or the placement rule ends the path. */
    private function placedParent(BulkSubscribeBatch $batch, Tag $tag, string $parentName): ?Tag
    {
        $existing = $this->existingTag($batch, $parentName);
        if ($tag->getParent() !== null) {
            return $tag->getParent() === $existing ? $existing : null;
        }
        if ($existing !== null) {
            $batch->rememberTag($parentName, $existing);

            return $batch->tagTree->fitsUnder($tag, $existing) ? $this->nest($batch, $tag, $existing) : null;
        }

        return $batch->tagTree->fitsUnderANewTag($tag)
            ? $this->nest($batch, $tag, $this->newTag($batch, $parentName, null))
            : null;
    }

    private function nest(BulkSubscribeBatch $batch, Tag $tag, Tag $parent): Tag
    {
        $tag->setParent($parent);
        $batch->tagTree->nest($tag, $parent);

        return $parent;
    }

    private function existingTag(BulkSubscribeBatch $batch, string $name): ?Tag
    {
        return $batch->tagNamed($name) ?? $this->tags->findOneByNameForUser($batch->user->requireId(), $name);
    }

    private function newTag(BulkSubscribeBatch $batch, string $name, ?TagStyleModel $style): Tag
    {
        $tag = $this->tagFactory->create(
            $batch->user,
            new TagDetailsModel($name, $style?->color, $style?->icon),
            $batch->positions->takeTagPosition(),
        );
        $this->entityManager->persist($tag);
        $batch->rememberNewTag($name, $tag);

        return $tag;
    }

    private function joinTag(BulkSubscribePositions $positions, Subscription $subscription, Tag $tag): void
    {
        $subscription->addTag($tag, $positions->takeFeedPositionIn(
            $tag,
            fn (): int => null === $tag->getId() ? 0 : $this->subscriptionTags->nextPositionForTag($tag),
        ));
    }
}
