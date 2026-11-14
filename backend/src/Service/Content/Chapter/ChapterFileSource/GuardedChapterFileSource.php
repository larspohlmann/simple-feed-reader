<?php

declare(strict_types=1);

namespace App\Service\Content\Chapter\ChapterFileSource;

use App\Service\Content\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Content\Chapter\Support\JsonChapters;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Exception\ResponseTooLargeException;
use App\Service\Outbound\Fetch\Exception\SsrfBlockedException;
use App\Service\Outbound\Fetch\GuardedApiClient;
use App\Service\Outbound\Fetch\Model\ApiBudgetModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ResponseSizeLimit;

/** Runs inside the entry request, so its budget is short. */
final readonly class GuardedChapterFileSource implements ChapterFileSourceInterface
{
    public const float BUDGET_SECONDS = 3.0;

    public function __construct(private GuardedApiClient $api)
    {
    }

    public function chaptersAt(string $url): array
    {
        try {
            $response = $this->api->sendWithin(
                ApiRequestModel::get($url),
                new ApiBudgetModel(self::BUDGET_SECONDS, self::BUDGET_SECONDS, ResponseSizeLimit::ChapterFile),
            );
        } catch (SsrfBlockedException | ResponseTooLargeException) {
            return [];
        } catch (FetchException $exception) {
            throw ChapterFileUnreachableException::from($url, $exception);
        }

        if ($response->isTransientFailure()) {
            throw ChapterFileUnreachableException::answered($url, $response->status);
        }

        return $response->isSuccessful() ? JsonChapters::from($response->json()) : [];
    }
}
