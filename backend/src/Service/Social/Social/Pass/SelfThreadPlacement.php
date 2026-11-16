<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Pass;

use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use App\Service\Social\Social\Model\PlacedSelfReplyModel;
use App\Service\Social\Social\Model\TimelineSelfReplyModel;
use App\Service\Social\Social\SelfReplyChain;

/** Puts a root's self-replies on its chain: one per position, the first SelfReplyChain::MAX_REPLIES placed. */
final class SelfThreadPlacement
{
    /** @var array<int, true> */
    private array $taken = [];

    /** @var array<string, true> by url: a second branch, whose replies leave the chain with it */
    private array $branches = [];

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

    public function replyCount(): int
    {
        return $this->taken === [] ? 0 : max(array_keys($this->taken));
    }

    /** @return list<ParsedEntryModel> the replies whose parent never joined the chain: they stay entries */
    public function unplaced(): array
    {
        return $this->unplaced;
    }

    private function join(TimelineSelfReplyModel $reply): void
    {
        $url = (string) $reply->entry->url;
        $position = $this->positions[$reply->parentUrl] + 1;
        $this->positions[$url] = $position;
        if (isset($this->branches[$reply->parentUrl]) || isset($this->taken[$position])) {
            $this->branches[$url] = true;

            return;
        }
        $this->taken[$position] = true;
        if ($position <= SelfReplyChain::MAX_REPLIES) {
            $this->placed[] = new PlacedSelfReplyModel($this->rootGuid, $position, $reply->post);
        }
    }
}
