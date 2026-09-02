<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Source;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\EmbedTarget;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageFurniture;
use App\Service\Reader\Media\PageTextBlocks;
use Dom\Element;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A publisher's own player declares its video as a bare id in `data-video-id`,
 * with no URL on the page. The id alone is ambiguous (Brightcove, Vimeo use the
 * same attribute), so the element itself must also name YouTube.
 */
#[AsTaggedItem(priority: 55)]
final readonly class YouTubeIdAttributeSource implements MediaCandidateSourceInterface
{
    private const string VIDEO_ID_ATTRIBUTE = 'data-video-id';
    private const string ID_PATTERN = '#^[A-Za-z0-9_-]{11}$#';
    private const string YOUTUBE_MARKER = '#youtube|(?:^|[^a-z])yt[-_]#i';

    public function __construct(private EmbedProviders $providers)
    {
    }

    public function find(string $pageHtml, string $pageUrl): array
    {
        $document = HtmlDocumentParser::parseOrNull($pageHtml);
        if ($document === null) {
            return [];
        }

        $blocks = PageTextBlocks::fromDocument($document);
        $found = [];
        foreach ($document->querySelectorAll('[' . self::VIDEO_ID_ATTRIBUTE . ']') as $element) {
            $target = $this->targetOf($element);
            if ($target !== null) {
                $found[$target->url] ??= new MediaCandidate(
                    MediaKind::Embed,
                    $target->url,
                    $target->posterUrl,
                    $target->label,
                    $blocks->before($element),
                );
            }
        }

        return array_values($found);
    }

    private function targetOf(Element $element): ?EmbedTarget
    {
        $id = $element->getAttribute(self::VIDEO_ID_ATTRIBUTE) ?? '';
        if (PageFurniture::holds($element)) {
            return null;
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1 || !$this->namesYouTube($element)) {
            return null;
        }

        return $this->providers->resolve('https://www.youtube.com/watch?v=' . $id);
    }

    private function namesYouTube(Element $element): bool
    {
        $ownMarkup = $element->localName;
        foreach ($element->attributes as $attribute) {
            $ownMarkup .= ' ' . $attribute->name . '=' . $attribute->value;
        }

        return preg_match(self::YOUTUBE_MARKER, $ownMarkup) === 1;
    }
}
