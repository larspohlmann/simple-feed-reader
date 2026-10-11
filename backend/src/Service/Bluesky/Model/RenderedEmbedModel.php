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
     */
    public function __construct(
        public string $html,
        public ?DeclaredImageModel $leadImage = null,
        public array $media = [],
        public array $linkCards = [],
        public ?string $uncardedLink = null,
    ) {
    }

    public static function card(LinkCardContent $card, ?DeclaredImageModel $leadImage): self
    {
        return new self('', $leadImage, linkCards: [$card]);
    }

    public static function uncarded(string $url): self
    {
        return new self('', uncardedLink: $url);
    }

    public function followedBy(self $next): self
    {
        return new self(
            $this->html . $next->html,
            $this->leadImage ?? $next->leadImage,
            [...$this->media, ...$next->media],
            [...$this->linkCards, ...$next->linkCards],
            $this->uncardedLink ?? $next->uncardedLink,
        );
    }
}
