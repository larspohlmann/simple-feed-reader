<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Exception;

final class UnhashableLinkException extends \LogicException
{
    public static function forUrl(string $url): self
    {
        return new self(sprintf('A card URL that is no absolute web address has no shared link: "%s".', $url));
    }
}
