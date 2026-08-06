<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The client-facing form of the Service\Ai refusals. One type for all of them,
 * because the client's move is the same in every case — show the message and
 * let the account correct the form — while `detail` carries which of "check
 * the address", "check the key" or "pick another model" applies.
 */
final class AiProviderApiException extends ApiException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_provider_rejected',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'The AI provider could not be used',
            $detail,
            [],
            $previous,
        );
    }
}
