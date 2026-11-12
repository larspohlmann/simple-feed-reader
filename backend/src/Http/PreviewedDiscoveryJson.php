<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;

final class PreviewedDiscoveryJson
{
    /** @return array<string, mixed> */
    public static function of(PreviewedDiscoveryModel $found): array
    {
        $payload = ['candidates' => array_map(self::candidate(...), $found->candidates)];
        if (null !== $found->scrapeFailureReason) {
            $payload['scrapeFailureReason'] = $found->scrapeFailureReason->value;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private static function candidate(PreviewedCandidateModel $previewed): array
    {
        $candidate = $previewed->candidate;
        $json = ['url' => $candidate->url, 'title' => $candidate->title, 'format' => $candidate->format];
        if (null !== $previewed->preview) {
            $json['preview'] = FeedPreviewJson::preview($previewed->preview);
        }

        if (null !== $candidate->noPreviewReason) {
            $json['noPreviewReason'] = $candidate->noPreviewReason->value;
        }

        return $json;
    }
}
