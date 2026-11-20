<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\Entry;
use App\Enum\ShareKind;
use App\Service\Content\Comments\Model\EntryCommentModel;
use App\Service\Content\LinkCard\EntryLinks;
use App\Service\Social\Social\Factory\ThreadPostFactory;
use App\Service\Social\Social\Model\SelfReplyChainModel;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SelfThreadFolder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThreadPostFactory $threadPostFactory,
        private EntryLinks $entryLinks,
    ) {
    }

    public function fold(Entry $entry, SelfReplyChainModel $chain): void
    {
        foreach ($chain->replies as $index => $reply) {
            $this->foldAt($entry, $index + 1, $reply);
        }
        $entry->discussionCounts()->recordSelfReplyCount($chain->replyCount);
    }

    public function foldAt(Entry $entry, int $position, EntryCommentModel $reply): void
    {
        $post = $this->threadPostFactory->build($entry, $position, $reply);
        $this->entityManager->persist($post);
        $card = $reply->embeds->linkCard;
        if ($card === null) {
            return;
        }
        $this->entryLinks->attachToThreadPost($post, $card);
        if ($entry->getShareKind() === null) {
            $entry->setShareKind(ShareKind::Link);
        }
    }
}
