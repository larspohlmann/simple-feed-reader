<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\RawPageModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A publisher's Open Graph and Twitter Player meta tags. `og:video` and
 * `og:audio` commonly point at a player PAGE rather than a file — ARD's
 * `og:video` is `…~player.html` — so every value goes through MediaUrlKind
 * and only what it recognises as playable media is emitted.
 */
#[AsTaggedItem(priority: 90)]
final readonly class MetaMediaSource implements MediaCandidateSourceInterface
{
    private const array PROPERTIES = [
        'og:audio',
        'og:audio:secure_url',
        'og:video:secure_url',
        'og:video',
        'twitter:player:stream',
    ];

    public function __construct(private MediaUrlKind $mediaUrlKind)
    {
    }

    public function find(RawPageModel $page): array
    {
        $found = [];
        foreach (self::PROPERTIES as $property) {
            $candidate = $this->candidateFor($page->document, $property);
            if ($candidate !== null) {
                $found[$candidate->url] = $candidate;
            }
        }

        return array_values($found);
    }

    private function candidateFor(\Dom\HTMLDocument $document, string $property): ?MediaCandidateModel
    {
        $url = $this->content($document, $property);
        $resolved = $url === null ? null : $this->mediaUrlKind->resolve($url);

        return $resolved === null ? null : new MediaCandidateModel($resolved->kind, $resolved->url);
    }

    private function content(\Dom\HTMLDocument $document, string $property): ?string
    {
        $element = $document->querySelector(\sprintf('meta[property="%1$s"], meta[name="%1$s"]', $property));

        return $element?->getAttribute('content');
    }
}
