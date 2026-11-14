<?php

declare(strict_types=1);

namespace App\Service\Parser\Support;

use App\Entity\EntryTranscript;
use App\Enum\TranscriptFormat;
use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Model\ParsedTranscriptModel;
use App\Service\Parser\Pass\CoreElement;

final class DeclaredTranscripts
{
    public static function of(CoreElement $item): ?ParsedTranscriptModel
    {
        $best = null;
        foreach ($item->in(FeedNamespace::Podcast)->children('transcript') as $element) {
            $candidate = self::candidate($element);
            if ($candidate !== null && self::isBetter($best, $candidate)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private static function isBetter(?ParsedTranscriptModel $best, ParsedTranscriptModel $candidate): bool
    {
        return $best === null || $candidate->format->preference() < $best->format->preference();
    }

    private static function candidate(\DOMElement $element): ?ParsedTranscriptModel
    {
        $url = trim($element->getAttribute('url'));
        $format = TranscriptFormat::fromMimeType($element->getAttribute('type'));
        if ($url === '' || $format === null) {
            return null;
        }

        $language = mb_substr(trim($element->getAttribute('language')), 0, EntryTranscript::LANGUAGE_MAX_LENGTH);

        return new ParsedTranscriptModel($url, $format, $language === '' ? null : $language);
    }

    private function __construct()
    {
    }
}
