<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The stored key cannot be decrypted — a rotated AI_KEY_SECRET, an edited row,
 * a row moved between accounts.
 *
 * A 422 rather than the opaque 500 an uncaught throw would produce: the account
 * can act on this one, by entering the key again, and without this mapping a
 * single unreadable row makes every read of the model list fail with nothing to
 * tell the account that re-entering the key is the way out.
 *
 * A type of its own, not one of the ai_provider_rejected refusals: those are
 * worth retrying once the form is corrected, this one never is, and the client
 * has to be able to tell them apart by the type alone. Sharing the type forced
 * the frontend to recognise this failure by prefix-matching the sentence below,
 * so rewording it silently changed what the account was told to do.
 *
 * The detail is fixed and says nothing about the cause. Which of the three
 * happened is server-side operational detail, and the cipher's own message
 * describes the stored material — neither belongs in a problem document.
 * $previous still carries the cause into the log.
 */
final class AiKeyUnreadableApiException extends ApiException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'ai_key_unreadable',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'The stored API key could not be read',
            'The stored API key can no longer be read. Enter it again.',
            [],
            $previous,
        );
    }
}
