<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Social\Model\PlacedSelfReplyModel;
use App\Service\Social\Model\TimelineSelfReplyModel;
use App\Service\Social\SelfReplyChain;

/** Puts a root's self-replies on its chain: one per position, at most SelfReplyChain::MAX_REPLIES. */
final class SelfThreadPlacement
{
    /** @var array<int, true> */
    private array $taken = [];

    /** @var list<PlacedSelfReplyModel> */
    private array $placed = [];

    /** @var list<ParsedEntryModel> */
    private array $unplaced = [];

    /** @param array<string, int> $positions url => position, the root at 0 and its stored thread posts */
    public function __construct(private readonly string $rootGuid, private array $positions)
    {
        foreach ($positions as $position) {
            if ($position > 0) {
                $this->taken[$position] = true;
            }
        }
    }

    /** @param list<TimelineSelfReplyModel> $replies oldest first */
    public function place(array $replies): void
    {
        $pending = array_filter(
            $replies,
            fn (TimelineSelfReplyModel $reply): bool => !isset($this->positions[(string) $reply->entry->url]),
        );
        do {
            $joined = array_filter(
                $pending,
                fn (TimelineSelfReplyModel $reply): bool => isset($this->positions[$reply->parentUrl]),
            );
            foreach ($joined as $reply) {
                $this->join($reply);
            }
            $pending = array_diff_key($pending, $joined);
        } while ($joined !== []);
        $this->unplaced = array_values(array_map(
            static fn (TimelineSelfReplyModel $reply): ParsedEntryModel => $reply->entry,
            $pending,
        ));
    }

    /** @return list<PlacedSelfReplyModel> */
    public function placed(): array
    {
        return $this->placed;
    }

    /** @return list<ParsedEntryModel> the replies whose parent never joined the chain: they stay entries */
    public function unplaced(): array
    {
        return $this->unplaced;
    }

    /** A dropped reply holds the last position, so its own replies are dropped with it. */
    private function join(TimelineSelfReplyModel $reply): void
    {
        $position = $this->positions[$reply->parentUrl] + 1;
        if ($position > SelfReplyChain::MAX_REPLIES || isset($this->taken[$position])) {
            $this->positions[(string) $reply->entry->url] = SelfReplyChain::MAX_REPLIES;

            return;
        }
        $this->positions[(string) $reply->entry->url] = $position;
        $this->taken[$position] = true;
        $this->placed[] = new PlacedSelfReplyModel($this->rootGuid, $position, $reply->post);
    }
}
