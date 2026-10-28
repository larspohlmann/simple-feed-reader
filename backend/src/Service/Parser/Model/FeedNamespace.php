<?php

declare(strict_types=1);

namespace App\Service\Parser\Model;

enum FeedNamespace: string
{
    case Atom10 = 'http://www.w3.org/2005/Atom';
    case Atom03 = 'http://purl.org/atom/ns#';
    case Rss1 = 'http://purl.org/rss/1.0/';
    case Rdf = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    case DublinCore = 'http://purl.org/dc/elements/1.1/';
    case Content = 'http://purl.org/rss/1.0/modules/content/';
    case WellFormedWeb = 'http://wellformedweb.org/CommentAPI/';
    case MediaRss = 'http://search.yahoo.com/mrss/';
    case Itunes = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    case Podcast = 'https://podcastindex.org/namespace/1.0';
    case PodloveSimpleChapters = 'http://podlove.org/simple-chapters';
    case GooglePlay = 'http://www.google.com/schemas/play-podcasts/1.0';

    public function conventionalPrefix(): ?string
    {
        return match ($this) {
            self::Atom10 => 'atom',
            self::Rdf => 'rdf',
            self::DublinCore => 'dc',
            self::Content => 'content',
            self::WellFormedWeb => 'wfw',
            self::MediaRss => 'media',
            self::Itunes => 'itunes',
            self::Podcast => 'podcast',
            self::PodloveSimpleChapters => 'psc',
            self::GooglePlay => 'googleplay',
            self::Atom03, self::Rss1 => null,
        };
    }
}
