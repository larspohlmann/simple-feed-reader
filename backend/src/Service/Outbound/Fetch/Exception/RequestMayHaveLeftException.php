<?php

declare(strict_types=1);

namespace App\Service\Outbound\Fetch\Exception;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/** A write's transport failed after its bytes may have reached the server, so it may have been acted on. */
final class RequestMayHaveLeftException extends \RuntimeException implements TransportExceptionInterface
{
}
