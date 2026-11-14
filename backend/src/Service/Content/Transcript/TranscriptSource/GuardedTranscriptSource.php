<?php

declare(strict_types=1);

namespace App\Service\Content\Transcript\TranscriptSource;

use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Exception\ResponseTooLargeException;
use App\Service\Outbound\Fetch\Exception\SsrfBlockedException;
use App\Service\Outbound\Fetch\Exception\UnresolvableHostException;
use App\Service\Outbound\Fetch\GuardedApiClient;
use App\Service\Outbound\Fetch\Model\ApiBudgetModel;
use App\Service\Outbound\Fetch\Model\ApiRequestModel;
use App\Service\Outbound\Fetch\Model\ResponseSizeLimit;
use App\Service\Content\Transcript\Exception\TranscriptUnreachableException;
use App\Service\Content\Transcript\Exception\TranscriptUnusableException;

final readonly class GuardedTranscriptSource implements TranscriptSourceInterface
{
    public function __construct(private GuardedApiClient $api)
    {
    }

    public function bodyAt(string $url, float $budgetSeconds): string
    {
        try {
            $response = $this->api->sendWithin(
                ApiRequestModel::get($url, ['Accept' => '*/*']),
                new ApiBudgetModel($budgetSeconds, $budgetSeconds, ResponseSizeLimit::TranscriptFile),
            );
        } catch (UnresolvableHostException $exception) {
            throw TranscriptUnreachableException::from($url, $exception);
        } catch (SsrfBlockedException | ResponseTooLargeException $exception) {
            throw TranscriptUnusableException::refused($url, $exception);
        } catch (FetchException $exception) {
            throw TranscriptUnreachableException::from($url, $exception);
        }

        if ($response->isTransientFailure()) {
            throw TranscriptUnreachableException::answered($url, $response->status);
        }
        if (!$response->isSuccessful()) {
            throw TranscriptUnusableException::answered($url, $response->status);
        }

        return $response->body;
    }
}
