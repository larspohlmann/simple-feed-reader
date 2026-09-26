<?php

declare(strict_types=1);

namespace App\Service\Mail\Digest\Exception;

final class TestDigestUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Mail is unavailable for this account.');
    }
}
