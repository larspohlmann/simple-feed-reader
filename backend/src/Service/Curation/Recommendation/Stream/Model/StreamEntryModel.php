<?php

declare(strict_types=1);

namespace App\Service\Curation\Recommendation\Stream\Model;

use App\Enum\FeedLanguage;

final readonly class StreamEntryModel
{
    public function __construct(
        public int $id,
        public ?FeedLanguage $language,
        public string $title,
        public ?string $summary,
    ) {
    }
}
