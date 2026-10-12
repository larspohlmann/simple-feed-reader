<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ImportCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;

final readonly class SocialImportJson
{
    /** @return array<string, mixed> */
    public function candidates(ImportCandidatesModel $candidates): array
    {
        return [
            'follows' => array_map(self::follow(...), $candidates->follows),
            'lists' => array_map(self::collection(...), $candidates->lists),
            'customFeeds' => array_map(self::collection(...), $candidates->customFeeds),
            'truncated' => $candidates->truncated,
        ];
    }

    /** @return array<string, mixed> */
    private static function follow(ImportCandidateModel $candidate): array
    {
        return [
            'identity' => $candidate->account->identity,
            'handle' => $candidate->account->handle,
            'displayName' => $candidate->account->displayName,
            'feedUrl' => $candidate->account->feedUrl,
            'state' => $candidate->state->value,
            'subscriptionId' => $candidate->subscriptionId,
        ];
    }

    /** @return array{id: string, name: string} */
    private static function collection(SocialCollectionModel $collection): array
    {
        return ['id' => $collection->id, 'name' => $collection->name];
    }
}
