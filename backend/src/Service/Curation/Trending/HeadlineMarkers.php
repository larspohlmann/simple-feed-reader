<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\Model\CleanedHeadlineModel;
use App\Service\Foundation\Stemming\Support\WordStems;

final readonly class HeadlineMarkers
{
    private const int MIN_REST_LENGTH = 3;
    private const string SPACE = '[\s\x{00A0}]';
    private const string OUTLET_SEPARATOR = '/^(.*)\s(?:-|–|—|\||·)\s(.*)$/su';

    private const array LABELS = [
        'en' => ['Live', 'Live updates', 'Liveblog', 'Live blog', 'Breaking', 'Breaking news', 'Exclusive', 'Update',
            'Updated', 'Watch', 'Video', 'Analysis', 'Opinion', 'Explainer'],
        'de' => ['Liveblog', 'Live', 'Liveticker', 'Live-Ticker', 'Ticker', 'Eilmeldung', 'Eil', 'Exklusiv', 'Breaking',
            'Kommentar', 'Analyse', 'Video', 'Update', 'News'],
        'fr' => ['En direct', 'Direct', 'Live', 'Exclusif', 'Info exclusive', 'Alerte info', 'Dernière minute',
            'Urgent', 'Breaking', 'Vidéo', 'Analyse', 'Décryptage', 'Tribune'],
        'es' => ['En directo', 'En vivo', 'Directo', 'Última hora', 'Urgente', 'Exclusiva', 'Exclusivo', 'Breaking',
            'Vídeo', 'Video', 'Análisis', 'Opinión'],
    ];

    private const string EVERY_LANGUAGE = '';

    /** @var array<string, string> the label pattern of each language, and of every list under EVERY_LANGUAGE */
    private array $labelPatterns;

    public function __construct()
    {
        $patterns = [self::EVERY_LANGUAGE => self::labelPattern(array_merge(...array_values(self::LABELS)))];
        foreach (self::LABELS as $language => $labels) {
            $patterns[$language] = self::labelPattern($labels);
        }
        $this->labelPatterns = $patterns;
    }

    public function clean(string $headline, ?string $feedTitle, ?FeedLanguage $language): CleanedHeadlineModel
    {
        $trimmed = trim($headline);
        $withoutLabel = $this->withoutLabel($this->withoutTicker($trimmed), $language);
        $markerFree = $withoutLabel === $trimmed;

        return new CleanedHeadlineModel($this->withoutOutletSuffix($withoutLabel, $feedTitle), $markerFree);
    }

    private function withoutTicker(string $headline): string
    {
        $stripped = preg_replace(['/^\+{2,}' . self::SPACE . '*/u', '/' . self::SPACE . '*\+{2,}$/u'], '', $headline);

        return trim($stripped ?? $headline);
    }

    private function withoutLabel(string $headline, ?FeedLanguage $language): string
    {
        $pattern = $this->labelPatterns[$language->value ?? self::EVERY_LANGUAGE];

        return preg_replace($pattern, '', $headline, 1) ?? $headline;
    }

    /** @param list<string> $labels */
    private static function labelPattern(array $labels): string
    {
        $labels = array_unique($labels);
        usort($labels, static fn (string $left, string $right): int => \strlen($right) <=> \strlen($left));

        return '/^(?:' . implode('|', array_map(static fn (string $label): string => preg_quote($label, '/'), $labels))
            . ')' . self::SPACE . '*:' . self::SPACE . '+/iu';
    }

    private function withoutOutletSuffix(string $headline, ?string $feedTitle): string
    {
        if (null === $feedTitle || 1 !== preg_match(self::OUTLET_SEPARATOR, $headline, $parts)) {
            return $headline;
        }

        $rest = trim($parts[1]);
        if (mb_strlen($rest) < self::MIN_REST_LENGTH || !$this->isOutletName($parts[2], $feedTitle)) {
            return $headline;
        }

        return $rest;
    }

    private function isOutletName(string $tail, string $feedTitle): bool
    {
        $tailWords = $this->bareWords($tail);
        $outletWords = $this->bareWords($feedTitle);

        return [] !== array_filter($tailWords, WordStems::isKept(...))
            && [] === array_diff($tailWords, $outletWords);
    }

    /** @return list<string> */
    private function bareWords(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_map(WordStems::bare(...), $words === false ? [] : $words);
    }
}
