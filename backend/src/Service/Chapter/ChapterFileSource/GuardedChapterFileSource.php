<?php

declare(strict_types=1);

namespace App\Service\Chapter\ChapterFileSource;

use App\Service\Chapter\Exception\ChapterFileUnreachableException;
use App\Service\Chapter\Support\JsonChapters;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiBudgetModel;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ResponseSizeLimit;

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

        if ($response->isServerError()) {
            throw ChapterFileUnreachableException::answered($url, $response->status);
        }

        return $response->isSuccessful() ? JsonChapters::from($response->json()) : [];
    }
}
