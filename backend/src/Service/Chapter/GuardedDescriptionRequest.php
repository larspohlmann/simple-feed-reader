<?php

declare(strict_types=1);

namespace App\Service\Chapter;

use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiBudgetModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ResponseSizeLimit;

/** One budgeted, size-capped request to a description provider (#1824). */
final readonly class GuardedDescriptionRequest
{
    public const float BUDGET_SECONDS = 3.0;

    public function __construct(private GuardedApiClient $api)
    {
    }

    /** @return array<mixed> */
    public function answerOf(ApiRequestModel $request, EmbedAddressModel $embed): array
    {
        try {
            $response = $this->api->sendWithin(
                $request,
                new ApiBudgetModel(self::BUDGET_SECONDS, self::BUDGET_SECONDS, ResponseSizeLimit::EmbedDescription),
            );
        } catch (SsrfBlockedException | ResponseTooLargeException) {
            return [];
        } catch (FetchException $exception) {
            throw EmbedDescriptionUnreachableException::from($embed, $exception);
        }
        if ($response->isTransientFailure()) {
            throw EmbedDescriptionUnreachableException::answered($embed, $response->status);
        }

        return $response->isSuccessful() ? $response->json() : [];
    }
}
