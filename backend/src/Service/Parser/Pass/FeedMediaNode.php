<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\ParsedAttachmentModel;
use App\Service\Parser\Model\ParsedMediumModel;
use App\Service\Parser\Model\VisualMediaKind;
use App\Service\Parser\Support\DeclaredImages;
use App\Service\Parser\Support\FeedMediaClassifier;
use App\Service\Parser\Support\MediaDuration;
use App\Service\Parser\Support\XmlHelper;

/**
 * One feed media element — a `<media:content>`, `<media:thumbnail>`, or an
 * `<enclosure>` — wrapped so the extractor reads its URL, kind, dimensions, and
 * enclosure metadata through named questions rather than raw attribute access.
 */
final readonly class FeedMediaNode
{
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

    public function toImage(): ParsedMediumModel
    {
        return new ParsedMediumModel(
            $this->url(),
            VisualMediaKind::Image,
            $this->width(),
            $this->intAttribute('height'),
        );
    }

    public function toVideo(?string $previewImageUrl): ParsedMediumModel
    {
        return new ParsedMediumModel(
            $this->url(),
            VisualMediaKind::Video,
            $this->width(),
            $this->intAttribute('height'),
            $previewImageUrl,
        );
    }

    public function toAttachment(?int $fallbackDuration): ParsedAttachmentModel
    {
        return new ParsedAttachmentModel(
            $this->url(),
            $this->kind(),
            self::nonEmpty($this->element->getAttribute('type')),
            MediaDuration::seconds($this->element->getAttribute('duration')) ?? $fallbackDuration,
            $this->intAttribute('length') ?? $this->intAttribute('fileSize'),
            $this->title(),
        );
    }

    private function title(): ?string
    {
        $title = XmlHelper::childElement($this->element, 'title', XmlHelper::MEDIA_RSS_NAMESPACE);

        return $title === null ? null : self::nonEmpty($title->textContent);
    }

    private function intAttribute(string $name): ?int
    {
        return DeclaredImages::positiveDimension($this->element->getAttribute($name));
    }

    private static function nonEmpty(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
