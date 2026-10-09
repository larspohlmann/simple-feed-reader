<?php

declare(strict_types=1);

namespace App\Service\Ingest\PlatformEntryRule;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Model\EmbedTargetModel;
use Dom\HTMLDocument;

final readonly class VideoPageEntryRule implements PlatformEntryRuleInterface
{
    private const string EMBEDDING_ELEMENTS = 'a[href], iframe[src], embed[src]';

    public function __construct(
        private EmbedProviders $embeds,
        private MediaMarkup $markup,
    ) {
    }

    public function supports(ParsedEntryModel $entry): bool
    {
        $target = $this->target($entry);

        return $target !== null
            && !($entry->media->mediaBundle?->isEpisode() ?? false)
            && !$this->bodyEmbeds($entry->contentHtml ?? '', $target);
    }

    public function apply(ParsedEntryModel $entry): ParsedEntryModel
    {
        $target = $this->target($entry);
        if ($target === null) {
            return $entry;
        }

        return $entry->withContentHtml($this->playerLink($target) . ($entry->contentHtml ?? ''));
    }

    private function target(ParsedEntryModel $entry): ?EmbedTargetModel
    {
        return $entry->url === null ? null : $this->embeds->resolve($entry->url);
    }

    private function bodyEmbeds(string $body, EmbedTargetModel $target): bool
    {
        if (trim($body) === '') {
            return false;
        }

        foreach (HtmlDocumentParser::parseFragment($body)->querySelectorAll(self::EMBEDDING_ELEMENTS) as $element) {
            $source = $element->getAttribute('href') ?? $element->getAttribute('src') ?? '';
            if ($this->embeds->resolve($source)?->url === $target->url) {
                return true;
            }
        }

        return false;
    }

    private function playerLink(EmbedTargetModel $target): string
    {
        $document = HTMLDocument::createEmpty();

        return $document->saveHtml($this->markup->embedLink($document, $target));
    }
}
