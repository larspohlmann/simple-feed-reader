<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\MediaCandidateSource;

use App\Service\Html\Support\MetaTags;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\RawPageModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A publisher's Open Graph and Twitter Player meta tags. `og:video` and `og:audio` often name a player page, not a
 * file, so every value goes through MediaUrlKind and only playable media is emitted.
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
        $meta = MetaTags::contentByKey($page->document);
        $found = [];
        foreach (self::PROPERTIES as $property) {
            $resolved = isset($meta[$property]) ? $this->mediaUrlKind->resolve($meta[$property]) : null;
            if ($resolved !== null) {
                $found[$resolved->url] = new MediaCandidateModel($resolved->kind, $resolved->url);
            }
        }

        return array_values($found);
    }
}
