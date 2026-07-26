<?php

declare(strict_types=1);

namespace App\Service\Fetch;

enum HeaderDecision
{
    /** 2xx: headers are fine, the body is still arriving. */
    case AwaitBody;
    /** 304: the exchange is over, no body will come. */
    case Terminal;
    /** 3xx: follow the Location header. */
    case Redirect;
}
