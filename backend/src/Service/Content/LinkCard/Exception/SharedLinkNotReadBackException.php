<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Exception;

final class SharedLinkNotReadBackException extends \RuntimeException
{
    public static function forUrl(string $url): self
    {
        return new self(sprintf(
            'The shared link for "%s" was inserted or already stored, but could not be read back; a caller inside a'
            . ' transaction does not see another writer\'s row.',
            $url,
        ));
    }
}
