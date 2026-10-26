<?php

declare(strict_types=1);

namespace App\Service\Chapter\Exception;

use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Exception\FetchException;

/** The provider did not answer for the embed; nothing is cached, the next open asks again. */
final class EmbedDescriptionUnreachableException extends \RuntimeException
{
    public static function from(EmbedAddressModel $embed, FetchException $exception): self
    {
        return new self(
            sprintf('The %s description of %s did not answer.', $embed->provider->value, $embed->id),
            0,
            $exception,
        );
    }

    public static function answered(EmbedAddressModel $embed, int $status): self
    {
        return new self(sprintf('The %s description of %s answered %d.', $embed->provider->value, $embed->id, $status));
    }
}
