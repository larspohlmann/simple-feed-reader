<?php

declare(strict_types=1);

namespace App\Enum;

/** The read flag a change of one copy writes onto the other copies of the same item or article. */
enum CopyReadChange
{
    case Read;
    case Unread;
    case Viewed;
    case Unviewed;

    public static function fromHidden(bool $isHidden): self
    {
        return $isHidden ? self::Read : self::Unread;
    }

    public static function fromViewed(bool $isViewed): self
    {
        return $isViewed ? self::Viewed : self::Unviewed;
    }
}
