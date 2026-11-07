<?php

declare(strict_types=1);

namespace App\Service\Reader\Media\Model;

/** One audio clip as the body plays it; title and duration only when the page stated them. */
final readonly class AudioClipModel
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?int $durationSeconds = null,
        public bool $narrated = false,
    ) {
    }

    public static function secondsFrom(?string $value): ?int
    {
        $trimmed = trim((string) $value);
        if (!ctype_digit($trimmed) || (int) $trimmed === 0) {
            return null;
        }

        return (int) $trimmed;
    }
}
