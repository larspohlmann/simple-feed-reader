<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

final class TagNameTakenException extends ApiException
{
    public function __construct(?string $detail = null)
    {
        parent::__construct('tag_name_taken', Response::HTTP_CONFLICT, 'Tag name already in use', $detail);
    }
}
