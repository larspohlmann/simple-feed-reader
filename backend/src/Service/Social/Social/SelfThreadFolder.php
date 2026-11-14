<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Enum\ShareKind;
use App\Repository\LinkCardRepository;
use App\Service\Comments\Model\EntryCommentModel;
use App\Service\Social\Factory\ThreadPostFactory;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SelfThreadFolder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ThreadPostFactory $threadPostFactory,
        private LinkCardRepository $linkCards,
    ) {
    }

    /** @param list<EntryCommentModel> $chain */
    public function fold(Entry $entry, array $chain): void
    {
        foreach ($chain as $index => $reply) {
            $this->foldAt($entry, $index + 1, $reply);
        }
    }

    public function foldAt(Entry $entry, int $position, EntryCommentModel $reply): void
    {
        $post = $this->threadPostFactory->build($entry, $position, $reply);
        $this->entityManager->persist($post);
        $card = $reply->embeds->linkCard;
        if ($card === null) {
            return;
        }
        $this->linkCards->appendToThreadPost($post, $card);
        if ($entry->getShareKind() === null) {
            $entry->setShareKind(ShareKind::Link);
        }
    }
}
