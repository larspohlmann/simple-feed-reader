<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Library\Opml\Model\OpmlFeedModel;
use App\Service\Library\Opml\Model\OpmlFeedState;
use App\Service\Library\Opml\Model\OpmlImportResultModel;
use App\Service\Library\Opml\Model\OpmlPlacedFolderModel;
use App\Service\Library\Opml\Model\OpmlPreviewModel;

final class OpmlJson
{
    /** @return array{imported: int, alreadySubscribed: int, invalid: int, skippedOverLimit: int} */
    public static function imported(OpmlImportResultModel $result): array
    {
        return [
            'imported' => $result->imported,
            'alreadySubscribed' => $result->alreadySubscribed,
            'invalid' => $result->invalid,
            'skippedOverLimit' => $result->skippedOverLimit,
        ];
    }

    /**
     * @return array{freeSlots: int, root: array{
     *     name: ?string, dropped: bool, destination: list<string>,
     *     feeds: list<array{url: string, title: ?string, state: string}>, folders: list<array<string, mixed>>
     * }}
     */
    public static function preview(OpmlPreviewModel $preview): array
    {
        return [
            'freeSlots' => $preview->freeSlots,
            'root' => self::folder($preview->root, $preview->states),
        ];
    }

    /**
     * @param array<string, OpmlFeedState> $states
     *
     * @return array{
     *     name: ?string, dropped: bool, destination: list<string>,
     *     feeds: list<array{url: string, title: ?string, state: string}>, folders: list<array<string, mixed>>
     * }
     */
    private static function folder(OpmlPlacedFolderModel $folder, array $states): array
    {
        return [
            'name' => $folder->name,
            'dropped' => $folder->dropped,
            'destination' => $folder->destination,
            'feeds' => array_map(
                static fn (OpmlFeedModel $feed): array => [
                    'url' => $feed->url,
                    'title' => $feed->title,
                    'state' => $states[$feed->url]->value,
                ],
                $folder->feeds,
            ),
            'folders' => array_map(
                static fn (OpmlPlacedFolderModel $child): array => self::folder($child, $states),
                $folder->folders,
            ),
        ];
    }
}
