<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream;

use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Model\YourStreamMatchModel;
use App\Service\Curation\YourStream\Pass\StreamSweepPass;

final readonly class YourStreamChunkMatcher
{
    public function __construct(private YourStreamSourceRepository $sources, private YourStreamScorer $scorer)
    {
    }

    /**
     * @param non-empty-list<int> $chunk
     *
     * @return list<YourStreamMatchModel>
     */
    public function matchChunk(StreamSweepPass $pass, array $chunk): array
    {
        $matches = [];
        $candidates = $this->sources->findCandidates($pass->collectMatchingUserIds(), $chunk, $pass->publishedSince);
        foreach ($candidates as $candidate) {
            $stems = $pass->stemming->collectStems($candidate->entry);
            foreach ($candidate->userIds as $userId) {
                $score = $this->scorer->findMatch(
                    $pass->requireTerms($userId),
                    $stems,
                    $pass->readMinimumScore($userId),
                );
                if ($score !== null) {
                    $matches[] = new YourStreamMatchModel($userId, $candidate->entry->id, $score);
                }
            }
        }

        return $matches;
    }
}
