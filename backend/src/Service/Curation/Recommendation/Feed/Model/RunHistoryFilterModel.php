<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Feed\Model;

use App\Enum\AiRunKind;

/** Which runs a history page reads: one kind or, when the kind is null, every kind; older than the cursor if set. */
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
