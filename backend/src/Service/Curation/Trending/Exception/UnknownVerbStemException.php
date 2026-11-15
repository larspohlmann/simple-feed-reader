<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Exception;

final class UnknownVerbStemException extends \InvalidArgumentException
{
    public static function forStem(string $presentStem): self
    {
        return new self(sprintf('"%s" is not a German verb stem.', $presentStem));
    }
}
