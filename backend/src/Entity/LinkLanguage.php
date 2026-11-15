<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FeedLanguage;
use App\Enum\LanguageSource;

final readonly class LinkLanguage
{
    public function __construct(
        public FeedLanguage $language,
        public LanguageSource $source,
    ) {
    }

    public static function stated(FeedLanguage $language): self
    {
        return new self($language, LanguageSource::Stated);
    }

    public static function guessed(FeedLanguage $language): self
    {
        return new self($language, LanguageSource::Guessed);
    }
}
