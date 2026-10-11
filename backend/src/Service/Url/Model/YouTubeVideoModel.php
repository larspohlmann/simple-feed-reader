<?php

declare(strict_types=1);

namespace App\Service\Url\Model;

final readonly class YouTubeVideoModel
{
    public const string PLAYER = 'https://www.youtube-nocookie.com/embed/';

    public const string SHORTS_FRAGMENT = '#shorts';

    private const string POSTER = 'https://i.ytimg.com/vi/%s/hqdefault.jpg';

    private function __construct(public string $id, public bool $isShort)
    {
    }

    public static function video(string $id): self
    {
        return new self($id, false);
    }

    public static function short(string $id): self
    {
        return new self($id, true);
    }

    public function playerUrl(): string
    {
        return self::PLAYER . $this->id . ($this->isShort ? self::SHORTS_FRAGMENT : '');
    }

    public function posterUrl(): string
    {
        return sprintf(self::POSTER, $this->id);
    }
}
