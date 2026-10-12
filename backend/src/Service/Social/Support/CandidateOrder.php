<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\SocialCollectionModel;

final class CandidateOrder
{
    /**
     * @param list<ImportCandidateModel> $candidates
     *
     * @return list<ImportCandidateModel>
     */
    public static function follows(array $candidates): array
    {
        usort(
            $candidates,
            static fn (ImportCandidateModel $left, ImportCandidateModel $right): int => [
                $left->state->listingRank(),
                strtolower($left->account->handle),
            ] <=> [
                $right->state->listingRank(),
                strtolower($right->account->handle),
            ],
        );

        return $candidates;
    }

    /**
     * @param list<SocialCollectionModel> $collections
     *
     * @return list<SocialCollectionModel>
     */
    public static function collections(array $collections): array
    {
        usort(
            $collections,
            static fn (SocialCollectionModel $left, SocialCollectionModel $right): int => strnatcasecmp(
                $left->name,
                $right->name,
            ),
        );

        return $collections;
    }

    private function __construct()
    {
    }
}
