<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * The client-facing form of a FeedPreviewException. The controller rethrows the
 * service's internal failure as this so ApiExceptionListener renders a proper
 * problem+json document — carrying the extractor's own diagnosis ("No article
 * list was detected on the page.") or the feed-parse message as $detail, rather
 * than the opaque, message-less shape a bare UnprocessableEntityHttpException
 * produced.
 */
final class FeedPreviewApiException extends ApiException
{
    public function __construct(string $detail, ?\Throwable $previous = null)
    {
        parent::__construct(
            'feed_preview_failed',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Feed preview failed',
            $detail,
            [],
            $previous,
        );
    }
}
