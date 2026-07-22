<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class InvalidOpmlException extends ApiException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct(
            'invalid_opml',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'The OPML document could not be parsed',
            $detail,
        );
    }
}
