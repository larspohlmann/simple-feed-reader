<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Enum\SupportedLocale;
use Symfony\Component\Validator\Constraints as Assert;

/** An unsupported value is a 422, never a quiet fall back to English that nobody would notice. */
final readonly class UpdateLocaleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: SupportedLocale::ALL)]
        public string $locale = '',
    ) {
    }
}
