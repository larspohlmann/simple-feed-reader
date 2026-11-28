<?php

declare(strict_types=1);

namespace App\Service\Curation\YourStream\Model;

use App\Enum\FeedLanguage;

final readonly class StreamEntryModel
{
    public const int SUMMARY_CHARACTERS = 2000;

    public function __construct(
        public int $id,
        public ?FeedLanguage $language,
        public string $title,
        public ?string $summary,
    ) {
    }
}
