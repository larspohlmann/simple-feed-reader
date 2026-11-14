<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Support\RetryAfterHeader;
use Symfony\Component\HttpFoundation\Response;

/** A 429: still an unreachable network to a caller that cannot wait, a schedule to the refresh that can. */
final class SocialNetworkThrottledException extends SocialNetworkUnreachableException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @throws self when the network answered 429 */
    public static function throwIfThrottled(ApiResponseModel $answer, string $message): void
    {
        if (Response::HTTP_TOO_MANY_REQUESTS !== $answer->status) {
            return;
        }

        throw new self($message, RetryAfterHeader::seconds($answer->header('retry-after')));
    }
}
