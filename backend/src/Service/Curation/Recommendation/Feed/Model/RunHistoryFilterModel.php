<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;

/** A null kind reads every kind. */
final readonly class RunHistoryFilterModel
{
    public function __construct(
        public ?AiRunKind $kind,
        public ?RunHistoryCursorModel $before,
    ) {
    }

    public static function all(): self
    {
        return new self(null, null);
    }
}
