<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Service\Text\Support\Whitespace;

/**
 * Fails a confident-but-wrong extraction: when a substantial feed body and the extraction share almost no wording,
 * readability grabbed page furniture, and the endpoint falls back to the feed body. The word-shingle measure is
 * blunt on purpose: a right extraction scores near 1, a wrong one near 0, so no finely tuned threshold decides.
 */
final readonly class ExtractionCoverageGate
{
    /**
     * Below this many characters the feed body is a teaser or an editorial
     * summary, not a full article; the reader is trusted to add the real body
     * and the gate stands aside.
     */
    private const int SUBSTANTIAL_FEED_LENGTH = 1000;

    /** Consecutive words per shingle — long enough that unrelated text rarely collides. */
    private const int SHINGLE_SIZE = 4;

    /** Below this share of the feed article's shingles, the extraction is not that article. */
    private const float MIN_COVERAGE = 0.25;

    public function verify(ExtractionResultModel $result, ?string $feedContentHtml): ExtractionResultModel
    {
        if (!$result->ok || $feedContentHtml === null) {
            return $result;
        }

        $feedText = $this->plainText($feedContentHtml);
        if (mb_strlen($feedText) < self::SUBSTANTIAL_FEED_LENGTH) {
            return $result;
        }

        $feedShingles = $this->shingles($feedText);
        if ($feedShingles === []) {
            return $result;
        }

        $extractionShingles = $this->shingles($this->plainText((string) $result->contentHtml));
        if ($this->coverage($feedShingles, $extractionShingles) >= self::MIN_COVERAGE) {
            return $result;
        }

        return ExtractionResultModel::failed($result->url, ExtractionFailure::Mismatch);
    }

    /**
     * @param array<string, true> $feedShingles
     * @param array<string, true> $extractionShingles
     */
    private function coverage(array $feedShingles, array $extractionShingles): float
    {
        $present = 0;
        foreach (array_keys($feedShingles) as $shingle) {
            if (isset($extractionShingles[$shingle])) {
                ++$present;
            }
        }

        return $present / count($feedShingles);
    }

    /** @return array<string, true> */
    private function shingles(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $lastStart = count($words) - self::SHINGLE_SIZE;
        $shingles = [];
        for ($start = 0; $start <= $lastStart; ++$start) {
            $shingles[implode(' ', array_slice($words, $start, self::SHINGLE_SIZE))] = true;
        }

        return $shingles;
    }

    private function plainText(string $html): string
    {
        $body = HtmlDocumentParser::parseOrEmpty($html)->body;
        $text = $body === null
            ? html_entity_decode(strip_tags($html))
            : (string) $body->textContent;

        return Whitespace::collapse($text);
    }
}
