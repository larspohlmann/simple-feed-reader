<?php

declare(strict_types=1);

namespace App\Service\Library\Opml\Exception;

final class ConflictingTagChoiceException extends \DomainException
{
    /** @param list<string> $feedUrls */
    public static function forFeeds(array $feedUrls): self
    {
        return new self('A feed cannot both replace and add tags: ' . implode(', ', $feedUrls));
    }
}
