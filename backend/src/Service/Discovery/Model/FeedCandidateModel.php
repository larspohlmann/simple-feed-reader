<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

final readonly class FeedCandidateModel
{
    /**
     * The dialect of a guessed candidate is unknown until something parses it,
     * so it is offered as a plain 'feed' — the dialog renders that as "Feed".
     */
    public const string GUESSED_FORMAT = 'feed';

    /**
     * @param string $format 'rss' or 'atom' when advertised or read, GUESSED_FORMAT when only guessed, else a
     *                       SourceFormat value
     */
    public function __construct(public string $url, public ?string $title, public string $format)
    {
    }

    public static function of(DiscoveredFeedModel $feed): self
    {
        return new self($feed->url, $feed->document->title, $feed->document->syntax->value ?? self::GUESSED_FORMAT);
    }
}
