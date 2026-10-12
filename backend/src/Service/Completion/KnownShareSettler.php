<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\ShareEnrichment;
use App\Enum\ShareEnrichmentKind;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\LinkCard\EntryCardWriter;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\Share\Support\ShareTargets;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Settles a new entry whose network already answered its embed or its card, as the later passes would have. */
final readonly class KnownShareSettler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntryEmbedWriterInterface $embedWriter,
        private EntryCardWriter $cardWriter,
        private LoggerInterface $logger,
    ) {
    }

    /** Ends where PostEnricher::settle() leaves an RSS Bluesky post: one without an embed shares nothing. */
    public function settleEmbedded(Entry $entry, JsonNodeModel $post, \DateTimeImmutable $queuedAt): void
    {
        $outcome = $this->filled($entry, $post);
        if ($outcome === FillOutcome::Unfilled) {
            $entry->setShareKind(null);

            return;
        }
        if ($outcome === FillOutcome::AwaitingLinkCards) {
            $this->awaitLinkCards($entry, $queuedAt);
        }
    }

    /** Takes a classified link share: only links the card does not cover still wait for a page fetch. */
    public function settleCarded(Entry $entry, LinkCardContent $card, \DateTimeImmutable $queuedAt): void
    {
        $this->cardWriter->write($entry, new EntryCardsModel([], [new PageCardModel($card, null)]));
        if (ShareTargets::uncardedOf($entry, [$card->url]) !== []) {
            $this->awaitLinkCards($entry, $queuedAt);
        }
    }

    private function filled(Entry $entry, JsonNodeModel $post): FillOutcome
    {
        try {
            return $this->embedWriter->fill($entry, $post);
        } catch (\Exception $exception) {
            $this->logger->warning(
                'Bluesky post {guid} could not be filled',
                ['guid' => $entry->getGuid(), 'exception' => $exception],
            );

            return FillOutcome::Unfilled;
        }
    }

    private function awaitLinkCards(Entry $entry, \DateTimeImmutable $queuedAt): void
    {
        $this->entityManager->persist(new ShareEnrichment($entry, ShareEnrichmentKind::LinkCards, $queuedAt));
    }
}
