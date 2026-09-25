<?php

declare(strict_types=1);

namespace App\Service\Opml\Exception;

final class InvalidOpmlException extends \RuntimeException
{
    /** @param non-empty-string $message OpmlProblems sends it verbatim as the problem detail */
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
