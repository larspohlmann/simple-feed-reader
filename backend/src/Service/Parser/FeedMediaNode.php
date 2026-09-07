<?php

declare(strict_types=1);

namespace App\Service\Parser;

/**
 * One feed media element — a `<media:content>`, `<media:thumbnail>`, or an
 * `<enclosure>` — wrapped so the extractor reads its URL, kind, dimensions, and
 * enclosure metadata through named questions rather than raw attribute access.
 */
final readonly class FeedMediaNode
{
    private const string MEDIA_NS = 'http://search.yahoo.com/mrss/';

    public function __construct(private \DOMElement $element)
    {
    }

    public function url(): string
    {
        $url = trim($this->element->getAttribute('url'));

        return $url !== '' ? $url : trim($this->element->getAttribute('href'));
    }

    public function kind(): FeedMediaKind
    {
        return FeedMediaClassifier::kind($this->element);
    }

    public function width(): ?int
    {
        return $this->intAttribute('width');
    }

    public function toImage(): ParsedMedium
    {
        return new ParsedMedium($this->url(), VisualMediaKind::Image, $this->width(), $this->intAttribute('height'));
    }

    public function toVideo(?string $previewImageUrl): ParsedMedium
    {
        return new ParsedMedium(
            $this->url(),
            VisualMediaKind::Video,
            $this->width(),
            $this->intAttribute('height'),
            $previewImageUrl,
        );
    }

    public function toAttachment(?int $fallbackDuration): ParsedAttachment
    {
        return new ParsedAttachment(
            $this->url(),
            self::nonEmpty($this->element->getAttribute('type')),
            MediaDuration::seconds($this->element->getAttribute('duration')) ?? $fallbackDuration,
            $this->intAttribute('length') ?? $this->intAttribute('fileSize'),
            $this->title(),
        );
    }

    private function title(): ?string
    {
        foreach ($this->element->childNodes as $child) {
            if (self::isMediaTitle($child)) {
                return self::nonEmpty($child->textContent);
            }
        }

        return null;
    }

    private static function isMediaTitle(\DOMNode $node): bool
    {
        return $node instanceof \DOMElement
            && $node->localName === 'title'
            && $node->namespaceURI === self::MEDIA_NS;
    }

    private function intAttribute(string $name): ?int
    {
        $value = filter_var(trim($this->element->getAttribute($name)), FILTER_VALIDATE_INT);

        return \is_int($value) && $value > 0 ? $value : null;
    }

    private static function nonEmpty(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
