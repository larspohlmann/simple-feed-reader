<?php

declare(strict_types=1);

namespace App\Service\Discovery\Model;

final readonly class FeedCandidateModel
{
    /**
     * @param string $format 'rss' or 'atom' when advertised, 'feed' when only guessed, else a SourceFormat value
     */
    public function __construct(public string $url, public ?string $title, public string $format)
    {
    }
}
