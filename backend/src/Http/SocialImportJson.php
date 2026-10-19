<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\CollectionCandidateModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ImportCandidatesModel;
use App\Service\Social\Model\SocialImportResultModel;

final readonly class SocialImportJson
{
    /** @return array<string, mixed> */
    public function candidates(ImportCandidatesModel $candidates): array
    {
        return [
            'follows' => array_map(self::follow(...), $candidates->follows),
            'lists' => array_map(self::collection(...), $candidates->lists),
            'customFeeds' => array_map(self::collection(...), $candidates->customFeeds),
            'tags' => array_map(self::collection(...), $candidates->tags),
            'truncated' => $candidates->truncated,
        ];
    }

    /** @return array<string, int> */
    public function result(SocialImportResultModel $result): array
    {
        return [
            'imported' => $result->imported,
            'alreadySubscribed' => $result->alreadySubscribed,
            'invalid' => $result->invalid,
            'skippedOverLimit' => $result->skippedOverLimit,
            'removed' => $result->removed,
        ];
    }

    /** @return array<string, mixed> */
    private static function follow(ImportCandidateModel $candidate): array
    {
        return [
            'identity' => $candidate->account->identity,
            'handle' => $candidate->account->handle,
            'displayName' => $candidate->account->displayName,
            'avatarUrl' => $candidate->account->avatarUrl,
            'feedUrl' => $candidate->account->feedUrl,
            'state' => $candidate->state->value,
            'subscriptionId' => $candidate->subscriptionId,
        ];
    }

    /** @return array{kind: string, id: string|null, name: string, state: string, subscriptionId: int|null} */
    private static function collection(CollectionCandidateModel $candidate): array
    {
        return [
            'kind' => $candidate->privateFeed->kind->value,
            'id' => '' === $candidate->privateFeed->remoteId ? null : $candidate->privateFeed->remoteId,
            'name' => $candidate->privateFeed->name,
            'state' => $candidate->state->value,
            'subscriptionId' => $candidate->subscriptionId,
        ];
    }
}
