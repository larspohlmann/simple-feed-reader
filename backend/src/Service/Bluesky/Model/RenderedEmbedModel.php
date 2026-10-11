<?php

declare(strict_types=1);

namespace App\Service\Bluesky\Model;

use App\Entity\LinkCardContent;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\ParsedMediumModel;

final readonly class RenderedEmbedModel
{
    /**
     * @param list<ParsedMediumModel> $media
     * @param list<LinkCardContent>   $linkCards in post order
     * @param list<string>            $uncardedLinks links shared without the title a card needs, to card from the page
     */
    public function __construct(
        public string $html,
        public ?DeclaredImageModel $leadImage = null,
        public array $media = [],
        public array $linkCards = [],
        public array $uncardedLinks = [],
    ) {
    }

    public static function card(LinkCardContent $card, ?DeclaredImageModel $leadImage): self
    {
        return new self('', $leadImage, linkCards: [$card]);
    }

    public static function uncarded(string $url): self
    {
        return new self('', uncardedLinks: [$url]);
    }

    public function followedBy(self $next): self
    {
        return new self(
            $this->html . $next->html,
            $this->leadImage ?? $next->leadImage,
            [...$this->media, ...$next->media],
            [...$this->linkCards, ...$next->linkCards],
            [...$this->uncardedLinks, ...$next->uncardedLinks],
        );
    }
}
