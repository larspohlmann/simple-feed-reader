<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;

/**
 * Guards against a confident-but-wrong extraction. Readability sometimes picks
 * page furniture over the article — a shop banner, a "related posts" list, a
 * repeated promo block — and returns it as a successful result that clears
 * every length check yet is not the article at all (#654: an Ankerherz
 * Shopify blog where even vanilla readability never finds the story).
 *
 * The feed's own article body is the ground truth: when that body is a full
 * article, not a truncated teaser (the case the reader exists to improve on),
 * a real extraction of the same page shares its wording. So when a
 * substantial feed body and the extraction share almost no text, the
 * extraction grabbed the wrong thing — this gate fails it and the endpoint
 * falls back to the feed content the client already trusts.
 *
 * The measure is word-shingle coverage, blunt on purpose: a correct
 * extraction scores near 1, a wrong one near 0, so the verdict never rides on
 * a finely tuned threshold.
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

    public function verify(ExtractionResult $result, ?string $feedContentHtml): ExtractionResult
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

        return ExtractionResult::failed($result->url, 'mismatch');
    }

    /**
     * @param array<string, true> $feedShingles
     * @param array<string, true> $extractionShingles
     */
    private function coverage(array $feedShingles, array $extractionShingles): float
    {
        $present = 0;
        foreach ($feedShingles as $shingle => $_) {
            if (isset($extractionShingles[$shingle])) {
                ++$present;
            }
        }

        return $present / count($feedShingles);
    }

    /** @return array<string, true> the distinct word shingles, as a lookup set */
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
        $body = HtmlDocumentParser::parseOrNull($html)?->body;
        $text = $body === null
            ? html_entity_decode(strip_tags($html))
            : (string) $body->textContent;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
