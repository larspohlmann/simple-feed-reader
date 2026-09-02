<?php

declare(strict_types=1);

namespace App\Service\Reader\Paywall;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Captured before readability consumes the shared document, judged when the
 * cleaned body exists (#785). Declaration decides alone; a DOM block counts
 * only at or after the last extracted paragraph.
 */
final readonly class PaywallSignals
{
    /** @param list<string> $blockTexts */
    private function __construct(
        private ?bool $declared,
        private array $blockTexts,
        private string $pageText,
    ) {
    }

    public static function fromPage(string $html, ?HTMLDocument $normalized): self
    {
        return new self(
            SchemaOrgAccess::paywalledIn($html),
            $normalized === null ? [] : PaywallBlocks::textsIn($normalized),
            SqueezedText::of((string) ($normalized?->body->textContent ?? '')),
        );
    }

    /** True when the cleaned body is the free preview of a paywalled article. */
    public function isPreview(string $cleanedBodyHtml): bool
    {
        if ($this->declared !== null) {
            return $this->declared;
        }
        $body = HtmlDocumentParser::parseOrNull($cleanedBodyHtml)?->body;
        if ($this->blockTexts === [] || $body === null) {
            return false;
        }

        $anchor = $this->lastProsePosition($body);
        $bodyText = SqueezedText::of((string) $body->textContent);
        foreach ($this->blockTexts as $blockText) {
            if ($this->standsBelowThePreview($blockText, $anchor, $bodyText)) {
                return true;
            }
        }

        return false;
    }

    /** Where the body's last prose paragraph stands in the page text, or null when it cannot be found there. */
    private function lastProsePosition(Element $body): ?int
    {
        foreach (array_reverse(iterator_to_array($body->getElementsByTagName('p'))) as $paragraph) {
            $text = SqueezedText::of((string) $paragraph->textContent);
            if ($text === '' || $this->isPartOfAPaywallBlock($text)) {
                continue;
            }
            $position = mb_strrpos($this->pageText, $text);

            return $position === false ? null : $position;
        }

        return null;
    }

    private function isPartOfAPaywallBlock(string $paragraphText): bool
    {
        foreach ($this->blockTexts as $blockText) {
            if (str_contains($blockText, $paragraphText)) {
                return true;
            }
        }

        return false;
    }

    /** With no anchor to measure against, a block the extraction dropped is the signal. */
    private function standsBelowThePreview(string $blockText, ?int $anchor, string $bodyText): bool
    {
        if ($anchor === null) {
            return !str_contains($bodyText, $blockText);
        }
        $position = mb_strrpos($this->pageText, $blockText);

        return $position !== false && $position >= $anchor;
    }
}
