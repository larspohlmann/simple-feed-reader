<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Enum\SupportedLocale;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An unsupported value is a 422 rather than a silent fall back to English: a
 * locale that degrades quietly is exactly how User.locale went unwritten and
 * unnoticed since registration (#180).
 */
final readonly class UpdateLocaleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: SupportedLocale::ALL)]
        public string $locale = '',
    ) {
    }
}
