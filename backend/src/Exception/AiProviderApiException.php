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

    /**
     * The stored key cannot be decrypted — a rotated AI_KEY_SECRET, an edited
     * row, a row moved between accounts.
     *
     * A 422 rather than the opaque 500 an uncaught throw would produce: the
     * account can act on this one, by entering the key again, and without this
     * mapping a single unreadable row makes every read of the model list fail
     * with nothing to tell the account that re-entering the key is the way out.
     *
     * The detail is fixed and says nothing about the cause. Which of the three
     * happened is server-side operational detail, and the cipher's own message
     * describes the stored material — neither belongs in a problem document.
     * $previous still carries the cause into the log.
     */
    public static function forUnreadableStoredKey(\Throwable $previous): self
    {
        return new self('The stored API key can no longer be read. Enter it again.', $previous);
    }
}
