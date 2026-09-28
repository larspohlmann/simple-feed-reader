<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Subscription\SubscribeOutcome;

final class SubscribeOutcomeJson
{
    /** @return array<string, mixed> */
    public static function candidates(SubscribeOutcome $outcome): array
    {
        $payload = [
            'candidates' => array_map(
                static fn (FeedCandidateModel $candidate): array => [
                    'url' => $candidate->url,
                    'title' => $candidate->title,
                    'format' => $candidate->format,
                ],
                $outcome->candidates,
            ),
        ];
        if (null !== $outcome->scrapeFailureReason) {
            $payload['scrapeFailureReason'] = $outcome->scrapeFailureReason->value;
        }

        return $payload;
    }
}
