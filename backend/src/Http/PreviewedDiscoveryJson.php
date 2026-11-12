<?php

declare(strict_types=1);

namespace App\Http;

use App\Enum\SourceFormat;
use App\Service\Parser\Model\FeedSyntax;
use App\Service\Preview\Model\PreviewedCandidateModel;
use App\Service\Preview\Model\PreviewedDiscoveryModel;

final class PreviewedDiscoveryJson
{
    private const array FORMAT_LABELS = [
        FeedSyntax::Rss->value => 'RSS',
        FeedSyntax::Atom->value => 'Atom',
        SourceFormat::WP_JSON => 'WordPress',
        SourceFormat::MIXCLOUD_API => 'Mixcloud',
        SourceFormat::BLUESKY_API => 'Bluesky',
        '' => 'Feed',
    ];

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
        $json = [
            'url' => $candidate->url,
            'title' => $candidate->title,
            'format' => $candidate->format,
            'label' => self::labelOf($candidate->format),
        ];
        if (SourceFormat::isVerbatim($candidate->format)) {
            $json['storedFormat'] = $candidate->format;
        }

        if (null !== $previewed->preview) {
            $json['preview'] = FeedPreviewJson::preview($previewed->preview);
        }

        if (null !== $candidate->noPreviewReason) {
            $json['noPreviewReason'] = $candidate->noPreviewReason->value;
        }

        return $json;
    }

    private static function labelOf(string $format): string
    {
        return self::FORMAT_LABELS[$format] ?? ucfirst($format);
    }
}
