<?php

declare(strict_types=1);

namespace App\Service\Completion;

use App\Entity\Entry;
use App\Entity\LinkCardContent;
use App\Entity\QuotedPostContent;
use App\Enum\ShareEnrichmentKind;
use App\Service\Bluesky\EntryEmbedWriter\EntryEmbedWriterInterface;
use App\Service\Bluesky\Model\FillOutcome;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\LinkCard\EntryCardWriter;
use App\Service\LinkCard\Model\EntryCardsModel;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\Model\QuotedTargetModel;
use App\Service\LinkCard\Support\QuoteLine;
use App\Service\Share\Support\ShareTargets;
use Psr\Log\LoggerInterface;

/** Settles a new entry whose network already answered its embed or its card, as the later passes would have. */
final readonly class KnownShareSettler
{
    public function __construct(
        private EntryEmbedWriterInterface $embedWriter,
        private EntryCardWriter $cardWriter,
        private LoggerInterface $logger,
    ) {
    }

    /** Ends where PostEnricher::settle() leaves an RSS Bluesky post: one without an embed or a link shares nothing. */
    public function settleEmbedded(Entry $entry, JsonNodeModel $post): ?ShareEnrichmentKind
    {
        $outcome = $this->filled($entry, $post);
        if ($outcome === FillOutcome::Unfilled) {
            $entry->setShareKind(null);

            return null;
        }

        return $outcome === FillOutcome::AwaitingLinkCards ? ShareEnrichmentKind::LinkCards : null;
    }

    /** Takes a classified link share and its card under a posted link: only the other links still wait for a page. */
    public function settleCarded(Entry $entry, LinkCardContent $card): ?ShareEnrichmentKind
    {
        $this->cardWriter->write($entry, new EntryCardsModel([], [new PageCardModel($card, null)]));

        return ShareTargets::uncardedOf($entry, [$card->url]) !== [] ? ShareEnrichmentKind::LinkCards : null;
    }

    /** A quoted status the instance already answered: its RE: line leaves the body, and no other link waits for a card. */
    public function settleQuoted(Entry $entry, QuotedPostContent $quote): void
    {
        $address = QuoteLine::targetIn((string) $entry->getContentHtml()) ?? $quote->url;
        $this->cardWriter->write($entry, EntryCardsModel::quoting(new QuotedTargetModel($address, $quote)));
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
}
