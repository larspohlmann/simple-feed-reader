<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Language\Model;

use App\Enum\FeedLanguage;

final readonly class LanguageVerdictModel
{
    public function __construct(
        public ?FeedLanguage $language,
    ) {
    }
}
