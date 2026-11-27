<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream;

use App\Repository\ForYouStreamSourceRepository;
use App\Service\Curation\Recommendation\Stream\Model\ForYouStreamMatchModel;
use App\Service\Curation\Recommendation\Stream\Pass\StreamSweepPass;

final readonly class ForYouStreamChunkMatcher
{
    public function __construct(private ForYouStreamSourceRepository $sources, private ForYouStreamScorer $scorer)
    {
    }

    /**
     * @param non-empty-list<int> $chunk
     *
     * @return list<ForYouStreamMatchModel>
     */
    public function matchChunk(StreamSweepPass $pass, array $chunk): array
    {
        $matches = [];
        $candidates = $this->sources->findCandidates($pass->collectUserIds(), $chunk, $pass->publishedSince);
        foreach ($candidates as $candidate) {
            $stems = $pass->stemming->collectStems($candidate->entry);
            foreach ($candidate->userIds as $userId) {
                $score = $this->scorer->findMatch($pass->requireTerms($userId), $stems);
                if ($score !== null) {
                    $matches[] = new ForYouStreamMatchModel($userId, $candidate->entry->id, $score);
                }
            }
        }

        return $matches;
    }
}
