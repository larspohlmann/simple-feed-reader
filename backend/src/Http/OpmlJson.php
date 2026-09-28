<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Opml\Model\OpmlImportResultModel;

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
}
