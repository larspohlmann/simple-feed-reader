<?php

declare(strict_types=1);

namespace App\Dto\Me;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateLocaleRequest
{
    /**
     * The languages the UI ships translations for. An unsupported value is a
     * 422 rather than a silent fall back to English: a locale that degrades
     * quietly is exactly how User.locale went unwritten and unnoticed since
     * registration (#180).
     */
    public const array SUPPORTED = ['en', 'de'];

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: self::SUPPORTED)]
        public string $locale = '',
    ) {
    }
}
