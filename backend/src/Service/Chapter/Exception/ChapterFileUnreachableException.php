<?php

declare(strict_types=1);

namespace App\Service\Chapter\Exception;

use App\Service\Fetch\Exception\FetchException;

/** The chapters file did not answer; the next open of the episode tries again. */
final class ChapterFileUnreachableException extends \RuntimeException
{
    public static function from(string $url, FetchException $exception): self
    {
        return new self(sprintf('Chapters file %s did not answer.', $url), 0, $exception);
    }

    public static function answered(string $url, int $status): self
    {
        return new self(sprintf('Chapters file %s answered %d.', $url, $status));
    }
}
