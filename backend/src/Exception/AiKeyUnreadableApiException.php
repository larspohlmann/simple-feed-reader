<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The stored key cannot be decrypted — a rotated AI_KEY_SECRET, an edited row,
 * a row moved between accounts.
 *
 * A 422 rather than the opaque 500 an uncaught throw would produce: the account
 * can act on this by re-entering the key, and without this mapping a single
 * unreadable row makes every read of the model list fail with no hint how to
 * recover.
 *
 * A type of its own, not one of the ai_provider_rejected refusals: those are
 * worth retrying once the form is corrected, this one never is, and the client
 * must tell them apart by type alone — sharing the type would force the
 * frontend to recognise this by prefix-matching the sentence below, so
 * rewording it would silently change what the account was told to do.
 *
 * The detail is fixed and says nothing about the cause: which of the three
 * happened is operational detail, and the cipher's own message describes the
 * stored material — neither belongs in a problem document. $previous still
 * carries the cause into the log.
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
