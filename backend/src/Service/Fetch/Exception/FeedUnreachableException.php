<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

final class FeedUnreachableException extends FetchException
{
    public function __construct(string $message, public readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
